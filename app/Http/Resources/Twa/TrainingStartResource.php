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
            // null for daily_review: the scope spans every lesson of the learner.
            'lesson' => $lesson === null ? null : [
                'stage' => $lesson->stage?->number,
                'lesson' => $lesson->number,
                'title' => $lesson->title,
            ],
            'total_words' => $this->resource->totalWords,
            ...(new TrainingNextResource($this->resource->step))->toArray($request),
        ];
    }
}
