<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Exceptions;

use App\Domain\Engagement\Services\Scoring\ScoreCorrectionService;
use RuntimeException;

/**
 * @internal {@see ScoreCorrectionService}: an answer credited a lexeme of the day that the correction had not locked (it was read
 *           before the lexemes were locked); the transaction is rolled back and starts over, so the lexemes are never locked after the day.
 */
final class ScoreCorrectionRetry extends RuntimeException {}
