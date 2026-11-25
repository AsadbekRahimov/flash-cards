<?php

declare(strict_types=1);

namespace App\Domain\Cup\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Cup\DTO\CupCardContent;
use App\Domain\Cup\Enums\CupMessageKind;
use App\Domain\Cup\Enums\CupMessageStatus;
use App\Domain\Cup\Enums\CupStatus;
use App\Domain\Cup\Support\CupAccess;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\DTO\PhotoSource;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Exceptions\TelegramRateLimitedException;
use App\Domain\Telegram\Services\GroupBotRemoval;
use App\Domain\Telegram\Services\TelegramMessageRateLimiter;
use App\Jobs\PostCupMessageJob;
use App\Models\Cup;
use App\Models\CupMessage;
use App\Models\TelegramGroup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The outgoing messages of a cup (docs/33 §2.8, §6.4): one row of `cup_messages` per `(cup, dedupe_key)` - `card` (the proposal, then
 * the card), `summary:N`, `final` - and {@see deliver()}, the one way a row reaches Telegram, run by {@see PostCupMessageJob}.
 *
 * At most once, never twice:
 *  1. a conditional UPDATE `pending → sending` claims the row - a second worker, a retried job or a second queueing updates 0 rows and
 *     leaves (the race of two workers is a test on PostgreSQL);
 *  2. a paused cup (the bot is out of the chat, the platform forbade cups), a cup switched off by `CUP_ACCESS` or a closed one →
 *     `skipped`;
 *  3. no slot of the chat (20 a minute, or the pause of this chat after its 429) → back to `pending`, the job comes back later;
 *  4. sent → `sent` with the message id (the card also lands in `cups.card_*`); a 429 → `pending` and the exception goes on (the
 *     middleware puts the job back for `retry_after`; a 429 of a group pauses only that chat - the client does so, docs/34 §4в п. 2);
 *     403 / chat not found → `skipped` and the group `disabled` ({@see GroupBotRemoval}); another definite refusal → `pending` and
 *     rethrown (a retry), `failed` after {@see MAX_ATTEMPTS}; a group moved to a supergroup is followed by the client (it repeats the
 *     send in the new chat);
 *  5. a timeout or a 5xx (it may have been posted) → `unknown`, never sent again: a summary lost is better than a summary twice; the
 *     card shows the result anyway.
 * A row left in `sending` (the worker died) becomes `unknown` after {@see STALE_SENDING_MINUTES} ({@see sweep()}), not `pending` - the
 * same reason.
 *
 * No lock is held across the call to Telegram: the claim is its own statement.
 */
final class CupOutbox
{
    /** Definite refusals of Telegram after which a row is given up (`failed`). */
    public const MAX_ATTEMPTS = 5;

    /** A row in `sending` older than this lost its worker. */
    public const STALE_SENDING_MINUTES = 10;

    /** A `pending` row untouched this long has no job any more (lost, failed, never queued): {@see sweep()} queues it again. */
    public const REQUEUE_AFTER_MINUTES = 2;

    public const CARD_KEY = 'card';

