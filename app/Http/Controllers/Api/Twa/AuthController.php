<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Twa\Exceptions\InvalidInitDataException;
use App\Domain\Twa\Services\InitDataValidator;
use App\Domain\Twa\Services\JwtService;
use App\Models\Student;
use App\Models\TelegramGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AuthController
{
    public function __construct(
        private readonly InitDataValidator $validator,
        private readonly JwtService $jwt,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'init_data' => ['required', 'string', 'max:4096'],
        ]);

        try {
            $parsed = $this->validator->validate($data['init_data']);
        } catch (InvalidInitDataException $e) {
            return $this->err(401, 'invalid_init_data', $e->reason);
        }

        $telegramUserId = (int) $parsed['user']['id'];

        /** @var Student|null $student */
        $student = Student::query()
            ->where('telegram_user_id', $telegramUserId)
            ->where('is_active', true)
            ->with('group')
            ->first();

        if ($student === null) {
            return $this->err(403, 'student_not_found', 'Student is not registered in any active group.');
        }

        /** @var TelegramGroup|null $group */
        $group = $student->group;
        if ($group === null || $group->status !== 'active') {
            return $this->err(403, 'group_inactive', 'Your group is not active.');
        }

        $student->last_seen_at = now();
        $student->save();

        $jwt = $this->jwt->issue($student->id, (int) $student->telegram_group_id);

        return response()->json([
            'token' => $jwt['token'],
            'expires_in' => $jwt['expires_in'],
            'student' => [
                'id' => $student->id,
                'first_name' => $student->first_name,
                'username' => $student->username,
                'telegram_group_id' => (int) $student->telegram_group_id,
            ],
        ]);
    }

    private function err(int $status, string $code, string $message): JsonResponse
    {
        return response()->json([
            'error' => ['code' => $code, 'message' => $message],
        ], $status);
    }
}
