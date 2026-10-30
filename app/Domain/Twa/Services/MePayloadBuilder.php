<?php

declare(strict_types=1);

namespace App\Domain\Twa\Services;

use App\Domain\Billing\DTO\Entitlement;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Billing\Services\LearnerBillingService;
use App\Domain\Learners\Services\GroupMembershipService;
use App\Domain\Learners\Services\LearnerSettingsService;
use App\Domain\Learning\DTO\LearnerStats;
use App\Domain\Learning\Services\LearnerStatsService;
use App\Domain\Practice\Services\PracticeAccess;
use App\Models\Learner;
use App\Models\TelegramGroup;
use Illuminate\Support\Collection;

/**
 * @phpstan-import-type SettingsPayload from LearnerSettingsService
 *
 * The data behind `App\Http\Resources\Twa\MeResource`: GET /me and every
 * endpoint that answers with the refreshed profile (e.g. POST /promo/redeem).
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
    ) {}

    /**
     * @return array{
     *   learner: Learner,
     *   groups: Collection<int, TelegramGroup>,
     *   stats: LearnerStats,
     *   entitlement: Entitlement,
     *   renewal: array{auto: bool, provider: string, ends_at: string|null, reminder_sent: bool}|null,
     *   lapsed: array{provider: string, ended_at: string}|null,
     *   paywall: array{mode: string, contact: string|null},
     *   settings: SettingsPayload,
     *   practice: array{available: bool, pro_required: bool, modes: list<string>, battle: bool}
     * }
     */
    public function build(Learner $learner): array
    {
        $entitlement = $this->entitlements->for($learner);

        return [
            'learner' => $learner,
            'groups' => $this->memberships->activeGroups($learner),
            'stats' => $this->stats->forLearner($learner),
            'entitlement' => $entitlement,
            'renewal' => $this->billing->renewal($learner),
            'lapsed' => $this->billing->lapsed($learner, $entitlement),
            'paywall' => $this->billing->paywall($learner),
            'settings' => $this->settings->describe($learner),
            'practice' => $this->practice->describe($learner, $entitlement),
        ];
    }
}
