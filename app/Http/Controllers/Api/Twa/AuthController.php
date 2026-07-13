<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Twa\Exceptions\InvalidInitDataException;
use App\Domain\Twa\Exceptions\StudentContextException;
use App\Domain\Twa\Services\InitDataValidator;
use App\Domain\Twa\Services\JwtService;
use App\Domain\Twa\Services\StudentContextResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AuthController
{
    public function __construct(
        private readonly InitDataValidator $validator,
        private readonly JwtService $jwt,
        private readonly StudentContextResolver $students,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'init_data' => ['required', 'string', 'max:4096'],
            'context' => ['sometimes', 'array'],
            'context.type' => ['required_with:context', 'string', 'in:training,exam'],
            'context.session_id' => ['required_with:context', 'integer', 'min:1'],
        ]);

        try {
            $parsed = $this->validator->validate($data['init_data']);
        } catch (InvalidInitDataException $e) {
            return $this->err(401, 'invalid_init_data', $e->reason);
        }

        $telegramUserId = (int) $parsed['user']['id'];

        try {
            $student = $this->students->resolve(
                $telegramUserId,
                isset($data['context']['type']) ? (string) $data['context']['type'] : null,
                isset($data['context']['session_id']) ? (int) $data['context']['session_id'] : null,
            );
        } catch (StudentContextException $e) {
            return $this->err($e->status, $e->errorCode, $e->getMessage());
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
