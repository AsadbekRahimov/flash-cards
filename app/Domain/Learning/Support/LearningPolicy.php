<?php

declare(strict_types=1);

namespace App\Domain\Learning\Support;

use InvalidArgumentException;

/**
 * Immutable scheduling parameters shared by the SRS engine, the card picker
 * and the review submission guard. Built once from `config/learning.php`
 * (see AppServiceProvider) so every component agrees on the same steps and
 * learn-ahead window.
 *
 * The constructor is strict: an inconsistent policy (e.g. a learn-ahead
 * window shorter than the longest step) would silently cut sessions short,
 * so it throws. AppServiceProvider resolves the singleton at boot, which
 * turns a bad env into a failed `config:cache` / health check at deploy time
 * instead of a 500 on the first learner request.
 */
final class LearningPolicy
{
    public const DEFAULT_ABANDONED_AFTER_HOURS = 12;

    public const DEFAULT_MAX_INTERVAL_DAYS = 3650;

    /** `word_repetitions.interval_days` is a smallint. */
    public const MAX_INTERVAL_DAYS_LIMIT = 32767;

    /**
     * @param  list<int>  $learningStepsMinutes  non-empty, each >= 1
     * @param  list<int>  $relearningStepsMinutes  non-empty, each >= 1
     * @param  int  $learnAheadMinutes  0 disables learn-ahead; otherwise >= max step
     * @param  int  $abandonedAfterHours  >= 1 and longer than the max step; see `config/learning.php`
     * @param  int  $maxIntervalDays  1..32767 (smallint column); ceiling for every scheduled interval
     */
    public function __construct(
        public readonly array $learningStepsMinutes,
        public readonly array $relearningStepsMinutes,
        public readonly int $graduatingIntervalDays,
        public readonly int $easyIntervalDays,
        public readonly int $learnAheadMinutes,
        public readonly int $abandonedAfterHours = self::DEFAULT_ABANDONED_AFTER_HOURS,
        public readonly int $maxIntervalDays = self::DEFAULT_MAX_INTERVAL_DAYS,
    ) {
        self::assertSteps($learningStepsMinutes, 'learning_steps');
        self::assertSteps($relearningStepsMinutes, 'relearning_steps');

        if ($graduatingIntervalDays < 1) {
            throw new InvalidArgumentException('graduating_interval_days must be >= 1');
        }
        if ($easyIntervalDays < 1) {
            throw new InvalidArgumentException('easy_interval_days must be >= 1');
        }
        if ($learnAheadMinutes < 0) {
            throw new InvalidArgumentException('learn_ahead_minutes must be >= 0');
        }

        $maxStep = $this->maxStepMinutes();
        if ($learnAheadMinutes > 0 && $learnAheadMinutes < $maxStep) {
            throw new InvalidArgumentException(sprintf(
                'learn_ahead_minutes (%d) must be 0 (disabled) or >= the largest learning/relearning step (%d min); '
                .'otherwise sessions end with cards still waiting for their step. '
                .'Fix LEARNING_LEARN_AHEAD_MINUTES / LEARNING_STEPS / RELEARNING_STEPS.',
                $learnAheadMinutes,
                $maxStep,
            ));
        }

        if ($abandonedAfterHours < 1) {
            throw new InvalidArgumentException('abandoned_after_hours must be >= 1');
        }
        if ($abandonedAfterHours * 60 <= $maxStep) {
            throw new InvalidArgumentException(sprintf(
                'abandoned_after_hours (%d h) must be longer than the largest learning/relearning step (%d min).',
                $abandonedAfterHours,
                $maxStep,
            ));
        }

        if ($maxIntervalDays < 1 || $maxIntervalDays > self::MAX_INTERVAL_DAYS_LIMIT) {
            throw new InvalidArgumentException(sprintf(
                'max_interval_days (%d) must be within 1..%d. Fix LEARNING_MAX_INTERVAL_DAYS.',
                $maxIntervalDays,
                self::MAX_INTERVAL_DAYS_LIMIT,
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $config  contents of config('learning')
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            learningStepsMinutes: self::parseSteps($config['learning_steps'] ?? '1,10'),
            relearningStepsMinutes: self::parseSteps($config['relearning_steps'] ?? '10'),
            graduatingIntervalDays: (int) ($config['graduating_interval_days'] ?? 1),
            easyIntervalDays: (int) ($config['easy_interval_days'] ?? 4),
            learnAheadMinutes: (int) ($config['learn_ahead_minutes'] ?? 20),
            abandonedAfterHours: (int) ($config['abandoned_after_hours'] ?? self::DEFAULT_ABANDONED_AFTER_HOURS),
            maxIntervalDays: (int) ($config['max_interval_days'] ?? self::DEFAULT_MAX_INTERVAL_DAYS),
        );
    }

    /** Longest minute step across learning and relearning. */
    public function maxStepMinutes(): int
    {
        // Both lists are non-empty (assertSteps); the literal 0 only keeps the
        // array provably non-empty for static analysis.
        return max([...$this->learningStepsMinutes, ...$this->relearningStepsMinutes, 0]);
    }

    /**
     * Accepts "1,10", [1, 10] or ['1', '10'].
     *
     * @return list<int>
     */
    private static function parseSteps(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }

        if (! is_array($raw)) {
            throw new InvalidArgumentException('Learning steps must be a comma-separated string or an array of minutes.');
        }

        $steps = [];
        foreach ($raw as $value) {
            if (is_string($value)) {
                $value = trim($value);
                if ($value === '') {
                    continue;
                }
            }
            if (! is_numeric($value)) {
                throw new InvalidArgumentException('Learning steps must be integers (minutes).');
            }
            $steps[] = (int) $value;
        }

        return $steps;
    }

    /**
     * @param  list<int>  $steps
     */
    private static function assertSteps(array $steps, string $name): void
    {
        if ($steps === []) {
            throw new InvalidArgumentException("{$name} must contain at least one step");
        }
        foreach ($steps as $minutes) {
            if ($minutes < 1) {
                throw new InvalidArgumentException("{$name} values must be >= 1 minute");
            }
        }
    }
}
