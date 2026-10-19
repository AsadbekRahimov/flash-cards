<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telegram;

use App\Domain\Telegram\Services\PreCheckoutResponder;
use App\Jobs\HandleTelegramUpdate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WebhookController
{
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

        HandleTelegramUpdate::dispatch($payload)
            ->onQueue((string) config('telegram.queue', 'high'));

        return response()->json(['ok' => true]);
    }
}
