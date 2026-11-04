<?php

declare(strict_types=1);

namespace App\Domain\Practice\DTO;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\Stage;
use Closure;

/**
 * Which material a mode may use (docs/16 §1.2): a course, optionally one lesson of it, and - for "repeat the
 * mistakes" - the {@see RetryMaterial} that narrows both words and tasks. The game (docs/18 §12.3.1) adds the scope of a
 * STAGE ({@see forStage()}): every lesson of one module, when a stop is short of words - `lesson` is null and the
 * queries filter by `stages.id`.
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
        public readonly ?Stage $stage = null,
        public readonly bool $countOnly = false,
    ) {}

    /** The words and the tasks of every lesson of the stage (docs/18 §12.3.1: a stop widened to its city). */
    public static function forStage(Course $course, Stage $stage): self
    {
        return new self($course, null, null, $stage);
    }

    /**
     * A scope that only COUNTS what the modes have (the hub, the options of a battle): the tasks are read as plain rows, no model
     * is hydrated. A scope that is meant to be played (the default) reads the models: the snapshot is built from them, and the
     * count is made of the very same read.
     */
    public static function forCount(Course $course, ?Lesson $lesson = null): self
    {
        return new self($course, $lesson, countOnly: true);
    }

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
