<?php

declare(strict_types=1);

namespace App\Listeners\Organizations;

use App\Domain\Telegram\Exceptions\TelegramApiException;
use Closure;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Log;

/**
 * A DM is a side effect of a join / leave / payment and must never break it. On a real queue a Telegram
 * outage is rethrown so the job is retried (`tries` / `backoff`). On the `sync` connection nothing can retry
 * and the exception would surface in the caller (`joinByInvite`, the payment path), so it is logged and dropped.
 */
trait RunsDmWithoutRetryOnSyncQueue
{
    /** @param Closure(): void $send */
    private function sendDm(Closure $send): void
    {
        try {
            $send();
        } catch (TelegramApiException $e) {
            if (! property_exists($this, 'job') || ! $this->job instanceof SyncJob) {
                throw $e;
            }

            Log::warning('telegram.organization_dm_failed', ['listener' => static::class, 'error' => $e->getMessage()]);
        }
    }
}
