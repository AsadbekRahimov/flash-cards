<?php

declare(strict_types=1);

namespace App\Domain\Learners\Exceptions;

use App\Domain\Learners\Services\LearnerSettingsService;
use InvalidArgumentException;

/**
 * A learner setting that {@see LearnerSettingsService} refuses: an
 * unknown field or a value outside the field's rules. HTTP callers validate first (FormRequest),
 * so this is the service's own line of defence, not a user-facing error.
 */
final class InvalidSettingException extends InvalidArgumentException
{
    public function __construct(public readonly string $field, string $reason)
    {
        parent::__construct("Setting [{$field}] {$reason}.");
    }

    public static function unknown(string $field): self
    {
        return new self($field, 'is not a learner setting');
    }

    public static function invalid(string $field): self
    {
        return new self($field, 'has an invalid value');
    }
}