    public const FINAL_KEY = 'final';

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly TelegramMessageRateLimiter $limiter,
        private readonly CupCardRenderer $renderer,
        private readonly GroupBotRemoval $removal,
        private readonly ProductEventRecorder $events,
    ) {}

    public static function summaryKey(int $day): string
    {
        return 'summary:'.$day;
    }

    /**
     * The row of a message (`INSERT … ON CONFLICT DO NOTHING`) and its job after the commit. A second call with the same key does
     * nothing: the row is already there (sent or on its way).
     */
    public function enqueue(int $cupId, string $dedupeKey, CupMessageKind $kind, CarbonImmutable $now): bool
    {
        $created = DB::table('cup_messages')->insertOrIgnore([
            'cup_id' => $cupId,
            'dedupe_key' => $dedupeKey,
            'kind' => $kind->value,
            'status' => CupMessageStatus::Pending->value,
            'attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]) > 0;

        if ($created) {
            $id = (int) DB::table('cup_messages')->where('cup_id', $cupId)->where('dedupe_key', $dedupeKey)->value('id');
            PostCupMessageJob::dispatch($id)->afterCommit();
        }

        return $created;
    }

    /**
     * The card is posted anew (docs/33 §4.5, §6.3): the bot came back to the chat, the chat became a supergroup, an administrator
     * deleted the card. Its row goes back to `pending` (or is made) and is queued; a row being sent right now is left alone. The old
     * `cups.card_*` stay until the new message is there.
     */
    public function republishCard(int $cupId, CarbonImmutable $now): bool
    {
        $reset = DB::table('cup_messages')
            ->where('cup_id', $cupId)
            ->where('dedupe_key', self::CARD_KEY)
            ->whereNotIn('status', [CupMessageStatus::Pending->value, CupMessageStatus::Sending->value])
            ->update([
                'status' => CupMessageStatus::Pending->value,
                'attempts' => 0,
                'message_id' => null,
                'claimed_at' => null,
                'sent_at' => null,
                'error' => null,
                'updated_at' => $now,
            ]);

        if ($reset > 0) {
            $id = (int) DB::table('cup_messages')->where('cup_id', $cupId)->where('dedupe_key', self::CARD_KEY)->value('id');
            PostCupMessageJob::dispatch($id)->afterCommit();

            return true;
        }

        return $this->enqueue($cupId, self::CARD_KEY, CupMessageKind::Card, $now);
    }

    /**
     * Sends one row (the body of {@see PostCupMessageJob}). Returns the seconds after which the job should come back (the chat has no
     * slot now), else 0.
     *
     * @throws TelegramRateLimitedException a 429: the row is `pending` again, the middleware puts the job back for `retry_after`
     * @throws TelegramApiException another definite refusal below {@see MAX_ATTEMPTS}: the row is `pending` again, the job retries
     */
    public function deliver(int $cupMessageId, CarbonImmutable $now): int
    {
        $claimed = DB::table('cup_messages')
            ->where('id', $cupMessageId)
            ->where('status', CupMessageStatus::Pending->value)
            ->increment('attempts', 1, ['status' => CupMessageStatus::Sending->value, 'claimed_at' => $now, 'updated_at' => $now]);
        if ($claimed !== 1) {
            return 0;
        }

        /** @var CupMessage $message */
        $message = CupMessage::query()->findOrFail($cupMessageId);
        /** @var Cup|null $cup */
        $cup = Cup::query()->with('telegramGroup')->find($message->cup_id);
        $group = $cup?->telegramGroup;
        if ($cup === null || $group === null) {
            $this->finish($message, CupMessageStatus::Skipped, $now, 'no_cup');

            return 0;
        }

        $skip = $this->skipReason($message, $cup, $group);
        if ($skip !== null) {
            $this->finish($message, CupMessageStatus::Skipped, $now, $skip);

            return 0;
        }

        $wait = $this->limiter->reserve($group->chat_id);
        if ($wait > 0) {
            $this->release($message, $now);

            return $wait;
        }

        [$content, $photo] = $this->content($message, $cup, $group, $now);
        $threadId = $cup->card_thread_id;

        try {
            $messageId = $photo !== null
                ? $this->telegram->sendPhotoMessage($group->chat_id, PhotoSource::file($photo), $content->textFor(true), $content->keyboard)->messageId
                : $this->telegram->sendGroupMessage($group->chat_id, $content->textFor(false), null, $content->keyboard, $threadId);
        } catch (TelegramForbiddenException $e) {
            // The bot is not in the chat (docs/34 §4в п. 3): nothing was posted and nothing will be.
            $this->finish($message, CupMessageStatus::Skipped, $now, 'forbidden:'.$e->reason);
            $this->removal->disable($group, $e, 'cup_message');

            return 0;
        } catch (TelegramRateLimitedException $e) {
            $this->release($message, $now);

            throw $e;
        } catch (TelegramApiException $e) {
            if (! $e->isApiResponse()) {
                // A timeout / 5xx: it may be in the chat. Never again (docs/33 §6.4 step 5).
                $this->finish($message, CupMessageStatus::Unknown, $now, $this->error($e));

                return 0;
            }
            if ($message->attempts >= self::MAX_ATTEMPTS) {
                $this->finish($message, CupMessageStatus::Failed, $now, $this->error($e));
                Log::warning('cup.message_failed', ['cup_message_id' => $message->id, 'kind' => $message->kind->value, 'code' => $e->getCode()]);

                return 0;
            }
            $this->release($message, $now, $this->error($e));

            throw $e;
        }

        $this->sent($message, $cup, $group, $content, $photo !== null, $messageId, $threadId, $now);

        return 0;
    }

    /**
     * `cups:send-outbox`: rows stuck in `sending` become `unknown`; `pending` rows nobody works on are queued again (each job claims
     * before it sends, so a row queued twice is still sent once). Both through the partial index of the open rows.
     *
     * @return array{stale: int, queued: int}
     */
    public function sweep(CarbonImmutable $now, int $batch): array
    {
        $stale = DB::table('cup_messages')
            ->where('status', CupMessageStatus::Sending->value)
            ->where('claimed_at', '<', $now->subMinutes(self::STALE_SENDING_MINUTES))
            ->update(['status' => CupMessageStatus::Unknown->value, 'error' => 'stale_sending', 'updated_at' => $now]);

        $ids = DB::table('cup_messages')
            ->where('status', CupMessageStatus::Pending->value)
            ->where('updated_at', '<=', $now->subMinutes(self::REQUEUE_AFTER_MINUTES))
            ->orderBy('created_at')
            ->limit(max(1, $batch))
            ->pluck('id');

        foreach ($ids as $id) {
            PostCupMessageJob::dispatch((int) $id);
        }

        return ['stale' => $stale, 'queued' => count($ids)];
    }

    /** Why the row is not sent at all, or null. */
    private function skipReason(CupMessage $message, Cup $cup, TelegramGroup $group): ?string
    {
        if (! CupAccess::allowsGroup($group)) {
            return 'access_off';
        }
        $pause = CupAccess::pauseReason($group);
        if ($pause !== null) {
            return 'paused:'.$pause->value;
        }

        return match ($message->kind) {
            CupMessageKind::Card => $cup->status->isOpen() ? null : 'closed',
            CupMessageKind::Summary => $cup->status === CupStatus::Running || $cup->status === CupStatus::Finished ? null : 'closed',
            CupMessageKind::Final => $cup->status === CupStatus::Finished ? null : 'not_finished',
            default => 'unsupported',
        };
    }

    /**
     * The text and the buttons of the row, and the picture for the card and the final - except in a topic of a forum (a photo of
     * the client has no `message_thread_id`) or when the picture cannot be drawn: then the same text as a message.
     *
     * @return array{0: CupCardContent, 1: string|null}
     */
    private function content(CupMessage $message, Cup $cup, TelegramGroup $group, CarbonImmutable $now): array
    {
        if ($message->kind === CupMessageKind::Summary) {
            return [$this->renderer->summary($cup, $group, (int) substr($message->dedupe_key, strlen('summary:'))), null];
        }

        $final = $message->kind === CupMessageKind::Final;
        $content = $final ? $this->renderer->final($cup, $group) : $this->renderer->card($cup, $group, $now);
        $photo = $cup->card_thread_id === null ? $this->renderer->photo($cup, $final) : null;

        return [$content, $photo];
    }

    private function sent(CupMessage $message, Cup $cup, TelegramGroup $group, CupCardContent $content, bool $photo, int $messageId, ?int $threadId, CarbonImmutable $now): void
    {
        DB::transaction(function () use ($message, $cup, $group, $content, $photo, $messageId, $threadId, $now): void {
            DB::table('cup_messages')->where('id', $message->id)->where('status', CupMessageStatus::Sending->value)->update([
                'status' => CupMessageStatus::Sent->value,
                'chat_id' => $group->chat_id,
                'message_id' => $messageId,
                'thread_id' => $threadId,
                'sent_at' => $now,
                'error' => null,
                'updated_at' => $now,
            ]);

            if ($message->kind === CupMessageKind::Card) {
                // Not `updated_at`: it says when the CUP changed, and the refresh of the cards compares it with `card_rendered_at`.
                DB::table('cups')->where('id', $cup->id)->update([
                    'card_chat_id' => $group->chat_id,
                    'card_message_id' => $messageId,
                    'card_text_hash' => $content->hash(),
                    'card_rendered_at' => $now,
                    'meta' => json_encode([...$cup->meta, 'card_kind' => $photo ? CupCards::KIND_PHOTO : CupCards::KIND_TEXT], JSON_THROW_ON_ERROR),
                ]);
            }
        });

        if ($message->kind === CupMessageKind::Summary) {
            $this->events->record(ProductEventName::CupSummaryPosted, ['cup_id' => $cup->id, 'day' => (int) substr($message->dedupe_key, strlen('summary:'))], group: $group);
        }
    }

    private function finish(CupMessage $message, CupMessageStatus $status, CarbonImmutable $now, string $error): void
    {
        DB::table('cup_messages')->where('id', $message->id)->where('status', CupMessageStatus::Sending->value)
            ->update(['status' => $status->value, 'error' => mb_substr($error, 0, 255), 'updated_at' => $now]);
    }

    /** Back to `pending`: not sent, the job (or the next sweep) tries again. A wait for a slot does not count as an attempt. */
    private function release(CupMessage $message, CarbonImmutable $now, ?string $error = null): void
    {
        $query = DB::table('cup_messages')->where('id', $message->id)->where('status', CupMessageStatus::Sending->value);
        $error === null
            ? $query->decrement('attempts', 1, ['status' => CupMessageStatus::Pending->value, 'claimed_at' => null, 'updated_at' => $now])
            : $query->update(['status' => CupMessageStatus::Pending->value, 'claimed_at' => null, 'error' => $error, 'updated_at' => $now]);
    }

    /** The reason of a refusal for `cup_messages.error`: the code and the (already redacted, token-free) text. */
    private function error(TelegramApiException $e): string
    {
        return mb_substr('api:'.$e->getCode().' '.$e->getMessage(), 0, 255);
    }
}
