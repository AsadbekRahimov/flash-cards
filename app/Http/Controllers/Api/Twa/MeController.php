<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Models\Student;
use App\Models\WordRepetition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MeController
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var Student $student */
        $student = $request->attributes->get('student');

        $student->loadMissing('group');

        $wordsLearned = WordRepetition::query()
            ->where('student_id', $student->id)
            ->where('repetitions', '>=', 1)
            ->count();

        $wordsDueToday = WordRepetition::query()
            ->where('student_id', $student->id)
            ->where('next_review_at', '<=', now())
            ->count();

        return response()->json([
            'student' => [
                'id'         => $student->id,
                'first_name' => $student->first_name,
                'username'   => $student->username,
            ],
            'group' => $student->group ? [
                'id'    => $student->group->id,
                'title' => $student->group->title,
            ] : null,
            'stats' => [
                'words_learned'   => $wordsLearned,
                'words_due_today' => $wordsDueToday,
            ],
        ]);
    }
}
