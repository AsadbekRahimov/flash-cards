<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Twa\Services\JwtService;
use App\Http\Resources\ApiErrorResource;
use App\Models\Learner;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
 */
final class TwaAuth
{
    public function __construct(private readonly JwtService $jwt) {}

    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) $request->header('Authorization', '');
        if (! str_starts_with($header, 'Bearer ')) {
            return $this->unauthorized('missing_token');
        }

        $token = substr($header, 7);
        if ($token === '') {
            return $this->unauthorized('missing_token');
        }

        try {
            $payload = $this->jwt->verify($token);
        } catch (Throwable) {
            return $this->unauthorized('invalid_token');
        }

        $learner = Learner::query()->find($payload['learner_id']);
        if ($learner === null) {
            return $this->unauthorized('learner_not_found');
        }

        if ($learner->blocked_at !== null) {
            return $this->unauthorized('learner_blocked');
        }

        $request->attributes->set('twa', ['learner_id' => (int) $learner->id]);
        $request->attributes->set('learner', $learner);

        return $next($request);
    }

    private function unauthorized(string $code): JsonResponse
    {
        return ApiErrorResource::respond(401, $code, 'Unauthorized');
    }
}
