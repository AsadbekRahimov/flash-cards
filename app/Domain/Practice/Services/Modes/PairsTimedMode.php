<?php

declare(strict_types=1);

namespace App\Domain\Practice\Services\Modes;

use App\Domain\Practice\DTO\ElementState;
use App\Domain\Practice\DTO\GradeResult;
use App\Domain\Practice\DTO\LocaleSet;
use App\Domain\Practice\DTO\MaterialScope;
use App\Domain\Practice\DTO\MaterialWord;
use App\Domain\Practice\DTO\ModeAvailability;
use App\Domain\Practice\DTO\PairsRound;
use App\Domain\Practice\DTO\Snapshot;
use App\Domain\Practice\DTO\SnapshotElement;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Domain\Practice\Services\Grading\PairsGrader;
use App\Domain\Practice\Services\PracticeMaterialService;
use App\Domain\Practice\Support\PairsPacker;
use App\Domain\Practice\Support\SeededShuffle;
use App\Models\Learner;

/**
 * "Pairs against the clock" (docs/15 §3.1, docs/16 §4.3a, §5.5a): the same board as {@see PairsMode} (one match at a time, the
 * right table never leaves the server), played for `practice.solo.pairs_timed.seconds` with a score; the clock, the score and the
 * penalty are the service's (`PracticeSessionService`), this handler only builds, projects and grades the rounds.
 *
 * MATERIAL: the words the learner has been introduced to (as for `pairs`), fit when they have an English text and a variant of the
 * translation in every locale. At least `min_words` of them (and one round of `per_round`), else `no_words`; enough words that
 * cannot stand together in a round, `ambiguous`. A game on ONE lesson that is short of them (the step 3 of the day plan, docs/31 §5б)
 * is TOPPED UP with words of the same course the learner has learned ({@see PracticeMaterialService::learnedCourseWordsOutsideLesson()}),
 * chosen by the seed of the session, to `min_words` rounded up to whole rounds: every fit word of the lesson stays in, the others
 * only fill the board. Only words fit by the same rule fill it, and how many are found does not depend on the seed: the availability
 * (under its own seed) and the game (under the session's) say the same. A lesson without a single fit word is not topped up
 * (`no_words`): the game would not be on the lesson. Only when a game is OPENED: the counting scope of the hub reports the lesson's
 * own words, as before (its budget of statements is pinned).
 *
 * ROUNDS: `rounds` rounds of exactly `per_round` words. Each cycle lays all fit words out under its own seed (`{seed}:cycle:{k}`,
 * the first cycle under the seed itself) with {@see PairsPacker} - no two words that clash in a round - and the cycles go on, over
 * the same words, until the rounds are all there. So with few words a word comes back in later rounds, never twice in one round.
 *
 * @phpstan-import-type Candidate from PairsMode
 */
final class PairsTimedMode implements PracticeModeHandler
{
    private const AVAILABILITY_SEED = 'practice.pairs_timed.availability';

    /** What the snapshot keeps besides the rounds (the titles, the locales), and the share of the limit the rounds may take: a quarter is spared. */
    private const OVERHEAD_BYTES = 2048;

    private const BUDGET_BYTES = (int) (Snapshot::MAX_BYTES / 1.25);

    public function __construct(
        private readonly PracticeMaterialService $material,
        private readonly PairsGrader $grader,
    ) {}

    public function mode(): PracticeMode
    {
        return PracticeMode::PairsTimed;
    }

    public function availability(MaterialScope $scope, ?Learner $learner, LocaleSet $locales): ModeAvailability
    {
        return $this->plan($scope, $learner, $locales, self::AVAILABILITY_SEED, self::rounds(), self::AVAILABILITY_SEED)['availability'];
    }

