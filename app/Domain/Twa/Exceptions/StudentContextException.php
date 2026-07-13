<?php

declare(strict_types=1);

namespace App\Domain\Twa\Exceptions;

use DomainException;

final class StudentContextException extends DomainException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
