<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Learning\DTO\ReviewOutcome;
use Illuminate\Http\Request;

/**
 * POST /api/twa/training/sessions/{id}/review
 *
 * @property ReviewOutcome $resource
 */
final class ReviewResultResource extends TwaResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $rep = $this->resource->repetition;

        return [
            'next_review_at' => $rep->next_review_at->toIso8601String(),
            'new_interval_days' => (int) $rep->interval_days,
            'easiness_factor' => (float) $rep->easiness_factor,
            ...(new TrainingNextResource($this->resource->next))->toArray($request),
        ];
    }
}
