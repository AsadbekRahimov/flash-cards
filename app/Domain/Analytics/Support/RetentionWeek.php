<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Support;

/**
 * What the retention report adds up for one registration week while it reads the rows of the two aggregate queries: the
 * totals of the cohort, how many whole days its youngest learner has lived through (`elapsed`, the maturity of a cell), and
 * the same sums per segment (`locale:ru`, `trial:yes`, ...) for the language / trial slice.
 */
final class RetentionWeek
{
    public int $size = 0;

    public int $onboarded = 0;

    public int $started = 0;

    /** Whole local days since registration of the youngest learner of the week; null while no row came. */
    public ?int $elapsed = null;

    /** @var array<string, int> learners that studied, by "d1" ... "within" */
    public array $hits;

    /** @var array<string, int> learners of the week by segment */
    public array $segmentSizes = [];

    /** @var array<string, array<string, int>> learners that studied, by segment and "d1" ... "within" */
    public array $segmentHits = [];

    /** @param  list<string>  $keys */
    public function __construct(array $keys)
    {
        $this->hits = array_fill_keys($keys, 0);
    }

    public function addCohort(string $locale, int $trial, int $size, int $onboarded, int $started, ?int $elapsed): void
    {
        $this->size += $size;
        $this->onboarded += $onboarded;
        $this->started += $started;

        if ($elapsed !== null) {
            $this->elapsed = min($this->elapsed ?? $elapsed, $elapsed);
        }

        foreach (self::segments($locale, $trial) as $segment) {
            $this->segmentSizes[$segment] = ($this->segmentSizes[$segment] ?? 0) + $size;
        }
    }

    /** @param  array<string, int>  $hits */
    public function addRetention(string $locale, int $trial, array $hits): void
    {
        foreach ($hits as $key => $count) {
            $this->hits[$key] = ($this->hits[$key] ?? 0) + $count;

            foreach (self::segments($locale, $trial) as $segment) {
                $this->segmentHits[$segment][$key] = ($this->segmentHits[$segment][$key] ?? 0) + $count;
            }
        }
    }

    /** @return list<string> */
    private static function segments(string $locale, int $trial): array
    {
        return ['locale:'.$locale, 'trial:'.($trial === 1 ? 'yes' : 'no')];
    }
}
