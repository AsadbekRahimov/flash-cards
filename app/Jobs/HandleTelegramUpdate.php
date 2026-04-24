<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Domain\Telegram\Support\UpdateSanitizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class HandleTelegramUpdate implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    /** @param  array<string, mixed>  $update */
    public function __construct(public readonly array $update) {}

    public function handle(TelegramDispatcher $dispatcher): void
    {
        Log::channel('daily')->info('telegram.update', UpdateSanitizer::forLog($this->update));

        try {
            $dispatcher->dispatch($this->update);
        } catch (Throwable $e) {
            Log::channel('daily')->error('telegram.handler_failed', [
                'message' => $e->getMessage(),
                'update_id' => $this->update['update_id'] ?? null,
            ]);

            throw $e;
        }
    }
}
