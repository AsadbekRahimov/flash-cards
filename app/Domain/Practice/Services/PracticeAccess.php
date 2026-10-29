<?php

declare(strict_types=1);

namespace App\Domain\Practice\Services;

use App\Domain\Billing\DTO\Entitlement;
use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Practice\DTO\PracticeAccessState;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Exceptions\BattleException;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Closure;

/**
 * The gate of the practice (docs/16 §3.2): the flags of `config/practice.php`, the pilot, the tariff.
 *
 *   access = off                                  -> `off`           403 practice_unavailable
 *   access = pilot, the learner is not in it      -> `not_in_pilot`  403 practice_unavailable (the same code: no hint)
 *   plan = pro, the learner's plan is not Pro     -> `pro_required`  403 practice_pro_required
 *   otherwise                                     -> `available`
 *
 * The order is fixed: first the access (`assertCanPractice`), then the switch of the mode / of the battle
 * (`assertModeEnabled` / `assertBattleEnabled`) - and the switches only where something is CREATED (a session, an
 * invitation, the acceptance) and in the hub; answers, polling, audio and summaries check the access alone, so a mode
 * switched off in the middle of a game lets the game end. Nothing here touches the database beyond the cached
 * EntitlementResolver, and that only when the plan flag asks for it.
 */
final class PracticeAccess
{
    public function __construct(private readonly EntitlementResolver $entitlements) {}

    public function state(Learner $learner, ?CarbonImmutable $now = null): PracticeAccessState
    {
        return $this->resolve($learner, fn (): Entitlement => $this->entitlements->for($learner, $now));
    }

    /**
     * @throws PracticeException practice_unavailable (403) | practice_pro_required (403)
     */
    public function assertCanPractice(Learner $learner): void
    {
        $state = $this->state($learner);

        if ($state->isProRequired()) {
            throw PracticeException::proRequired();
        }
        if (! $state->isAvailable()) {
            throw PracticeException::unavailable();
        }
    }

    /**
     * @throws PracticeException practice_mode_disabled (409)
     */
    public function assertModeEnabled(PracticeMode $mode): void
    {
        if (! $mode->isEnabled()) {
            throw PracticeException::modeDisabled($mode);
        }
    }

    /**
     * @throws BattleException battle_disabled (409)
     */
    public function assertBattleEnabled(): void
    {
        if (! $this->battleEnabled()) {
            throw BattleException::disabled();
        }
    }

    /**
     * The block `practice` of `GET /me` (docs/16 §3.3), always there. `available`: the entries may be shown (a Free
     * learner at `plan = pro` too - with a Pro chip); `pro_required`: the tap opens the paywall `practice`; `modes`: the
     * modes that are switched on; `battle`: the battle switch.
     *
     * @return array{available: bool, pro_required: bool, modes: list<string>, battle: bool}
     */
    public function describe(Learner $learner, Entitlement $entitlement): array
    {
        $state = $this->resolve($learner, static fn (): Entitlement => $entitlement);

        return [
            'available' => $state->showsEntries(),
            'pro_required' => $state->isProRequired(),
            'modes' => $this->enabledModes(),
            'battle' => $this->battleEnabled(),
        ];
    }

    /**
     * The second player of a battle must be able to practice as well (the same `state()`).
     *
     * @throws BattleException battle_friend_no_access (409)
     */
    public function assertFriendCanPractice(Learner $friend): void
    {
        if (! $this->state($friend)->isAvailable()) {
            throw BattleException::friendNoAccess();
        }
    }

    /** @return list<string> the modes that are switched on, in the order of the hub */
    public function enabledModes(): array
    {
        $modes = [];
        foreach (PracticeMode::cases() as $mode) {
            if ($mode->isEnabled()) {
                $modes[] = $mode->value;
            }
        }

        return $modes;
    }

    public function battleEnabled(): bool
    {
        return (bool) config('practice.battle.enabled', false);
    }

    /** @param Closure(): Entitlement $entitlement read lazily: only the flag `plan = pro` needs it */
    private function resolve(Learner $learner, Closure $entitlement): PracticeAccessState
    {
        $access = strtolower(trim((string) config('practice.access', 'off')));

        // Anything but `all` / `pilot` is `off`: a typo in the .env must not open the practice to everybody.
        if ($access !== 'all' && $access !== 'pilot') {
            return PracticeAccessState::off();
        }
        if ($access === 'pilot' && $learner->practice_pilot_at === null) {
            return PracticeAccessState::notInPilot();
        }
        if (strtolower(trim((string) config('practice.plan', 'all'))) === 'pro' && $entitlement()->plan !== Plan::Pro) {
            return PracticeAccessState::proRequired();
        }

        return PracticeAccessState::available();
    }
}
