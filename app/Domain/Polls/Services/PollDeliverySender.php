<?php

declare(strict_types=1);

namespace App\Domain\Polls\Services;

use App\Domain\Polls\DTO\PollBatchOutcome;
use App\Domain\Polls\Enums\PollStatus;
use App\Domain\Polls\Exceptions\PollLaunchException;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\DTO\PhotoSource;
use App\Domain\Telegram\DTO\SentMessage;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Exceptions\TelegramRateLimitedException;
use App\Domain\Telegram\Services\DmReachability;
use App\Domain\Telegram\Services\PollMessenger;
use App\Domain\Telegram\Services\TelegramMessageRateLimiter;
use App\Jobs\SendPollBatchJob;
use App\Models\Poll;
use App\Models\PollOption;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The broadcast of a launched poll (docs/35 §1.5 "Рассылка", O3): the body of {@see SendPollBatchJob}. At most ONE message per
 * delivery, whatever crashes, retries or parallel workers do - a duplicate poll is worse than a missed one.
 *
 * One batch:
 *  1. **Lease** (1 statement): up to `polls.batch_size` rows of the poll that are `pending` and not leased by a live worker, in id
 *     order along the partial index `poll_deliveries_unsent_idx`, `FOR UPDATE SKIP LOCKED` (PostgreSQL), stamped `claimed_at`. Two
 *     workers never lease the same rows; a crashed worker's lease runs out after `polls.lease_seconds` and the rows come back.
 *  2. Per row: a slot of the bot's budget for bulk sends ({@see TelegramMessageRateLimiter::reserveGlobalBelow()}: under the
 *     `polls.send_per_second` ceiling of the whole bot, and none while any other sender is refused), then **the step to `sending`**
 *     (1 statement): `status = 'pending'` AND the poll is still `sending` AND the learner is still reachable. This conditional
 *     UPDATE is what makes the send unique - a row is sent only by the worker whose UPDATE took it from `pending` - and the stop of
 *     the poll is seen right there. Then the Telegram call, then the **result** (1 statement): `sent` (+ `message_id`), `forbidden`
 *     (+ {@see DmReachability::markUnreachable()}), `failed` (another definite refusal, reported) or `unknown` (a timeout / 5xx: it
 *     may have arrived - never sent again).
 *  3. A 429: the row goes back to `pending` (definitely not delivered), the rest of the lease is released, the job comes back after
 *     `retry_after`. A transport error: the row is `unknown`, the lease released and the exception thrown (the job's backoff waits
 *     out the outage). No slot within `telegram.outbound.sync_wait_ms`, or `$budgetSeconds` spent: the lease is released, a fresh job
 *     goes on.
 *  4. Nothing left to lease: the poll goes `sending → sent` when no row of it is `pending` or `sending` any more (1 statement).
 *  5. The picture is needed (Telegram has no `file_id` of it, or refused the stored one) and its file is gone: the broadcast stops
 *     for good ({@see PollLauncher::halt()}: `cancelled`, the rest `skipped` with `image_missing`), reported once - not a retry loop
 *     until the deadline.
 *
 * Statements: 2 per message + at most 5 per batch (the poll, its options, the lease, the release, the finish) - a test pins it.
 * A worker that dies between the step to `sending` and the result leaves the row `sending`: `polls:sweep` makes it `unknown`.
 *
 * The status literals in the SQL are constants on purpose (never bindings): the planner proves the predicate of the partial index
 * from them even under a generic plan.
 */
