<?php

declare(strict_types=1);

namespace App\Domain\Practice\DTO;

/**
 * What a learner may invite a friend to (docs/16 §7.3, `BattleOptionsResource`): the friend, the course / lesson and each
 * format with its state - `available` / `not_enough` (with the numbers and the reason of ModeAvailability for the material
 * of BOTH players' languages) / `disabled` - and the time of a round. `friendAccess`: `available` | `no_access` - the
 * friend must be able to practice as well (docs/16 §3.2).
 */
final readonly class BattleOptionsView
{
    /**
     * @param  array{first_name: string|null}  $friend
     * @param  array{slug: string, title: string}  $course
     * @param  array{id: int, stage: int|null, number: int|null, title: string|null}|null  $lesson
     * @param  list<array{mode: string, state: string, available: int, required: int, reason: string|null, round_seconds: int}>  $modes
     */
    public function __construct(
        public array $friend,
        public array $course,
        public ?array $lesson,
        public array $modes,
        public string $friendAccess,
    ) {}
}
