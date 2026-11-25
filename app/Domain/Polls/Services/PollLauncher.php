<?php

declare(strict_types=1);

namespace App\Domain\Polls\Services;

use App\Domain\Polls\Enums\PollDeliveryStatus;
use App\Domain\Polls\Enums\PollStatus;
use App\Domain\Polls\Exceptions\PollLaunchException;
use App\Domain\Polls\Support\PollAudience;
use App\Domain\Telegram\Services\PollMessenger;
use App\Jobs\SendPollBatchJob;
use App\Models\AuditLog;
use App\Models\Poll;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The life of a poll after the form (docs/35 §1.5): launch, stop, close. Every transition is one short transaction under the poll's
 * row lock (`polls FOR UPDATE`) and is idempotent: a second click, a second administrator or a parallel command finds the new status
 * and does nothing (the method returns false).
 *
 * Launch:
 *  1. under the lock `draft → sending`, `launched_at`, the normalized audience written back (the snapshot: the form cannot change it
 *     after the launch, O6), `recipient_count = NULL`. The poll must have 2-10 options, a text that fits Telegram's limit in both
 *     languages, and a test send that reached an administrator;
 *  2. the recipients, by ranges of learner ids ({@see fill()}): per range one SELECT of the audience ({@see PollAudienceQuery}, the
 *     same definition as the preview count) and one `INSERT … ON CONFLICT (poll_id, learner_id) DO NOTHING`, each range its own short
 *     transaction under `polls FOR SHARE` - a stop waits for the range in flight and the next range sees it. Only learners created
 *     by `launched_at`: a resumed insert does not pick up later registrations. The token of each row is `Str::random(12)` (CSPRNG,
 *     62^12 ≈ 2^71) made in PHP: SQL has no portable strong random string, and the conflict target is the learner, so a token
 *     collision (≈10^-13 for 40 thousand rows) fails loudly instead of silently dropping a recipient;
 *  3. `recipient_count` is written - the mark that the insert is complete - and `polls.workers` job chains start.
 * If the process dies inside step 2, the poll stays `sending` with `recipient_count` NULL: `polls:sweep` runs {@see fill()} again
 * (the conflict target makes it idempotent) and starts the chains.
 *
 * Stop ({@see cancel()}): `draft|sending → cancelled`, then the rows still `pending` become `skipped` (`cancelled`) in short chunks.
 * A worker checks the poll status in the same statement that takes a row to `sending`, so after the stop each worker sends at most
 * the one message it had already started.
 *
 * Close ({@see close()}): `sending|sent → closed`, `closed_at`, the rows still `pending` become `skipped` (`closed`), and the totals
 * go into `results_snapshot` ({@see PollResults}), read after the status commit. A tap holds the poll row `FOR KEY SHARE` from its
 * first statement to its commit ({@see PollVoteService}), and the close takes it `FOR UPDATE`: the close waits for the taps in
 * flight, the taps after it see `closed` - so the snapshot has exactly the votes of the table (and of the CSV).
 */
final class PollLauncher
{
    public const AUDIT_LAUNCHED = 'poll.launched';

    public const AUDIT_CANCELLED = 'poll.cancelled';

    public const AUDIT_CLOSED = 'poll.closed';

    /** Pending rows turned `skipped` per statement on a stop / close. */
    private const SKIP_CHUNK = 5000;

    public function __construct(
        private readonly PollAudienceQuery $audience,
        private readonly PollMessenger $messenger,
        private readonly PollResults $results,
    ) {}

    /**
     * @return bool true = this call launched the poll; false = it was not a draft any more (launched, stopped, closed)
     *
     * @throws PollLaunchException when the poll cannot go out as it is (nothing is written)
     */
    public function launch(Poll $poll, User $by, ?CarbonImmutable $now = null): bool
    {
        $now = ($now ?? CarbonImmutable::now())->utc()->startOfSecond();

        $launched = DB::transaction(function () use ($poll, $by, $now): ?Poll {
            /** @var Poll|null $locked */
            $locked = Poll::query()->whereKey($poll->id)->lockForUpdate()->first();
            if ($locked === null || $locked->status !== PollStatus::Draft) {
                return null;
            }

            $this->assertLaunchable($locked);

            $locked->forceFill([
                'status' => PollStatus::Sending,
                'launched_at' => $now,
                'launched_by_user_id' => $by->id,
                'audience' => PollAudience::from($locked->audience)->toArray(),
                'recipient_count' => null,
            ])->save();

            AuditLog::query()->create([
                'user_id' => $by->id,
                'action' => self::AUDIT_LAUNCHED,
                'entity_type' => Poll::class,
                'entity_id' => $locked->id,
                'meta' => ['audience' => $locked->audience],
            ]);

            return $locked;
        });

        if ($launched === null) {
            return false;
        }

        $this->fill($launched);
        $poll->refresh();

        return true;
    }

