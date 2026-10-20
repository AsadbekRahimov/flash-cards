<?php

declare(strict_types=1);

namespace App\Domain\Billing\Providers\Payme;

/**
 * Payme Merchant API authentication (docs/13 §4.2, §10): `Authorization: Basic base64("Paycom:" + KEY)`.
 * The key is compared with `hash_equals` against the production key and, when set, the test
 * cabinet's key (sandbox and production share one webhook URL and differ by key). A wrong or
 * missing header is answered by the controller with HTTP 200 + JSON-RPC `-32504`.
 */
final class PaymeAuth
{
    public const LOGIN = 'Paycom';

    public const MODE_LIVE = 'live';

    public const MODE_TEST = 'test';

    public function __construct(
        private readonly string $key,
        private readonly string $testKey = '',
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (string) config('billing.providers.payme.key', ''),
            (string) config('billing.providers.payme.test_key', ''),
        );
    }

    /**
     * Which key the header carries: `live`, `test`, or null when it matches neither (no key
     * configured = nothing ever matches). Both comparisons always run.
     */
    public function verify(?string $authorizationHeader): ?string
    {
        $password = self::basicPassword($authorizationHeader);
        if ($password === null) {
            return null;
        }

        $live = $this->key !== '' && hash_equals($this->key, $password);
        $test = $this->testKey !== '' && hash_equals($this->testKey, $password);

        return match (true) {
            $live => self::MODE_LIVE,
            $test => self::MODE_TEST,
            default => null,
        };
    }

    /** The password of a `Basic` header whose login is `Paycom`; null for anything else. */
    private static function basicPassword(?string $header): ?string
    {
        if ($header === null || preg_match('/^\s*Basic\s+([A-Za-z0-9+\/=]+)\s*$/i', $header, $m) !== 1) {
            return null;
        }

        $decoded = base64_decode($m[1], true);
        if ($decoded === false || ! str_contains($decoded, ':')) {
            return null;
        }

        [$login, $password] = explode(':', $decoded, 2);

        return hash_equals(self::LOGIN, $login) && $password !== '' ? $password : null;
    }
}
