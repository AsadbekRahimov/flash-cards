<?php

declare(strict_types=1);

namespace App\Domain\Cup\Services;

use App\Domain\Cup\DTO\CupCardContent;
use App\Domain\Cup\Enums\CupDuelEndReason;
use App\Domain\Cup\Enums\CupDuelStatus;
use App\Domain\Cup\Support\CupScoring;
use App\Domain\Cup\Support\DisplayName;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Services\BotButtons;
use App\Domain\Telegram\Support\TelegramText;
use App\Domain\Telegram\Support\TwaScreen;
use App\Models\Cup;
use App\Models\CupDuel;
use App\Models\TelegramGroup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The public message of a duel of a cup in its chat (docs/33 §6.1, §5.7): rendered from the duel AS IT IS when it goes - the row
 * `duel:{id}` (a new message) and the row `duel:{id}:end` (the edit of that message with the outcome) both call {@see message()}, so a
 * challenge that ended before its message went out is posted with its outcome at once and the edit changes nothing («message is not
 * modified» = success).
 *
 *  - open: who challenges whom (or «любого участника»), rated or friendly, until when; one url button «Принять вызов» (a link into the
 *    Mini App, `TwaScreen::CupDuel` - the code and the duel are hints, the server decides);
 *  - accepted: «Дуэль идёт: A — B», no button;
 *  - done: «A 4 : 3 B», the winner or a draw; a rated result after the finish - «не вошёл в зачёт»; a friendly one - «без очков»;
 *  - declined / expired / canceled / void: ONE line «Вызов не состоялся», no button (docs/33 §5.7).
 *
 * Plain text in the language of the cup, names as the bot may print them ({@see DisplayName::forChat()}), the length within Telegram's
 * limit in UTF-16 units ({@see TelegramText}). One statement: the names of the two participants.
 */
final class CupDuelRenderer
{
    public function __construct(
        private readonly BotButtons $buttons,
        private readonly LocaleResolver $locales,
    ) {}

    public function message(CupDuel $duel, Cup $cup, TelegramGroup $group): CupCardContent
    {
        $locale = $this->locales->isSupported($cup->locale) ? $cup->locale : $this->locales->default();

        if ($duel->status->fellThrough()) {
            return new CupCardContent(__('cup.duel.fell_through', [], $locale), null);
        }

        $names = $this->names($duel);
        $challenger = $names[$duel->challenger_participant_id] ?? '…';
        $opponent = $duel->opponent_participant_id === null ? null : ($names[$duel->opponent_participant_id] ?? '…');

        if ($duel->status === CupDuelStatus::Open) {
            $lines = [
                $opponent === null
                    ? __('cup.duel.open_any', ['challenger' => $challenger], $locale)
                    : __('cup.duel.open_to', ['challenger' => $challenger, 'opponent' => $opponent], $locale),
                $duel->rated_offered ? __('cup.duel.rated', ['max' => min(CupScoring::DUEL_CAP, $cup->duel_tasks)], $locale) : __('cup.duel.friendly', [], $locale),
                __('cup.duel.until', ['time' => CarbonImmutable::instance($duel->expires_at)->setTimezone($cup->timezone)->format('H:i')], $locale),
            ];
            $keyboard = $this->buttons->single($group->chat_id, $this->buttons->open(
                __('buttons.cup_duel_accept', [], $locale),
                TwaScreen::CupDuel,
                ['code' => $cup->public_code, 'duel' => $duel->id],
                ['from' => 'cup_duel'],
            ));

            return $this->content($lines, $keyboard);
        }

        if ($duel->status === CupDuelStatus::Accepted) {
            return $this->content([
                __('cup.duel.playing', ['challenger' => $challenger, 'opponent' => (string) $opponent], $locale),
                $duel->rated === true ? __('cup.duel.rated', ['max' => min(CupScoring::DUEL_CAP, $cup->duel_tasks)], $locale) : __('cup.duel.friendly', [], $locale),
            ], null);
        }

        $lines = [__('cup.duel.result', [
            'challenger' => $challenger,
            'challenger_points' => (int) $duel->challenger_points,
            'opponent_points' => (int) $duel->opponent_points,
            'opponent' => (string) $opponent,
        ], $locale)];
        $winner = $duel->winner_participant_id === null ? null : ($names[$duel->winner_participant_id] ?? null);
        $lines[] = $winner === null ? __('cup.duel.draw', [], $locale) : __('cup.duel.winner', ['name' => $winner], $locale);
        if ($duel->rated !== true) {
            $lines[] = __('cup.duel.friendly_done', [], $locale);
        } elseif ($duel->end_reason === CupDuelEndReason::CupClosed) {
            $lines[] = __('cup.duel.not_counted', [], $locale);
        }

        return $this->content($lines, null);
    }

    /** @return array<int, string> participant id => the name as the bot prints it */
    private function names(CupDuel $duel): array
    {
        $ids = array_values(array_filter([$duel->challenger_participant_id, $duel->opponent_participant_id]));
        $names = [];
        foreach (DB::table('cup_participants')->whereIn('id', $ids)->get(['id', 'display_name']) as $row) {
            $names[(int) $row->id] = DisplayName::forChat((string) $row->display_name);
        }

        return $names;
    }

    /**
     * @param  list<string>  $lines
     * @param  array{inline_keyboard: list<list<array<string, mixed>>>}|null  $keyboard
     */
    private function content(array $lines, ?array $keyboard): CupCardContent
    {
        return new CupCardContent(TelegramText::cut(implode("\n", $lines), TelegramText::MESSAGE_LIMIT), $keyboard);
    }
}
