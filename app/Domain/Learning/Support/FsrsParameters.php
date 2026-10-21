<?php

declare(strict_types=1);

namespace App\Domain\Learning\Support;

use InvalidArgumentException;

/**
 * Immutable FSRS-5 parameters (docs/06 §9): the 19 weights and the desired retention.
 * Built once from `config/learning.php` → `fsrs` (AppServiceProvider resolves it at boot, so a bad
 * FSRS_WEIGHTS / FSRS_DESIRED_RETENTION fails `config:cache` at deploy time, like LearningPolicy).
 *
 * Weight source: FSRS-5 defaults of open-spaced-repetition (fsrs-rs `DEFAULT_PARAMETERS` v1.x,
 * ts-fsrs 4.x `default_w`, fsrs4anki wiki "The Algorithm", FSRS-5 section).
 */
final class FsrsParameters
{
    public const WEIGHT_COUNT = 19;

    /** @var list<float> */
    public const DEFAULT_WEIGHTS = [
        0.40255, 1.18385, 3.173, 15.69105, 7.1949, 0.5345, 1.4604, 0.0046, 1.54575, 0.1192,
        1.01925, 1.9395, 0.11, 0.29605, 2.2698, 0.2315, 2.9898, 0.51655, 0.6621,
    ];

    public const DEFAULT_DESIRED_RETENTION = 0.9;

    /** The FSRS-5 forgetting curve R(t, S) = (1 + FACTOR · t / S) ^ DECAY. */
    public const DECAY = -0.5;

    public const FACTOR = 19.0 / 81.0;

    /**
     * @param  list<float>  $weights  exactly 19 values, each finite and >= 0
     * @param  float  $desiredRetention  strictly between 0 and 1
     * @param  int  $rolloutPercent  0..100 — share of new learners bucketed into FSRS
     */
    public function __construct(
        public readonly array $weights,
        public readonly float $desiredRetention = self::DEFAULT_DESIRED_RETENTION,
        public readonly int $rolloutPercent = 0,
    ) {
        if (count($weights) !== self::WEIGHT_COUNT) {
            throw new InvalidArgumentException(sprintf(
                'FSRS needs exactly %d weights, got %d. Fix FSRS_WEIGHTS (or unset it for the defaults).',
                self::WEIGHT_COUNT,
                count($weights),
            ));
        }
        foreach ($weights as $i => $w) {
            if (! is_finite($w) || $w < 0) {
                throw new InvalidArgumentException("FSRS weight w[{$i}] must be a finite number >= 0. Fix FSRS_WEIGHTS.");
            }
        }
        if (! ($desiredRetention > 0.0 && $desiredRetention < 1.0)) {
            throw new InvalidArgumentException('FSRS desired_retention must be strictly between 0 and 1. Fix FSRS_DESIRED_RETENTION.');
        }
        if ($rolloutPercent < 0 || $rolloutPercent > 100) {
            throw new InvalidArgumentException('FSRS rollout_percent must be within 0..100. Fix FSRS_ROLLOUT_PERCENT.');
        }
    }

    /**
     * @param  array<string, mixed>  $config  contents of config('learning.fsrs')
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            weights: self::parseWeights($config['weights'] ?? null),
            desiredRetention: (float) ($config['desired_retention'] ?? self::DEFAULT_DESIRED_RETENTION),
            rolloutPercent: (int) ($config['rollout_percent'] ?? 0),
        );
    }

    public static function defaults(): self
    {
        return new self(self::DEFAULT_WEIGHTS);
    }

    public function w(int $index): float
    {
        return $this->weights[$index];
    }

    /**
     * Accepts "0.4,1.18,…", [0.4, 1.18, …] or null / '' (defaults).
     *
     * @return list<float>
     */
    private static function parseWeights(mixed $raw): array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return self::DEFAULT_WEIGHTS;
        }
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        if (! is_array($raw)) {
            throw new InvalidArgumentException('FSRS weights must be a comma-separated string or an array of numbers.');
        }

        $weights = [];
        foreach ($raw as $value) {
            if (is_string($value)) {
                $value = trim($value);
            }
            if (! is_numeric($value)) {
                throw new InvalidArgumentException('FSRS weights must be numbers. Fix FSRS_WEIGHTS.');
            }
            $weights[] = (float) $value;
        }

        return $weights;
    }
}
