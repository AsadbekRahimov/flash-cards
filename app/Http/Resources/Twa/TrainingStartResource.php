<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Learning\DTO\TrainingStart;
use Illuminate\Http\Request;

/**
 * POST /api/twa/training/sessions/{id}/start
 *
 * @property TrainingStart $resource
 */
final class TrainingStartResource extends TwaResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $lesson = $this->resource->lesson;

        return [
            'session_id' => $this->resource->session->id,
            'mode' => $this->resource->session->mode,
            // When the session began, server time (ISO-8601, UTC): the summary screen tells "earned in this session" by it, not by the device clock.
            'started_at' => $this->resource->session->started_at->toImmutable()->utc()->toIso8601String(),
            // null for daily_review: the scope spans every lesson of the learner.
            'lesson' => $lesson === null ? null : [
                'stage' => $lesson->stage?->number,
                'lesson' => $lesson->number,
                'title' => $this->resource->lessonTitle,
            ],
            'total_words' => $this->resource->totalWords,
            ...(new TrainingNextResource($this->resource->step))->toArray($request),
        ];
    }
}
