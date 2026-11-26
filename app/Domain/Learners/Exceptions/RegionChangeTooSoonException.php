<?php

declare(strict_types=1);

namespace App\Domain\Learners\Exceptions;

use App\Domain\Learners\Services\LearnerRegionService;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * The region may change once in `regions.change_days` (30) days (docs/35 §2.3): the region tables must not be a place to hop
 * between. The first choice and a reset to "not given" are not refused ({@see LearnerRegionService::set()}). HTTP: 422
 * `region_change_too_soon` with `details = {available_at}` (ISO 8601 UTC); the bot says "You can change the region from <date>".
 * Nothing of the request is applied.
 */
final class RegionChangeTooSoonException extends RuntimeException
{
    public const ERROR_CODE = 'region_change_too_soon';

    public readonly string $errorCode;

    /** @var array{available_at: string} */
    public readonly array $details;

    public function __construct(public readonly CarbonImmutable $availableAt)
    {
        $this->errorCode = self::ERROR_CODE;
        $this->details = ['available_at' => $availableAt->utc()->toIso8601String()];

        parent::__construct('The region can be changed once in '.LearnerRegionService::changeDays().' days; next change at '.$this->details['available_at'].'.');
    }
}
