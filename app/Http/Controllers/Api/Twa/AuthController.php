<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Twa\Exceptions\InvalidInitDataException;
use App\Domain\Twa\Exceptions\StudentContextException;
use App\Domain\Twa\Services\InitDataValidator;
use App\Domain\Twa\Services\JwtService;
use App\Domain\Twa\Services\StudentContextResolver;
use App\Http\Resources\ApiErrorResource;
use App\Http\Resources\Twa\AuthTokenResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AuthController
{
    public function __construct(
        private readonly InitDataValidator $validator,
        private readonly JwtService $jwt,
        private readonly StudentContextResolver $students,
        private readonly ProductEventRecorder $events,
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
            return ApiErrorResource::respond(401, 'invalid_init_data', $e->reason);
        }

        $telegramUserId = (int) $parsed['user']['id'];

        try {
            $student = $this->students->resolve(
                $telegramUserId,
                isset($data['context']['type']) ? (string) $data['context']['type'] : null,
                isset($data['context']['session_id']) ? (int) $data['context']['session_id'] : null,
            );
        } catch (StudentContextException $e) {
            return ApiErrorResource::respond($e->status, $e->errorCode, $e->getMessage());
        }

        $student->last_seen_at = now();
        $student->save();

        $this->events->record(
            ProductEventName::TwaAuthenticated,
            ['context' => isset($data['context']['type']) ? (string) $data['context']['type'] : 'none'],
            student: $student,
        );

        $jwt = $this->jwt->issue($student->id, (int) $student->telegram_group_id);

        return (new AuthTokenResource(['jwt' => $jwt, 'student' => $student]))->response();
    }
}
