<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use App\Domain\Telegram\Services\TelegramMessageRateLimiter;
use App\Jobs\HandleTelegramUpdate;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Job middleware of {@see HandleTelegramUpdate} (docs/34 §4в п. 5, docs/13 §9): one slot of the bot-wide per-second
 * counter per incoming update, taken without waiting ({@see TelegramMessageRateLimiter::consumeGlobal()}). A reply never waits
 * and is never refused here; background sends of the same second see that much less room. A cache that cannot be reached costs
 * the count, never the update.
 */
final class CountTelegramReplies
{
    public function __construct(private readonly ?TelegramMessageRateLimiter $limiter = null) {}

    public function handle(object $job, Closure $next): mixed
    {
        try {
            ($this->limiter ?? app(TelegramMessageRateLimiter::class))->consumeGlobal();
        } catch (Throwable $e) {
            Log::warning('telegram.reply_count_failed', ['exception' => $e::class]);
        }

        return $next($job);
    }
}
