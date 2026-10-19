<?php

declare(strict_types=1);

namespace App\Domain\Operations\Support;

use App\Domain\Telegram\Support\UpdateSanitizer;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\Logs\Log;
use Sentry\Stacktrace;

/**
 * `before_send` / `before_send_transaction` / `before_send_log` hooks for Sentry (and GlitchTip).
 *
 * Strips credentials and personal data before an event leaves the server:
 * Authorization/Cookie headers, `init_data` bodies, Telegram bot token and
 * webhook secrets (also inside URLs, exception messages, breadcrumbs and
 * spans). Telegram update bodies reuse {@see UpdateSanitizer}.
 *
 * Stack frame arguments (`Frame::getVars()`) are dropped entirely: sentry-php
 * puts the raw arguments of every call there (e.g. the
 * `https://api.telegram.org/bot<TOKEN>/…` URL passed to the HTTP client, chat
 * texts, `initData`), and unlike request data they have no structure we could
 * whitelist. Source-code context lines are kept (they are code, not data).
 *
 * Referenced from config/sentry.php as array callables so `config:cache`
 * keeps working (closures cannot be cached).
 */
final class SentryScrubber
{
    public const REDACTED = SecretRedactor::REDACTED;

    public static function beforeSend(Event $event, ?EventHint $hint = null): Event
    {
        return self::scrub($event);
    }

    public static function beforeSendTransaction(Event $event, ?EventHint $hint = null): Event
    {
        return self::scrub($event);
    }

    /**
     * `before_send_log`: Sentry Logs (`SENTRY_ENABLE_LOGS`) bypass `before_send`,
     * so the body and every attribute are redacted here.
     */
    public static function beforeSendLog(Log $log): Log
    {
        $log->setBody(SecretRedactor::redact($log->getBody()));

        foreach ($log->attributes()->all() as $key => $attribute) {
            $value = $attribute->getValue();
            if (is_string($value)) {
                $redacted = SecretRedactor::redactArray([$key => $value]);
                $log->setAttribute($key, $redacted[$key]);
            }
        }

        return $log;
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
            $event->setRequest(self::scrubArray($request, true));
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
            self::scrubStacktrace($exception->getStacktrace());
        }
        self::scrubStacktrace($event->getStacktrace());

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
        return SecretRedactor::redact($value);
    }

    private static function scrubStacktrace(?Stacktrace $stacktrace): void
    {
        if ($stacktrace === null) {
            return;
        }

        foreach ($stacktrace->getFrames() as $frame) {
            $frame->setVars([]);
        }
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private static function scrubArray(array $data, bool $inRequest = false): array
    {
        return SecretRedactor::redactArray($data, $inRequest);
    }
}
