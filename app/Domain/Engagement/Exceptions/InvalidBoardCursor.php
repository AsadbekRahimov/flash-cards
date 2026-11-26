<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Exceptions;

use RuntimeException;

/**
 * `?after=` of a table of the word points is not a cursor of this table (docs/04 A.19): broken, of another month or category, or the
 * month is not one of the word points v2 (a v1 month has no pages). The API answers 422 `invalid_cursor`.
 */
final class InvalidBoardCursor extends RuntimeException
{
    public const ERROR_CODE = 'invalid_cursor';
}
