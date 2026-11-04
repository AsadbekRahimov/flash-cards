<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Billing\DTO\Entitlement;
use App\Domain\Billing\Support\ProPromises;
use App\Domain\Game\DTO\GameAccessState;
use App\Domain\Game\DTO\GameLimitState;
use App\Domain\Game\Exceptions\GameException;
use App\Models\Learner;
use Carbon\CarbonImmutable;

/**
 * The gate of the game "Caravan" (docs/18 §12.7.4): the flag of `config/game.php` and the pilot. There is no tariff mode - the free
 * part of the game is the product decision, and the Free limits are the business of `GameLimits`.
 *
 *   access = off                                  -> `off`           403 game_unavailable
 *   access = pilot, the learner is not in it      -> `not_in_pilot`  403 game_unavailable (the same code: no hint)
 *   otherwise                                     -> `available`
 *
 * The pilot is the one of the practice (`learners.practice_pilot_at`, docs/18 §12.15): one audience for both. The check itself
 * reads the config and the loaded learner only; the block of `/me` adds one query (the limits, the coins and the tickets) while the
 * game is open.
 *
 * @phpstan-import-type LimitsBlock from GameLimitState
 */
final class GameAccess
{
    public function __construct(private readonly GameLimits $limits) {}

    public function state(Learner $learner): GameAccessState
    {
        $access = strtolower(trim((string) config('game.access', 'off')));

        // Anything but `all` / `pilot` is `off`: a typo in the .env must not open the game to everybody.
        if ($access !== 'all' && $access !== 'pilot') {
            return GameAccessState::off();
        }
        if ($access === 'pilot' && $learner->practice_pilot_at === null) {
            return GameAccessState::notInPilot();
        }

        return GameAccessState::available();
    }

    /**
     * @throws GameException game_unavailable (403)
     */
    public function assertAvailable(Learner $learner): void
    {
        if (! $this->state($learner)->isAvailable()) {
            throw GameException::unavailable();
        }
    }

    /** `GAME_ACCESS` is `off` for everybody (anything but `all` / `pilot`): the sweeper has nothing to wait for. */
    public function isOff(): bool
    {
        $access = strtolower(trim((string) config('game.access', 'off')));

        return $access !== 'all' && $access !== 'pilot';
    }

    /**
     * The class caravan is for the whole group, so it opens only with `GAME_ACCESS=all`: in the pilot the game is seen by single learners,
     * and a caravan of a class with half of it outside the game would be a broken promise (decided in G2.13). `off` and `pilot`: no
     * `/start_caravan` and `/cancel_caravan` (silence, not in the menu and the help), no page in `/org`, no start, no cards.
     */
    public function classCaravanOpen(): bool
    {
        return strtolower(trim((string) config('game.access', 'off'))) === 'all';
    }

    /**
     * The block `game` of `GET /me` (docs/18 §12.8, docs/04 A.2), always there. `available`: the entries may be shown; `limits`: the
     * Free limits of the day and the week ({@see GameLimits}, docs/18 §12.7.2) - `null` while the game is closed; `pro_promise`: what
     * the "what Pro gives" list may say about the game to this learner - `none` | `full` ({@see ProPromises::game()}); `coins` and
     * `tickets`: the balances, 0 while the game is closed or the learner has not played. The Mini App draws it and decides nothing.
     * One statement while the game is open (the counts and the balances together), none while it is closed.
     *
     * @return array{available: bool, limits: LimitsBlock|null, pro_promise: 'none'|'full', coins: int, tickets: int}
     */
    public function describe(Learner $learner, ?Entitlement $entitlement = null, ?CarbonImmutable $now = null): array
    {
        $available = $this->state($learner)->isAvailable();
        $state = $available ? $this->limits->state($learner, $entitlement, $now) : null;

        return [
            'available' => $available,
            'limits' => $state?->toArray(),
            'pro_promise' => ProPromises::game(open: $available)->value,
            'coins' => $state === null ? 0 : $state->coins,
            'tickets' => $state === null ? 0 : $state->tickets,
        ];
    }
}
