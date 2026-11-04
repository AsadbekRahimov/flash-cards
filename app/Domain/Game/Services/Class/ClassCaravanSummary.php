<?php

declare(strict_types=1);

namespace App\Domain\Game\Services\Class;

use App\Models\ClassCaravan;

/**
 * The text of the weekly summary of a class caravan for the group chat (docs/18 §12.11): the stars against the target, the runs, the
 * boss of the class, the top contributors. HTML parse mode; every name is escaped. The language is the group's (`LocaleResolver::forGroup`).
 */
final class ClassCaravanSummary
{
    public const TOP = 5;

    /**
     * @param  list<array{learner_id: int, name: string, stars: int, runs: int, best_score: int, boss_won: bool}>  $standings  best first
     */
    public function text(ClassCaravan $caravan, array $standings, string $locale): string
    {
        $lines = [
            __('game.class.summary.title', ['group' => $this->escape($caravan->group->title ?? '')], $locale),
            '',
            __('game.class.summary.stars', ['stars' => $caravan->stars_total, 'target' => $caravan->target_stars], $locale),
            __('game.class.summary.runs', ['runs' => $caravan->runs_total], $locale),
            __(match (true) {
                $caravan->boss_defeated_at !== null => 'game.class.summary.boss_defeated',
                $caravan->boss_unlocked_at !== null => 'game.class.summary.boss_survived',
                default => 'game.class.summary.boss_locked',
            }, [], $locale),
        ];

        if ($standings === []) {
            $lines[] = '';
            $lines[] = __('game.class.summary.nobody', [], $locale);

            return implode("\n", $lines);
        }

        $lines[] = '';
        $lines[] = __('game.class.summary.top', [], $locale);
        foreach (array_slice($standings, 0, self::TOP) as $i => $row) {
            $place = match ($i) {
                0 => '🥇',
                1 => '🥈',
                2 => '🥉',
                default => ($i + 1).'.',
            };
            $lines[] = __('game.class.summary.row', [
                'place' => $place,
                'name' => $this->escape($row['name']),
                'stars' => $row['stars'],
                'runs' => $row['runs'],
            ], $locale);
        }

        return implode("\n", $lines);
    }

    private function escape(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
