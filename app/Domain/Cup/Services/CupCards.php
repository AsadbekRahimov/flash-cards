<?php

declare(strict_types=1);

namespace App\Domain\Cup\Services;

use App\Domain\Cup\Enums\CupStatus;
use App\Domain\Cup\Support\CupAccess;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TelegramChatMigratedException;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Exceptions\TelegramRateLimitedException;
use App\Domain\Telegram\Services\GroupBotRemoval;
use App\Domain\Telegram\Services\TelegramMessageRateLimiter;
use App\Models\Cup;
use App\Models\TelegramGroup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The one card of a cup in its chat (docs/33 §6.1, §6.3): posted once through the outbox ({@see CupOutbox}, row `card`), then only
 * EDITED - the caption of the picture (`editMessageCaption`) or the text of a text card (`editMessageText`, in a topic of a forum or
 * when the picture could not be drawn; `cups.meta.card_kind`).
 *
 * An edit happens only when the text or the buttons changed (`cups.card_text_hash`) and, from `cups:refresh-cards`, at most once in
 * {@see THROTTLE_MINUTES} per card; the scheduler picks only the cards with news ({@see dueIds()}). "message is not modified" is a
 * success. A card that disappeared (an administrator deleted it) is posted anew at most once a day; a chat that became a supergroup
 * gets it anew at once (the old message ids are not in the new chat). A 403 means the bot is out: the group goes `disabled`.
 */
final class CupCards
{
    public const KIND_PHOTO = 'photo';

    public const KIND_TEXT = 'text';

    /** docs/33 §6.3: edits of a card no more often than this. */
    public const THROTTLE_MINUTES = 5;

