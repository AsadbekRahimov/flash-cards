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
use App\Domain\Practice\DTO\SnapshotElement;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Domain\Practice\Services\Grading\PairsGrader;
use App\Domain\Practice\Services\PracticeMaterialService;
use App\Domain\Practice\Support\OpaqueId;
use App\Domain\Practice\Support\PairsPacker;
use App\Domain\Practice\Support\SeededShuffle;
use App\Domain\Practice\Support\TranslationVariants;
use App\Models\Learner;

/**
 * "Find the pairs" (docs/15 §3.1, docs/16 §4.3, §4.4, §5.5): the words of the learner in two columns, the English ones and their
 * translations, matched one pair at a time (solo) or as one table (battle).
 *
 * MATERIAL: the active words of the course / lesson the learner has been introduced to (a battle: all the active words), with the
 * translation each locale of the {@see LocaleSet} shows after the fallback to ru - read through `PracticeMaterialService`, so the
 * hub, the opening of a session and the build cost the queries of one. A word is FIT when it has an English text and a variant of
 * the translation in every locale ({@see TranslationVariants}); the others are left out.
 *
 * ROUNDS: solo - up to 3 rounds of 3-4 words by the formula of §4.3; a battle - exactly 5 rounds of 4 (`$size` rounds), no less,
 * or the format is refused. The words that clash (the same English text, or a shared variant of the translation in ANY locale of the
 * set, after the fallback) never stand in one round - {@see PairsPacker} - and a round that ends with fewer than 3 words is dropped.
 * The hub has no seed, so it lays the rounds out under a seed of its own: the same material gives the same answer there.
 *
 * Why the material may fall short: `no_words` - fewer fit words than the minimum (3; a battle 20); `ambiguous` - the words are
 * enough in number but so many of them clash that no round (a battle: not all 5 rounds) can be made. `available` then counts what
 * can stand together.
 *
 * SNAPSHOT: a round is an element `pairs_round` ({@see PairsRound}): the words (`left`) and the translations (`right`) each shuffled
 * on its own - never row against row - and the table `pairs`; the translation card carries its text in EVERY locale of the snapshot,
 * the viewer sees his own. The element of a battle is marked, and then only a whole table is an answer.
 *
 * @phpstan-type Candidate array{word: MaterialWord, text: string, labels: array<string, string>, keys: list<string>}
 */
final class PairsMode implements PracticeModeHandler
{
    /** The hub and the options of a battle have no seed; any fixed one will do for laying the rounds out. */
    private const AVAILABILITY_SEED = 'practice.pairs.availability';

    public function __construct(
        private readonly PracticeMaterialService $material,
        private readonly PairsGrader $grader,
    ) {}

    public function mode(): PracticeMode
    {
        return PracticeMode::Pairs;
    }

    public function availability(MaterialScope $scope, ?Learner $learner, LocaleSet $locales): ModeAvailability
    {
        $battle = $learner === null;
        $rounds = $battle ? self::battleRounds() : self::soloRounds();

        return $this->plan($scope, $learner, $locales, self::AVAILABILITY_SEED, $rounds, $battle)['availability'];
    }

    public function build(MaterialScope $scope, ?Learner $learner, LocaleSet $locales, string $seed, int $size, bool $strictSize): array
    {
        $plan = $this->plan($scope, $learner, $locales, $seed, max(1, $size), $strictSize);
        if (! $plan['availability']->isAvailable) {
            throw PracticeException::notEnoughMaterial($this->mode(), $plan['availability'], $scope->course->slug, $scope->lesson?->id);
        }

        $total = count($plan['rounds']);
        $taken = [];
        $elements = [];
        foreach ($plan['rounds'] as $index => $members) {
            $number = $index + 1;
            $elementId = OpaqueId::make('e', $taken);
            $taken[] = $elementId;

            $left = [];
            $right = [];
            $table = [];
            foreach ($members as $member) {
                $candidate = $plan['words'][$member];
                $leftId = OpaqueId::make('l', $taken);
                $taken[] = $leftId;
                $rightId = OpaqueId::make('r', $taken);
                $taken[] = $rightId;

                $left[] = ['id' => $leftId, 'word_id' => $candidate['word']->id, 'text' => $candidate['text']];
                $right[] = ['id' => $rightId, 'text' => $candidate['labels']];
                $table[$leftId] = $rightId;
            }

            [$left, $right] = self::columns($left, $right, $table, $seed, $number);
            $round = new PairsRound($number, $total, $left, $right, $table, $strictSize);
            $elements[] = new SnapshotElement($elementId, SnapshotElement::KIND_PAIRS_ROUND, $round->toData());
        }

        return $elements;
    }

    public function project(SnapshotElement $element, string $locale, ElementState $state): array
    {
        $round = PairsRound::of($element);
        $matched = PairsRound::matched($state);

        // In the order of the words on the screen, whatever order the state was stored in.
        $left = [];
        $done = [];
        foreach ($round->left as $card) {
            $left[] = ['id' => $card['id'], 'text' => $card['text']];
            if (isset($matched[$card['id']])) {
                $done[] = [$card['id'], $matched[$card['id']]];
            }
        }

        return [
            'id' => $element->id,
            'kind' => $element->kind,
            'round' => $round->number,
            'rounds' => $round->rounds,
            'left' => $left,
            'right' => $round->rightCards($locale),
            'matched' => $done,
            'mismatches' => PairsRound::mismatches($state),
        ];
    }

