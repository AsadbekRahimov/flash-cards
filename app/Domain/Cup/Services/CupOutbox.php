<?php

declare(strict_types=1);

namespace App\Domain\Cup\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Cup\DTO\CupCardContent;
use App\Domain\Cup\Enums\CupDuelStatus;
use App\Domain\Cup\Enums\CupMessageKind;
use App\Domain\Cup\Enums\CupMessageStatus;
use App\Domain\Cup\Enums\CupStatus;
use App\Domain\Cup\Support\CupAccess;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\DTO\PhotoSource;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TelegramChatMigratedException;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Exceptions\TelegramRateLimitedException;
use App\Domain\Telegram\Services\GroupBotRemoval;
use App\Domain\Telegram\Services\TelegramMessageRateLimiter;
use App\Jobs\PostCupMessageJob;
use App\Models\Cup;
use App\Models\CupDuel;
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
 * The rows of a duel (`kind = duel`, D2, {@see deliverDuel()}): `duel:{id}` is a new message (never one that only says the challenge
 * fell through), `duel:{id}:end` the EDIT of that message - never a new one; it waits while `duel:{id}` is on its way.
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

    /** `cups.meta` key: post the card again when its send in flight ends without posting it ({@see republishCard()}). */
    public const REPOST_KEY = 'card_repost';

    /** The edit of a duel waits this long for the message it edits (`duel:{id}` still `pending` / `sending`). */
    public const DUEL_EDIT_WAIT_SECONDS = 20;

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly TelegramMessageRateLimiter $limiter,
        private readonly CupCardRenderer $renderer,
        private readonly GroupBotRemoval $removal,
        private readonly ProductEventRecorder $events,
        private readonly CupDuelRenderer $duels,
    ) {}

    public static function summaryKey(int $day): string
    {
        return 'summary:'.$day;
    }

    /** The public challenge of a duel in the chat (docs/33 §6.1): a new message, rendered from the duel as it is when it goes. */
    public static function duelKey(int $duelId): string
    {
        return 'duel:'.$duelId;
    }

    /**
     * The outcome of a public challenge (docs/33 §6.1, §5.7): an EDIT of the message of {@see duelKey()} - never a new message; written
     * once, when the duel ends (declined, expired, canceled, void, done). Delivery of both kinds is D2 (docs/33 §11.9).
     */
    public static function duelEndKey(int $duelId): string
    {
        return 'duel:'.$duelId.':end';
    }

    /**
     * The acceptance of a public challenge (docs/33 §6.1): an EDIT of the message of {@see duelKey()} - «Дуэль идёт: A — B», the button
     * «Принять вызов» gone - never a new message; the outcome edits the same message later ({@see duelEndKey()}), and never before this
     * edit is through (see {@see deliverDuel()}).
     */
    public static function duelAcceptedKey(int $duelId): string
    {
        return 'duel:'.$duelId.':accepted';
    }

    /**
     * Rows of several messages in ONE statement (`INSERT … ON CONFLICT DO NOTHING RETURNING id`) and the jobs of the rows it made, after
     * the commit. A key that has its row already makes nothing. Returns how many rows were made.
     *
     * @param  list<array{cup_id: int, dedupe_key: string}>  $rows
     */
    public function enqueueMany(array $rows, CupMessageKind $kind, CarbonImmutable $now): int
    {
        if ($rows === []) {
            return 0;
        }
        $at = $now->format('Y-m-d H:i:s');
        $values = [];
        $bindings = [];
        foreach ($rows as $row) {
            $values[] = '(?, ?, ?, ?, 0, ?, ?)';
            array_push($bindings, $row['cup_id'], $row['dedupe_key'], $kind->value, CupMessageStatus::Pending->value, $at, $at);
        }

        $made = DB::select(
            'INSERT INTO cup_messages (cup_id, dedupe_key, kind, status, attempts, created_at, updated_at) VALUES '.implode(', ', $values)
            .' ON CONFLICT (cup_id, dedupe_key) DO NOTHING RETURNING id',
            $bindings,
        );
        foreach ($made as $row) {
            PostCupMessageJob::dispatch((int) $row->id)->afterCommit();
        }

        return count($made);
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
     * Queues the job of a `pending` row written by someone who does not know the bot (the finish writes `final`, docs/33 §5.6): one
     * read by the unique `(cup_id, dedupe_key)`, the job after the commit. Without it the row would wait for the sweep (2-3 minutes).
     */
    public function queue(int $cupId, string $dedupeKey): bool
    {
        $id = DB::table('cup_messages')->where('cup_id', $cupId)->where('dedupe_key', $dedupeKey)->where('status', CupMessageStatus::Pending->value)->value('id');
        if ($id === null) {
            return false;
        }
        PostCupMessageJob::dispatch((int) $id)->afterCommit();

        return true;
    }

    /**
     * The card is posted anew (docs/33 §4.5, §6.3): the bot came back to the chat, the chat became a supergroup, an administrator
     * deleted the card. Its row goes back to `pending` (or is made) and is queued. A row `pending` already has its job. A row being
     * SENT right now (its send may be going into the chat the bot has just left) is marked - `cups.meta.card_repost` - and the end of
     * that send ({@see settled()}) puts it back to `pending` unless it was posted; the second try of the reset closes the gap of a
     * send that ended between the two statements. The old `cups.card_*` stay until the new message is there.
     */
    public function republishCard(int $cupId, CarbonImmutable $now): bool
    {
        if ($this->resetCard($cupId, $now) || $this->enqueue($cupId, self::CARD_KEY, CupMessageKind::Card, $now)) {
            return true;
        }

        [$set, $bindings] = self::metaSet(self::REPOST_KEY, true);
        $marked = DB::update(
            "UPDATE cups SET meta = {$set} WHERE id = ? AND EXISTS (SELECT 1 FROM cup_messages m WHERE m.cup_id = cups.id AND m.dedupe_key = ? AND m.status = ?)",
            [...$bindings, $cupId, self::CARD_KEY, CupMessageStatus::Sending->value],
        ) > 0;
        if ($marked && $this->resetCard($cupId, $now)) {
            $this->takeRepost($cupId);
        }

        return $marked;
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
            $this->settled($message, false, $now);

            return 0;
        }

        if ($message->kind === CupMessageKind::Duel) {
            return $this->deliverDuel($message, $cup, $group, $now);
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
            $this->settled($message, false, $now);

            return 0;
        } catch (TelegramRateLimitedException $e) {
            $this->release($message, $now);

            throw $e;
        } catch (TelegramApiException $e) {
            if (! $e->isApiResponse()) {
                // A timeout / 5xx: it may be in the chat. Never again (docs/33 §6.4 step 5).
                $this->finish($message, CupMessageStatus::Unknown, $now, $this->error($e));
                $this->settled($message, false, $now);

                return 0;
            }
            if ($message->attempts >= self::MAX_ATTEMPTS) {
                $this->finish($message, CupMessageStatus::Failed, $now, $this->error($e));
                Log::warning('cup.message_failed', ['cup_message_id' => $message->id, 'kind' => $message->kind->value, 'code' => $e->getCode()]);
                $this->settled($message, false, $now);

                return 0;
            }
            $this->release($message, $now, $this->error($e));

            throw $e;
        }

        $this->sent($message, $cup, $group, $content, $photo !== null, $messageId, $threadId, $now);
        $this->settled($message, true, $now);

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

    /**
     * A card row that is done (sent, skipped, unknown, failed) back to `pending`, and its job; false when it is pending or sending.
     * Impure: the row changes between two calls (a send in flight ends).
     *
     * @phpstan-impure
     */
    private function resetCard(int $cupId, CarbonImmutable $now): bool
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
        if ($reset === 0) {
            return false;
        }
        $id = (int) DB::table('cup_messages')->where('cup_id', $cupId)->where('dedupe_key', self::CARD_KEY)->value('id');
        PostCupMessageJob::dispatch($id)->afterCommit();

        return true;
    }

    /**
     * A send of the card ended (posted or not, {@see republishCard()}): a repost asked for while it was in flight is taken - one
     * conditional UPDATE that clears the mark - and, unless this send posted the card, the row goes back to `pending`.
     */
    private function settled(CupMessage $message, bool $posted, CarbonImmutable $now): void
    {
        if ($message->kind !== CupMessageKind::Card) {
            return;
        }
        if ($this->takeRepost($message->cup_id) && ! $posted) {
            $this->resetCard($message->cup_id, $now);
        }
    }

    /** Clears the mark of a repost in ONE conditional statement; true when it was set (this call took it). */
    private function takeRepost(int $cupId): bool
    {
        [$set, $bindings] = self::metaSet(self::REPOST_KEY, false);
        $isSet = DB::getDriverName() === 'pgsql'
            ? "meta @> '{\"".self::REPOST_KEY."\": true}'::jsonb"
            : "json_type(meta) = 'object' AND json_extract(meta, '$.".self::REPOST_KEY."') = 1";

        return DB::update("UPDATE cups SET meta = {$set} WHERE id = ? AND {$isSet}", [...$bindings, $cupId]) > 0;
    }

    /**
     * The SQL that sets ONE key of `cups.meta` in the row as it is (PostgreSQL `jsonb ||`, SQLite `json_set`), and its bindings. A
     * `meta` that is not an object (an empty PHP array is stored as `[]`) becomes `{}` first - `jsonb_set` refuses a text path in an
     * array.
     *
     * @return array{0: string, 1: list<string>}
     */
    private static function metaSet(string $key, string|bool $value): array
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR);

        return DB::getDriverName() === 'pgsql'
            ? ["(CASE WHEN jsonb_typeof(meta) = 'object' THEN meta ELSE '{}'::jsonb END) || jsonb_build_object(?::text, ?::jsonb)", [$key, $json]]
            : ["json_set(CASE WHEN json_type(meta) = 'object' THEN meta ELSE '{}' END, ?, json(?))", ['$.'.$key, $json]];
    }

    /**
     * A row of a duel (docs/33 §6.1, §11.9 п. 10; D2), claimed and not paused - the text is {@see CupDuelRenderer::message()} of the
     * duel as it is NOW:
     *  - `duel:{id}` - a new message in the chat (the topic of the card) with the button «Принять вызов»; a challenge that has already
     *    fallen through (declined, expired, withdrawn, void) is `skipped`: the chat never gets a message that says nothing happened;
     *  - `duel:{id}:accepted` / `duel:{id}:end` - the EDIT of that message (`editMessageText`, no buttons), never a new one: the
     *    message `sent` → edited («message is not modified» = success: the message went out with the state already); still `pending` /
     *    `sending` → this row back to `pending`, the job comes back in {@see DUEL_EDIT_WAIT_SECONDS}; anything else (skipped, unknown,
     *    failed, no row) → `skipped` - nothing to edit.
     *  - the order of the two edits: `:accepted` of a duel that is not `accepted` any more is `skipped` (the outcome edits the message);
     *    `:end` waits while `:accepted` is `pending` / `sending` - an «идёт» that was read before the outcome never lands after it. The
     *    outcome row is made in the transaction that ends the duel, so an `:accepted` claimed after it reads the duel ended.
     * The refusals of Telegram are those of every row (docs/34 §4в): 403 → `skipped` and the group `disabled`, 429 → `pending` (the
     * client paused only this chat), a timeout → `unknown`.
     *
     * @throws TelegramRateLimitedException
     * @throws TelegramApiException
     */
    private function deliverDuel(CupMessage $message, Cup $cup, TelegramGroup $group, CarbonImmutable $now): int
    {
        $key = self::parseDuelKey($message->dedupe_key);
        /** @var CupDuel|null $duel */
        $duel = $key === null ? null : CupDuel::query()->find($key['duel_id']);
        if ($key === null || $duel === null || $duel->cup_id !== $cup->id) {
            $this->finish($message, CupMessageStatus::Skipped, $now, 'no_duel');

            return 0;
        }

        if ($key['edit'] === null) {
            if ($duel->status->fellThrough()) {
                $this->finish($message, CupMessageStatus::Skipped, $now, 'fell_through');

                return 0;
            }

            return $this->sendDuel($message, $cup, $group, $duel, $now);
        }
        if ($key['edit'] === 'accepted' && $duel->status !== CupDuelStatus::Accepted) {
            $this->finish($message, CupMessageStatus::Skipped, $now, 'superseded');

            return 0;
        }

        // The message and (for the outcome) the edit of the acceptance - one statement by the unique `(cup_id, dedupe_key)`.
        $rows = DB::table('cup_messages')->where('cup_id', $cup->id)
            ->whereIn('dedupe_key', [self::duelKey($duel->id), self::duelAcceptedKey($duel->id)])
            ->get(['dedupe_key', 'status', 'chat_id', 'message_id'])
            ->keyBy('dedupe_key');
        $base = $rows->get(self::duelKey($duel->id));
        $baseStatus = $base === null ? null : CupMessageStatus::tryFrom((string) $base->status);
        $accepted = $key['edit'] === 'end' ? $rows->get(self::duelAcceptedKey($duel->id)) : null;
        $acceptedStatus = $accepted === null ? null : CupMessageStatus::tryFrom((string) $accepted->status);
        if ($baseStatus === CupMessageStatus::Pending || $baseStatus === CupMessageStatus::Sending
            || $acceptedStatus === CupMessageStatus::Pending || $acceptedStatus === CupMessageStatus::Sending) {
            $this->release($message, $now);

            return self::DUEL_EDIT_WAIT_SECONDS;
        }
        if ($base === null || $baseStatus !== CupMessageStatus::Sent || $base->message_id === null || $base->chat_id === null) {
            $this->finish($message, CupMessageStatus::Skipped, $now, 'nothing_to_edit');

            return 0;
        }

        return $this->editDuel($message, $cup, $group, $duel, (int) $base->chat_id, (int) $base->message_id, $now);
    }

    /** @throws TelegramApiException */
    private function sendDuel(CupMessage $message, Cup $cup, TelegramGroup $group, CupDuel $duel, CarbonImmutable $now): int
    {
        $wait = $this->limiter->reserve($group->chat_id);
        if ($wait > 0) {
            $this->release($message, $now);

            return $wait;
        }

        $content = $this->duels->message($duel, $cup, $group);
        try {
            $messageId = $this->telegram->sendGroupMessage($group->chat_id, $content->textFor(false), null, $content->keyboard, $cup->card_thread_id);
        } catch (TelegramForbiddenException $e) {
            $this->finish($message, CupMessageStatus::Skipped, $now, 'forbidden:'.$e->reason);
            $this->removal->disable($group, $e, 'cup_duel');

            return 0;
        } catch (TelegramRateLimitedException $e) {
            $this->release($message, $now);

            throw $e;
        } catch (TelegramApiException $e) {
            return $this->refused($message, $e, $now);
        }

        $this->sent($message, $cup, $group, $content, false, $messageId, $cup->card_thread_id, $now);

        return 0;
    }

    /** @throws TelegramApiException */
    private function editDuel(CupMessage $message, Cup $cup, TelegramGroup $group, CupDuel $duel, int $chatId, int $messageId, CarbonImmutable $now): int
    {
        $wait = $this->limiter->reserve($chatId);
        if ($wait > 0) {
            $this->release($message, $now);

            return $wait;
        }

        $content = $this->duels->message($duel, $cup, $group);
        try {
            $this->telegram->editMessageText($chatId, $messageId, $content->textFor(false), null, $content->keyboard);
        } catch (TelegramChatMigratedException) {
            // The message is in a chat that is gone (the group became a supergroup): there is nothing left to edit.
            $this->finish($message, CupMessageStatus::Skipped, $now, 'migrated');

            return 0;
        } catch (TelegramRateLimitedException $e) {
            $this->release($message, $now);

            throw $e;
        } catch (TelegramApiException $e) {
            if (! $e->isMessageNotModified()) {
                return $this->refusedEdit($message, $group, $e, $now);
            }
        }

        $this->sent($message, $cup, $group, $content, false, $messageId, null, $now);

        return 0;
    }

    /**
     * A refused edit of a duel (not a 429, not «not modified»): 403 - the bot is out of the chat; the message deleted by an
     * administrator or too old to be edited - nothing to do; the rest as a refused send ({@see refused()}).
     *
     * @throws TelegramApiException
     */
    private function refusedEdit(CupMessage $message, TelegramGroup $group, TelegramApiException $e, CarbonImmutable $now): int
    {
        if ($e->isApiResponse() && $e->getCode() === 403) {
            $this->finish($message, CupMessageStatus::Skipped, $now, $this->error($e));
            $this->removal->disable($group, new TelegramForbiddenException(TelegramForbiddenException::REASON_FORBIDDEN), 'cup_duel');

            return 0;
        }
        $text = strtolower($e->getMessage());
        if ($e->isApiResponse() && (str_contains($text, 'message to edit not found') || str_contains($text, "message can't be edited"))) {
            $this->finish($message, CupMessageStatus::Skipped, $now, $this->error($e));

            return 0;
        }

        return $this->refused($message, $e, $now);
    }

    /**
     * A refusal of Telegram other than 403 / 429 for a row of a duel: a timeout → `unknown` (never again); a definite refusal → back to
     * `pending` and rethrown (the job retries), `failed` after {@see MAX_ATTEMPTS}.
     *
     * @throws TelegramApiException
     */
    private function refused(CupMessage $message, TelegramApiException $e, CarbonImmutable $now): int
    {
        if (! $e->isApiResponse()) {
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

    /**
     * `duel:{id}` / `duel:{id}:accepted` / `duel:{id}:end` → the duel and which edit the row is (null - the message itself); null for
     * any other key.
     *
     * @return array{duel_id: int, edit: 'accepted'|'end'|null}|null
     */
    private static function parseDuelKey(string $key): ?array
    {
        if (preg_match('/^duel:([1-9][0-9]{0,18})(?::(accepted|end))?$/', $key, $m) !== 1) {
            return null;
        }
        $edit = $m[2] ?? '';

        return ['duel_id' => (int) $m[1], 'edit' => $edit === 'accepted' ? 'accepted' : ($edit === 'end' ? 'end' : null)];
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
            // A duel speaks of itself whatever the cup became (a result may come after the finish): {@see deliverDuel()}.
            CupMessageKind::Duel => null,
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
                // `card_kind` is set as ONE key of the row as it is now, never the `meta` of the model read without a lock.
                [$meta, $metaBindings] = self::metaSet('card_kind', $photo ? CupCards::KIND_PHOTO : CupCards::KIND_TEXT);
                DB::update(
                    "UPDATE cups SET card_chat_id = ?, card_message_id = ?, card_text_hash = ?, card_rendered_at = ?, meta = {$meta} WHERE id = ?",
                    [$group->chat_id, $messageId, $content->hash(), $now->format('Y-m-d H:i:s'), ...$metaBindings, $cup->id],
                );
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
