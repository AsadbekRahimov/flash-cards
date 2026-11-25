<?php

declare(strict_types=1);

namespace App\Domain\Cup\Services;

use App\Domain\Cup\DTO\CupCardContent;
use App\Domain\Cup\Enums\CupCancelReason;
use App\Domain\Cup\Enums\CupStatus;
use App\Domain\Cup\Support\CupClock;
use App\Domain\Engagement\DTO\CardSpec;
use App\Domain\Engagement\Services\AchievementCardRenderer;
use App\Domain\Learners\Services\ProEmojiService;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Services\BotButtons;
use App\Domain\Telegram\Support\BotButton;
use App\Domain\Telegram\Support\CupCallback;
use App\Domain\Telegram\Support\TwaScreen;
use App\Models\Cup;
use App\Models\TelegramGroup;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The messages of a cup in its chat (docs/33 §6.1): the proposal of a draft, the card (the same message, edited as the cup goes on),
 * the summary of a day and the final - plain text in the language of the cup (`cups.locale`), the buttons through {@see BotButtons}.
 *
 * What a card shows of people is the table of {@see CupStandings} (cached, the same as the Mini App): the place, the name in the
 * tournament (`display_name`, docs/33 §2.3 - nothing else: no Telegram name, no id) and the points; next to a name the emoji of Pro
 * (docs/31 §5а) - an ordinary emoji of the platform's set, only while the learner's Pro is live: {@see CupProEmoji}, the rule of
 * {@see ProEmojiService::visibleForMany()} narrowed by the participants of the cup - ONE statement per cup, cached beside the table
 * (the same map the Mini App shows). The picture (renderer v2, {@see AchievementCardRenderer::renderSpec()}) carries no personal data
 * at all: the brand, "Кубок друзей", the level and the rules - one file per level, sizes and language, shared by every cup, so it is
 * drawn once.
 *
 * Statements of a card, a summary or a final: the table (1 cold, 0 warm) and the emoji (1 cold, 0 warm); none for the emoji of a table
 * without points, a proposal or a canceled card.
 */
final class CupCardRenderer
{
    /** Rows of the table shown in the card and the summary; the rest is "…and N more". */
    public const SHOWN_ROWS = 10;

    /** Places of the final that get a medal. */
    private const MEDALS = [1 => '🥇', 2 => '🥈', 3 => '🥉'];

    public function __construct(
        private readonly CupStandings $standings,
        private readonly CupProEmoji $emoji,
        private readonly BotButtons $buttons,
        private readonly AchievementCardRenderer $cards,
        private readonly LocaleResolver $locales,
    ) {}

    /** The card as the cup is now: the proposal of a draft, else the card of its status. */
    public function card(Cup $cup, TelegramGroup $group, CarbonImmutable $now): CupCardContent
    {
        if ($cup->status === CupStatus::Draft) {
            return $this->proposal($cup, $group);
        }

        $locale = $this->locale($cup);
        $lines = [$this->title($cup, $group, $locale)];

        if ($cup->status === CupStatus::Canceled) {
            $lines[] = __(match ($cup->cancel_reason) {
                CupCancelReason::DraftExpired => 'cup.card.expired',
                CupCancelReason::NoPlayers => 'cup.card.no_players',
                default => 'cup.card.canceled',
            }, [], $locale);

            return new CupCardContent(implode("\n", $lines), null);
        }

        $table = $this->standings->table($cup);
        if ($cup->status === CupStatus::Scheduled) {
            $lines[] = __('cup.card.scheduled', ['start' => $this->date($cup->starts_at, $cup), 'end' => $this->date($cup->ends_at, $cup)], $locale);
        } elseif ($cup->status === CupStatus::Running) {
            $day = $cup->starts_at === null ? 1 : max(1, min($cup->days, CupClock::dayNumber($cup->starts_at, $cup->timezone, $now)));
            $lines[] = __('cup.card.running', ['day' => $day, 'days' => $cup->days, 'end' => $this->date($cup->ends_at, $cup)], $locale);
        } else {
            $lines[] = __('cup.card.finished', [], $locale);
        }
        if ($group->bot_status === 'member' && $cup->status !== CupStatus::Finished) {
            // The bot was demoted (docs/33 §4.4): membership cannot be checked until it is an administrator again.
            $lines[] = __('cup.card.bot_not_admin', [], $locale);
        }

        $lines[] = '';
        if ($cup->status === CupStatus::Scheduled && $table === []) {
            $lines[] = __('cup.card.join_hint', [], $locale);
        } else {
            array_push($lines, ...$this->rows($cup, $table, $locale, false));
        }

        $footer = __('cup.card.participants', ['count' => count($table)], $locale);
        if ($cup->status !== CupStatus::Finished && $cup->summaries_enabled) {
            $footer .= ' · '.__('cup.card.summary_at', ['time' => $this->minute($cup->summary_minute)], $locale);
        }
        $lines[] = '';
        $lines[] = $footer;

        return new CupCardContent(implode("\n", $lines), $this->cardKeyboard($cup, $group, $locale));
    }

