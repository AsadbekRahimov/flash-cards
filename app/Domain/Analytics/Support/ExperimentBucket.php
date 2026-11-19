<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Support;

use App\Domain\Learning\Services\SchedulerResolver;

/**
 * The sticky arm of an A/B experiment without a column: `crc32("salt:id") % 100 < share` (the same idea as the FSRS
 * rollout of {@see SchedulerResolver::bucketFor()}, plus a salt). The same id always lands in
 * the same bucket for a given salt, raising the share only moves learners INTO the treated arm, and a different salt
 * gives an independent split, so two experiments on the same learners do not overlap by construction. Pure: no I/O.
 */
final class ExperimentBucket
{
    /** The bucket 0..99 of `$id` under `$salt`. */
    public static function of(string $salt, int|string $id): int
    {
        return crc32($salt.':'.$id) % 100;
    }

    /** Whether `$id` is in the first `$share` percent (0 = nobody, 100 = everybody). */
    public static function in(string $salt, int|string $id, int $share): bool
    {
        return self::of($salt, $id) < $share;
    }
}
