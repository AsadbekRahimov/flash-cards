<?php

declare(strict_types=1);

namespace App\Domain\Cup\DTO;

use App\Domain\Practice\DTO\SnapshotElement;

/**
 * `cup_days.snapshot` (docs/33 §2.4, §5.1): the tasks of the day in every supported locale, with the right answers - server only. The
 * client gets the projection of one element at a time (`CupProjection`), never this.
 */
final readonly class CupDaySnapshot
{
    public const VERSION = 1;

    /**
     * @param  list<string>  $locales
     * @param  list<SnapshotElement>  $elements
     */
    public function __construct(
        public array $locales,
        public array $elements,
        public string $courseSlug = '',
        public int $v = self::VERSION,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $elements = [];
        foreach ((array) ($data['elements'] ?? []) as $raw) {
            if (is_array($raw)) {
                /** @var array<string, mixed> $raw */
                $elements[] = SnapshotElement::fromArray($raw);
            }
        }
        $locales = [];
        foreach ((array) ($data['locales'] ?? []) as $locale) {
            if (is_string($locale)) {
                $locales[] = $locale;
            }
        }

        return new self($locales, $elements, (string) ($data['course_slug'] ?? ''), (int) ($data['v'] ?? self::VERSION));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'v' => $this->v,
            'course_slug' => $this->courseSlug,
            'locales' => $this->locales,
            'elements' => array_map(static fn (SnapshotElement $e): array => $e->toArray(), $this->elements),
        ];
    }

    public function element(string $id): ?SnapshotElement
    {
        foreach ($this->elements as $element) {
            if ($element->id === $id) {
                return $element;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_map(static fn (SnapshotElement $e): string => $e->id, $this->elements);
    }

    /**
     * The word ids of the tasks (for the tests of "the same words for everybody"; never sent out).
     *
     * @return list<int>
     */
    public function wordIds(): array
    {
        $ids = [];
        foreach ($this->elements as $element) {
            $id = $element->get('word_id');
            if (is_int($id) || is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }
}
