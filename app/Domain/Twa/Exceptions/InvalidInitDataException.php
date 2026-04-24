<?php

declare(strict_types=1);

namespace App\Domain\Twa\Exceptions;

use DomainException;

final class InvalidInitDataException extends DomainException
{
    public const REASON_MISSING_FIELDS = 'missing_fields';

    public const REASON_INVALID_HASH = 'invalid_hash';

    public const REASON_EXPIRED = 'expired';

    public const REASON_NO_USER = 'no_user';

    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
