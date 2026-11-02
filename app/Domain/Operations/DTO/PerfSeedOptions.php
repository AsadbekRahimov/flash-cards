<?php

declare(strict_types=1);

namespace App\Domain\Operations\DTO;

use InvalidArgumentException;

/**
 * The volume of a `perf:seed` data set (docs/20 F0, docs/19 C0). Whatever the caller leaves out is derived from the
 * number of learners, so a small run (`--learners=50`) keeps the proportions of the big one.
 */
final readonly class PerfSeedOptions
{
    private function __construct(
        public int $learners,
        public int $words,
        public int $courses,
        /** word_repetitions per learner (the first {@see PerfSeedOptions::HEAVY_LEARNERS} learners get far more). */
        public int $repetitionsPerLearner,
        public int $reviews,
        public int $payers,
        public int $exams,
        public int $organizations,
        public int $membersPerOrganization,
        public int $groups,
        public int $events,
        public int $seed,
    ) {}

    /** The first learners are "power users" with this many repetitions: the measured screens need full pages. */
    public const HEAVY_LEARNERS = 20;

    public const HEAVY_REPETITIONS = 300;

    public static function make(
        int $learners = 200_000,
        int $words = 100_000,
        int $courses = 12,
        int $repetitionsPerLearner = 24,
        ?int $reviews = null,
        ?int $payers = null,
        ?int $exams = null,
        ?int $organizations = null,
        int $membersPerOrganization = 150,
        ?int $groups = null,
        ?int $events = null,
        int $seed = 1,
    ): self {
        $options = new self(
            learners: $learners,
            words: $words,
            courses: $courses,
            repetitionsPerLearner: $repetitionsPerLearner,
            reviews: $reviews ?? $learners * 3,
            payers: $payers ?? max(1, intdiv($learners * 6, 100)),
            exams: $exams ?? max(1, intdiv($learners, 20)),
            organizations: $organizations ?? max(1, intdiv($learners, 2000)),
            membersPerOrganization: $membersPerOrganization,
            groups: $groups ?? max(1, intdiv($learners, 200)),
            events: $events ?? $learners * 3,
            seed: $seed,
        );

        $options->validate();

        return $options;
    }

    private function validate(): void
    {
        foreach ([
            'learners' => $this->learners,
            'courses' => $this->courses,
            'repetitions' => $this->repetitionsPerLearner,
            'members' => $this->membersPerOrganization,
        ] as $name => $value) {
            if ($value < 1) {
                throw new InvalidArgumentException("--{$name} must be at least 1.");
            }
        }

        if ($this->words < $this->courses) {
            throw new InvalidArgumentException('--words must be at least --courses: every course needs a word.');
        }

        foreach ([
            'reviews' => $this->reviews,
            'payers' => $this->payers,
            'exams' => $this->exams,
            'organizations' => $this->organizations,
            'groups' => $this->groups,
            'events' => $this->events,
        ] as $name => $value) {
            if ($value < 0) {
                throw new InvalidArgumentException("--{$name} cannot be negative.");
            }
        }

        if ($this->payers > $this->learners) {
            throw new InvalidArgumentException('--payers cannot exceed --learners.');
        }
    }
}