    /**
     * Steps 2-3 of the launch, also the resume of `polls:sweep`: inserts the audience of a `sending` poll by ranges of learner ids,
     * then writes `recipient_count` (once) and starts the job chains. Stops quietly when the poll is no longer `sending`.
     *
     * @return int|null the recipients of the poll, null when it was stopped meanwhile
     */
    public function fill(Poll $poll): ?int
    {
        $launchedAt = $poll->launched_at === null ? CarbonImmutable::now()->utc() : CarbonImmutable::instance($poll->launched_at)->utc();
        $audience = PollAudience::from($poll->audience);
        $chunk = max(100, (int) config('polls.insert_chunk', 2000));
        $bounds = DB::table('learners')->selectRaw('min(id) as lo, max(id) as hi')->first();
        $lo = (int) ($bounds->lo ?? 0);
        $hi = (int) ($bounds->hi ?? 0);

        for ($from = $lo; $hi > 0 && $from <= $hi; $from += $chunk) {
            $to = min($hi, $from + $chunk - 1);

            $open = DB::transaction(function () use ($poll, $audience, $launchedAt, $from, $to): bool {
                $status = DB::table('polls')->where('id', $poll->id)->sharedLock()->value('status');
                if ($status !== PollStatus::Sending->value) {
                    return false;
                }

                $this->insertRange($poll->id, $audience, $launchedAt, $from, $to);

                return true;
            });

            if (! $open) {
                return null;
            }
        }

        $count = DB::table('poll_deliveries')->where('poll_id', $poll->id)->whereNotNull('learner_id')->count();
        $marked = DB::table('polls')
            ->where('id', $poll->id)
            ->where('status', PollStatus::Sending->value)
            ->whereNull('recipient_count')
            ->update(['recipient_count' => $count, 'updated_at' => now()]);

        if ($marked === 0) {
            return null;
        }

        $this->startWorkers($poll->id, $launchedAt);

        return $count;
    }

    /**
     * Starts the job chains of a launched poll (the launch, a resumed insert, a sweep of a stalled broadcast): one job per slot
     * 0..`polls.workers`-1, skipping a slot whose job is still waiting in the queue ({@see SendPollBatchJob::enqueue()}) - so a
     * repeated start never adds chains. Returns the jobs queued.
     */
    public function startWorkers(int $pollId, CarbonImmutable $launchedAt, int $workers = 0): int
    {
        $notAfter = SendPollBatchJob::deadline($launchedAt)->getTimestamp();
        $workers = $workers > 0 ? $workers : max(1, (int) config('polls.workers', 2));
        $queued = 0;

        for ($slot = 0; $slot < $workers; $slot++) {
            if (SendPollBatchJob::enqueue($pollId, $notAfter, $slot)) {
                $queued++;
            }
        }

        return $queued;
    }

    /**
     * Stops the broadcast: `draft` or `sending` → `cancelled`; the rows not sent yet become `skipped`.
     *
     * @return bool true = this call stopped it
     */
    public function cancel(Poll $poll, User $by): bool
    {
        $done = $this->transition($poll->id, [PollStatus::Draft, PollStatus::Sending], PollStatus::Cancelled, $by, self::AUDIT_CANCELLED, null);

        if ($done) {
            $this->skipPending($poll->id, 'cancelled');
            $poll->refresh();
        }

        return $done;
    }

    /**
     * Closes the poll: `sending` or `sent` → `closed` (by hand, or `polls:close-due` with `$by` null at `closes_at`); taps answer
     * "closed" from now on. What was not sent yet is skipped; the totals go into `results_snapshot`.
     *
     * @return bool true = this call closed it
     */
    public function close(Poll $poll, ?User $by, ?CarbonImmutable $now = null): bool
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $done = $this->transition($poll->id, [PollStatus::Sending, PollStatus::Sent], PollStatus::Closed, $by, self::AUDIT_CLOSED, $now);

        if (! $done) {
            return false;
        }

