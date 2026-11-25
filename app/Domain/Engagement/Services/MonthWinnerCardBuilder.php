<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Services;

use App\Domain\Engagement\DTO\CardSpec;
use App\Domain\Engagement\Enums\ScoreCategory;
use App\Models\LeagueMonth;
use RuntimeException;

/**
 * The picture of a winner of the month (docs/36 §2б, A5): the photo {@see MonthWinnerSender} sends with the congratulation. The
 * layout of the achievements with the illustration `month` (the medal with the number of the place until the designer's file is
 * there), "N place" over "in the rating for <month> - P points" (the phrase of docs/36 §2б split in two: a title line holds
 * about 13 letters). A month of the word points v2 adds its table (Free / Pro) to the label.
 *
 * Never the learner's name: the picture is forwarded. Nothing else personal either, so one file serves every winner with the same
 * place, month, table and points (a tie on a prize place) in one language.
 */
final class MonthWinnerCardBuilder
{
    public function __construct(private readonly AchievementCardRenderer $cards) {}

    public function spec(LeagueMonth $month, int $place, int $points, ?ScoreCategory $category, string $locale): CardSpec
    {
        $label = $category === null
            ? __('leagues.month.card.label', [], $locale)
            : __('leagues.month.card.label_category', ['category' => __('leagues.month.card.category.'.$category->value, [], $locale)], $locale);

        return new CardSpec(
            key: 'month_place_'.$place.($category === null ? '' : '_'.$category->value),
            palette: 'month',
            badge: (string) $place,
            title: __('leagues.month.card.title', ['place' => $place], $locale),
            description: __('leagues.month.card.description', [
                'month' => self::monthName($month, $locale),
                'points' => trans_choice('leagues.month.points', $points, ['count' => $points], $locale),
            ], $locale),
            label: $label,
        );
    }

    /**
     * @return string absolute path of the JPEG on the cards disk
     *
     * @throws RuntimeException GD / the font is not available
     */
    public function render(LeagueMonth $month, int $place, int $points, ?ScoreCategory $category, string $locale): string
    {
        return $this->cards->renderSpec($this->spec($month, $place, $points, $category, $locale), $locale);
    }

    /** "октябрь 2026" / "2026-yil oktyabr": the month as the congratulation names it. */
    public static function monthName(LeagueMonth $month, string $locale): string
    {
        return trans('leagues.month.label', [
            'name' => trans('leagues.month.names.'.$month->starts_on->month, [], $locale),
            'year' => $month->starts_on->year,
        ], $locale);
    }
}