    /** The proposal of a draft (docs/33 §6.1): what the cup is, «Опубликовать» / «Настроить» / «Отменить». */
    public function proposal(Cup $cup, TelegramGroup $group): CupCardContent
    {
        $locale = $this->locale($cup);
        $text = __('cup.proposal.text', [
            'brand' => $this->brand(),
            'group' => $group->title,
            'level' => $cup->level,
            'days' => trans_choice('cup.days', $cup->days, ['count' => $cup->days], $locale),
            'tasks' => $cup->round_tasks,
            'best' => trans_choice('cup.best_days', $cup->counted_days, ['count' => $cup->counted_days], $locale),
        ], $locale);

        $keyboard = $this->buttons->keyboard($group->chat_id, [
            [$this->buttons->callback(__('buttons.cup_publish', [], $locale), CupCallback::publish($cup->id))],
            [
                $this->buttons->open(__('buttons.cup_setup', [], $locale), TwaScreen::CupSetup, ['code' => $cup->public_code], ['from' => 'cup_proposal']),
                $this->buttons->callback(__('buttons.cup_cancel', [], $locale), CupCallback::cancel($cup->id)),
            ],
        ]);

        return new CupCardContent($text, $keyboard);
    }

    /** The summary of day `$day` (docs/33 §6.1): the table as it is now, one button «Таблица». */
    public function summary(Cup $cup, TelegramGroup $group, int $day): CupCardContent
    {
        $locale = $this->locale($cup);
        $lines = [__('cup.summary.title', ['day' => $day, 'days' => $cup->days], $locale), ''];
        array_push($lines, ...$this->rows($cup, $this->standings->table($cup), $locale, false));
        $lines[] = '';
        $lines[] = __('cup.summary.footer', ['end' => $this->date($cup->ends_at, $cup)], $locale);

        $keyboard = $this->buttons->single($group->chat_id, $this->tableButton($cup, $locale, 'buttons.cup_table', 'cup_summary'));

        return new CupCardContent(implode("\n", $lines), $keyboard);
    }

    /** The final (docs/33 §6.1, §5.5): medals for the first three places, ties share the place (1, 1, 3) and the congratulation. */
    public function final(Cup $cup, TelegramGroup $group): CupCardContent
    {
        $locale = $this->locale($cup);
        $table = $this->standings->table($cup);
        $lines = [
            __('cup.final.title', [], $locale),
            __('cup.final.group', ['group' => $group->title, 'level' => $cup->level], $locale),
            '',
        ];
        array_push($lines, ...$this->rows($cup, $table, $locale, true));

        $winners = array_values(array_filter($table, static fn (array $row): bool => $row['rank'] === 1 && $row['total'] > 0));
        if ($winners !== []) {
            $lines[] = '';
            $lines[] = __('cup.final.winner', ['names' => implode(', ', array_column($winners, 'display_name'))], $locale);
        }

        $keyboard = $this->buttons->single($group->chat_id, $this->tableButton($cup, $locale, 'buttons.cup_final_table', 'cup_final'));

        return new CupCardContent(implode("\n", $lines), $keyboard);
    }

