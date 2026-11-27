<?php

declare(strict_types=1);

namespace App\Domain\Learners\DTO;

/**
 * The outcome of the placement test (docs/40 §2.1a, docs/04 A.27): the level the answers point at, the course the level screen
 * offers for it (what the client puts into the `level` step), and the right answers per tested level.
 */
final readonly class PlacementResult
{
    /** @param  array<string, int>  $correctByLevel  `{A1: 2, A2: 1, …}` for the levels that were asked */
    public function __construct(
        public string $level,
        public string $courseSlug,
        public array $correctByLevel,
    ) {}

    /** @return array{level: string, course_slug: string, correct_by_level: array<string, int>} */
    public function toArray(): array
    {
        return ['level' => $this->level, 'course_slug' => $this->courseSlug, 'correct_by_level' => $this->correctByLevel];
    }

    /** @param  array<string, mixed>  $stored  what {@see toArray()} wrote into `meta`; null when it is not of the shape */
    public static function fromStored(array $stored): ?self
    {
        $level = $stored['level'] ?? null;
        $slug = $stored['course_slug'] ?? null;
        $counts = $stored['correct_by_level'] ?? null;
        if (! is_string($level) || ! is_string($slug) || ! is_array($counts)) {
            return null;
        }

        $byLevel = [];
        foreach ($counts as $key => $count) {
            if (! is_string($key) || ! is_int($count)) {
                return null;
            }
            $byLevel[$key] = $count;
        }

        return new self($level, $slug, $byLevel);
    }
}
