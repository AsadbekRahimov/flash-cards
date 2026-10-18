<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Learning\DTO\StudentStats;
use App\Models\Student;
use Illuminate\Http\Request;

/**
 * GET /api/twa/me
 *
 * @property array{student: Student, stats: StudentStats} $resource
 */
final class MeResource extends TwaResource
{
    /**
     * @return array{
     *   student: array{id: int, first_name: string|null, username: string|null},
     *   group: array{id: int, title: string}|null,
     *   stats: array{words_learned: int, words_due_today: int}
     * }
     */
    public function toArray(Request $request): array
    {
        $student = $this->resource['student'];
        $stats = $this->resource['stats'];
        $group = $student->group;

        return [
            'student' => [
                'id' => $student->id,
                'first_name' => $student->first_name,
                'username' => $student->username,
            ],
            'group' => $group !== null ? [
                'id' => $group->id,
                'title' => $group->title,
            ] : null,
            'stats' => [
                'words_learned' => $stats->wordsLearned,
                'words_due_today' => $stats->wordsDueToday,
            ],
        ];
    }
}
