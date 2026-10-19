<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Billing\DTO\Entitlement;
use App\Domain\Learners\Services\LearnerSettingsService;
use App\Domain\Learning\DTO\LearnerStats;
use App\Models\Learner;
use App\Models\TelegramGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * GET /api/twa/me
 *
 * @phpstan-import-type SettingsPayload from LearnerSettingsService
 *
 * @property array{learner: Learner, groups: Collection<int, TelegramGroup>, stats: LearnerStats, entitlement: Entitlement, paywall: array{mode: string, contact: string|null}, settings: SettingsPayload} $resource
 */
final class MeResource extends TwaResource
{
    /**
     * @return array{
     *   learner: array{id: int, first_name: string|null, username: string|null, locale: string, onboarding_completed: bool},
     *   groups: list<array{id: int, title: string}>,
     *   stats: array{words_learned: int, words_due_today: int},
     *   entitlement: array{plan: string, source: string, until: string|null, new_words_per_day: int|null},
     *   paywall: array{mode: string, contact: string|null},
     *   settings: SettingsPayload
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
            'groups' => array_values(
                $this->resource['groups']
                    ->map(fn (TelegramGroup $group): array => ['id' => $group->id, 'title' => $group->title])
                    ->all(),
            ),
            'stats' => [
                'words_learned' => $stats->wordsLearned,
                'words_due_today' => $stats->wordsDueToday,
            ],
            'entitlement' => [
                'plan' => $entitlement->plan->value,
                'source' => $entitlement->source,
                'until' => $entitlement->until?->toIso8601String(),
                'new_words_per_day' => $entitlement->newWordsPerDay,
            ],
            'paywall' => $this->resource['paywall'],
            'settings' => $this->resource['settings'],
        ];
    }
}
