<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Exceptions;

use RuntimeException;

/**
 * Internal to StreakFreezeService: unwinds the freeze transaction when the inventory decrement
 * or the claim of the date did not go through (nothing is left changed).
 */
final class FreezeNotApplied extends RuntimeException {}