    public function build(MaterialScope $scope, ?Learner $learner, LocaleSet $locales, string $seed, int $size, bool $strictSize): array
    {
        $plan = $this->plan($scope, $learner, $locales, $seed, max(1, $size), $seed);
        if (! $plan['availability']->isAvailable) {
            throw PracticeException::notEnoughMaterial($this->mode(), $plan['availability'], $scope->course->slug, $scope->lesson?->id);
        }

        $total = count($plan['rounds']);
        $taken = [];
        $elements = [];
        $bytes = self::OVERHEAD_BYTES;
        foreach ($plan['rounds'] as $index => $members) {
            $candidates = array_map(static fn (int $member): array => $plan['words'][$member], $members);
            $element = PairsMode::roundElement($candidates, $plan['seeds'][$index], $index + 1, $total, false, $taken);

            // Ten rounds of words at the limits of their columns (100 and 500 characters) would not fit a snapshot: the game is then
            // shorter - never one that cannot be opened. Ordinary words (a few KB for all ten rounds) never come near this.
            $bytes += strlen((string) json_encode($element->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if ($elements !== [] && $bytes > self::BUDGET_BYTES) {
                break;
            }
            $elements[] = $element;
        }

        return self::renumbered($elements);
    }

    public function project(SnapshotElement $element, string $locale, ElementState $state): array
    {
        return PairsMode::projectRound($element, $locale, $state);
    }

    public function grade(SnapshotElement $element, array $answer, ElementState $state): GradeResult
    {
        return $this->grader->grade($element, $answer, $state);
    }

    /**
     * @return array{pairs: array<string, string>, translation: null, explanation: null}
     */
    public function reveal(SnapshotElement $element, string $locale, ElementState $state): array
    {
        return ['pairs' => PairsRound::of($element)->table, 'translation' => null, 'explanation' => null];
    }

    /** No list of mistakes and no retry: a timed game is played again from the start. */
    public function mistakeLabel(SnapshotElement $element, ElementState $state, string $locale): array
    {
        return [];
    }

    /**
     * The rounds that were kept, told how many there are (`round` / `rounds` of the board are the numbers the client shows).
     *
     * @param  list<SnapshotElement>  $elements
     * @return list<SnapshotElement>
     */
    private static function renumbered(array $elements): array
    {
        $total = count($elements);

        return array_map(static function (SnapshotElement $element) use ($total): SnapshotElement {
            return new SnapshotElement($element->id, $element->kind, [...$element->data, 'rounds' => $total]);
        }, $elements);
    }

    /**
     * @return array{words: list<Candidate>, rounds: list<list<int>>, seeds: list<string>, availability: ModeAvailability}
     */
    private function plan(MaterialScope $scope, ?Learner $learner, LocaleSet $locales, string $seed, int $wanted, string $topUpSeed): array
    {
        $words = $this->toppedUp($scope, $learner, $locales, $this->words($scope, $learner, $locales), $topUpSeed);
        $count = count($words);
        $perRound = self::perRound();
        $required = max(self::minWords(), $perRound);

        if ($count < $required) {
            return ['words' => $words, 'rounds' => [], 'seeds' => [], 'availability' => ModeAvailability::notEnough($count, $required, ModeAvailability::REASON_NO_WORDS)];
        }

        $keys = array_column($words, 'keys');
        $rounds = [];
        $seeds = [];
        $largest = 0;
        // A cycle that makes no round makes none under another seed either, in practice; the bound keeps the loop finite regardless.
        for ($cycle = 0; count($rounds) < $wanted && $cycle < $wanted * 2; $cycle++) {
            $cycleSeed = $cycle === 0 ? $seed : "{$seed}:cycle:{$cycle}";
            $places = min(intdiv($count, $perRound), $wanted - count($rounds));
            $packed = PairsPacker::pack($keys, array_fill(0, $places, $perRound), $perRound, $cycleSeed);
            $largest = max($largest, $packed['largest']);

            if ($packed['rounds'] === []) {
                if ($cycle === 0) {
                    break;
                }

                continue;
            }
            foreach ($packed['rounds'] as $round) {
                $rounds[] = $round;
                $seeds[] = $cycleSeed;
            }
        }

        if ($rounds === []) {
            return ['words' => $words, 'rounds' => [], 'seeds' => [], 'availability' => ModeAvailability::notEnough($largest, $perRound, ModeAvailability::REASON_AMBIGUOUS)];
        }

        return ['words' => $words, 'rounds' => $rounds, 'seeds' => $seeds, 'availability' => ModeAvailability::enough($count, $required)];
    }

    /**
     * The words that can be played, in the order of their ids (the scope remembers them: the hub reads them once for `pairs` and this).
     *
     * @return list<Candidate>
     */
    private function words(MaterialScope $scope, ?Learner $learner, LocaleSet $locales): array
    {
        return $scope->remember('pairs_timed:words:'.($learner->id ?? 0).':'.implode(',', $locales->locales), function () use ($scope, $learner, $locales): array {
            $material = $learner === null
                ? $this->material->allWords($scope, $locales)
                : $this->material->introducedWords($scope, $learner, $locales);

            $fit = [];
            foreach ($material as $word) {
                $candidate = PairsMode::candidate($word, $locales);
                if ($candidate !== null) {
                    $fit[] = $candidate;
                }
            }

            return $fit;
        });
    }

    /**
     * The words of a lesson short of `min_words` with learned words of the course after them (see the class doc); any other scope as it is.
     *
     * @param  list<Candidate>  $words
     * @return list<Candidate>
     */
    private function toppedUp(MaterialScope $scope, ?Learner $learner, LocaleSet $locales, array $words, string $seed): array
    {
        $perRound = self::perRound();
        $target = (int) (ceil(max(self::minWords(), $perRound) / $perRound) * $perRound);
        // Only a scope a game is opened from: the counts of the hub (`forCount`) stay as they were - no statement more on the hub, whose
        // budget is pinned (docs/16 §13); the step 3 of the day plan opens the game directly (`source = deeplink`), not through the hub.
        if ($scope->countOnly || $scope->lesson === null || $learner === null || $words === [] || count($words) >= $target) {
            return $words;
        }

        $key = 'pairs_timed:topup:'.$learner->id.':'.implode(',', $locales->locales).':'.bin2hex(hash('sha256', $seed, true));

        return $scope->remember($key, function () use ($scope, $learner, $locales, $words, $seed, $target): array {
            $want = $target - count($words);
            $taken = array_flip(array_map(static fn (array $c): int => $c['word']->id, $words));

            // The material reads on past its sample until `$want` words the board can use are found: the number of them does not
            // depend on the seed, so the availability (one seed) and the game (the seed of the session) agree.
            $usable = static fn (MaterialWord $word): bool => ! isset($taken[$word->id]) && PairsMode::candidate($word, $locales) !== null;

            $extra = [];
            foreach ($this->material->learnedCourseWordsOutsideLesson($scope, $learner, $locales, $seed, $want, $usable) as $word) {
                $candidate = PairsMode::candidate($word, $locales);
                if ($candidate !== null) {
                    $extra[] = $candidate;
                }
            }

            return [...$words, ...array_slice(SeededShuffle::apply($extra, $seed, 'practice:topup:order'), 0, $want)];
        });
    }

    private static function rounds(): int
    {
        return max(1, (int) config('practice.solo.pairs_timed.rounds'));
    }

    private static function perRound(): int
    {
        return max(2, (int) config('practice.solo.pairs_timed.per_round'));
    }

    private static function minWords(): int
    {
        return max(1, (int) config('practice.solo.pairs_timed.min_words'));
    }
}
