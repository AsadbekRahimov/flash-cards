<?php

declare(strict_types=1);

namespace App\Domain\Practice\DTO;

use Carbon\CarbonImmutable;

/**
 * A battle as one of its players sees it (docs/16 §7.4, `BattleResource`): everything the screen of any status needs, from
 * the invitation to the result. The dates are the server's; the client counts down to them with `server_now`.
 *
 *   role                   the viewer's side (challenger | opponent); `opponent` is the other player (the first name only)
 *   round                  the round the match is in (countdown: number and deadline, no phase); null outside countdown / playing
 *   result                 finished / closed only: `{outcome: won|lost|draw|none, end_reason, rounds: [{no, me: {verdict, points},
 *                          opponent: {...}}]}` - the rounds that were resolved (a round cut short by a surrender is not listed)
 *   rematch                the nearest battle with `rematch_of_id = id`: `{battle_id, status, by_me}` (`by_me`: the viewer offered it)
 *   opponentAbsentSince    the last presence of the opponent when he counts as absent (docs/16 §8.4), else null
 *   presenceGraceSeconds   how long an absence is forgiven; deadlineGraceMs - how late an answer may arrive (the client does not guess)
 *   audioPreload           dictation, room / countdown only: the URLs of the clips of the five rounds (the same `audio_url`
 *                          the rounds give), so "Ready" can wait for all of them (docs/16 §10.3); null otherwise
 */
final readonly class BattleView
{
    /**
     * @param  array{slug: string, title: string}  $course
     * @param  array{id: int, stage: int|null, number: int|null, title: string|null}|null  $lesson
     * @param  array{first_name: string|null}  $opponent
     * @param  array{me: bool, opponent: bool}  $ready
     * @param  array{me: int, opponent: int}  $scores
     * @param  array{outcome: string, end_reason: string|null, rounds: list<array{no: int, me: array{verdict: string, points: int}, opponent: array{verdict: string, points: int}}>}|null  $result
     * @param  array{battle_id: int, status: string, by_me: bool}|null  $rematch
     * @param  list<string>|null  $audioPreload
     */
    public function __construct(
        public int $id,
        public string $status,
        public ?string $endReason,
        public string $mode,
        public string $role,
        public array $course,
        public ?array $lesson,
        public array $opponent,
        public int $roundsTotal,
        public int $roundSeconds,
        public int $countdownSeconds,
        public int $revealSeconds,
        public int $presenceGraceSeconds,
        public int $deadlineGraceMs,
        public CarbonImmutable $inviteExpiresAt,
        public ?CarbonImmutable $roomExpiresAt,
        public ?CarbonImmutable $startsAt,
        public int $version,
        public CarbonImmutable $serverNow,
        public array $ready,
        public bool $opponentPresent,
        public ?CarbonImmutable $opponentAbsentSince,
        public ?RoundStateView $round,
        public array $scores,
        public ?array $result,
        public ?array $rematch,
        public ?int $friendshipId,
        public ?array $audioPreload,
    ) {}
}
