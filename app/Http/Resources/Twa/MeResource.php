<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Billing\DTO\Entitlement;
use App\Domain\Learners\Services\LearnerSettingsService;
use App\Domain\Learning\DTO\LearnerStats;
use App\Domain\Twa\Services\MePayloadBuilder;
use App\Models\Learner;
use Illuminate\Http\Request;

/**
 * GET /api/twa/me
 *
 * @phpstan-import-type SettingsPayload from LearnerSettingsService
 * @phpstan-import-type PracticeBlock from MePayloadBuilder
 * @phpstan-import-type GameBlock from MePayloadBuilder
 *
 * @property array{learner: Learner, groups: list<array{id: int, title: string}>, stats: LearnerStats, entitlement: Entitlement, renewal: array{auto: bool, provider: string, ends_at: string|null, reminder_sent: bool}|null, lapsed: array{provider: string, ended_at: string}|null, paywall: array{mode: string, contact: string|null}, settings: SettingsPayload, practice: PracticeBlock, game: GameBlock} $resource
 */
final class MeResource extends TwaResource
{
    /**
     * @return array{
     *   learner: array{id: int, first_name: string|null, username: string|null, locale: string, onboarding_completed: bool},
     *   groups: list<array{id: int, title: string}>,
     *   stats: array{words_learned: int, words_due_today: int},
     *   entitlement: array{plan: string, source: string, until: string|null, new_words_per_day: int|null, renewal: array{auto: bool, provider: string, ends_at: string|null, reminder_sent: bool}|null, lapsed: array{provider: string, ended_at: string}|null},
     *   paywall: array{mode: string, contact: string|null},
     *   settings: SettingsPayload,
     *   practice: PracticeBlock,
     *   game: GameBlock
     * }
     */
    public function toArray(Request $request): array
    {
        $learner = $this->resource['learner'];
        $stats = $this->resource['stats'];
        $entitlement = $this->resource['entitlement'];

        return [
            'learner' => [
                'id' => $learner->id,
                'first_name' => $learner->first_name,
                'username' => $learner->username,
                'locale' => $learner->interface_locale,
                'onboarding_completed' => $learner->onboarding_state === 'done',
            ],
            'groups' => $this->resource['groups'],
            'stats' => [
                'words_learned' => $stats->wordsLearned,
                'words_due_today' => $stats->wordsDueToday,
            ],
            'entitlement' => [
                'plan' => $entitlement->plan->value,
                'source' => $entitlement->source,
                'until' => $entitlement->until?->toIso8601String(),
                'new_words_per_day' => $entitlement->newWordsPerDay,
                'renewal' => $this->resource['renewal'],
                'lapsed' => $this->resource['lapsed'],
            ],
            'paywall' => $this->resource['paywall'],
            'settings' => $this->resource['settings'],
            'practice' => $this->resource['practice'],
            'game' => $this->resource['game'],
        ];
    }
}
