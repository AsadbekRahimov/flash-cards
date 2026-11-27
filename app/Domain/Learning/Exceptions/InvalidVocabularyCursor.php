<?php

declare(strict_types=1);

namespace App\Domain\Learning\Exceptions;

use RuntimeException;

/** `?after=` of "My vocabulary" is not a cursor of the list (docs/04 A.25): the API answers 422 `invalid_cursor`. */
final class InvalidVocabularyCursor extends RuntimeException
{
    public const ERROR_CODE = 'invalid_cursor';
}
