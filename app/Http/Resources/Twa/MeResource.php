<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Learning\DTO\LearnerStats;
use App\Models\Learner;
use App\Models\TelegramGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * GET /api/twa/me
 *
 * @property array{learner: Learner, groups: Collection<int, TelegramGroup>, stats: LearnerStats} $resource
 */
final class MeResource extends TwaResource
{
    /**
     * @return array{
     *   learner: array{id: int, first_name: string|null, username: string|null, locale: string, onboarding_completed: bool},
     *   groups: list<array{id: int, title: string}>,
     *   stats: array{words_learned: int, words_due_today: int}
     * }
     */
    public function toArray(Request $request): array
    {
        $learner = $this->resource['learner'];
        $stats = $this->resource['stats'];

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
        ];
    }
}
