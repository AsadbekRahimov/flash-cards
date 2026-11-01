<?php

declare(strict_types=1);

namespace App\Domain\Practice\DTO;

/**
 * A round of "Find the pairs" as the mode reads it from the snapshot (docs/16 §2.8) and from the state of the session (§6.2):
 * the one place that knows the shape of both, so the builder, the projection, the grader and the list of mistakes agree.
 *
 * The element of the snapshot (`SnapshotElement::$data`):
 *   round, rounds   the number of this round (from 1) and how many rounds the session has;
 *   left            [{id: "l…", word_id, text: "mother"}]  - in the order shown;
 *   right           [{id: "r…", text: {ru: "мама", uz: "ona"}}]  - shuffled on its own, the text of EACH locale of the snapshot;
 *   pairs           {l…: r…}  - the right table; never leaves the server;
 *   battle          true on the round of a battle (one final answer: the whole table), absent in a solo session.
 * The state of the round (`ElementState::$extra`, written by the grader): `{matched: {l…: r…}, mismatches, wrong_left: [l…]}`.
 */
final readonly class PairsRound
{
    /** The key that marks a round of a battle. */
    public const BATTLE = 'battle';

    /**
     * @param  list<array{id: string, word_id: int, text: string}>  $left
     * @param  list<array{id: string, text: array<string, string>}>  $right
     * @param  array<string, string>  $table  left id => right id
     */
    public function __construct(
        public int $number,
        public int $rounds,
        public array $left,
        public array $right,
        public array $table,
        public bool $battle = false,
    ) {}

    /** Reads a round out of an element of the snapshot; what is not in the shape is left out. */
    public static function of(SnapshotElement $element): self
    {
        $left = [];
        foreach (self::cards($element->get('left')) as $card) {
            if (is_string($card['id'] ?? null) && is_string($card['text'] ?? null)) {
                $left[] = ['id' => $card['id'], 'word_id' => (int) ($card['word_id'] ?? 0), 'text' => $card['text']];
            }
        }

        $right = [];
        foreach (self::cards($element->get('right')) as $card) {
            if (is_string($card['id'] ?? null)) {
                $right[] = ['id' => $card['id'], 'text' => self::strings($card['text'] ?? null)];
            }
        }

        return new self(
            max(1, (int) $element->get('round', 1)),
            max(1, (int) $element->get('rounds', 1)),
            $left,
            $right,
            self::strings($element->get('pairs')),
            $element->get(self::BATTLE) === true,
        );
    }

    /**
     * The `data` of the element of the snapshot.
     *
     * @return array<string, mixed>
     */
    public function toData(): array
    {
        $data = ['round' => $this->number, 'rounds' => $this->rounds, 'left' => $this->left, 'right' => $this->right, 'pairs' => $this->table];
        if ($this->battle) {
            $data[self::BATTLE] = true;
        }

        return $data;
    }

    /** @return list<string> */
    public function leftIds(): array
    {
        return array_column($this->left, 'id');
    }

    /** @return list<string> */
    public function rightIds(): array
    {
        return array_column($this->right, 'id');
    }

    /** The English text of a word of the round. */
    public function leftText(string $leftId): ?string
    {
        foreach ($this->left as $card) {
            if ($card['id'] === $leftId) {
                return $card['text'];
            }
        }

        return null;
    }

    /** What a translation card says for a viewer of the locale: his own language, else the first the snapshot has. */
    public function rightText(string $rightId, string $locale): ?string
    {
        foreach ($this->right as $card) {
            if ($card['id'] === $rightId) {
                return self::say($card['text'], $locale);
            }
        }

        return null;
    }

    /**
     * The translation cards for a viewer of the locale, in the order shown.
     *
     * @return list<array{id: string, text: string}>
     */
    public function rightCards(string $locale): array
    {
        return array_map(static fn (array $card): array => ['id' => $card['id'], 'text' => self::say($card['text'], $locale)], $this->right);
    }

    /**
     * The pairs already put together: left id => right id.
     *
     * @return array<string, string>
     */
    public static function matched(ElementState $state): array
    {
        return self::strings($state->extra['matched'] ?? null);
    }

    /**
     * The words that had a wrong match at least once, each once, in the order of the first mistake.
     *
     * @return list<string>
     */
    public static function wrongLeft(ElementState $state): array
    {
        $ids = [];
        $stored = $state->extra['wrong_left'] ?? null;
        foreach (is_array($stored) ? $stored : [] as $id) {
            if (is_string($id) && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    public static function mismatches(ElementState $state): int
    {
        return max(0, (int) ($state->extra['mismatches'] ?? 0));
    }

    /** @param array<string, string> $texts locale => text */
    private static function say(array $texts, string $locale): string
    {
        return $texts[$locale] ?? (array_values($texts)[0] ?? '');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function cards(mixed $value): array
    {
        $cards = [];
        foreach (is_array($value) ? $value : [] as $card) {
            if (is_array($card)) {
                /** @var array<string, mixed> $card */
                $cards[] = $card;
            }
        }

        return $cards;
    }

    /**
     * @return array<string, string>
     */
    private static function strings(mixed $value): array
    {
        $strings = [];
        foreach (is_array($value) ? $value : [] as $key => $item) {
            if (is_string($item)) {
                $strings[(string) $key] = $item;
            }
        }

        return $strings;
    }
}
