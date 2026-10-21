<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use App\Domain\Telegram\Services\TelegramMessageRateLimiter;
use Closure;
use Illuminate\Queue\Jobs\SyncJob;

/**
 * Job middleware of every background Telegram send (docs/13 §9, 2R.3): takes a slot of the bot-wide
 * per-second budget ({@see TelegramMessageRateLimiter::reserveGlobal()}) BEFORE the job (or queued
 * listener) runs. No slot -> the job is put back with a short random delay and nothing else happens:
 * claims, markers and rows are only written by the handler, so a released job loses nothing.
 *
 * `release()` consumes an attempt, so jobs using this middleware must not rely on `$tries` alone:
 * they set `$tries = 0` + `$maxExceptions` (real failures) or a `retryUntil`.
 *
 * The `sync` connection (tests, local) has nowhere to release to: such a job runs unthrottled.
 */
final class ThrottleTelegramOutbound
{
    public function __construct(private readonly ?TelegramMessageRateLimiter $limiter = null) {}

    public function handle(object $job, Closure $next): mixed
    {
        $queueJob = property_exists($job, 'job') ? $job->job : null;

        if ($queueJob === null || $queueJob instanceof SyncJob || ! method_exists($job, 'release')) {
            return $next($job);
        }

        $limiter = $this->limiter ?? app(TelegramMessageRateLimiter::class);

        if ($limiter->reserveGlobal()) {
            return $next($job);
        }

        $job->release($limiter->globalRetryDelay());

        return null;
    }
}