        $this->skipPending($poll->id, 'closed');
        $fresh = Poll::query()->findOrFail($poll->id);
        DB::table('polls')->where('id', $poll->id)->update([
            'results_snapshot' => json_encode($this->results->totals($fresh, $now), JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
        $poll->refresh();

        return true;
    }

    /**
     * The rows of a stopped / closed poll still `pending` → `skipped`, in chunks along `poll_deliveries_unsent_idx` (each statement
     * its own short transaction). Rows already `sending` are left to their worker / `polls:sweep`.
     */
    public function skipPending(int $pollId, string $reason): int
    {
        $total = 0;

        do {
            $ids = DB::table('poll_deliveries')
                ->where('poll_id', $pollId)
                ->whereRaw("status = 'pending'")
                ->limit(self::SKIP_CHUNK)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                break;
            }

            $total += DB::table('poll_deliveries')
                ->whereIn('id', $ids)
                ->whereRaw("status = 'pending'")
                ->update(['status' => PollDeliveryStatus::Skipped->value, 'error_code' => $reason, 'claimed_at' => null]);
        } while (count($ids) === self::SKIP_CHUNK);

        return $total;
    }

    /**
     * Stops a broadcast that cannot go on by itself (its picture vanished from the disk, {@see PollDeliverySender}): `sending →
     * cancelled` with no user (audit `poll.cancelled`, `reason` in the meta - `/admin` shows the poll stopped), then the rows still
     * `pending` become `skipped` with `$reason` as their code. True = this call stopped it: the caller reports the cause once.
     */
    public function halt(int $pollId, string $reason): bool
    {
        $done = $this->transition($pollId, [PollStatus::Sending], PollStatus::Cancelled, null, self::AUDIT_CANCELLED, null, ['reason' => $reason]);
        $this->skipPending($pollId, $reason);

        return $done;
    }

    /**
     * @param  list<PollStatus>  $from
     * @param  array<string, string>  $meta  more for the audit row
     */
    private function transition(int $pollId, array $from, PollStatus $to, ?User $by, string $action, ?CarbonImmutable $closedAt, array $meta = []): bool
    {
        return DB::transaction(function () use ($pollId, $from, $to, $by, $action, $closedAt, $meta): bool {
            /** @var Poll|null $locked */
            $locked = Poll::query()->whereKey($pollId)->lockForUpdate()->first();
            if ($locked === null || ! in_array($locked->status, $from, true)) {
                return false;
            }

            $was = $locked->status;
            $locked->status = $to;
            if ($closedAt !== null) {
                $locked->forceFill(['closed_at' => $closedAt]);
            }
            $locked->save();

            AuditLog::query()->create([
                'user_id' => $by?->id,
                'action' => $action,
                'entity_type' => Poll::class,
                'entity_id' => $locked->id,
                'meta' => ['from' => $was->value, ...$meta],
            ]);

            return true;
        });
    }

    private function assertLaunchable(Poll $poll): void
    {
        $options = $poll->options()->get();
        if ($options->count() < 2 || $options->count() > 10) {
            throw PollLaunchException::because(PollLaunchException::OPTIONS);
        }

        if (! $this->messenger->fits($poll, $options)) {
            throw PollLaunchException::because(PollLaunchException::TOO_LONG);
        }

        // The file is needed whenever Telegram refuses the stored `file_id` (the broadcast uploads it again), so it must be there.
        if (! PollDeliverySender::pictureReadable($poll)) {
            throw PollLaunchException::because(PollLaunchException::IMAGE_MISSING);
        }

        $tested = DB::table('poll_deliveries')
            ->where('poll_id', $poll->id)
            ->whereNull('learner_id')
            ->where('status', PollDeliveryStatus::Sent->value)
            ->exists();

        if (! $tested) {
            throw PollLaunchException::because(PollLaunchException::UNTESTED);
        }
    }

    /** One range of learner ids: one SELECT of the audience, one INSERT of its rows. */
    private function insertRange(int $pollId, PollAudience $audience, CarbonImmutable $launchedAt, int $from, int $to): void
    {
        $learners = $this->audience->for($audience, $launchedAt, $from, $to)
            ->where('learners.created_at', '<=', $launchedAt)
            ->orderBy('learners.id')
            ->toBase()
            ->get(['learners.id', 'learners.telegram_user_id', 'learners.interface_locale']);

        if ($learners->isEmpty()) {
            return;
        }

        $placeholders = [];
        $bindings = [];
        foreach ($learners as $learner) {
            $placeholders[] = '(?, ?, ?, ?, ?, ?, ?)';
            array_push(
                $bindings,
                $pollId,
                (int) $learner->id,
                (int) $learner->telegram_user_id,
                false,
                Str::random(12),
                PollMessenger::locale(is_string($learner->interface_locale) ? $learner->interface_locale : null),
                PollDeliveryStatus::Pending->value,
            );
        }

        DB::insert(
            'INSERT INTO poll_deliveries (poll_id, learner_id, telegram_user_id, is_test, token, locale, status) VALUES '
            .implode(', ', $placeholders).' ON CONFLICT (poll_id, learner_id) DO NOTHING',
            $bindings,
        );
    }
}
