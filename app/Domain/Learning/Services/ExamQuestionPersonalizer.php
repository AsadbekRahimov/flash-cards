<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Models\ExamSession;
use App\Models\Learner;

/**
 * Projects the shared, canonical question list stored in
 * `exam_sessions.config.questions` onto a per-learner view:
 *
 *   - the ORDER of questions is a learner-specific permutation;
 *   - the ORDER of options inside every question is a learner-specific permutation;
 *   - `correct_index` is computed server-side for that exact view.
 *
 * Both permutations are derived from an HMAC-SHA256 over (session_id, learner_id)
 * keyed with the session's random `config.order_secret` (server-side only, never
 * serialized into any API/panel output; sessions created before it existed fall
 * back to `app.key`, so rotating `app.key` cannot reshuffle a running exam), so a learner cannot predict a classmate's
 * order, while the mapping is stable for the whole life of the session
 * (re-fetching `?index=i` returns the same word with the same option order).
 *
 * `question_index` everywhere in the exam API / `exam_question_attempts`
 * therefore means "position in THIS learner's sequence". Because the
 * permutation is a bijection, the `(exam_session_id, learner_id, word_id)`
 * uniqueness in `exam_answers` keeps protecting against double answers.
 *
 * Sessions created before per-learner ordering (no `config.question_order`)
 * are served with the identity permutation so in-flight exams finish exactly
 * as they started.
 */
final class ExamQuestionPersonalizer
{
    public const ORDER_PER_LEARNER = 'per_learner';

    public const ORDER_SHARED = 'shared';

    private const HMAC_ALGO = 'sha256';

    /**
     * @return array{
     *   question_index: int,
     *   canonical_index: int,
     *   word_id: int,
     *   word: string,
     *   options: list<string>,
     *   correct_index: int,
     *   correct_translation: string
     * }|null  null when $index is out of range
     */
    public function questionAt(ExamSession $session, Learner $learner, int $index): ?array
    {
        $questions = $this->canonicalQuestions($session);
        $total = count($questions);

        if ($index < 0 || $index >= $total) {
            return null;
        }

        $perLearner = $this->isPerLearner($session);
        $seed = $perLearner ? $this->seed($session, $learner) : null;

        $canonicalIndex = $seed === null
            ? $index
            : $this->permutation($seed, 'q', $total)[$index];

        $question = $questions[$canonicalIndex];
        $canonicalOptions = $question['options'];

        $optionOrder = $seed === null
            ? range(0, count($canonicalOptions) - 1)
            : $this->permutation($seed, "o:{$canonicalIndex}", count($canonicalOptions));

        $options = [];
        foreach ($optionOrder as $canonicalOptionIndex) {
            $options[] = $canonicalOptions[$canonicalOptionIndex];
        }

        $correctIndex = array_search($question['correct_translation'], $options, true);
        if ($correctIndex === false) {
            // Corrupt payload: the correct translation is not among the options.
            // Fall back to the first option so the API never crashes, but keep
            // the view consistent (`options[correct_index]` IS the answer key).
            $correctIndex = 0;
            $options[0] = $question['correct_translation'];
        }

        return [
            'question_index' => $index,
            'canonical_index' => $canonicalIndex,
            'word_id' => $question['word_id'],
            'word' => $question['word'],
            'options' => $options,
            'correct_index' => (int) $correctIndex,
            'correct_translation' => $question['correct_translation'],
        ];
    }

    public function count(ExamSession $session): int
    {
        return count($this->canonicalQuestions($session));
    }

    /**
     * Returns the canonical question indices in the order this learner sees
     * them (position i → canonical index). Exposed for tests and diagnostics.
     *
     * @return list<int>
     */
    public function questionOrder(ExamSession $session, Learner $learner): array
    {
        $total = $this->count($session);
        if ($total === 0) {
            return [];
        }

        if (! $this->isPerLearner($session)) {
            return range(0, $total - 1);
        }

        return $this->permutation($this->seed($session, $learner), 'q', $total);
    }

    public function isPerLearner(ExamSession $session): bool
    {
        $order = $session->config['question_order'] ?? self::ORDER_SHARED;

        return $order === self::ORDER_PER_LEARNER;
    }

    /**
     * Secret, per-(session, learner) seed. Keyed with the session's own random
     * `order_secret` (stable even if `app.key` is rotated mid-exam); legacy
     * sessions without it use the application key. Either way the seed cannot
     * be reconstructed from values visible to a participant.
     */
    private function seed(ExamSession $session, Learner $learner): string
    {
        $secret = $session->config['order_secret'] ?? null;
        if (is_string($secret) && $secret !== '') {
            $key = $secret;
        } else {
            $key = (string) config('app.key');
            if (str_starts_with($key, 'base64:')) {
                $decoded = base64_decode(substr($key, 7), true);
                if ($decoded !== false) {
                    $key = $decoded;
                }
            }
        }

        return hash_hmac(self::HMAC_ALGO, "exam-order|{$session->id}|{$learner->id}", $key, true);
    }

    /**
     * Deterministic permutation of 0..n-1 via keyed sort. Sorting a range by a
     * pseudo-random key is a bijection by construction (ties — practically
     * impossible with 256-bit keys — are broken by the original index).
     *
     * @return list<int>
     */
    private function permutation(string $seed, string $label, int $n): array
    {
        if ($n <= 0) {
            return [];
        }

        $keys = [];
        for ($i = 0; $i < $n; $i++) {
            $keys[$i] = hash_hmac(self::HMAC_ALGO, "{$label}|{$i}", $seed, true);
        }

        $order = range(0, $n - 1);
        usort($order, static fn (int $a, int $b): int => ($keys[$a] <=> $keys[$b]) ?: ($a <=> $b));

        return $order;
    }

    /**
     * Normalises `config.questions` into a strictly typed list. Accepts both
     * the legacy shape (with a stored `correct_index`) and the current shape.
     *
     * @return list<array{word_id:int, word:string, correct_translation:string, options:list<string>}>
     */
    private function canonicalQuestions(ExamSession $session): array
    {
        $raw = $session->config['questions'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $questions = [];
        foreach ($raw as $item) {
            if (! is_array($item) || ! isset($item['word_id'], $item['options']) || ! is_array($item['options'])) {
                continue;
            }

            $options = [];
            foreach ($item['options'] as $option) {
                if (is_string($option)) {
                    $options[] = $option;
                }
            }

            $correct = (string) ($item['correct_translation'] ?? '');
            if ($correct === '' && isset($item['correct_index']) && isset($options[(int) $item['correct_index']])) {
                $correct = $options[(int) $item['correct_index']];
            }

            $questions[] = [
                'word_id' => (int) $item['word_id'],
                'word' => (string) ($item['word'] ?? ''),
                'correct_translation' => $correct,
                'options' => $options,
            ];
        }

        return $questions;
    }
}