    /**
     * The picture of the card or of the final: the absolute path of the JPEG, or null when it cannot be drawn (no GD / font) - the
     * message then goes as text. No personal data: the same file for every cup of the level, language and size.
     */
    public function photo(Cup $cup, bool $final): ?string
    {
        $locale = $this->locale($cup);
        $level = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $cup->level));
        $spec = $final
            ? new CardSpec(
                key: 'cup_final_'.$level,
                palette: 'cup',
                badge: $cup->level,
                title: __('cup.photo.final_title', [], $locale),
                description: __('cup.photo.final_description', ['level' => $cup->level], $locale),
                label: __('cup.photo.label', [], $locale),
            )
            : new CardSpec(
                key: 'cup_card_'.$level.'_'.$cup->days.'_'.$cup->round_tasks.'_'.$cup->counted_days,
                palette: 'cup',
                badge: $cup->level,
                title: __('cup.photo.title', [], $locale),
                description: __('cup.photo.description', [
                    'days' => trans_choice('cup.days', $cup->days, ['count' => $cup->days], $locale),
                    'tasks' => $cup->round_tasks,
                    'best' => trans_choice('cup.best_days', $cup->counted_days, ['count' => $cup->counted_days], $locale),
                ], $locale),
                label: __('cup.photo.label', [], $locale),
            );

        try {
            return $this->cards->renderSpec($spec, $locale);
        } catch (Throwable $e) {
            Log::warning('cup.card_photo_failed', ['cup_id' => $cup->id, 'exception' => $e::class]);

            return null;
        }
    }

    /**
     * The lines of the table: `place. name [emoji] [(left)] — points`, at most {@see SHOWN_ROWS}; medals for the final.
     *
     * @param  list<array{participant_id: int, rank: int, display_name: string, total: int, round_points: int, days_played: int, left: bool}>  $table
     * @return list<string>
     */
    private function rows(Cup $cup, array $table, string $locale, bool $medals): array
    {
        $empty = array_filter($table, static fn (array $row): bool => $row['total'] > 0) === [];
        if ($empty) {
            return [__($medals ? 'cup.final.empty' : 'cup.card.empty', [], $locale)];
        }

        $shown = array_slice($table, 0, self::SHOWN_ROWS);
        $emoji = $this->emoji->forCup($cup);

        $lines = [];
        foreach ($shown as $row) {
            $name = $row['display_name'];
            if (isset($emoji[$row['participant_id']])) {
                $name .= ' '.$emoji[$row['participant_id']];
            }
            if ($row['left']) {
                $name .= ' '.__('cup.card.left', [], $locale);
            }
            $lines[] = $medals && isset(self::MEDALS[$row['rank']])
                ? self::MEDALS[$row['rank']]." {$name} — {$row['total']}"
                : __('cup.card.row', ['place' => $row['rank'], 'name' => $name, 'points' => $row['total']], $locale);
        }
        if (count($table) > count($shown)) {
            $lines[] = __('cup.card.more', ['count' => count($table) - count($shown)], $locale);
        }

        return $lines;
    }

    /**
     * The buttons of the card (docs/33 §6.1): «Участвовать» before the start / «Играть сегодня» while it runs, «Таблица», «Правила»;
     * one «Итоговая таблица» after the finish. All are links into the Mini App (`startapp` in a group).
     *
     * @return array{inline_keyboard: list<list<array<string, mixed>>>}
     */
    private function cardKeyboard(Cup $cup, TelegramGroup $group, string $locale): array
    {
        if ($cup->status === CupStatus::Finished) {
            return $this->buttons->single($group->chat_id, $this->tableButton($cup, $locale, 'buttons.cup_final_table', 'cup_card'));
        }

        $main = $cup->status === CupStatus::Running
            ? $this->buttons->open(__('buttons.cup_play', [], $locale), TwaScreen::CupPlay, ['code' => $cup->public_code], ['from' => 'cup_card'])
            : $this->buttons->open(__('buttons.cup_join', [], $locale), TwaScreen::CupInvite, ['code' => $cup->public_code], ['from' => 'cup_card']);

        return $this->buttons->keyboard($group->chat_id, [
            [$main],
            [
                $this->tableButton($cup, $locale, 'buttons.cup_table', 'cup_card'),
                $this->buttons->open(__('buttons.cup_rules', [], $locale), TwaScreen::CupInvite, ['code' => $cup->public_code], ['from' => 'cup_card', 'view' => 'rules']),
            ],
        ]);
    }

    private function tableButton(Cup $cup, string $locale, string $textKey, string $from): BotButton
    {
        return $this->buttons->open(__($textKey, [], $locale), TwaScreen::CupInvite, ['code' => $cup->public_code], ['from' => $from]);
    }

    private function title(Cup $cup, TelegramGroup $group, string $locale): string
    {
        return __('cup.card.title', ['brand' => $this->brand(), 'group' => $group->title, 'level' => $cup->level], $locale);
    }

    /** `dd.mm` in the timezone of the cup (the start, the end - local midnights). */
    private function date(?CarbonInterface $at, Cup $cup): string
    {
        return $at === null ? '' : CarbonImmutable::instance($at)->setTimezone($cup->timezone)->format('d.m');
    }

    /** `HH:MM` of a minute of the day. */
    private function minute(int $minute): string
    {
        $minute = max(0, min(1439, $minute));

        return sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
    }

    private function brand(): string
    {
        return trim((string) config('app.brand'));
    }

    private function locale(Cup $cup): string
    {
        return $this->locales->isSupported($cup->locale) ? $cup->locale : $this->locales->default();
    }
}
