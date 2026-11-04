<?php

declare(strict_types=1);

namespace App\Domain\Twa\Services;

use App\Domain\Billing\DTO\Entitlement;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Billing\Services\LearnerBillingService;
use App\Domain\Game\DTO\GameLimitState;
use App\Domain\Game\Services\GameAccess;
use App\Domain\Learners\Services\GroupMembershipService;
use App\Domain\Learners\Services\LearnerSettingsService;
use App\Domain\Learning\DTO\LearnerStats;
use App\Domain\Learning\Services\LearnerStatsService;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Practice\Services\PracticeAccess;
use App\Domain\Practice\Services\PracticeLimits;
use App\Models\Learner;
use App\Models\TelegramGroup;
use Carbon\CarbonImmutable;

/**
 * @phpstan-import-type SettingsPayload from LearnerSettingsService
 * @phpstan-import-type LimitsBlock from PracticeLimits
 * @phpstan-import-type LimitsBlock from GameLimitState as GameLimitsBlock
 * @phpstan-import-type ProfileSnapshot from MeProfileCache
 *
 * @phpstan-type PracticeBlock array{available: bool, pro_required: bool, modes: list<string>, battle: bool, pro_promise: 'none'|'solo'|'both', limits: LimitsBlock|null}
 * @phpstan-type GameBlock array{available: bool, limits: GameLimitsBlock|null, pro_promise: 'none'|'full', coins: int, tickets: int}
 *
 * The data behind `App\Http\Resources\Twa\MeResource`: GET /me and every
 * endpoint that answers with the refreshed profile (e.g. POST /promo/redeem).
 *
 * The plan, the groups, the renewal / lapsed blocks and the active course of the settings are one snapshot of the
 * evicting store ({@see MeProfileCache}, docs/19 K9); the stats are counted in one statement; the rest comes from the
 * learner's row and the config.
 */
final class MePayloadBuilder
{
    public function __construct(
        private readonly LearnerStatsService $stats,
        private readonly GroupMembershipService $memberships,
        private readonly EntitlementResolver $entitlements,
        private readonly LearnerSettingsService $settings,
        private readonly LearnerBillingService $billing,
        private readonly PracticeAccess $practice,
        private readonly PracticeLimits $limits,
        private readonly GameAccess $game,
        private readonly LocaleResolver $locales,
        private readonly MeProfileCache $profile,
    ) {}

    /**
     * @return array{
     *   learner: Learner,
     *   groups: list<array{id: int, title: string}>,
     *   stats: LearnerStats,
     *   entitlement: Entitlement,
     *   renewal: array{auto: bool, provider: string, ends_at: string|null, reminder_sent: bool}|null,
     *   lapsed: array{provider: string, ended_at: string}|null,
     *   paywall: array{mode: string, contact: string|null},
     *   settings: SettingsPayload,
     *   practice: PracticeBlock,
     *   game: GameBlock
     * }
     */
    public function build(Learner $learner): array
    {
        $now = CarbonImmutable::now();
        $locale = $this->locales->forLearner($learner);

        $snapshot = $this->profile->remember($learner, $locale, $now, fn (): array => $this->snapshot($learner, $locale, $now));
        $entitlement = Entitlement::fromArray($snapshot['entitlement']);

        return [
            'learner' => $learner,
            'groups' => $snapshot['groups'],
            'stats' => $this->stats->forLearner($learner, $now),
            'entitlement' => $entitlement,
            'renewal' => $snapshot['renewal'],
            'lapsed' => $snapshot['lapsed'],
            'paywall' => $this->billing->paywall($learner, $now),
            'settings' => $this->settings->describe($learner, activeCourse: $snapshot['active_course']),
            'practice' => [
                ...$this->practice->describe($learner, $entitlement),
                // The Free limits of the day (docs/18 G1): one statement, and only while the practice is open to the learner.
                'limits' => $this->limits->describe($learner, $this->practice->stateFor($learner, $entitlement), $entitlement, $now),
            ],
            'game' => $this->game->describe($learner, $entitlement, $now),
        ];
    }

    /**
     * The snapshot from the database: the plan (its own cache first), the groups, the own subscription twice (live, lapsed),
     * the course.
     *
     * @return ProfileSnapshot
     */
    private function snapshot(Learner $learner, string $locale, CarbonImmutable $now): array
    {
        $entitlement = $this->entitlements->for($learner, $now);

        return [
            'entitlement' => $entitlement->toArray(),
            'groups' => array_values(
                $this->memberships->activeGroups($learner)
                    ->map(static fn (TelegramGroup $group): array => ['id' => (int) $group->id, 'title' => (string) $group->title])
                    ->all(),
            ),
            'renewal' => $this->billing->renewal($learner, $now),
            'lapsed' => $this->billing->lapsed($learner, $entitlement, $now),
            'active_course' => $this->settings->activeCourseBlock($learner, $locale),
        ];
    }
}
