<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Support;

/**
 * What the retention report adds up for one registration week while it reads the rows of the two aggregate queries: the
 * totals of the cohort, how many whole days its youngest learner has lived through (`elapsed`, the maturity of a cell), the
 * same sums per segment (`locale:ru`, `trial:yes`, ...) for the language / trial slice, and the activation in 24 hours by its
 * cuts (`all`, `ru`, `uz`, `solo`, `class`) with the number of learners whose 24 hours have not passed yet (`young`).
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

    /** Learners registered less than 24 hours ago: while there is one, the week's activation is not known yet. */
    public int $young = 0;

    /** @var array<string, array{count: int, of: int}> activated learners and the base, by cut ("all", "ru", "uz", "solo", "class") */
    public array $activation = [];

    /** @param  list<string>  $keys */
    public function __construct(array $keys)
    {
        $this->hits = array_fill_keys($keys, 0);
    }

    public function addCohort(
        string $locale,
        int $trial,
        int $size,
        int $onboarded,
        int $started,
        ?int $elapsed,
        int $class = 0,
        int $activated = 0,
        int $young = 0,
    ): void {
        $this->size += $size;
        $this->onboarded += $onboarded;
        $this->started += $started;
        $this->young += $young;

        // A language other than ru / uz is only in "all"; a learner is "class" or "solo", never both.
        $cuts = ['all', $class === 1 ? 'class' : 'solo'];
        if (in_array($locale, ['ru', 'uz'], true)) {
            $cuts[] = $locale;
        }
        foreach ($cuts as $cut) {
            $this->activation[$cut] = [
                'count' => ($this->activation[$cut]['count'] ?? 0) + $activated,
                'of' => ($this->activation[$cut]['of'] ?? 0) + $size,
            ];
        }

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
