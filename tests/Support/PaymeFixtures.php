<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\PaymentOrder;
use Carbon\CarbonImmutable;

/**
 * Payme JSON-RPC request bodies for tests, from `tests/Fixtures/billing/payme/<Method>.json`
 * (docs/13 §4.2). `for($order)` rewrites the account / amount placeholders for a concrete order.
 */
final class PaymeFixtures
{
    public const TRANSACTION_ID = '68f4d8a0b1c2d3e4f5a6b7c8';

    /**
     * @param  array<string, mixed>  $params  overrides merged into `params` (recursive)
     * @return array<string, mixed>
     */
    public static function rpc(string $method, array $params = [], int|string|null $id = null): array
    {
        $path = base_path("tests/Fixtures/billing/payme/{$method}.json");
        /** @var array<string, mixed> $body */
        $body = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $base */
        $base = $body['params'] ?? [];
        $body['params'] = array_replace_recursive($base, $params);
        if ($id !== null) {
            $body['id'] = $id;
        }

        return $body;
    }

    /**
     * The request of `$method` for `$order`: account = order token, amount = order amount, a Payme
     * transaction `time` one minute ago (ms), `id` as given.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public static function for(string $method, PaymentOrder $order, array $params = [], string $transactionId = self::TRANSACTION_ID): array
    {
        $defaults = [
            'id' => $transactionId,
            'time' => CarbonImmutable::now()->subMinute()->getTimestampMs(),
            'amount' => $order->amount,
            'account' => ['order_id' => $order->token],
        ];

        /** @var array<string, mixed> $base */
        $base = self::rpc($method)['params'];
        $relevant = array_intersect_key($defaults, $base);

        return self::rpc($method, array_replace_recursive($relevant, $params));
    }

    public static function basic(string $key, string $login = 'Paycom'): string
    {
        return 'Basic '.base64_encode($login.':'.$key);
    }
}
