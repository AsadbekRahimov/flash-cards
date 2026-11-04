<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Practice\DTO\HubView;
use App\Domain\Practice\Services\PracticeLimits;
use App\Domain\Practice\Services\PracticeSessionService;
use Illuminate\Http\Request;

/**
 * GET /api/twa/practice/hub (docs/16 §7.1). `limits` - the Free limits of the day (docs/18 G1): `null` when none applies.
 *
 * @property HubView $resource
 *
 * @phpstan-import-type ActiveSummary from PracticeSessionService
 * @phpstan-import-type LimitsBlock from PracticeLimits
 */
final class PracticeHubResource extends TwaResource
{
    /**
     * @return array{
     *   course: array{slug: string, title: string},
     *   lesson: array{id: int, stage: int|null, number: int|null, title: string|null}|null,
     *   modes: list<array{mode: string, state: string, available: int, required: int, reason: string|null, estimate_seconds: int}>,
     *   active_session: ActiveSummary|null,
     *   battle: array{enabled: bool, active_battle_id: int|null, incoming_count: int},
     *   limits: LimitsBlock|null
     * }
     */
    public function toArray(Request $request): array
    {
        $hub = $this->resource;

        return [
            'course' => $hub->course,
            'lesson' => $hub->lesson,
            'modes' => $hub->modes,
            'active_session' => $hub->activeSession,
            'battle' => $hub->battle,
            'limits' => $hub->limits,
        ];
    }
}
