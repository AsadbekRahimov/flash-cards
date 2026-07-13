<?php

declare(strict_types=1);

namespace App\Domain\Twa\Services;

use App\Domain\Twa\Exceptions\StudentContextException;
use App\Models\ExamSession;
use App\Models\Student;
use App\Models\TrainingSession;

final class StudentContextResolver
{
    public function resolve(int $telegramUserId, ?string $contextType, ?int $sessionId): Student
    {
        $groupId = $this->resolveGroupId($contextType, $sessionId);

        $query = Student::query()
            ->where('telegram_user_id', $telegramUserId)
            ->where('is_active', true)
            ->whereHas('group', fn ($groupQuery) => $groupQuery->where('status', 'active'))
            ->with('group')
            ->orderBy('id');

        if ($groupId !== null) {
            $query->where('telegram_group_id', $groupId);
        }

        $students = $query->limit(2)->get();

        if ($students->count() === 1) {
            /** @var Student $student */
            $student = $students->first();

            return $student;
        }

        if ($students->count() > 1) {
            throw new StudentContextException(
                409,
                'session_context_required',
                'This Telegram account belongs to multiple groups. Open a training or exam link.',
            );
        }

        $hasActiveMembership = Student::query()
            ->where('telegram_user_id', $telegramUserId)
            ->where('is_active', true)
            ->when($groupId !== null, fn ($studentQuery) => $studentQuery->where('telegram_group_id', $groupId))
            ->exists();

        if ($hasActiveMembership) {
            throw new StudentContextException(403, 'group_inactive', 'Your group is not active.');
        }

        throw new StudentContextException(
            403,
            'student_not_found',
            'Student is not registered in the group for this session.',
        );
    }

    private function resolveGroupId(?string $contextType, ?int $sessionId): ?int
    {
        if ($contextType === null || $sessionId === null) {
            return null;
        }

        $groupId = match ($contextType) {
            'training' => TrainingSession::query()->whereKey($sessionId)->value('telegram_group_id'),
            'exam' => ExamSession::query()->whereKey($sessionId)->value('telegram_group_id'),
            default => null,
        };

        if (! is_numeric($groupId)) {
            throw new StudentContextException(404, 'session_not_found', 'Session not found.');
        }

        return (int) $groupId;
    }
}
