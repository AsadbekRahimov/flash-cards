<?php

declare(strict_types=1);

namespace App\Domain\Operations\Support;

use Illuminate\Log\Logger;
use Monolog\Logger as MonologLogger;

/**
 * Laravel log channel `tap`: pushes {@see RedactingLogProcessor} onto the
 * channel's Monolog logger. For the `stack` driver Laravel merges the
 * processors of its member channels, so tapping the members is enough.
 */
final class RedactSecretsInLogs
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if ($monolog instanceof MonologLogger) {
            $monolog->pushProcessor(new RedactingLogProcessor);
        }
    }
}
