<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Practice\DTO\HubView;
use Illuminate\Http\Request;

/**
 * GET /api/twa/practice/hub (docs/16 §7.1).
 *
 * @property HubView $resource
 */
final class PracticeHubResource extends TwaResource
{
    /**
     * @return array{
     *   course: array{slug: string, title: string},
     *   lesson: array{id: int, stage: int|null, number: int|null, title: string|null}|null,
     *   modes: list<array{mode: string, state: string, available: int, required: int, reason: string|null, estimate_seconds: int}>,
     *   active_session: array{id: int, mode: string, course: string, lesson_id: int|null, progress: array{done: int, total: int}}|null,
     *   battle: array{enabled: bool, active_battle_id: int|null, incoming_count: int}
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
        ];
    }
}
