<?php

declare(strict_types=1);

namespace App\Domain\Practice\DTO;

/**
 * The hub of a course / lesson (docs/16 §7.1): the four modes with their state, the unfinished session and the battle summary.
 * `state` of a mode: available | not_enough | disabled | pro_required.
 */
final readonly class HubView
{
    /**
     * @param  array{slug: string, title: string}  $course
     * @param  array{id: int, stage: int|null, number: int|null, title: string|null}|null  $lesson
     * @param  list<array{mode: string, state: string, available: int, required: int, reason: string|null, estimate_seconds: int}>  $modes
     * @param  array{id: int, mode: string, course: string, lesson_id: int|null, progress: array{done: int, total: int}}|null  $activeSession
     * @param  array{enabled: bool, active_battle_id: int|null, incoming_count: int}  $battle
     */
    public function __construct(
        public array $course,
        public ?array $lesson,
        public array $modes,
        public ?array $activeSession,
        public array $battle,
    ) {}
}
