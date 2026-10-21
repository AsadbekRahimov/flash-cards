<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Models\ExamSession;
use Illuminate\Http\Request;

/**
 * POST /api/twa/courses/{slug}/mock-exam
 *
 * Allow-list over the session: `config` (answer key, ordering secret) never leaves the server.
 *
 * @property ExamSession $resource
 */
final class MockExamSessionResource extends TwaResource
{
    /**
     * @return array{
     *   session_id: int,
     *   mode: string,
     *   course_id: int|null,
     *   started_at: string|null,
     *   ends_at: string|null,
     *   total_questions: int,
     *   seconds: int,
     *   resumed: bool
     * }
     */
    public function toArray(Request $request): array
    {
        $session = $this->resource;

        return [
            'session_id' => (int) $session->id,
            'mode' => $session->mode,
            'course_id' => $session->course_id,
            'started_at' => $session->started_at?->toIso8601String(),
            'ends_at' => $session->ends_at?->toIso8601String(),
            'total_questions' => (int) ($session->config['total_questions'] ?? 0),
            'seconds' => (int) ($session->config['seconds'] ?? 0),
            'resumed' => ! $session->wasRecentlyCreated,
        ];
    }
}
