<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Learning\DTO\SessionSummary;
use Illuminate\Http\Request;

/**
 * POST /api/twa/training/sessions/{id}/finish. Goal and streak join it with `TodayService` (task 1.3b).
 *
 * @property SessionSummary $resource
 */
final class SessionSummaryResource extends TwaResource
{
    /**
     * @return array{reviewed: int, correct: int, new_words: int}
     */
    public function toArray(Request $request): array
    {
        return [
            'reviewed' => $this->resource->reviewed,
            'correct' => $this->resource->correct,
            'new_words' => $this->resource->newWords,
        ];
    }
}