final class PollDeliverySender
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly TelegramMessageRateLimiter $outbound,
        private readonly DmReachability $reachability,
        private readonly PollMessenger $messenger,
        private readonly PollLauncher $launcher,
    ) {}

    public function sendBatch(int $pollId, int $budgetSeconds, ?CarbonImmutable $now = null): PollBatchOutcome
    {
        $clock = static fn (): CarbonImmutable => ($now ?? CarbonImmutable::now())->utc();
        $deadline = hrtime(true) + max(0, $budgetSeconds) * 1_000_000_000;
        $maxWaitMs = max(0, (int) config('telegram.outbound.sync_wait_ms', 2000));
        $ceiling = (int) config('polls.send_per_second', 15);
        $count = ['sent' => 0, 'skipped' => 0, 'forbidden' => 0, 'failed' => 0, 'unknown' => 0, 'released' => 0];

        /** @var Poll|null $poll */
        $poll = Poll::query()->find($pollId);
        if ($poll === null || $poll->status !== PollStatus::Sending || $this->pastDeadline($poll, $clock())) {
            return new PollBatchOutcome;
        }

        $options = $poll->options()->get();
        if ($poll->image_file_id === null && ! self::pictureReadable($poll)) {
            // Telegram does not know the picture and the file is gone: no send can succeed. Stop the broadcast for good.
            return $this->haltWithoutPicture($poll, $count);
        }

        $leasedAt = $clock()->startOfSecond();
        $rows = $this->lease($pollId, $leasedAt);
        if ($rows === []) {
            $this->finishIfDone($pollId, $clock());

            return new PollBatchOutcome;
        }

        $more = true;
        $delay = 0;

        foreach ($rows as $i => $row) {
            $rest = array_slice($rows, $i);

            if ($i > 0 && hrtime(true) >= $deadline) {
                $count['released'] += $this->release($rest, $leasedAt);

                break;
            }

            if (! $this->outbound->awaitGlobalBelow($ceiling, $maxWaitMs)) {
                $count['released'] += $this->release($rest, $leasedAt);
                $delay = $this->outbound->globalRetryDelay();

                break;
            }

            $took = $this->takeForSending($row['id'], $clock());
            if (! $took) {
                $why = $this->whyNotTaken($row['id']);
                if ($why === 'stopped') {
                    // Stopped, closed or done meanwhile: the launcher skips what is pending, nothing more for this chain.
                    $more = false;

                    break;
                }
                if ($why === 'unreachable') {
                    $count['skipped'] += $this->finish($row['id'], 'pending', 'skipped', 'unreachable');
                }

                continue;
            }

            try {
                $sent = $this->transmit($poll, $options, $row['telegram_user_id'], $row['token'], $row['locale']);
            } catch (PollLaunchException $e) {
                // The picture vanished while the broadcast ran (Telegram refused the `file_id`, the upload has no file): nothing was
                // sent for this row. It and the rest of the poll are skipped; the poll stops.
                $count['skipped'] += $this->finish($row['id'], 'sending', 'skipped', PollLaunchException::IMAGE_MISSING);
                $count['released'] += $this->release(array_slice($rest, 1), $leasedAt);

                return $this->haltWithoutPicture($poll, $count);
            } catch (TelegramForbiddenException $e) {
                $this->finish($row['id'], 'sending', 'forbidden', $e->reason);
                $this->reachability->markUnreachable($row['telegram_user_id'], $e->reason, $clock());
                $count['forbidden']++;

                continue;
            } catch (TelegramRateLimitedException $e) {
                // Not delivered: back to `pending` for the next job, with the rest of the lease. The client has paused the bot.
                $this->finish($row['id'], 'sending', 'pending', null, unlease: true);
                $count['released'] += $this->release(array_slice($rest, 1), $leasedAt) + 1;
                $delay = max($e->retryAfter, $this->outbound->globalRetryDelay());
                Log::info('polls.broadcast_rate_limited', ['poll_id' => $pollId, 'retry_after' => $e->retryAfter]);

                break;
            } catch (TelegramApiException $e) {
                if ($e->isApiResponse()) {
                    // A definite refusal of this one message (not a 403, not a 429): it will not get better by a retry.
                    $this->finish($row['id'], 'sending', 'failed', 'http_'.$e->getCode());
                    $count['failed']++;
                    report($e);

                    continue;
                }

                // A timeout / 5xx: it may have been delivered - never again. The outage likely hits the rest: stop, let the job back off.
                // The exception takes the place of the outcome, so the batch's counts go to the log line.
                $count['unknown'] += $this->finish($row['id'], 'sending', 'unknown', 'transport');
                $count['released'] += $this->release(array_slice($rest, 1), $leasedAt);
                Log::warning('polls.broadcast_transport_failed', ['poll_id' => $pollId, ...$count]);

                throw $e;
            }

            DB::update(
                "UPDATE poll_deliveries SET status = 'sent', message_id = ?, sent_at = ?, error_code = NULL WHERE id = ? AND status = 'sending'",
                [$sent->messageId, $clock()->format('Y-m-d H:i:s'), $row['id']],
            );
            $count['sent']++;
            $this->rememberFileId($poll, $sent);
        }

        return new PollBatchOutcome(...$count, more: $more, delaySeconds: $delay);
    }

    /**
     * Sends the first message of a delivery: the photo by its `file_id` once Telegram knows it (else the file of the private disk),
     * or the text. A refusal of the stored `file_id` ITSELF (the bot changed, the file expired: {@see refusesFileId()}) is retried
     * once by an upload; any other refusal of the message is the row's.
     *
     * @param  Collection<int, PollOption>  $options
     *
     * @throws TelegramForbiddenException the recipient cannot be reached
     * @throws TelegramApiException any other refusal ({@see TelegramRateLimitedException} for a 429), a timeout
     * @throws PollLaunchException {@see PollLaunchException::IMAGE_MISSING}: an upload is needed and the file cannot be read
     *                             (nothing was sent by this call)
     */
    public function transmit(Poll $poll, Collection $options, int $chatId, string $token, string $locale): SentMessage
    {
        $text = $this->messenger->text($poll, $options, $locale);
        $keyboard = $this->messenger->keyboard($poll, $options, $token, $locale, $chatId);

        if ($poll->image_file_id === null && $poll->image_path === null) {
            return $this->telegram->sendTextMessage($chatId, $text, $keyboard);
        }

        if ($poll->image_file_id !== null) {
            try {
                return $this->telegram->sendPhotoMessage($chatId, PhotoSource::fileId($poll->image_file_id), $text, $keyboard);
            } catch (TelegramForbiddenException|TelegramRateLimitedException $e) {
                throw $e;
            } catch (TelegramApiException $e) {
                if (! $e->isApiResponse() || $poll->image_path === null || ! self::refusesFileId($e)) {
                    throw $e;
                }

                Log::warning('polls.file_id_refused', ['poll_id' => $poll->id, 'error' => $e->getMessage()]);
                DB::table('polls')->where('id', $poll->id)->where('image_file_id', $poll->image_file_id)->update(['image_file_id' => null]);
                $poll->image_file_id = null;
            }
        }

        if (! self::pictureReadable($poll)) {
            throw PollLaunchException::because(PollLaunchException::IMAGE_MISSING);
        }

        return $this->telegram->sendPhotoMessage($chatId, PhotoSource::file(Storage::disk(PollEditor::IMAGE_DISK)->path((string) $poll->image_path)), $text, $keyboard);
    }

    /** Whether the poll's picture can be uploaded: no picture, or its file on the private disk is there and readable. */
    public static function pictureReadable(Poll $poll): bool
    {
        if ($poll->image_path === null || $poll->image_path === '') {
            return true;
        }

        $disk = Storage::disk(PollEditor::IMAGE_DISK);
        if (! $disk->exists($poll->image_path)) {
            return false;
        }

        $path = $disk->path($poll->image_path);

        return is_file($path) && is_readable($path);
    }

    /**
     * A refusal that is about the `file_id` sent, not about the message (Bot API descriptions: "wrong file identifier/HTTP URL
     * specified", "wrong remote file identifier specified", "FILE_REFERENCE_EXPIRED", "type of file mismatch", "invalid file_id").
     * Only these make the stored id worthless; a caption, a keyboard or a chat refused would be refused for an upload too.
     */
    public static function refusesFileId(TelegramApiException $e): bool
    {
        $description = strtolower($e->getMessage());

        foreach (['file identifier', 'file_id', 'file reference', 'file_reference', 'type of file mismatch'] as $needle) {
            if (str_contains($description, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** The first photo that went out by upload gives the `file_id` every later send uses. */
    public function rememberFileId(Poll $poll, SentMessage $sent): void
    {
        if ($poll->image_file_id !== null || $poll->image_path === null || $sent->photoFileId === null) {
            return;
        }

        DB::table('polls')->where('id', $poll->id)->whereNull('image_file_id')->update(['image_file_id' => $sent->photoFileId]);
        $poll->image_file_id = $sent->photoFileId;
    }

    /**
     * `sending → sent` once no non-test row of the poll is `pending` or `sending` and the insert of the launch has finished.
     * One statement along `poll_deliveries_unsent_idx`.
     */
    public function finishIfDone(int $pollId, ?CarbonImmutable $now = null): bool
    {
        return DB::update(
            "UPDATE polls SET status = 'sent', updated_at = ? WHERE id = ? AND status = 'sending' AND recipient_count IS NOT NULL "
            ."AND NOT EXISTS (SELECT 1 FROM poll_deliveries d WHERE d.poll_id = polls.id AND d.status IN ('pending', 'sending') "
            .'AND d.learner_id IS NOT NULL)',
            [($now ?? CarbonImmutable::now())->utc()->format('Y-m-d H:i:s'), $pollId],
        ) > 0;
    }

    /**
     * @return list<array{id: int, telegram_user_id: int, token: string, locale: string}> in id order
     */
    private function lease(int $pollId, CarbonImmutable $at): array
    {
        $lock = DB::getDriverName() === 'pgsql' ? ' FOR UPDATE SKIP LOCKED' : '';
        $leaseSeconds = max(1, (int) config('polls.lease_seconds', 300));
        $stamp = $at->format('Y-m-d H:i:s');

        $rows = DB::select(
            'WITH c AS (SELECT id FROM poll_deliveries WHERE poll_id = ? AND status = \'pending\' '
            .'AND (claimed_at IS NULL OR claimed_at < ?) ORDER BY id LIMIT ?'.$lock.') '
            .'UPDATE poll_deliveries SET claimed_at = ? WHERE id IN (SELECT id FROM c) '
            .'RETURNING id, telegram_user_id, token, locale',
            [$pollId, $at->subSeconds($leaseSeconds)->format('Y-m-d H:i:s'), max(1, (int) config('polls.batch_size', 100)), $stamp],
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) $row->id,
                'telegram_user_id' => (int) $row->telegram_user_id,
                'token' => trim((string) $row->token),
                'locale' => (string) $row->locale,
            ];
        }
        usort($out, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return $out;
    }

    /**
     * The step `pending → sending`: only while the poll is `sending` and the learner can still be written to - the same definition
     * of "reachable" as the audience of the launch ({@see PollAudienceQuery::reachableSql()}).
     */
    private function takeForSending(int $deliveryId, CarbonImmutable $at): bool
    {
        return DB::update(
            "UPDATE poll_deliveries SET status = 'sending', claimed_at = ? WHERE id = ? AND status = 'pending' "
            ."AND EXISTS (SELECT 1 FROM polls p WHERE p.id = poll_deliveries.poll_id AND p.status = 'sending') "
            .'AND EXISTS (SELECT 1 FROM learners l WHERE l.id = poll_deliveries.learner_id AND '.PollAudienceQuery::reachableSql('l').')',
            [$at->format('Y-m-d H:i:s'), $deliveryId],
        ) === 1;
    }

    /**
     * Why the step to `sending` did not happen (the rare path, one statement): the poll is not `sending` any more, the learner is
     * gone / banned / closed the DM, or another worker has the row (or it was purged with its learner).
     *
     * @return 'stopped'|'unreachable'|'taken'
     */
    private function whyNotTaken(int $deliveryId): string
    {
        $row = DB::selectOne(
            'SELECT d.status AS delivery, p.status AS poll FROM poll_deliveries d JOIN polls p ON p.id = d.poll_id WHERE d.id = ?',
            [$deliveryId],
        );

        if ($row === null) {
            return 'taken'; // the learner was purged meanwhile: the row went with them
        }

        if ($row->poll !== PollStatus::Sending->value) {
            return 'stopped';
        }

        return $row->delivery === 'pending' ? 'unreachable' : 'taken';
    }

    /** One result write, guarded by the status the row must still have. Returns the rows changed (0 or 1). */
    private function finish(int $deliveryId, string $from, string $to, ?string $errorCode, bool $unlease = false): int
    {
        return DB::update(
            'UPDATE poll_deliveries SET status = ?, error_code = ?'.($unlease ? ', claimed_at = NULL' : '').' WHERE id = ? AND status = ?',
            [$to, $errorCode, $deliveryId, $from],
        );
    }

    /**
     * Gives back the rows of the lease this batch will not send. Only if still pending and still leased by this batch.
     *
     * @param  list<array{id: int, telegram_user_id: int, token: string, locale: string}>  $rows
     */
    private function release(array $rows, CarbonImmutable $leasedAt): int
    {
        if ($rows === []) {
            return 0;
        }

        return DB::table('poll_deliveries')
            ->whereIn('id', array_column($rows, 'id'))
            ->whereRaw("status = 'pending'")
            ->where('claimed_at', $leasedAt->format('Y-m-d H:i:s'))
            ->update(['claimed_at' => null]);
    }

    private function pastDeadline(Poll $poll, CarbonImmutable $now): bool
    {
        return $poll->launched_at !== null
            && $now->greaterThanOrEqualTo(SendPollBatchJob::deadline(CarbonImmutable::instance($poll->launched_at)));
    }

    /**
     * The picture is gone and Telegram cannot be given it: the poll stops ({@see PollLauncher::halt()}: `cancelled`, the pending rows
     * `skipped` with {@see PollLaunchException::IMAGE_MISSING}), reported once - by the batch that stopped it - and the chain ends.
     * Without this every batch would throw until the deadline and the poll would hang in `sending`.
     *
     * @param  array{sent: int, skipped: int, forbidden: int, failed: int, unknown: int, released: int}  $count
     */
    private function haltWithoutPicture(Poll $poll, array $count): PollBatchOutcome
    {
        if ($this->launcher->halt($poll->id, PollLaunchException::IMAGE_MISSING)) {
            report(new RuntimeException("The broadcast of poll {$poll->id} stopped: its picture {$poll->image_path} is missing on the private disk and Telegram has no file_id of it."));
        }

        return new PollBatchOutcome(...$count, more: false);
    }
}
