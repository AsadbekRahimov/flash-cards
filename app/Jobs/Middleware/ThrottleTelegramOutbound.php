<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use App\Domain\Telegram\Exceptions\TelegramOutboundBusyException;
use App\Domain\Telegram\Exceptions\TelegramRateLimitedException;
use App\Domain\Telegram\Services\TelegramMessageRateLimiter;
use Closure;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Log;

/**
 * Job middleware of every background Telegram send (docs/13 §9, 2R.3): takes a slot of the bot-wide
 * per-second budget ({@see TelegramMessageRateLimiter::reserveGlobal()}) BEFORE the job (or queued
 * listener) runs. No slot -> the job is put back with a short random delay and nothing else happens:
 * claims, markers and rows are only written by the handler, so a released job loses nothing.
 *
 * `release()` consumes an attempt, so jobs using this middleware must not rely on `$tries` alone:
 * they set `$tries = 0` + `$maxExceptions` (real failures) or a `retryUntil`.
 *
 * While the bot is paused after a 429 ({@see TelegramMessageRateLimiter::pause()}) there is no slot either, and the job comes
 * back after the rest of the pause.
 *
 * A 429 that reaches the handler ({@see TelegramRateLimitedException}, rethrown by the sender after it removed its marker,
 * docs/34 §4в) is caught here: the job is put back for `retry_after` + 0-3 s. Nothing is thrown, so the worker does not count it
 * against `maxExceptions` — a flood wave never sends a job to `failed_jobs`.
 *
 * A handler that sends several messages and takes a slot for each one after the first (the one of this middleware) throws
 * {@see TelegramOutboundBusyException} when none comes: the job is put back for its `retryAfter`, also without an exception.
 *
 * The `sync` connection (tests, local) has nowhere to release to: such a job runs unthrottled and a 429 is thrown to the caller.
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

        if (! $limiter->reserveGlobal()) {
            $job->release($limiter->globalRetryDelay());

            return null;
        }

        try {
            return $next($job);
        } catch (TelegramRateLimitedException $e) {
            Log::info('telegram.outbound_job_rate_limited', ['job' => $job::class, 'retry_after' => $e->retryAfter]);

            $job->release($e->retryAfter + random_int(0, 3));

            return null;
        } catch (TelegramOutboundBusyException $e) {
            // A handler that sends several messages found no slot for a later one: the same as no slot before the job.
            $job->release($e->retryAfter);

            return null;
        }
    }
}