    public function grade(SnapshotElement $element, array $answer, ElementState $state): GradeResult
    {
        return $this->grader->grade($element, $answer, $state);
    }

    public function mistakeLabel(SnapshotElement $element, ElementState $state, string $locale): array
    {
        $round = PairsRound::of($element);

        $rows = [];
        foreach (PairsRound::wrongLeft($state) as $leftId) {
            $word = $round->leftText($leftId);
            $translation = $round->rightText($round->table[$leftId] ?? '', $locale);
            if ($word !== null && $translation !== null) {
                $rows[] = ['label' => "{$word} — {$translation}", 'your_answer' => null];
            }
        }

        return $rows;
    }

    /**
     * The fit words, how they fall into rounds under the seed, and what the material comes to.
     *
     * @return array{words: list<Candidate>, rounds: list<list<int>>, availability: ModeAvailability}
     */
    private function plan(MaterialScope $scope, ?Learner $learner, LocaleSet $locales, string $seed, int $size, bool $strict): array
    {
        $words = $this->words($scope, $learner, $locales);
        $count = count($words);
        $perRound = $strict ? self::battlePerRound() : self::soloPerRound();
        $minimum = self::minPerRound();
        $required = $strict ? $size * $perRound : $minimum;

        if ($count < $required) {
            return ['words' => $words, 'rounds' => [], 'availability' => ModeAvailability::notEnough($count, $required, ModeAvailability::REASON_NO_WORDS)];
        }

        $capacities = $strict ? array_fill(0, $size, $perRound) : PairsPacker::capacities($count, $size, $perRound, $minimum);
        $packed = PairsPacker::pack(array_column($words, 'keys'), $capacities, $strict ? $perRound : $minimum, $seed);

        if ($strict ? count($packed['rounds']) === $size : $packed['rounds'] !== []) {
            return ['words' => $words, 'rounds' => $packed['rounds'], 'availability' => ModeAvailability::enough($count, $required)];
        }

        // Enough words, too few that can stand together: a battle counts the words that fit somewhere, a solo session the biggest round.
        $together = $strict ? $packed['fitted'] : $packed['largest'];

        return ['words' => $words, 'rounds' => [], 'availability' => ModeAvailability::notEnough($together, $required, ModeAvailability::REASON_AMBIGUOUS)];
    }

    /**
     * The words that can be played, in the order of their ids, with what the packing and the cards need. Remembered by the scope.
     *
     * @return list<Candidate>
     */
    private function words(MaterialScope $scope, ?Learner $learner, LocaleSet $locales): array
    {
        return $scope->remember('pairs:words:'.($learner->id ?? 0).':'.implode(',', $locales->locales), function () use ($scope, $learner, $locales): array {
            $material = $learner === null
                ? $this->material->allWords($scope, $locales)
                : $this->material->introducedWords($scope, $learner, $locales);

            $fit = [];
            foreach ($material as $word) {
                $translations = [];
                foreach ($locales->locales as $locale) {
                    $translations[$locale] = $word->translationFor($locale);
                }

                $keys = TranslationVariants::clashKeys($word->word, $translations);
                if ($keys === null) {
                    continue;
                }

                $labels = [];
                foreach ($translations as $locale => $translation) {
                    $labels[$locale] = (string) TranslationVariants::label($translation);
                }

                $fit[] = ['word' => $word, 'text' => trim((string) preg_replace('/\s+/u', ' ', $word->word)), 'labels' => $labels, 'keys' => $keys];
            }

            return $fit;
        });
    }

    /**
     * Each column shuffled on its own. A board where every word stands beside its own translation gives itself away, so the right
     * column is turned by one then (a rotation of a whole row of matches is never one).
     *
     * @param  list<array{id: string, word_id: int, text: string}>  $left
     * @param  list<array{id: string, text: array<string, string>}>  $right
     * @param  array<string, string>  $table
     * @return array{0: list<array{id: string, word_id: int, text: string}>, 1: list<array{id: string, text: array<string, string>}>}
     */
    private static function columns(array $left, array $right, array $table, string $seed, int $number): array
    {
        $left = SeededShuffle::apply($left, $seed, "pairs:{$number}:left");
        $right = SeededShuffle::apply($right, $seed, "pairs:{$number}:right");

        $aligned = count($left) > 1;
        foreach ($left as $row => $card) {
            $aligned = $aligned && ($table[$card['id']] ?? null) === $right[$row]['id'];
        }
        if ($aligned) {
            $right = [...array_slice($right, 1), $right[0]];
        }

        return [$left, $right];
    }

    private static function soloRounds(): int
    {
        return max(1, (int) config('practice.solo.pairs.rounds'));
    }

    private static function soloPerRound(): int
    {
        return max(1, (int) config('practice.solo.pairs.per_round'));
    }

    private static function minPerRound(): int
    {
        return max(1, (int) config('practice.solo.pairs.min_per_round'));
    }

    private static function battleRounds(): int
    {
        return max(1, (int) config('practice.battle.rounds'));
    }

    private static function battlePerRound(): int
    {
        return max(1, (int) config('practice.battle.pairs_per_round'));
    }
}
