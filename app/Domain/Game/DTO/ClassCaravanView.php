<?php

declare(strict_types=1);

namespace App\Domain\Game\DTO;

/**
 * A caravan of the learner's class as the Mini App sees it (docs/18 §12.8, docs/04 A.18): what `ClassCaravanResource` draws. The row of the
 * list has no `members`, `my` and `summary` (`null`): the screen of a caravan reads them with `GET /game/class/{id}`. The classmates are
 * shown by the first name only - no id, no username (the same as the leaderboard of an exam).
 *
 * @phpstan-type Boss array{unlocked: bool, defeated: bool, my_attempt: 'won'|'lost'|null}
 * @phpstan-type Member array{name: string, stars: int, runs: int, best_score: int, boss_won: bool, is_me: bool}
 * @phpstan-type My array{stars: int, runs: int, best_score: int, boss_won: bool}
 * @phpstan-type Summary array{target_reached: bool, boss_defeated: bool, finished_at: string|null}
 */
final readonly class ClassCaravanView
{
    /**
     * @param  array{slug: string, title: string}  $course
     * @param  'active'|'finished'  $status
     * @param  Boss  $boss
     * @param  list<Member>|null  $members
     * @param  My|null  $my
     * @param  Summary|null  $summary
     */
    public function __construct(
        public int $id,
        public string $groupTitle,
        public array $course,
        public string $status,
        public string $startsAt,
        public string $endsAt,
        public int $starsTotal,
        public int $targetStars,
        public int $runsTotal,
        public array $boss,
        public ?array $members = null,
        public ?array $my = null,
        public ?array $summary = null,
    ) {}
}
