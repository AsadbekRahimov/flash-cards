<?php

declare(strict_types=1);

namespace App\Domain\Polls\Exceptions;

use RuntimeException;

/**
 * A poll that cannot go out as it is (docs/35 §1.5): the launch or the test send refuses it before anything is written. `$reason`
 * is a stable code for the notification of `/admin` (O6 words it).
 */
final class PollLaunchException extends RuntimeException
{
    /** Fewer than 2 or more than 10 options. */
    public const OPTIONS = 'options';

    /** The question is empty in a language, or the text with the list of options is over the Telegram limit (1 024 / 4 096). */
    public const TOO_LONG = 'too_long';

    /** No test send reached the administrator yet: the launch is refused (docs/35 §1.5). */
    public const UNTESTED = 'untested';

    /** The administrator has no Telegram account linked: nowhere to send the test. */
    public const NO_TELEGRAM = 'no_telegram';

    /** The poll is closed or cancelled: no test send any more. */
    public const FINISHED = 'finished';

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function because(string $reason): self
    {
        return new self($reason, 'The poll cannot be sent: '.$reason.'.');
    }
}
