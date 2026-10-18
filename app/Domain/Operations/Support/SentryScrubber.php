<?php

declare(strict_types=1);

namespace App\Domain\Operations\Support;

use App\Domain\Telegram\Support\UpdateSanitizer;
use Sentry\Event;
use Sentry\EventHint;

/**
 * `before_send` / `before_send_transaction` hooks for Sentry (and GlitchTip).
 *
 * Strips credentials and personal data before an event leaves the server:
 * Authorization/Cookie headers, `init_data` bodies, Telegram bot token and
 * webhook secrets (also inside URLs, exception messages, breadcrumbs and
 * spans). Telegram update bodies reuse {@see UpdateSanitizer}.
 *
 * Referenced from config/sentry.php as array callables so `config:cache`
 * keeps working (closures cannot be cached).
 */
final class SentryScrubber
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
    ];

    public static function beforeSend(Event $event, ?EventHint $hint = null): Event
    {
        return self::scrub($event);
    }

    public static function beforeSendTransaction(Event $event, ?EventHint $hint = null): Event
    {
        return self::scrub($event);
    }

    public static function scrub(Event $event): Event
    {
        $request = $event->getRequest();
        if ($request !== []) {
            unset($request['cookies']);
            if (isset($request['data']) && is_array($request['data'])) {
                /** @var array<string, mixed> $data */
                $data = $request['data'];
                $request['data'] = UpdateSanitizer::forLog($data);
            }
            $event->setRequest(self::scrubArray($request));
        }

        $message = $event->getMessage();
        if ($message !== null) {
            $formatted = $event->getMessageFormatted();
            $event->setMessage(
                self::scrubString($message),
                self::scrubArray($event->getMessageParams()),
                $formatted === null ? null : self::scrubString($formatted),
            );
        }

        $transaction = $event->getTransaction();
        if ($transaction !== null) {
            $event->setTransaction(self::scrubString($transaction));
        }

        foreach ($event->getExceptions() as $exception) {
            $exception->setValue(self::scrubString($exception->getValue()));
        }

        $breadcrumbs = [];
        foreach ($event->getBreadcrumbs() as $breadcrumb) {
            $crumb = $breadcrumb;
            $crumbMessage = $breadcrumb->getMessage();
            if ($crumbMessage !== null) {
                $crumb = $crumb->withMessage(self::scrubString($crumbMessage));
            }
            foreach (self::scrubArray($breadcrumb->getMetadata()) as $name => $value) {
                $crumb = $crumb->withMetadata((string) $name, $value);
            }
            $breadcrumbs[] = $crumb;
        }
        $event->setBreadcrumb($breadcrumbs);

        $event->setExtra(self::scrubArray($event->getExtra()));
        $event->setTags(self::scrubArray($event->getTags()));
        foreach ($event->getContexts() as $name => $context) {
            $event->setContext((string) $name, self::scrubArray($context));
        }

        foreach ($event->getSpans() as $span) {
            $description = $span->getDescription();
            if ($description !== null) {
                $span->setDescription(self::scrubString($description));
            }
            $span->setData(self::scrubArray($span->getData()));
            $span->setTags(self::scrubArray($span->getTags()));
        }

        return $event;
    }

    public static function scrubString(string $value): string
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
            // ?secret=... / init_data=... / token=...
            '#\b(secret|init_?data|token|hash|signature)=[^&\s"\']+#i' => '$1='.self::REDACTED,
        ];

        return preg_replace(array_keys($patterns), array_values($patterns), $value) ?? self::REDACTED;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private static function scrubArray(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                $result[$key] = self::REDACTED;

                continue;
            }

            $result[$key] = match (true) {
                is_array($value) => self::scrubArray($value),
                is_string($value) => self::scrubString($value),
                default => $value,
            };
        }

        return $result;
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
