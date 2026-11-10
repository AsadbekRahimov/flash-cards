<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telegram;

use App\Domain\Telegram\Services\PreCheckoutResponder;
use App\Jobs\HandleTelegramUpdate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class WebhookController
{
    private const DEDUP_SECONDS = 600;

    public function __invoke(Request $request, string $secret, PreCheckoutResponder $preCheckout): JsonResponse
    {
        $expectedUrl = (string) config('telegram.url_secret');
        $expectedHeader = (string) config('telegram.header_secret');

        if ($expectedUrl === '' || ! hash_equals($expectedUrl, $secret)) {
            abort(404);
        }

        $header = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');
        if ($expectedHeader === '' || ! hash_equals($expectedHeader, $header)) {
            abort(403);
        }

        /** @var array<string, mixed> $payload */
        $payload = $request->all();

        // A Stars pre-checkout must be answered within 10 s: synchronously, not through the queue
        // (docs/13 §4.1). Everything else keeps going to the `high` queue.
        if (PreCheckoutResponder::applies($payload)) {
            $preCheckout->respond($payload);

            return response()->json(['ok' => true]);
        }

        // Telegram re-sends an update it got no 200 for in time: the marker (default, non-evicting store: it is a marker, docs/19 §3)
        // lets only the first copy through. A failed dispatch drops it, so the retry is not mistaken for a duplicate.
        $updateId = $payload['update_id'] ?? null;
        $marker = is_int($updateId) ? "tg:update:{$updateId}" : null;
        if ($marker !== null && ! Cache::add($marker, 1, self::DEDUP_SECONDS)) {
            return response()->json(['ok' => true]);
        }

        try {
            HandleTelegramUpdate::dispatch($payload)
                ->onQueue((string) config('telegram.queue', 'high'));
        } catch (Throwable $e) {
            if ($marker !== null) {
                Cache::forget($marker);
            }

            throw $e;
        }

        return response()->json(['ok' => true]);
    }
}
