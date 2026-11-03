<?php

declare(strict_types=1);

namespace App\Domain\Game\DTO;

use App\Domain\Practice\DTO\ElementState;
use App\Domain\Practice\Enums\Verdict;

/**
 * `game_runs.state` (docs/18 §12.3.3), changed only under the lock of the run:
 *
 *   { current, elements: {id: {status, verdict, first, hinted, hint, points, flask_lost, answered_at}}, pairs: {id: {...}},
 *     combo, flasks, oasis_used, boosters: {extra_flask, second_chance_left}, srs_pulled: [word ids] }
 *
 * `elements[id]` is a superset of what the practice stores per element, so a handler of the practice reads it as an
 * {@see ElementState} ({@see ElementState()}). `hinted` is set by a hint before the answer; the answer that follows is counted in
 * `hints`. `srs_pulled` lists the words the run was wrong about (the list of mistakes); how many of them were really brought
 * closer is the column `game_runs.srs_pulled`.
 */
final class RunState
{
    public const STATUS_OPEN = 'open';

    public const STATUS_ANSWERED = 'answered';

    /**
     * @param  array<string, array<string, mixed>>  $elements
     * @param  array<string, array<string, mixed>>  $pairs
     * @param  list<int>  $srsPulled
     */
    public function __construct(
        public int $current = 0,
        public array $elements = [],
        public array $pairs = [],
        public int $combo = 0,
        public int $flasks = 3,
        public bool $oasisUsed = false,
        public bool $extraFlask = false,
        public int $secondChanceLeft = 0,
        public array $srsPulled = [],
    ) {}

    public static function initial(int $flasks): self
    {
        return new self(flasks: $flasks);
    }

    /** @param array<string, mixed> $state the column */
    public static function fromArray(array $state): self
    {
        $elements = [];
        foreach (is_array($state['elements'] ?? null) ? $state['elements'] : [] as $id => $entry) {
            if (is_array($entry)) {
                /** @var array<string, mixed> $entry */
                $elements[(string) $id] = $entry;
            }
        }
        $pairs = [];
        foreach (is_array($state['pairs'] ?? null) ? $state['pairs'] : [] as $id => $entry) {
            if (is_array($entry)) {
                /** @var array<string, mixed> $entry */
                $pairs[(string) $id] = $entry;
            }
        }
        $boosters = is_array($state['boosters'] ?? null) ? $state['boosters'] : [];
        $pulled = [];
        foreach (is_array($state['srs_pulled'] ?? null) ? $state['srs_pulled'] : [] as $wordId) {
            $pulled[] = (int) $wordId;
        }

        return new self(
            current: (int) ($state['current'] ?? 0),
            elements: $elements,
            pairs: $pairs,
            combo: (int) ($state['combo'] ?? 0),
            flasks: (int) ($state['flasks'] ?? 0),
            oasisUsed: (bool) ($state['oasis_used'] ?? false),
            extraFlask: (bool) ($boosters['extra_flask'] ?? false),
            secondChanceLeft: (int) ($boosters['second_chance_left'] ?? 0),
            srsPulled: $pulled,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'current' => $this->current,
            'elements' => $this->elements,
            'pairs' => $this->pairs,
            'combo' => $this->combo,
            'flasks' => $this->flasks,
            'oasis_used' => $this->oasisUsed,
            'boosters' => ['extra_flask' => $this->extraFlask, 'second_chance_left' => $this->secondChanceLeft],
            'srs_pulled' => $this->srsPulled,
        ];
    }

    /** @return array<string, mixed>|null */
    public function entry(string $elementId): ?array
    {
        return $this->elements[$elementId] ?? null;
    }

    public function isAnswered(string $elementId): bool
    {
        return ($this->elements[$elementId]['status'] ?? null) === self::STATUS_ANSWERED;
    }

    public function isHinted(string $elementId): bool
    {
        return (bool) ($this->elements[$elementId]['hinted'] ?? false);
    }

    /**
     * The hint given on the element, as it was answered to the client.
     *
     * @return array<string, mixed>|null
     */
    public function hintOf(string $elementId): ?array
    {
        $hint = $this->elements[$elementId]['hint'] ?? null;

        return is_array($hint) ? $hint : null;
    }

    public function verdictOf(string $elementId): ?Verdict
    {
        return Verdict::tryFrom((string) ($this->elements[$elementId]['verdict'] ?? ''));
    }

    /** @param array<string, mixed> $hint */
    public function markHinted(string $elementId, array $hint): void
    {
        $this->elements[$elementId] = [
            ...($this->elements[$elementId] ?? ['status' => self::STATUS_OPEN]),
            'hinted' => true,
            'hint' => $hint,
        ];
    }

    /** @param array<string, mixed>|null $answer */
    public function markAnswered(string $elementId, Verdict $verdict, bool $first, int $points, bool $flaskLost, ?array $answer, string $answeredAt): void
    {
        $this->elements[$elementId] = [
            ...($this->elements[$elementId] ?? []),
            'status' => self::STATUS_ANSWERED,
            'verdict' => $verdict->value,
            'first' => $first,
            'hinted' => $this->isHinted($elementId),
            'points' => $points,
            'flask_lost' => $flaskLost,
            'answer' => $answer,
            'answered_at' => $answeredAt,
        ];
    }

    /** A flask lost on an element that is still open (a round of pairs loses it at the first mismatch, docs/18 §12.3.2). */
    public function markFlaskLost(string $elementId): void
    {
        $this->elements[$elementId] = [
            ...($this->elements[$elementId] ?? ['status' => self::STATUS_OPEN]),
            'flask_lost' => true,
        ];
    }

    public function hasLostFlask(string $elementId): bool
    {
        return ($this->elements[$elementId]['flask_lost'] ?? false) === true;
    }

    /** @param list<int> $wordIds */
    public function rememberMistakes(array $wordIds): void
    {
        foreach ($wordIds as $wordId) {
            if (! in_array($wordId, $this->srsPulled, true)) {
                $this->srsPulled[] = $wordId;
            }
        }
    }

    /** The element as a handler of the practice sees it (the stored entry plus the `pairs` block). */
    public function elementState(string $elementId): ElementState
    {
        return ElementState::fromStored($this->elements[$elementId] ?? null, $this->pairs[$elementId] ?? []);
    }

    /** How many flasks the run has lost so far (the stars are counted from it). */
    public function flaskLosses(): int
    {
        $losses = 0;
        foreach ($this->elements as $entry) {
            if (($entry['flask_lost'] ?? false) === true) {
                $losses++;
            }
        }

        return $losses;
    }
}
