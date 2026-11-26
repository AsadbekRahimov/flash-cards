<?php

declare(strict_types=1);

namespace App\Domain\Learners\Exceptions;

use App\Domain\Learners\Services\LearnerRegionService;
use RuntimeException;

/**
 * {@see LearnerRegionService::revert()} has nothing to take back: no recent change of the region, the
 * {@see LearnerRegionService::UNDO_MINUTES} minutes have passed, or the undo was already used. HTTP: 422 `region_revert_unavailable`.
 */
final class RegionRevertUnavailableException extends RuntimeException
{
    public const ERROR_CODE = 'region_revert_unavailable';

    public readonly string $errorCode;

    public function __construct()
    {
        $this->errorCode = self::ERROR_CODE;

        parent::__construct('The region change can be taken back only within '.LearnerRegionService::UNDO_MINUTES.' minutes and only once.');
    }
}
