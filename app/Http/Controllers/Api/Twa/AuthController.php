<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Twa\Exceptions\InvalidInitDataException;
use App\Domain\Twa\Services\InitDataValidator;
use App\Domain\Twa\Services\JwtService;
use App\Domain\Twa\Services\LearnerContextResolver;
use App\Http\Resources\ApiErrorResource;
use App\Http\Resources\Twa\AuthTokenResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

final class AuthController
{
    /** Verified `/auth` calls per Telegram user per minute (a legitimate client calls it once per token). */
    private const USER_ATTEMPTS_PER_MINUTE = 10;

    public function __construct(
        private readonly InitDataValidator $validator,
        private readonly JwtService $jwt,
        private readonly LearnerContextResolver $learners,
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

        // Per person, and only for a verified identity: a forged initData must not be able to burn
        // the budget of the Telegram id it claims (docs/12 §8).
        $userKey = 'twa-auth-user|'.(int) ($parsed['user']['id'] ?? 0);
        if (RateLimiter::tooManyAttempts($userKey, self::USER_ATTEMPTS_PER_MINUTE)) {
            return ApiErrorResource::respond(429, 'rate_limited', 'Too many requests')
                ->header('Retry-After', (string) RateLimiter::availableIn($userKey));
        }
        RateLimiter::hit($userKey, 60);

        [$learner, $contextGroup] = $this->learners->resolve(
            $parsed['user'],
            isset($data['context']['type']) ? (string) $data['context']['type'] : null,
            isset($data['context']['session_id']) ? (int) $data['context']['session_id'] : null,
        );

        if ($learner->blocked_at !== null) {
            return ApiErrorResource::respond(401, 'learner_blocked', 'Unauthorized');
        }

        $this->events->record(
            ProductEventName::TwaAuthenticated,
            ['context' => isset($data['context']['type']) ? (string) $data['context']['type'] : 'none'],
            learner: $learner,
            group: $contextGroup,
        );

        $jwt = $this->jwt->issue($learner->id);

        return (new AuthTokenResource(['jwt' => $jwt, 'learner' => $learner]))->response();
    }
}
