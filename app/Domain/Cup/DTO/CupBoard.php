<?php

declare(strict_types=1);

namespace App\Domain\Cup\DTO;

use App\Domain\Cup\Services\CupDuelReader;
use Carbon\CarbonImmutable;

/**
 * The table of a cup as the Mini App shows it (`GET /cups/{id}`, docs/33 §7.2, K7): the cup, its standings with the emoji of Pro, and
 * for a participant his day and his days. `canManage` is a HINT for the settings link (the learner proposed or published the cup); every
 * action of an administrator asks Telegram again.
 *
 * @phpstan-import-type Availability from CupDuelReader
 */
final readonly class CupBoard
{
    /**
     * @param  list<array{participant_id: int, rank: int, display_name: string, pro_emoji: string|null, total: int, round_points: int, days_played: int, left: bool, me: bool}>  $standings
     * @param  array{state: 'not_started'|'active'|'done'|'not_available', day: int|null, attempt_id: int|null, points: int|null, expires_at: string|null}|null  $today
     * @param  list<array{day: int, points: int, round_points: int, duel_points: int, played: bool, counted: bool, available: bool}>|null  $days
     * @param  Availability|null  $duel  may the participant challenge today (null: the duels are off, or not a participant)
     */
    public function __construct(
        public CupContext $context,
        public array $standings,
        public ?array $today,
        public ?array $days,
        public bool $canManage,
        public CarbonImmutable $now,
        public ?array $duel = null,
    ) {}
}
