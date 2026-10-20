<?php

declare(strict_types=1);

namespace App\Domain\Billing\Support;

use App\Domain\Operations\Support\SecretRedactor;

/**
 * Raw provider payloads (`payments.raw`, `provider_transactions.raw` / `history`,
 * `payment_orders.meta`) as the admin UI may show them (docs/13 §10). Values under key-like names
 * (signatures, keys, tokens, auth headers, the order token carried as `invoice_payload` /
 * `merchant_trans_id` / `order_id`) are replaced, every other string goes through
 * {@see SecretRedactor}. Storage is already redacted by the adapters; this is the second belt for
 * the places that display it.
 */
final class PayloadMasker
{
    /** Key fragments (lower-case) on top of {@see SecretRedactor}'s own list. */
    private const EXTRA_KEY_FRAGMENTS = ['sign', 'key', 'hash', 'auth', 'invoice_payload', 'merchant_trans_id', 'order_id', 'credential'];

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public static function mask(array $payload): array
    {
        $masked = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && self::isSensitive($key)) {
                $masked[$key] = SecretRedactor::REDACTED;

                continue;
            }

            $masked[$key] = is_array($value) ? self::mask($value) : $value;
        }

        // Keys the extra list did not catch still go through the shared redactor (sensitive keys, bot tokens, bearer strings).
        return SecretRedactor::redactArray($masked);
    }

    /** Pretty JSON of the masked payload for a `<pre>` block; `—` for an empty one. */
    public static function json(mixed $payload): string
    {
        if (! is_array($payload) || $payload === []) {
            return '—';
        }

        return (string) json_encode(self::mask($payload), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /** `AbCd…wXyZ`: enough to recognise an order token, not enough to use it. */
    public static function token(?string $token): string
    {
        if ($token === null || $token === '') {
            return '—';
        }

        return strlen($token) <= 8 ? str_repeat('•', strlen($token)) : substr($token, 0, 4).'…'.substr($token, -4);
    }

    private static function isSensitive(string $key): bool
    {
        $key = strtolower($key);

        foreach (self::EXTRA_KEY_FRAGMENTS as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
