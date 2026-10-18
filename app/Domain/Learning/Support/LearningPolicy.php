<?php

declare(strict_types=1);

namespace App\Domain\Learning\Support;

use InvalidArgumentException;

/**
 * Immutable scheduling parameters shared by the SRS engine, the card picker
 * and the review submission guard. Built once from `config/learning.php`
 * (see AppServiceProvider) so every component agrees on the same steps and
 * learn-ahead window.
 */
final class LearningPolicy
{
    /**
     * @param  list<int>  $learningStepsMinutes  non-empty, each >= 1
     * @param  list<int>  $relearningStepsMinutes  non-empty, each >= 1
     */
    public function __construct(
        public readonly array $learningStepsMinutes,
        public readonly array $relearningStepsMinutes,
        public readonly int $graduatingIntervalDays,
        public readonly int $easyIntervalDays,
        public readonly int $learnAheadMinutes,
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
        );
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
