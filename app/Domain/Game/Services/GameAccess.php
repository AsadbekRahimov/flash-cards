<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Billing\Support\ProPromises;
use App\Domain\Game\DTO\GameAccessState;
use App\Domain\Game\Exceptions\GameException;
use App\Models\Learner;
use Illuminate\Support\Facades\DB;

/**
 * The gate of the game "Caravan" (docs/18 §12.7.4): the flag of `config/game.php` and the pilot. There is no tariff mode - the free
 * part of the game is the product decision, and the Free limits are the business of `GameLimits` (G2.8).
 *
 *   access = off                                  -> `off`           403 game_unavailable
 *   access = pilot, the learner is not in it      -> `not_in_pilot`  403 game_unavailable (the same code: no hint)
 *   otherwise                                     -> `available`
 *
 * The pilot is the one of the practice (`learners.practice_pilot_at`, docs/18 §12.15): one audience for both. The check itself
 * reads the config and the loaded learner only; the block of `/me` adds one query (the coins and the tickets) while the game is open.
 */
final class GameAccess
{
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
     * The block `game` of `GET /me` (docs/18 §12.8, docs/04 A.2), always there. `available`: the entries may be shown; `limits`: the
     * Free limits of the day - `null` until `GameLimits` (G2.8) and while the game is closed; `pro_promise`: what the "what Pro gives"
     * list may say about the game to this learner - `none` | `full` ({@see ProPromises::game()}); `coins` and `tickets`: the balances,
     * 0 while the game is closed or the learner has not played. The Mini App draws it and decides nothing.
     *
     * @return array{available: bool, limits: null, pro_promise: 'none'|'full', coins: int, tickets: int}
     */
    public function describe(Learner $learner): array
    {
        $available = $this->state($learner)->isAvailable();
        [$coins, $tickets] = $available ? $this->balances($learner) : [0, 0];

        return [
            'available' => $available,
            'limits' => null,
            'pro_promise' => ProPromises::game(open: $available)->value,
            'coins' => $coins,
            'tickets' => $tickets,
        ];
    }

    /**
     * The coins and the tickets of the learner in one query (both rows are created lazily: a missing one is 0).
     *
     * @return array{0: int, 1: int}
     */
    private function balances(Learner $learner): array
    {
        $row = DB::table('learners')
            ->leftJoin('game_wallets', 'game_wallets.learner_id', '=', 'learners.id')
            ->leftJoin('game_profiles', 'game_profiles.learner_id', '=', 'learners.id')
            ->where('learners.id', $learner->id)
            ->first(['game_wallets.balance as coins', 'game_profiles.tickets as tickets']);

        return [(int) ($row->coins ?? 0), (int) ($row->tickets ?? 0)];
    }
}
