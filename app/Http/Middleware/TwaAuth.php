<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Twa\Services\JwtService;
use App\Http\Resources\ApiErrorResource;
use App\Models\Learner;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Authenticates TWA API requests by verifying the Bearer JWT (v2) and
 * loading the corresponding learner into the request.
 *
 * Sets:
 *   $request->attributes->get('twa') = ['learner_id' => int]
 *   $request->attributes->get('learner') = Learner
 *
 * Access to a concrete group session is NOT decided here (membership policies
 * on the session routes); a platform ban (`blocked_at`) is checked on every request.
 *
 * It runs before the `throttle:*` middleware (so they see the learner), which leaves refused
 * requests without a limit of their own: every refusal counts against the client IP
 * (`twa.unauthorized_per_minute`), and past it the answer is 429 `rate_limited`.
 */
final class TwaAuth
{
    public function __construct(private readonly JwtService $jwt) {}

    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) $request->header('Authorization', '');
        if (! str_starts_with($header, 'Bearer ')) {
            return $this->unauthorized($request, 'missing_token');
        }

        $token = substr($header, 7);
        if ($token === '') {
            return $this->unauthorized($request, 'missing_token');
        }

        try {
            $payload = $this->jwt->verify($token);
        } catch (Throwable) {
            return $this->unauthorized($request, 'invalid_token');
        }

        $learner = Learner::query()->find($payload['learner_id']);
        if ($learner === null) {
            return $this->unauthorized($request, 'learner_not_found');
        }

        if ($learner->blocked_at !== null) {
            return $this->unauthorized($request, 'learner_blocked');
        }

        $request->attributes->set('twa', ['learner_id' => (int) $learner->id]);
        $request->attributes->set('learner', $learner);

        return $next($request);
    }

    private function unauthorized(Request $request, string $code): JsonResponse
    {
        $key = 'twa-unauthorized|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, (int) config('twa.unauthorized_per_minute', 60))) {
            return ApiErrorResource::respond(429, 'rate_limited', 'Too many requests')
                ->header('Retry-After', (string) RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, 60);

        return ApiErrorResource::respond(401, $code, 'Unauthorized');
    }
}
