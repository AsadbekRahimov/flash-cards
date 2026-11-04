<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-IP limit of `GET /health/ready` (60 / min) that fails open.
 *
 * The stock `throttle:` middleware keeps its counters in the rate limiter store (Redis); with Redis down it
 * throws before the controller and the probe answers 500 instead of its own 503 with `checks.redis = fail`.
 * A probe that cannot say what is broken is useless, so a limiter store error only skips the limit: the
 * report itself already says the store is gone.
 */
final class ReadinessThrottle
{
    private const MAX_ATTEMPTS = 60;

    private const DECAY_SECONDS = 60;

    public function __construct(private readonly RateLimiter $limiter) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = 'health.ready:'.sha1((string) $request->ip());

        try {
            if ($this->limiter->tooManyAttempts($key, self::MAX_ATTEMPTS)) {
                throw new ThrottleRequestsException('Too Many Attempts.', null, [
                    'Retry-After' => $this->limiter->availableIn($key),
                ]);
            }

            $this->limiter->hit($key, self::DECAY_SECONDS);
        } catch (ThrottleRequestsException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::warning('readiness.throttle_unavailable', ['exception' => $e::class, 'error' => $e->getMessage()]);
        }

        return $next($request);
    }
}
