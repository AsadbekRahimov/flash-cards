<?php

declare(strict_types=1);

namespace App\Domain\Game\DTO;

use App\Domain\Game\Enums\RunKind;

/**
 * The shape of a run the composer builds (docs/18 §12.3.1): the sizes of `config/game.php` `run.*`, and what the kind of the run
 * allows - a stop has pairs, authored tasks, flasks and events; a boss has none of them (12 words, no flasks); the daily caravan has
 * only tasks made of words. Read from the config once per run, so the composer stays a pure function of the pool, the seed and this.
 */
final readonly class RunLimits
{
    public function __construct(
        public int $sizeMin,
        public int $sizeMax,
        public int $minElements,
        public int $authoredMax,
        public int $pairsRoundsMax,
        public int $pairsPerRound,
        public int $flasks,
        public float $eventsChance,
        public bool $withPairs,
        public bool $withAuthored,
        public bool $withEvents,
    ) {}

    /** The limits of a run of the kind, from the config. */
    public static function for(RunKind $kind): self
    {
        $run = (array) config('game.run', []);
        $boss = (array) config('game.boss', []);
        $size = (int) ($run['size_max'] ?? 10);
        $isBoss = $kind === RunKind::Boss || $kind === RunKind::ClassBoss;

        return new self(
            sizeMin: $isBoss ? (int) ($boss['size'] ?? 12) : (int) ($run['size_min'] ?? 8),
            sizeMax: $isBoss ? (int) ($boss['size'] ?? 12) : $size,
            minElements: $isBoss ? (int) ($boss['min_words'] ?? 8) : (int) ($run['min_elements'] ?? 6),
            authoredMax: $kind === RunKind::Stop ? (int) ($run['authored_max'] ?? 3) : 0,
            pairsRoundsMax: $kind === RunKind::Stop ? (int) ($run['pairs_rounds_max'] ?? 1) : 0,
            pairsPerRound: (int) ($run['pairs_per_round'] ?? 4),
            flasks: $isBoss ? 0 : (int) ($run['flasks'] ?? 3),
            eventsChance: (float) config('game.events.chance', 0.6),
            withPairs: $kind === RunKind::Stop,
            withAuthored: $kind === RunKind::Stop,
            withEvents: ! $isBoss,
        );
    }

    /** The same limits with another chance of an event (the tests pin it to 0 or 1). */
    public function withEventsChance(float $chance): self
    {
        return new self($this->sizeMin, $this->sizeMax, $this->minElements, $this->authoredMax, $this->pairsRoundsMax, $this->pairsPerRound, $this->flasks, $chance, $this->withPairs, $this->withAuthored, $this->withEvents);
    }
}
