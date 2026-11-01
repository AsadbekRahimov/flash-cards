<?php

declare(strict_types=1);

namespace App\Domain\Practice\DTO;

use App\Models\Course;
use App\Models\Lesson;
use Closure;

/**
 * Which material a mode may use (docs/16 §1.2): a course, optionally one lesson of it, and - for "repeat the
 * mistakes" - the {@see RetryMaterial} that narrows both words and tasks.
 *
 * A scope is ONE operation's view of the material (a hub request, an opening of a session): it remembers what was read
 * from the database for it, so four modes asking for the same words and tasks cost the queries of one
 * (docs/16 §13: the hub reads the words and the tasks once). Build a new scope for the next operation: nothing is
 * shared between scopes, so nothing can go stale between requests.
 */
final class MaterialScope
{
    /** @var array<string, mixed> */
    private array $memo = [];

    public function __construct(
        public readonly Course $course,
        public readonly ?Lesson $lesson = null,
        public readonly ?RetryMaterial $retry = null,
    ) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $load
     * @return T
     */
    public function remember(string $key, Closure $load): mixed
    {
        if (! array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $load();
        }

        /** @var T */
        return $this->memo[$key];
    }
}
