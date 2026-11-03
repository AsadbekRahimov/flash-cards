<?php

declare(strict_types=1);

namespace App\Domain\Game\DTO;

/**
 * The map of a course for a learner (docs/18 §12.8, docs/04 A.18): what `GameMapResource` draws. The cities and the stops are
 * derived from the course, the rest is the learner's own. `class_caravan` and `limits` are `null` until G2.9 / G2.8 and are the
 * resource's business.
 *
 * @phpstan-type Stop array{lesson_id: int, number: int, title: string|null, unlocked: bool, stars: int, best_score: int, runs: int}
 * @phpstan-type Boss array{state: string, defeated_at: string|null, introduced: int, required: int, cleared_stops: int, required_stops: int}
 * @phpstan-type City array{stage_id: int, number: int, city: string, title: string|null, unlocked: bool, stops: list<Stop>, boss: Boss}
 * @phpstan-type Daily array{available: bool, done_today: bool, due_count: int, resets_at: string}
 * @phpstan-type Me array{stops_cleared: int, cities_cleared: int, coins: int, tickets: int}
 * @phpstan-type Friend array{friendship_id: int, name: string, city_number: int, stops_cleared: int, camel_asset: string|null}
 * @phpstan-type ActiveRun array{id: int, kind: string, progress: array{answered: int, total: int}}
 */
final readonly class GameMapView
{
    /**
     * @param  array{slug: string, title: string}  $course
     * @param  list<City>  $cities
     * @param  Daily  $daily
     * @param  Me  $me
     * @param  list<Friend>  $friends
     * @param  ActiveRun|null  $activeRun
     */
    public function __construct(
        public array $course,
        public array $cities,
        public array $daily,
        public array $me,
        public array $friends,
        public ?array $activeRun,
    ) {}
}
