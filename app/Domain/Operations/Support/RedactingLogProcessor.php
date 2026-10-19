<?php

declare(strict_types=1);

namespace App\Domain\Operations\Support;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Keeps secrets out of log files / stderr: runs `message`, `context` and
 * `extra` of every record through {@see SecretRedactor}.
 *
 * Why the exception object in `context['exception']` is replaced with text:
 * Monolog's formatter would print `getMessage()` and `getTraceAsString()` of
 * the original throwable, and a Guzzle/SDK message contains the full
 * `https://api.telegram.org/bot<TOKEN>/…` URL (Guzzle masks only userinfo).
 * The record is formatted after processors run, so swapping the object for its
 * redacted text form ({@see SecretRedactor::describeThrowable()}, same layout
 * as LineFormatter, call arguments omitted) is the one reliable way to cover
 * the message, the `previous` chain and the trace regardless of the formatter.
 *
 * Registered on each channel by {@see RedactSecretsInLogs} (`tap` in config/logging.php).
 */
final class RedactingLogProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: SecretRedactor::redact($record->message),
            context: SecretRedactor::redactArray($record->context),
            extra: SecretRedactor::redactArray($record->extra),
        );
    }
}
