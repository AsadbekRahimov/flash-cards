<?php

declare(strict_types=1);

namespace App\Domain\Game\DTO;

use App\Domain\Game\Enums\RunEvent;
use App\Domain\Game\Enums\RunKind;
use App\Domain\Practice\DTO\SnapshotElement;
use LogicException;

/**
 * The snapshot of a run (`game_runs.snapshot`, docs/18 §12.3.2): the WHOLE issue - the elements with their expected answers, the
 * events fixed at the start, and what the screens show about the place without a query (the course, the city, the stop). Server-only:
 * a client gets `TaskHandler::project()` of the current element and nothing else.
 *
 * An element is a {@see SnapshotElement}, the same type the practice keeps in its snapshots: the elements of the kinds `pairs_round`,
 * `collocation`, `sentence` and `dictation` are built, projected and graded by the handlers of the practice as they are.
 */
final readonly class RunSnapshot
{
    public const VERSION = 1;

    /** The size limit in UTF-8 bytes (docs/18 §12.3.2): the biggest valid composition stays a quarter under it (RunSnapshotSizeTest). */
    public const MAX_BYTES = 40 * 1024;

    /**
     * @param  list<string>  $locales
     * @param  list<SnapshotElement>  $elements
     * @param  list<array{at: int, type: string}>  $events
     * @param  array<string, string>  $courseTitle  locale => title
     * @param  array<string, string>  $stageTitle  locale => title of the module
     * @param  array<string, string>  $lessonTitles  locale => title of the lesson
     */
    public function __construct(
        public RunKind $kind,
        public array $locales,
        public array $elements,
        public array $events = [],
        public string $courseSlug = '',
        public array $courseTitle = [],
        public ?int $stageNumber = null,
        public array $stageTitle = [],
        public ?string $city = null,
        public ?int $lessonNumber = null,
        public array $lessonTitles = [],
        public bool $scopeWidened = false,
        public int $flasksMax = 3,
        public int $v = self::VERSION,
    ) {}

    /** @param array<string, mixed> $data the `snapshot` column */
    public static function fromArray(array $data): self
    {
        $kind = RunKind::tryFrom((string) ($data['kind'] ?? '')) ?? throw new LogicException('A run snapshot without a known kind.');

        $elements = [];
        foreach ((array) ($data['elements'] ?? []) as $raw) {
            if (is_array($raw)) {
                /** @var array<string, mixed> $raw */
                $elements[] = SnapshotElement::fromArray($raw);
            }
        }

        $events = [];
        foreach ((array) ($data['events'] ?? []) as $event) {
            if (is_array($event) && isset($event['at'], $event['type']) && is_string($event['type'])) {
                $events[] = ['at' => (int) $event['at'], 'type' => $event['type']];
            }
        }

        return new self(
            kind: $kind,
            locales: self::strings($data['locales'] ?? []),
            elements: $elements,
            events: $events,
            courseSlug: (string) ($data['course_slug'] ?? ''),
            courseTitle: self::map($data['course_title'] ?? []),
            stageNumber: isset($data['stage_number']) ? (int) $data['stage_number'] : null,
            stageTitle: self::map($data['stage_title'] ?? []),
            city: isset($data['city']) ? (string) $data['city'] : null,
            lessonNumber: isset($data['lesson_number']) ? (int) $data['lesson_number'] : null,
            lessonTitles: self::map($data['lesson_titles'] ?? []),
            scopeWidened: (bool) ($data['scope_widened'] ?? false),
            flasksMax: (int) ($data['flasks_max'] ?? 3),
            v: (int) ($data['v'] ?? self::VERSION),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'v' => $this->v,
            'kind' => $this->kind->value,
            'locales' => $this->locales,
            'course_slug' => $this->courseSlug,
            'course_title' => $this->courseTitle,
            'stage_number' => $this->stageNumber,
            'stage_title' => $this->stageTitle,
            'city' => $this->city,
            'lesson_number' => $this->lessonNumber,
            'lesson_titles' => $this->lessonTitles,
            'scope_widened' => $this->scopeWidened,
            'flasks_max' => $this->flasksMax,
            'events' => $this->events,
            'elements' => array_map(static fn (SnapshotElement $e): array => $e->toArray(), $this->elements),
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

    /** The event fixed on the element at `$index` (docs/18 §12.3.1: shown to the client only when the element is current). */
    public function eventAt(int $index): ?RunEvent
    {
        foreach ($this->events as $event) {
            if ($event['at'] === $index) {
                return RunEvent::tryFrom($event['type']);
            }
        }

        return null;
    }

    /**
     * The title in the locale, else in any locale the snapshot has, else null.
     *
     * @param  array<string, string>  $titles
     */
    public static function titleFor(array $titles, string $locale): ?string
    {
        return $titles[$locale] ?? (array_values($titles)[0] ?? null);
    }

    /**
     * The snapshot must stay a modest row (docs/18 §12.3.2). Measured in UTF-8, the way the database keeps `jsonb` text.
     *
     * @throws LogicException when the JSON is bigger than {@see self::MAX_BYTES}
     */
    public function assertFits(): void
    {
        $bytes = strlen(json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($bytes > self::MAX_BYTES) {
            throw new LogicException("A run snapshot of {$bytes} bytes exceeds the limit of ".self::MAX_BYTES.' bytes.');
        }
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        $list = [];
        foreach ((array) $value as $item) {
            if (is_string($item)) {
                $list[] = $item;
            }
        }

        return $list;
    }

    /** @return array<string, string> */
    private static function map(mixed $value): array
    {
        $map = [];
        foreach ((array) $value as $key => $item) {
            if (is_string($item)) {
                $map[(string) $key] = $item;
            }
        }

        return $map;
    }
}
