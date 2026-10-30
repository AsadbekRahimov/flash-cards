<?php

declare(strict_types=1);

namespace App\Domain\Practice\DTO;

/**
 * The battles of a learner (docs/16 §7.3, `BattleListResource`): the match the learner is busy with (an invitation of
 * their own that has not expired, a room, a countdown, a game), the invitations to them that have not expired, and the last
 * `practice.battle.finished_list` matches that are over. Dates inside the rows are ISO 8601 strings (`IsoTime`).
 */
final readonly class BattleListView
{
    /**
     * @param  array{id: int, status: string, mode: string, role: string, course: array{slug: string, title: string}, lesson: array{id: int, stage: int|null, number: int|null, title: string|null}|null, opponent: array{first_name: string|null}}|null  $active
     * @param  list<array{id: int, mode: string, from: array{first_name: string|null}, course: array{slug: string, title: string}, lesson: array{id: int, stage: int|null, number: int|null, title: string|null}|null, invite_expires_at: string, rematch: bool}>  $incoming
     * @param  list<array{id: int, mode: string, opponent: array{first_name: string|null}, outcome: string, scores: array{me: int, opponent: int}, finished_at: string}>  $finished
     */
    public function __construct(
        public ?array $active,
        public array $incoming,
        public array $finished,
    ) {}
}
