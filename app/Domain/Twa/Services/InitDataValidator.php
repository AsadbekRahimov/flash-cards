<?php

declare(strict_types=1);

namespace App\Domain\Twa\Services;

use App\Domain\Twa\Exceptions\InvalidInitDataException;

/**
 * Validates Telegram Web App initData according to
 * https://core.telegram.org/bots/webapps#validating-data-received-via-the-web-app
 *
 * Algorithm:
 *   1. Parse init_data as a query string into key => value pairs.
 *   2. Remove the `hash` field; sort the remaining pairs by key.
 *   3. Build data_check_string as "key=value" lines joined with "\n".
 *   4. secret_key = HMAC_SHA256("WebAppData", bot_token)
 *   5. hex( HMAC_SHA256(secret_key, data_check_string) ) === hash
 *   6. auth_date must not be older than max_age seconds.
 */
final class InitDataValidator
{
    public function __construct(private readonly string $botToken) {}

    /**
     * @return array{
     *   user: array{id:int, first_name?:string, last_name?:string, username?:string, language_code?:string},
     *   auth_date: int,
     *   raw: array<string, string>
     * }
     *
     * @throws InvalidInitDataException
     */
    public function validate(string $initData, ?int $maxAgeSeconds = null): array
    {
        $maxAgeSeconds ??= (int) config('twa.init_data.max_age', 86400);

        // parse_str() mutates keys (e.g. "a.b" -> "a_b"). Use manual parsing instead.
        $data = $this->parsePairs($initData);

        if (! isset($data['hash'], $data['auth_date'])) {
            throw new InvalidInitDataException(InvalidInitDataException::REASON_MISSING_FIELDS);
        }

        $hash = $data['hash'];
        unset($data['hash']);

        ksort($data);
        $pairs = [];
        foreach ($data as $k => $v) {
            $pairs[] = "{$k}={$v}";
        }
        $dataCheckString = implode("\n", $pairs);

        $secretKey = hash_hmac('sha256', $this->botToken, 'WebAppData', true);
        $calc = hash_hmac('sha256', $dataCheckString, $secretKey);

        if (! hash_equals($calc, $hash)) {
            throw new InvalidInitDataException(InvalidInitDataException::REASON_INVALID_HASH);
        }

        $authDate = (int) $data['auth_date'];
        if ((time() - $authDate) > $maxAgeSeconds) {
            throw new InvalidInitDataException(InvalidInitDataException::REASON_EXPIRED);
        }

        $user = isset($data['user']) ? json_decode($data['user'], true) : null;
        if (! is_array($user) || empty($user['id'])) {
            throw new InvalidInitDataException(InvalidInitDataException::REASON_NO_USER);
        }
        $user['id'] = (int) $user['id'];

        return [
            'user' => $user,
            'auth_date' => $authDate,
            'raw' => $data,
        ];
    }

    /**
     * Parse "a=1&b=%7B%22x%22%3A1%7D" into ['a' => '1', 'b' => '{"x":1}'].
     * Unlike parse_str() this preserves dots/brackets in keys and does not create arrays.
     *
     * @return array<string, string>
     */
    private function parsePairs(string $query): array
    {
        $out = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            $eq = strpos($pair, '=');
            if ($eq === false) {
                $out[urldecode($pair)] = '';

                continue;
            }
            $k = urldecode(substr($pair, 0, $eq));
            $v = urldecode(substr($pair, $eq + 1));
            $out[$k] = $v;
        }

        return $out;
    }

    /**
     * Helper for tests / seeders: build a signed initData string.
     *
     * @param  array<string, string>  $fields  pre-encoded fields (user is JSON-encoded)
     */
    public static function sign(string $botToken, array $fields): string
    {
        ksort($fields);
        $lines = [];
        foreach ($fields as $k => $v) {
            $lines[] = "{$k}={$v}";
        }
        $dataCheckString = implode("\n", $lines);
        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $hash = hash_hmac('sha256', $dataCheckString, $secretKey);

        $fields['hash'] = $hash;

        $parts = [];
        foreach ($fields as $k => $v) {
            $parts[] = rawurlencode($k).'='.rawurlencode($v);
        }

        return implode('&', $parts);
    }
}
