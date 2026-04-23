<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telegram;

use App\Jobs\HandleTelegramUpdate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WebhookController
{
    public function __invoke(Request $request, string $secret): JsonResponse
    {
        $expectedUrl = (string) config('telegram.url_secret');
        $expectedHeader = (string) config('telegram.header_secret');

        if ($expectedUrl === '' || ! hash_equals($expectedUrl, $secret)) {
            abort(403);
        }

        $header = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');
        if ($expectedHeader === '' || ! hash_equals($expectedHeader, $header)) {
            abort(403);
        }

        /** @var array<string, mixed> $payload */
        $payload = $request->all();

        HandleTelegramUpdate::dispatch($payload)
            ->onQueue((string) config('telegram.queue', 'high'));

        return response()->json(['ok' => true]);
    }
}
