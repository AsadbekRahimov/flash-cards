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

    /** @var array<string, true> the memo keys of the word lists that were cut at {@see} */
    private array $truncated = [];

    /** Whether a word list of a play scope was cut to a sample ({@see markPlaySampled()}). */
    private bool $playSampled = false;

    /** Whether a cut word list was read since the last {@see takeTruncatedRead()}. */
    private bool $truncatedRead = false;

    public function __construct(
        public readonly Course $course,
        public readonly ?Lesson $lesson = null,
        public readonly ?RetryMaterial $retry = null,
        public readonly ?Stage $stage = null,
        public readonly bool $countOnly = false,
        public readonly ?int $wordSample = null,
        public readonly ?string $playSeed = null,
        public readonly ?int $playSample = null,
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
        // A lesson is small anyway; only the whole course (thousands of words) is counted over a sample.
        $sample = max(0, (int) config('practice.count_sample'));

        return new self($course, $lesson, countOnly: true, wordSample: $lesson === null && $sample > 0 ? $sample : null);
    }

    /**
     * A scope a session / a battle is BUILT from, with the seed of its snapshot. A whole course (no lesson, no stage, no retry) with
     * `practice.play_sample` > 0 reads not every word of the learner but a sample of that size chosen by the seed
     * ({@see PracticeMaterialService}): opening a session costs the same at 300 and at 8000 started words. Any other scope - a
     * lesson, a module, a retry - is read whole, as before. Not the {@see $wordSample} of a counting scope: another path.
     */
    public static function forPlay(Course $course, ?Lesson $lesson, ?RetryMaterial $retry, string $seed): self
    {
        $sample = max(0, (int) config('practice.play_sample'));
        $sampled = $lesson === null && $retry === null && $sample > 0;

        return new self($course, $lesson, $retry, playSeed: $sampled ? $seed : null, playSample: $sampled ? $sample : null);
    }

    /**
     * The same scope read over ALL the words (a fresh memo): what a play scope falls back to when the sample did not give a snapshot,
     * so that "can it be built" and the refusal are those of the whole material.
     */
    public function withoutPlaySample(): self
    {
        return new self($this->course, $this->lesson, $this->retry, $this->stage);
    }

    /**
     * The same course / lesson for counting over ALL the words, with a memo of its own: the exact answer a counting scope falls back
     * to when its sample was cut and the mode came out short.
     */
    public function withoutSample(): self
    {
        return new self($this->course, $this->lesson, $this->retry, $this->stage, countOnly: true);
    }

    /** The word list remembered under `$key` was cut at {@see $wordSample}: there are more words behind it. */
    public function markTruncated(string $key): void
    {
        $this->truncated[$key] = true;
    }

    public function wasTruncated(string $key): bool
    {
        return isset($this->truncated[$key]);
    }

    /** A play scope has cut a word list to a sample: there are words the snapshot did not see. */
    public function markPlaySampled(): void
    {
        $this->playSampled = true;
    }

    public function wasPlaySampled(): bool
    {
        return $this->playSampled;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->memo);
    }

    /** A mode has just read a cut word list: if it is short of material, only the whole list can say so for sure. */
    public function noteTruncatedRead(): void
    {
        $this->truncatedRead = true;
    }

    /** Whether a cut list was read since the last call (and forget it: one mode at a time). */
    public function takeTruncatedRead(): bool
    {
        $read = $this->truncatedRead;
        $this->truncatedRead = false;

        return $read;
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
