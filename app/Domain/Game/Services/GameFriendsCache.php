<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Operations\Support\PureCache;

/**
 * The key of `game:friends` (docs/19 §2.2, docs/18 §12.12): the friends of a learner on the map, a pure cache. The value is made by
 * {@see MapService}; whoever changes a friendship drops the keys of BOTH sides ({@see forget()}) after the write, and the progress of
 * a friend may be up to the TTL old - that is allowed.
 */
final class GameFriendsCache
{
    /** The version of the FORMAT of the value (raise it when the shape changes: nobody reads the old entries). */
    public const VERSION = 'v2';

    public const TTL_SECONDS = 300;

    public static function key(int $learnerId): string
    {
        return 'game:friends:'.self::VERSION.':'.$learnerId;
    }

    /** Drops the entries of the learners (one call to the store; never throws). */
    public static function forget(int ...$learnerIds): void
    {
        PureCache::forgetMany(array_map(self::key(...), array_values(array_unique($learnerIds))));
    }
}
