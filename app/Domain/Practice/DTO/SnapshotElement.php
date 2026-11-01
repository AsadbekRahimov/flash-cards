<?php

declare(strict_types=1);

namespace App\Domain\Practice\DTO;

/**
 * One element of a snapshot (docs/16 §2.8): an opaque `id` (`e…`), a `kind` (the mode, except for pairs: `pairs_round`)
 * and everything else the mode keeps there in `data` - the texts, the layout, the EXPECTED answers. It is server-only;
 * the client gets {@see PracticeModeHandler::project()} of it, never `data`.
 *
 * `toArray()` is the shape of the snapshot JSON: `id`, `kind`, then the keys of `data`.
 */
final readonly class SnapshotElement
{
    public const KIND_PAIRS_ROUND = 'pairs_round';

    /** @param array<string, mixed> $data */
    public function __construct(
        public string $id,
        public string $kind,
        public array $data = [],
    ) {}

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $id = $raw['id'] ?? '';
        $kind = $raw['kind'] ?? '';
        unset($raw['id'], $raw['kind']);

        return new self(is_string($id) ? $id : '', is_string($kind) ? $kind : '', $raw);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['id' => $this->id, 'kind' => $this->kind, ...$this->data];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function isPairsRound(): bool
    {
        return $this->kind === self::KIND_PAIRS_ROUND;
    }
}
