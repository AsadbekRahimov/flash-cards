<?php

declare(strict_types=1);

namespace App\Domain\Operations\Support;

use Stringable;
use Throwable;

/**
 * Single place that knows how secrets look inside free text and structured data:
 * Telegram bot token (`bot<id>:<token>` in API URLs), the configured literal
 * secrets (`TELEGRAM_BOT_TOKEN`, `TELEGRAM_URL_SECRET`, `TELEGRAM_HEADER_SECRET`,
 * `TWA_JWT_SECRET`, the payment provider keys `PAYME_KEY` / `PAYME_TEST_KEY` /
 * `CLICK_SECRET_KEY`), the `/telegram/webhook/{secret}` URL segment, Bearer and
 * Basic credentials, `secret=`/`init_data=`/`token=` query pairs, Click's
 * `sign_string` and password/2FA JSON fields (docs/13 §10).
 *
 * Used by every sink that can see exception messages: the Sentry hooks
 * ({@see SentryScrubber}), the Monolog processor ({@see RedactingLogProcessor},
 * i.e. laravel.log / stderr) and the Telegram client boundary (so the message
 * stored in `failed_jobs.exception` is clean as well).
 */
final class SecretRedactor
{
    public const REDACTED = '[redacted]';

    /** Key fragments (lower-case) whose values are always dropped. */
    private const SENSITIVE_KEY_FRAGMENTS = [
        'authorization',
        'cookie',
        'token',
        'secret',
        'password',
        'init_data',
        'initdata',
        'signature',
        'sign_string',
    ];

    /**
     * Exact keys (last dotted segment, lower-case) that are sensitive only inside
     * the request payload: 2FA / recovery codes posted by forms and Livewire
     * (`updates` keys look like `data.code`).
     */
    private const SENSITIVE_REQUEST_KEYS = ['code', 'recovery_code', 'one_time_password'];

    public static function redact(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        foreach (self::configuredSecrets() as $secret) {
            $value = str_replace($secret, self::REDACTED, $value);
        }

        $patterns = [
            // /telegram/webhook/{secret}
            '#(/telegram/webhook/)[^/?\#\s"\']+#' => '$1'.self::REDACTED,
            // https://api.telegram.org/bot<id>:<token>/method
            '#bot\d{6,}:[A-Za-z0-9_-]{20,}#' => 'bot'.self::REDACTED,
            // bare bot token
            '#(?<![A-Za-z0-9])\d{6,}:[A-Za-z0-9_-]{30,}#' => self::REDACTED,
            // Authorization: Bearer ...
            '#Bearer\s+[A-Za-z0-9._~+/=-]+#i' => 'Bearer '.self::REDACTED,
            // Authorization: Basic base64(login:key) — Payme's merchant key travels like this
            '#Basic\s+[A-Za-z0-9+/=]{8,}#' => 'Basic '.self::REDACTED,
            // ?secret=... / init_data=... / token=... / sign_string=... (Click)
            '#\b(secret|init_?data|token|hash|sign(?:ature|_string)?)=[^&\s"\']+#i' => '$1='.self::REDACTED,
            // JSON strings (Livewire `snapshot` / `updates`, possibly double-encoded):
            // "password":"…", "code":"…", "recovery_code":"…"
            '#(\\\\*"(?:[a-z_]*password[a-z_]*|code|recovery_code|one_time_password)\\\\*"\s*:\s*)(?:(\\\\*)".*?(?<!\\\\)\\2"|[^,}\]\s"\\\\]+)#i' => '$1$2"'.self::REDACTED.'$2"',
        ];

        return preg_replace(array_keys($patterns), array_values($patterns), $value) ?? self::REDACTED;
    }

    /**
     * Recursively redacts strings, drops values under sensitive keys and
     * flattens throwables / stringable objects into redacted strings.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function redactArray(array $data, bool $inRequest = false): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && (self::isSensitiveKey($key) || ($inRequest && self::isSensitiveRequestKey($key)))) {
                $result[$key] = self::REDACTED;

                continue;
            }

            $result[$key] = match (true) {
                is_array($value) => self::redactArray($value, $inRequest),
                is_string($value) => self::redact($value),
                $value instanceof Throwable => self::describeThrowable($value),
                $value instanceof Stringable => self::redact((string) $value),
                default => $value,
            };
        }

        return $result;
    }

    /**
     * Text form of a throwable (class, code, redacted message, location, trace
     * and the `previous` chain) that never contains secrets: messages go
     * through {@see redact()} and trace frames are printed without call
     * arguments (the raw ones carry URLs, chat texts, initData). The layout
     * follows Monolog's LineFormatter so laravel.log stays uniform.
     */
    public static function describeThrowable(Throwable $e): string
    {
        $parts = [];
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            $parts[] = sprintf(
                "[object] (%s(code: %s): %s at %s:%d)\n[stacktrace]\n%s",
                $current::class,
                (string) $current->getCode(),
                self::redact($current->getMessage()),
                $current->getFile(),
                $current->getLine(),
                self::traceWithoutArgs($current),
            );
        }

        return implode("\n\nNext ", $parts);
    }

    private static function traceWithoutArgs(Throwable $e): string
    {
        $lines = [];
        foreach ($e->getTrace() as $i => $frame) {
            $location = isset($frame['file']) ? $frame['file'].'('.($frame['line'] ?? 0).')' : '[internal function]';
            $lines[] = sprintf('#%d %s: %s%s%s()', $i, $location, $frame['class'] ?? '', $frame['type'] ?? '', $frame['function']);
        }
        $lines[] = sprintf('#%d {main}', count($lines));

        return implode("\n", $lines);
    }

    private static function isSensitiveKey(string $key): bool
    {
        $key = strtolower($key);

        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private static function isSensitiveRequestKey(string $key): bool
    {
        $segments = explode('.', strtolower($key));

        return in_array(end($segments), self::SENSITIVE_REQUEST_KEYS, true);
    }

    /**
     * Literal secret values currently configured for this deployment.
     *
     * @return list<string>
     */
    private static function configuredSecrets(): array
    {
        $values = [
            config('telegram.bot_token'),
            config('telegram.url_secret'),
            config('telegram.header_secret'),
            config('twa.jwt.secret'),
            config('billing.providers.payme.key'),
            config('billing.providers.payme.test_key'),
            config('billing.providers.click.secret_key'),
        ];

        $secrets = [];
        foreach ($values as $value) {
            if (is_string($value) && strlen($value) >= 8) {
                $secrets[] = $value;
            }
        }

        return $secrets;
    }
}
