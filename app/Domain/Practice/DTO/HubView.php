<?php

declare(strict_types=1);

namespace App\Domain\Practice\DTO;

use App\Domain\Practice\Services\PracticeLimits;
use App\Domain\Practice\Services\PracticeSessionService;

/**
 * The hub of a course / lesson (docs/16 §7.1): the four modes with their state, the unfinished session, the battle summary
 * and the Free limits of the day (`null` when none applies: Pro, or the practice is behind the paywall for this learner).
 * `state` of a mode: available | not_enough | disabled | pro_required.
 *
 * @phpstan-import-type ActiveSummary from PracticeSessionService
 * @phpstan-import-type LimitsBlock from PracticeLimits
 */
final readonly class HubView
{
    /**
     * @param  array{slug: string, title: string}  $course
     * @param  array{id: int, stage: int|null, number: int|null, title: string|null}|null  $lesson
     * @param  list<array{mode: string, state: string, available: int, required: int, reason: string|null, estimate_seconds: int}>  $modes
     * @param  ActiveSummary|null  $activeSession
     * @param  array{enabled: bool, active_battle_id: int|null, incoming_count: int}  $battle
     * @param  LimitsBlock|null  $limits
     */
    public function __construct(
        public array $course,
        public ?array $lesson,
        public array $modes,
        public ?array $activeSession,
        public array $battle,
        public ?array $limits,
    ) {}
}
