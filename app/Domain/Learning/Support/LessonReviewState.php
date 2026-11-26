<?php

declare(strict_types=1);

namespace App\Domain\Learning\Support;

use App\Domain\Learning\Services\LessonReviewService;
use App\Models\TrainingSession;

/**
 * The state of a review of a lesson (`lesson_review`, docs/06 §15) as it lives in `training_sessions.meta` - the only place it is
 * written ({@see LessonReviewService}, always under `training_sessions FOR UPDATE`):
 *
 *   {
 *     "lesson_words": [41, 42, 43],     the set, fixed when the round was opened (study order: by the word's id)
 *     "tries":        [1, 2, 0],        serves answered, per position of the set
 *     "won":          [2, 1, 0],        0 - not answered right yet, 1 - answered right on a later serve, 2 - right on the first serve
 *     "answers": 3, "correct": 2,       every accepted answer and the right ones among them (the summary)
 *     "serve": {"pos": 2, "word_id": 43, "exercise": "meaning", "token": "…"} | null    the card on screen, until it is answered
 *   }
 *
 * Positions, not word ids, key the lists: a JSON object keyed by ids would come back as a list for some id sets.
 */
final class LessonReviewState
{
    public const META_WORDS = 'lesson_words';

    public const WON_NOT_YET = 0;

    public const WON_LATER = 1;

    public const WON_FIRST_TRY = 2;

    /**
     * @param  list<int>  $words
     * @param  array<int, int>  $tries  by position
     * @param  array<int, int>  $won  by position
     * @param  array{pos: int, word_id: int, exercise: string, token: string}|null  $serve
     */
    private function __construct(
        public readonly array $words,
        private array $tries,
        private array $won,
        private int $answers,
        private int $correct,
        private ?array $serve,
    ) {}

    /** @param  list<int>  $words */
    public static function fresh(array $words): self
    {
        $words = array_values(array_unique(array_map('intval', $words)));
        $zeros = array_fill(0, count($words), 0);

        return new self($words, $zeros, $zeros, 0, 0, null);
    }

    public static function of(TrainingSession $session): self
    {
        $meta = is_array($session->meta) ? $session->meta : [];
        $raw = $meta[self::META_WORDS] ?? [];
        $words = [];
        foreach (is_array($raw) ? $raw : [] as $id) {
            if (is_numeric($id)) {
                $words[] = (int) $id;
            }
        }

        $n = count($words);
        $list = static function (mixed $values) use ($n): array {
            $out = array_fill(0, $n, 0);
            if (is_array($values)) {
                foreach (array_values($values) as $i => $value) {
                    if ($i < $n && is_numeric($value)) {
                        $out[$i] = max(0, (int) $value);
                    }
                }
            }

            return $out;
        };

        $serve = null;
        $rawServe = $meta['serve'] ?? null;
        if (is_array($rawServe) && is_numeric($rawServe['pos'] ?? null) && is_numeric($rawServe['word_id'] ?? null)
            && is_string($rawServe['exercise'] ?? null) && is_string($rawServe['token'] ?? null)) {
            $pos = (int) $rawServe['pos'];
            if ($pos >= 0 && $pos < $n && $words[$pos] === (int) $rawServe['word_id']) {
                $serve = ['pos' => $pos, 'word_id' => (int) $rawServe['word_id'], 'exercise' => $rawServe['exercise'], 'token' => $rawServe['token']];
            }
        }

        return new self(
            $words,
            $list($meta['tries'] ?? null),
            array_map(static fn (int $w): int => min($w, self::WON_FIRST_TRY), $list($meta['won'] ?? null)),
            is_numeric($meta['answers'] ?? null) ? max(0, (int) $meta['answers']) : 0,
            is_numeric($meta['correct'] ?? null) ? max(0, (int) $meta['correct']) : 0,
            $serve,
        );
    }

    /** @return array{lesson_words: list<int>, tries: list<int>, won: list<int>, answers: int, correct: int, serve: array{pos: int, word_id: int, exercise: string, token: string}|null} */
    public function toMeta(): array
    {
        return [
            self::META_WORDS => $this->words,
            'tries' => array_values($this->tries),
            'won' => array_values($this->won),
            'answers' => $this->answers,
            'correct' => $this->correct,
            'serve' => $this->serve,
        ];
    }

    public function total(): int
    {
        return count($this->words);
    }

    public function answers(): int
    {
        return $this->answers;
    }

    public function correct(): int
    {
        return $this->correct;
    }

    /** Words answered right (on any serve). */
    public function wonCount(): int
    {
        return count(array_filter($this->won, static fn (int $w): bool => $w !== self::WON_NOT_YET));
    }

    /** Words answered right on their first serve. */
    public function firstTryCount(): int
    {
        return count(array_filter($this->won, static fn (int $w): bool => $w === self::WON_FIRST_TRY));
    }

    public function triesAt(int $pos): int
    {
        return $this->tries[$pos] ?? 0;
    }

    /** @return array{pos: int, word_id: int, exercise: string, token: string}|null */
    public function serve(): ?array
    {
        return $this->serve;
    }

    /**
     * The position to ask next: a word not answered right yet with serves left, the fewest serves first, then the order of the set -
     * a word answered wrong comes back after the others (the mockup: "the word will come back once more"). Null: nothing is left.
     */
    public function next(int $maxServes): ?int
    {
        $best = null;
        foreach ($this->words as $pos => $_) {
            if ($this->won[$pos] !== self::WON_NOT_YET || $this->tries[$pos] >= $maxServes) {
                continue;
            }
            if ($best === null || $this->tries[$pos] < $this->tries[$best]) {
                $best = $pos;
            }
        }

        return $best;
    }

    /** A word that can no longer be served (withdrawn from the course meanwhile): it uses up its serves, nothing is counted. */
    public function exhaust(int $pos, int $maxServes): void
    {
        $this->tries[$pos] = max($this->tries[$pos], $maxServes);
        if ($this->serve !== null && $this->serve['pos'] === $pos) {
            $this->serve = null;
        }
    }

    public function startServe(int $pos, string $exercise, string $token): void
    {
        $this->serve = ['pos' => $pos, 'word_id' => $this->words[$pos], 'exercise' => $exercise, 'token' => $token];
    }

    /** The answer to the card on screen: counted, and the serve is spent (a replay of the same request finds no serve). */
    public function record(bool $right): void
    {
        if ($this->serve === null) {
            return;
        }
        $pos = $this->serve['pos'];
        $firstServe = $this->tries[$pos] === 0;
        $this->tries[$pos]++;
        $this->answers++;
        if ($right) {
            $this->correct++;
            $this->won[$pos] = $firstServe ? self::WON_FIRST_TRY : self::WON_LATER;
        }
        $this->serve = null;
    }
}