    /** A deleted card is posted again no more often than this. */
    public const REPUBLISH_HOURS = 24;

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly TelegramMessageRateLimiter $limiter,
        private readonly CupCardRenderer $renderer,
        private readonly CupOutbox $outbox,
        private readonly GroupBotRemoval $removal,
        private readonly CupStandings $standings,
        private readonly CupProEmoji $emoji,
    ) {}

    /**
     * Brings the card in the chat to what the cup is now. Returns what happened: `edited`, `same` (nothing to change), `none` (no
     * card yet), `paused`, `deferred` (no slot of the chat now - the next run), `republish`, `failed`.
     */
    public function refresh(Cup $cup, CarbonImmutable $now): string
    {
        $marks = [];
        $outcome = $this->refreshOne($cup, $now, null, null, $marks);
        $this->writeRendered($marks, $now);

        return $outcome;
    }

    /**
     * `cups:refresh-cards`: the cards with news, at most `$batch`, each refreshed. Statements do NOT grow with the cards: the choice,
     * the cups, their groups, the tables of all of them (one statement for the ones not cached), the emoji of the tables with points
     * (one statement), the edits (Telegram, one by one), and one `UPDATE … CASE` of `card_rendered_at` / `card_text_hash` for all.
     * The marks are written after the edits (also when one throws): an edit repeated after a crash between the two is "message is
     * not modified" - the same.
     *
     * @return array{checked: int, edited: int, same: int, deferred: int, republished: int, failed: int}
     */
    public function refreshDue(CarbonImmutable $now, int $batch): array
    {
        $report = ['checked' => 0, 'edited' => 0, 'same' => 0, 'deferred' => 0, 'republished' => 0, 'failed' => 0];
        $ids = $this->dueIds($now, $batch);
        if ($ids === []) {
            return $report;
        }

        $cups = Cup::query()->with('telegramGroup')->whereIn('id', $ids)->orderBy('id')->get()->all();
        $withTable = array_values(array_filter($cups, static fn (Cup $cup): bool => $cup->card_message_id !== null
            && in_array($cup->status, [CupStatus::Scheduled, CupStatus::Running, CupStatus::Finished], true)));
        $tables = $this->standings->tables($withTable);
        $scored = [];
        foreach ($tables as $cupId => $table) {
            foreach ($table as $row) {
                if ($row['total'] > 0) {
                    $scored[] = $cupId;
                    break;
                }
            }
        }
        $emoji = $this->emoji->forCups($scored, $now);

        $marks = [];
        try {
            foreach ($cups as $cup) {
                $report['checked']++;
                try {
                    $outcome = $this->refreshOne($cup, $now, $tables[$cup->id] ?? null, $emoji[$cup->id] ?? [], $marks);
                } catch (\Throwable $e) {
                    report($e);
                    $outcome = 'failed';
                }
                match ($outcome) {
                    'edited' => $report['edited']++,
                    'same', 'paused', 'none' => $report['same']++,
                    'deferred' => $report['deferred']++,
                    'republish' => $report['republished']++,
                    default => $report['failed']++,
                };
            }
        } finally {
            $this->writeRendered($marks, $now);
        }

        return $report;
    }

    /**
     * The cards with news since their last render, older than {@see THROTTLE_MINUTES} - ONE statement:
     *
     *  - an open cup (scheduled / running, partial indexes `cups_scheduled_start_idx` / `cups_running_end_idx`) with a participant
     *    written since the render (`EXISTS` through the unique `(cup_id, learner_id)` of `cup_participants`), or a day that began since
     *    (the "Day N" of the card). A participant row is what the card shows, and every write of a day score rewrites it in the same
     *    transaction (`best_*` / `days_played` with `updated_at`, {@see CupScoreBook}) - so it stands for the `EXISTS` over
     *    `cup_day_scores` of docs/33 §11.4 at a fraction of the rows (one per participant, not one per participant and day);
     *  - any cup whose own row changed since the render - a transition, the settings, a cancel (`updated_at >= card_rendered_at`;
     *    the partial index `cups_card_stale_idx` holds only those rows).
     * `>=`: the timestamps have whole seconds - a write in the second of the render is looked at once more (the hash then says
     * "same").
     *
     * @return list<int>
     */
    public function dueIds(CarbonImmutable $now, int $batch): array
    {
        $before = $now->subMinutes(self::THROTTLE_MINUTES);
        $news = static fn (string $status) => DB::table('cups as c')
            ->select('c.id')
            ->where('c.status', $status)
            ->whereNotNull('c.card_message_id')
            ->where('c.card_rendered_at', '<=', $before)
            ->where(fn ($q) => $q
                ->whereExists(fn ($p) => $p->selectRaw('1')->from('cup_participants as p')->whereColumn('p.cup_id', 'c.id')->whereColumn('p.updated_at', '>=', 'c.card_rendered_at'))
                ->orWhereExists(fn ($d) => $d->selectRaw('1')->from('cup_days as d')->whereColumn('d.cup_id', 'c.id')->whereColumn('d.starts_at', '>', 'c.card_rendered_at')->where('d.starts_at', '<=', $now)));

        $changed = DB::table('cups as c')
            ->select('c.id')
            ->whereNotNull('c.card_message_id')
            ->whereColumn('c.card_rendered_at', '<=', 'c.updated_at')
            ->where('c.card_rendered_at', '<=', $before);

        return array_values(array_map('intval', DB::query()
            ->fromSub($news(CupStatus::Running->value)->union($news(CupStatus::Scheduled->value))->union($changed), 'due')
            ->orderBy('id')
            ->limit(max(1, $batch))
            ->pluck('id')
            ->all()));
    }

    /**
     * @param  list<array{participant_id: int, rank: int, display_name: string, total: int, round_points: int, days_played: int, left: bool}>|null  $table
     * @param  array<int, string>|null  $emoji
     * @param  array<int, string|null>  $marks  cup id => the new hash (null: only `card_rendered_at`) - written by the caller
     */
    private function refreshOne(Cup $cup, CarbonImmutable $now, ?array $table, ?array $emoji, array &$marks): string
    {
        $group = $cup->telegramGroup;
        if ($cup->card_message_id === null) {
            return 'none';
        }
        if (! CupAccess::allowsGroup($group) || CupAccess::pauseReason($group) !== null) {
            // Nobody can be told now; the bot coming back posts the card anew.
            $marks[$cup->id] = null;

            return 'paused';
        }
        if ($cup->card_chat_id !== null && $cup->card_chat_id !== $group->chat_id) {
            // The chat became a supergroup: the old message is in a chat that is gone (docs/33 §4.6).
            return $this->republish($cup, $now, $marks);
        }

        $content = $this->renderer->card($cup, $group, $now, $table, $emoji);
        $hash = $content->hash();
        if ($hash === $cup->card_text_hash) {
            $marks[$cup->id] = null;

            return 'same';
        }

        if ($this->limiter->reserve($group->chat_id) > 0) {
            return 'deferred';
        }

        $photo = ($cup->meta['card_kind'] ?? self::KIND_TEXT) === self::KIND_PHOTO;
        try {
            $photo
                ? $this->telegram->editMessageCaption($group->chat_id, $cup->card_message_id, $content->textFor(true), $content->keyboard)
                : $this->telegram->editMessageText($group->chat_id, $cup->card_message_id, $content->textFor(false), null, $content->keyboard);
        } catch (TelegramChatMigratedException) {
            // The client has moved the group already; the card is posted in the new chat.
            return $this->republish($cup, $now, $marks);
        } catch (TelegramRateLimitedException) {
            // The client paused this chat (only it, docs/34 §4в п. 2); the next run tries again.
            return 'deferred';
        } catch (TelegramApiException $e) {
            return $this->refused($cup, $group, $e, $hash, $now, $marks);
        }

        $marks[$cup->id] = $hash;

        return 'edited';
    }

    /**
     * The bot was added back to the chat (docs/33 §4.5): the open cup of the group gets its card posted anew - the old one may be
     * gone with the bot. No second cup, the dates stay.
     */
    public function onBotReturned(TelegramGroup $group, CarbonImmutable $now): bool
    {
        if (! CupAccess::allowsGroup($group)) {
            return false;
        }
        $cupId = DB::table('cups')->where('active_group_id', $group->id)->value('id');

        return $cupId !== null && $this->outbox->republishCard((int) $cupId, $now);
    }

    /** A refused edit (not a 429, not a migration). */
    /** @param array<int, string|null> $marks */
    private function refused(Cup $cup, TelegramGroup $group, TelegramApiException $e, string $hash, CarbonImmutable $now, array &$marks): string
    {
        if ($e->isMessageNotModified()) {
            $marks[$cup->id] = $hash;

            return 'same';
        }
        if (! $e->isApiResponse()) {
            // A timeout: an edit is safe to repeat, the next run does.
            Log::info('cup.card_edit_failed', ['cup_id' => $cup->id, 'code' => $e->getCode()]);

            return 'failed';
        }
        if ($e->getCode() === 403) {
            $this->removal->disable($group, new TelegramForbiddenException(TelegramForbiddenException::REASON_FORBIDDEN), 'cup_card');
            $marks[$cup->id] = null;

            return 'failed';
        }

        $text = strtolower($e->getMessage());
        if (str_contains($text, 'message to edit not found') || str_contains($text, "message can't be edited")) {
            // Deleted by an administrator: posted anew, at most once a day (until then the card stays gone).
            $sentAt = DB::table('cup_messages')->where('cup_id', $cup->id)->where('dedupe_key', CupOutbox::CARD_KEY)->value('sent_at');
            if ($sentAt === null || CarbonImmutable::parse((string) $sentAt)->lte($now->subHours(self::REPUBLISH_HOURS))) {
                return $this->republish($cup, $now, $marks);
            }
        }

        Log::warning('cup.card_edit_refused', ['cup_id' => $cup->id, 'code' => $e->getCode()]);
        $marks[$cup->id] = null;

        return 'failed';
    }

    /** @param array<int, string|null> $marks */
    private function republish(Cup $cup, CarbonImmutable $now, array &$marks): string
    {
        if ($cup->status->isOpen()) {
            $this->outbox->republishCard($cup->id, $now);
        }
        $marks[$cup->id] = null;

        return 'republish';
    }

    /**
     * The cards were looked at now (with the new hash where it was edited) - ONE statement for all: `card_rendered_at = now`, the
     * hash by `CASE id` (bindings only). Not `updated_at`: that one says when the cup changed.
     *
     * @param  array<int, string|null>  $marks
     */
    private function writeRendered(array $marks, CarbonImmutable $now): void
    {
        if ($marks === []) {
            return;
        }
        $hashes = array_filter($marks, static fn (?string $hash): bool => $hash !== null);
        DB::update(
            'UPDATE cups SET card_rendered_at = ?'.($hashes === [] ? '' : ', card_text_hash = '.self::caseOf($hashes)).' WHERE id IN ('.implode(', ', array_fill(0, count($marks), '?')).')',
            [$now->format('Y-m-d H:i:s'), ...self::caseBindings($hashes), ...array_keys($marks)],
        );
    }

    /** @param array<int, string> $hashes */
    private static function caseOf(array $hashes): string
    {
        return 'CASE id'.str_repeat(' WHEN ? THEN ?', count($hashes)).' ELSE card_text_hash END';
    }

    /**
     * @param  array<int, string>  $hashes
     * @return list<int|string>
     */
    private static function caseBindings(array $hashes): array
    {
        $bindings = [];
        foreach ($hashes as $id => $hash) {
            $bindings[] = $id;
            $bindings[] = $hash;
        }

        return $bindings;
    }
}
