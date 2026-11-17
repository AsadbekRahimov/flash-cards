<?php

declare(strict_types=1);

namespace App\Domain\Learners\Services;

use App\Domain\Learners\DTO\ReminderContent;
use App\Domain\Learners\DTO\ReminderQuiz;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * The state of the reminder question a learner got last (docs/12 §7.5, docs/19 §2.2): one key per learner, so tomorrow's
 * question replaces yesterday's. It lives in the default (non-evictable) Redis, not in the pure cache: it is a
 * protection marker (the right option is only here, never in the button), not something that can be recomputed.
 *
 * A plain array of scalars (docs/19 §3: no objects in the cache); the numbers are read back through `(int)`.
 *
 *   token, word_id, exercise (meaning|reverse), word, answer, options (list), correct (index), kind, due_count,
 *   sent_at, expires_at (unix), answered_at (?unix), picked (?index)
 */
final class ReminderQuizStore
{
    public const KEY_PREFIX = 'reminders:quiz:v1:';

    public static function key(int $learnerId): string
    {
        return self::KEY_PREFIX.$learnerId;
    }

    public function ttlSeconds(): int
    {
        return max(1, (int) config('learning.reminders.quiz.ttl_hours', 26)) * 3600;
    }

    public function put(int $learnerId, ReminderQuiz $quiz, ReminderContent $content, CarbonImmutable $now): void
    {
        $ttl = $this->ttlSeconds();

        $this->locked($learnerId, fn () => Cache::put(self::key($learnerId), [
            'token' => $quiz->token,
            'word_id' => $quiz->wordId,
            'exercise' => $quiz->exercise->value,
            'word' => $quiz->word,
            'answer' => $quiz->answer,
            'options' => $quiz->options,
            'correct' => $quiz->correctIndex,
            'kind' => $content->kind->value,
            'due_count' => $content->dueCount,
            'sent_at' => $now->getTimestamp(),
            'expires_at' => $now->getTimestamp() + $ttl,
            'answered_at' => null,
            'picked' => null,
        ], $ttl));
    }

    /**
     * @return array{token: string, word_id: int, exercise: string, word: string, answer: string, options: list<string>, correct: int, kind: string, due_count: int, sent_at: int, expires_at: int, answered_at: int|null, picked: int|null}|null
     */
    public function get(int $learnerId): ?array
    {
        $state = Cache::get(self::key($learnerId));
        if (! is_array($state) || ! is_string($state['token'] ?? null) || ! is_array($state['options'] ?? null)) {
            return null;
        }

        $options = [];
        foreach ($state['options'] as $option) {
            $options[] = (string) $option;
        }

        return [
            'token' => $state['token'],
            'word_id' => (int) ($state['word_id'] ?? 0),
            'exercise' => (string) ($state['exercise'] ?? ''),
            'word' => (string) ($state['word'] ?? ''),
            'answer' => (string) ($state['answer'] ?? ''),
            'options' => $options,
            'correct' => (int) ($state['correct'] ?? -1),
            'kind' => (string) ($state['kind'] ?? ''),
            'due_count' => (int) ($state['due_count'] ?? 0),
            'sent_at' => (int) ($state['sent_at'] ?? 0),
            'expires_at' => (int) ($state['expires_at'] ?? 0),
            'answered_at' => isset($state['answered_at']) ? (int) $state['answered_at'] : null,
            'picked' => isset($state['picked']) ? (int) $state['picked'] : null,
        ];
    }

    /**
     * Records the pick, keeping the original expiry (the answer does not stretch the life of the question). Compare-and-write under
     * the state lock that {@see put()} takes too: the state is read again and written only when it is still the question of
     * `$state` (the same token) - a newer question that the sender stored meanwhile is never overwritten by the old one.
     *
     * @param  array<string, mixed>  $state  what {@see get()} returned
     * @return bool false when nothing was written: the question is gone, expired or has been replaced
     */
    public function markAnswered(int $learnerId, array $state, int $picked, CarbonImmutable $now): bool
    {
        return $this->locked($learnerId, function () use ($learnerId, $state, $picked, $now): bool {
            $current = $this->get($learnerId);
            if ($current === null || ! hash_equals($current['token'], (string) ($state['token'] ?? ''))) {
                return false;
            }

            $remaining = $current['expires_at'] - $now->getTimestamp();
            if ($remaining <= 0) {
                return false;
            }

            Cache::put(self::key($learnerId), [...$current, 'answered_at' => $now->getTimestamp(), 'picked' => $picked], $remaining);

            return true;
        });
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     *
     * @throws LockTimeoutException
     */
    private function locked(int $learnerId, callable $callback): mixed
    {
        return Cache::lock("reminders:quiz:state:{$learnerId}", 5)->block(3, $callback);
    }

    public function forget(int $learnerId): void
    {
        Cache::forget(self::key($learnerId));
    }
}
