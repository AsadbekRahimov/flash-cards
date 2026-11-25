<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Exceptions;

use RuntimeException;

/**
 * A table of a region asked of a month that has none - a month of the old rules (v1), or the monthly rating / the word points off
 * (docs/35 §2.4: the region tables are a filter over the tables v2). The API answers 422 `region_scope_unavailable`.
 */
final class RegionScopeUnavailable extends RuntimeException
{
    public const ERROR_CODE = 'region_scope_unavailable';
}
