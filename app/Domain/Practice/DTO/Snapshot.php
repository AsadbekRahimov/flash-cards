<?php

declare(strict_types=1);

namespace App\Domain\Practice\DTO;

use App\Domain\Practice\Enums\PracticeMode;
use LogicException;

/**
 * The snapshot of a session / a battle (docs/16 §2.8): the FULL issue - the texts, the layout, the expected answers,
 * the opaque ids. It is server-only and never goes into a response; a mode's `project()` shows the client its part.
 *
 *   v, mode, locales, pool (the unused candidates in seeded order: the spares of a dictation), elements[],
 *   + what the screens show about the scope without a query (docs/16 §13): course_slug, course_title (locale =>
 *   title), lesson_stage, lesson_number, lesson_title.
 */
final readonly class Snapshot
{
    public const VERSION = 1;

    /** The size limit of a snapshot (docs/16 §13): 5 elements fit with a wide margin; a bigger one is a bug. */
    public const MAX_BYTES = 32 * 1024;

    /**
     * @param  list<string>  $locales
     * @param  list<SnapshotElement>  $elements
     * @param  list<array<string, mixed>>  $pool
     * @param  array<string, string>  $courseTitle  locale => the title of the course
     */
    public function __construct(
        public PracticeMode $mode,
        public array $locales,
        public array $elements,
        public array $pool = [],
        public string $courseSlug = '',
        public array $courseTitle = [],
        public ?int $lessonStage = null,
        public ?int $lessonNumber = null,
        public ?string $lessonTitle = null,
        public int $v = self::VERSION,
    ) {}

    /** @param array<string, mixed> $data the `snapshot` column */
    public static function fromArray(array $data): self
    {
        $mode = PracticeMode::tryFrom((string) ($data['mode'] ?? '')) ?? throw new LogicException('A snapshot without a known mode.');

        $elements = [];
        foreach ((array) ($data['elements'] ?? []) as $raw) {
            if (is_array($raw)) {
                /** @var array<string, mixed> $raw */
                $elements[] = SnapshotElement::fromArray($raw);
            }
        }

        $pool = [];
        foreach ((array) ($data['pool'] ?? []) as $candidate) {
            if (is_array($candidate)) {
                /** @var array<string, mixed> $candidate */
                $pool[] = $candidate;
            }
        }

        $locales = [];
        foreach ((array) ($data['locales'] ?? []) as $locale) {
            if (is_string($locale)) {
                $locales[] = $locale;
            }
        }

        $titles = [];
        foreach ((array) ($data['course_title'] ?? []) as $locale => $title) {
            if (is_string($title)) {
                $titles[(string) $locale] = $title;
            }
        }

        return new self(
            mode: $mode,
            locales: $locales,
            elements: $elements,
            pool: $pool,
            courseSlug: (string) ($data['course_slug'] ?? ''),
            courseTitle: $titles,
            lessonStage: isset($data['lesson_stage']) ? (int) $data['lesson_stage'] : null,
            lessonNumber: isset($data['lesson_number']) ? (int) $data['lesson_number'] : null,
            lessonTitle: isset($data['lesson_title']) ? (string) $data['lesson_title'] : null,
            v: (int) ($data['v'] ?? self::VERSION),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'v' => $this->v,
            'mode' => $this->mode->value,
            'locales' => $this->locales,
            'pool' => $this->pool,
            'elements' => array_map(static fn (SnapshotElement $e): array => $e->toArray(), $this->elements),
            'course_slug' => $this->courseSlug,
            'course_title' => $this->courseTitle,
            'lesson_stage' => $this->lessonStage,
            'lesson_number' => $this->lessonNumber,
            'lesson_title' => $this->lessonTitle,
        ];
    }

    public function count(): int
    {
        return count($this->elements);
    }

    public function at(int $index): ?SnapshotElement
    {
        return $this->elements[$index] ?? null;
    }

    public function element(string $id): ?SnapshotElement
    {
        $index = $this->indexOf($id);

        return $index === null ? null : $this->elements[$index];
    }

    public function indexOf(string $id): ?int
    {
        foreach ($this->elements as $index => $element) {
            if ($element->id === $id) {
                return $index;
            }
        }

        return null;
    }

    /** @param list<SnapshotElement> $elements */
    public function withElements(array $elements): self
    {
        return new self($this->mode, $this->locales, $elements, $this->pool, $this->courseSlug, $this->courseTitle, $this->lessonStage, $this->lessonNumber, $this->lessonTitle, $this->v);
    }

    /** @param list<array<string, mixed>> $pool */
    public function withPool(array $pool): self
    {
        return new self($this->mode, $this->locales, $this->elements, $pool, $this->courseSlug, $this->courseTitle, $this->lessonStage, $this->lessonNumber, $this->lessonTitle, $this->v);
    }

    /** The title of the course in the locale, else in any locale the snapshot has, else the slug. */
    public function courseTitleFor(string $locale): string
    {
        return $this->courseTitle[$locale] ?? (array_values($this->courseTitle)[0] ?? $this->courseSlug);
    }

    /**
     * The snapshot must stay a modest row (docs/16 §13).
     *
     * @throws LogicException when the JSON is bigger than {@see self::MAX_BYTES}
     */
    public function assertFits(): void
    {
        // Encoded the way the `json` cast writes the column (non-ASCII escaped): the size that really goes into the row.
        $bytes = strlen(json_encode($this->toArray(), JSON_THROW_ON_ERROR));
        if ($bytes > self::MAX_BYTES) {
            throw new LogicException("A snapshot of {$bytes} bytes exceeds the limit of ".self::MAX_BYTES.' bytes.');
        }
    }
}
