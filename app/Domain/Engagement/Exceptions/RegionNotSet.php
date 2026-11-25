<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Exceptions;

use RuntimeException;

/** `scope=region` without `region=` from a learner who has no region (docs/35 §2.4): the API answers 422 `region_not_set`. */
final class RegionNotSet extends RuntimeException
{
    public const ERROR_CODE = 'region_not_set';
}
