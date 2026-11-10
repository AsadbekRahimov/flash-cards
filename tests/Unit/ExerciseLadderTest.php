<?php

declare(strict_types=1);

use App\Domain\Billing\DTO\Entitlement;
use App\Domain\Billing\Enums\Plan;
use App\Domain\Learning\DTO\LearnerExerciseSettings;
use App\Domain\Learning\Enums\ExerciseType;
use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Learning\Services\ExerciseSelector;
use App\Domain\Learning\Services\GapFillBuilder;
use App\Domain\Learning\Support\LadderPolicy;
use App\Models\Word;
use App\Models\WordRepetition;

/*
 * The ladder of exercises by the maturity of the card (docs/25 §3.1, docs/06 §12.1): which rung a row is on, how a lapse
 * lowers the ceiling, what Free and Pro get on each rung, how the settings and the session mode narrow the choice.
 */

function ladderRep(int $repetitions, int $intervalDays, array $meta = [], RepetitionState $state = RepetitionState::Review, int $lapses = 0): WordRepetition
{
    return new WordRepetition(['repetitions' => $repetitions, 'interval_days' => $intervalDays, 'state' => $state->value, 'meta' => $meta, 'lapses' => $lapses]);
}

function ladderWord(int $id, string $word = 'walk', ?string $example = 'They walked home.'): Word
{
    $w = new Word(['word' => $word, 'example' => $example]);
    $w->id = $id;

    return $w;
}

function ladderSelector(array $config = []): ExerciseSelector
{
    config(['learning.ladder' => array_replace(['enabled' => true, 'choice_from_repetitions' => 3, 'typed_from_interval_days' => 21, 'typed_recall_in_free' => false], $config)]);

    return new ExerciseSelector(new GapFillBuilder, LadderPolicy::fromConfig());
}

function pro(): Entitlement
{
    return Entitlement::fromPlan(Plan::Pro, Entitlement::SOURCE_SUBSCRIPTION, null, 1);
}

describe('LadderPolicy', function (): void {
    it('reads the config and clamps the thresholds to at least one', function (): void {
        config(['learning.ladder' => ['enabled' => false, 'choice_from_repetitions' => 0, 'typed_from_interval_days' => -5, 'typed_recall_in_free' => true]]);
        config(['learning.leech' => ['threshold' => 0]]);
        config(['learning.pretest' => ['enabled' => true]]);
        config(['learning.objective_grading' => ['accept_legacy_quality' => false]]);

        $policy = LadderPolicy::fromConfig();

        expect($policy->ladderEnabled)->toBeFalse()
            ->and($policy->choiceFromRepetitions)->toBe(1)
            ->and($policy->typedFromIntervalDays)->toBe(1)
            ->and($policy->typedRecallInFree)->toBeTrue()
            ->and($policy->leechThreshold)->toBe(0)
            ->and($policy->pretestEnabled)->toBeTrue()
            ->and($policy->acceptLegacyChoiceQuality)->toBeFalse()
            ->and($policy->isLeech(ladderRep(0, 0, lapses: 99)))->toBeFalse(); // threshold 0 = never
    });

    it('puts a card on the rung its maturity earned: passes for the options, the interval for typing', function (): void {
        $policy = new LadderPolicy(choiceFromRepetitions: 3, typedFromIntervalDays: 21);

        expect($policy->maturityRung(ladderRep(1, 1)))->toBe(LadderPolicy::RUNG_FLASH)
            ->and($policy->maturityRung(ladderRep(2, 6)))->toBe(LadderPolicy::RUNG_FLASH)
            ->and($policy->maturityRung(ladderRep(3, 15)))->toBe(LadderPolicy::RUNG_CHOICE)
            ->and($policy->maturityRung(ladderRep(3, 20)))->toBe(LadderPolicy::RUNG_CHOICE)
            ->and($policy->maturityRung(ladderRep(4, 21)))->toBe(LadderPolicy::RUNG_TYPED)
            // A long interval alone is maturity (FSRS can grow it fast); few passes with a short one is not.
            ->and($policy->maturityRung(ladderRep(1, 30)))->toBe(LadderPolicy::RUNG_TYPED);
    });

    it('never serves below the floor a lapse left, clamps garbage, and the floor cannot exceed the top rung', function (): void {
        $policy = new LadderPolicy;

        expect($policy->rungOf(ladderRep(1, 1, [LadderPolicy::FLOOR_META => 1])))->toBe(LadderPolicy::RUNG_CHOICE)
            ->and($policy->rungOf(ladderRep(1, 1, [LadderPolicy::FLOOR_META => 2])))->toBe(LadderPolicy::RUNG_TYPED)
            ->and($policy->rungOf(ladderRep(1, 1, [LadderPolicy::FLOOR_META => 9])))->toBe(LadderPolicy::RUNG_TYPED)
            ->and($policy->rungOf(ladderRep(1, 1, [LadderPolicy::FLOOR_META => -3])))->toBe(LadderPolicy::RUNG_FLASH)
            ->and($policy->rungOf(ladderRep(1, 1, [LadderPolicy::FLOOR_META => 'x'])))->toBe(LadderPolicy::RUNG_FLASH)
            ->and($policy->rungOf(ladderRep(4, 37, [LadderPolicy::FLOOR_META => 1])))->toBe(LadderPolicy::RUNG_TYPED); // maturity wins when higher
    });

    it('flags a leech from the configured number of lapses', function (): void {
        $policy = new LadderPolicy(leechThreshold: 4);

        expect($policy->isLeech(ladderRep(1, 1, lapses: 3)))->toBeFalse()
            ->and($policy->isLeech(ladderRep(1, 1, lapses: 4)))->toBeTrue()
            ->and($policy->isLeech(ladderRep(0, 0, [], RepetitionState::Relearning, 7)))->toBeTrue();
    });

    it('maps the retention modes to the configured targets and refuses unknown or impossible ones', function (): void {
        config(['learning.fsrs.retention_modes' => ['calm' => 0.85, 'normal' => 0.9, 'intense' => 0.93, 'broken' => 1.5]]);
        $policy = new LadderPolicy;

        expect($policy->retentionFor('calm'))->toBe(0.85)
            ->and($policy->retentionFor('intense'))->toBe(0.93)
            ->and($policy->retentionFor('broken'))->toBeNull()
            ->and($policy->retentionFor('nope'))->toBeNull()
            ->and($policy->retentionFor(null))->toBeNull();
    });
});

describe('ExerciseSelector on the ladder', function (): void {
    it('keeps cards in minute steps on flash whatever their maturity marks say', function (): void {
        $selector = ladderSelector();
        foreach ([RepetitionState::New, RepetitionState::Learning, RepetitionState::Relearning] as $state) {
            expect($selector->select(ladderRep(5, 40, [LadderPolicy::FLOOR_META => 2], $state), ladderWord(1), pro(), new LearnerExerciseSettings, 'lesson'))->toBe(ExerciseType::Flash);
        }
    });

    it('climbs flash → options → typed with the maturity of a Pro card', function (): void {
        $selector = ladderSelector();
        $all = new LearnerExerciseSettings;

        expect($selector->select(ladderRep(1, 1), ladderWord(2), pro(), $all, 'lesson'))->toBe(ExerciseType::Flash)
            ->and($selector->select(ladderRep(2, 6), ladderWord(2), pro(), $all, 'lesson'))->toBe(ExerciseType::Flash)
            ->and($selector->select(ladderRep(3, 15), ladderWord(2), pro(), $all, 'lesson'))->toBe(ExerciseType::Audio) // (3 + 2) odd → audio
            ->and($selector->select(ladderRep(3, 15), ladderWord(3), pro(), $all, 'lesson'))->toBe(ExerciseType::Reverse) // (3 + 3) even → reverse
            ->and($selector->select(ladderRep(4, 37), ladderWord(2), pro(), $all, 'lesson'))->toBe(ExerciseType::GapFill) // even → gap_fill first
            ->and($selector->select(ladderRep(4, 37), ladderWord(3), pro(), $all, 'lesson'))->toBe(ExerciseType::Spelling) // odd → spelling first
            ->and($selector->select(ladderRep(4, 37), ladderWord(2, 'go', 'She went home.'), pro(), $all, 'lesson'))->toBe(ExerciseType::Spelling); // no form in the example
    });

    it('serves a mature card at the floor rung after a lapse and lets maturity take over again', function (): void {
        $selector = ladderSelector();
        $all = new LearnerExerciseSettings;

        // Relearned after failing a typed card: interval 1, one pass — but the floor keeps it at the options.
        expect($selector->select(ladderRep(1, 1, [LadderPolicy::FLOOR_META => 1]), ladderWord(3), pro(), $all, 'lesson'))->toBe(ExerciseType::Reverse)
            // Failed again at the options: the floor is gone, back to flash.
            ->and($selector->select(ladderRep(1, 1), ladderWord(3), pro(), $all, 'lesson'))->toBe(ExerciseType::Flash);
    });

    it('tops out at the options for Free unless typed recall is opened to Free', function (): void {
        $all = new LearnerExerciseSettings;

        $closed = ladderSelector();
        expect($closed->select(ladderRep(4, 37), ladderWord(2), Entitlement::free(), $all, 'lesson'))->toBe(ExerciseType::Reverse)
            ->and($closed->select(ladderRep(4, 37), ladderWord(3), Entitlement::free(), $all, 'lesson'))->toBe(ExerciseType::Audio)
            ->and($closed->typedAvailableToPlan(Entitlement::free()))->toBeFalse();

        $open = ladderSelector(['typed_recall_in_free' => true]);
        expect($open->select(ladderRep(4, 37), ladderWord(2), Entitlement::free(), $all, 'lesson'))->toBe(ExerciseType::GapFill)
            ->and($open->select(ladderRep(4, 37), ladderWord(3), Entitlement::free(), $all, 'lesson'))->toBe(ExerciseType::Spelling)
            ->and($open->typedAvailableToPlan(Entitlement::free()))->toBeTrue()
            // The learner's own switch still wins.
            ->and($open->select(ladderRep(4, 37), ladderWord(2), Entitlement::free(), new LearnerExerciseSettings(spelling: false, gapFill: false), 'lesson'))->toBe(ExerciseType::Reverse);
    });

    it('narrows the options rung to what the learner switched on, flash when nothing is', function (): void {
        $selector = ladderSelector();

        expect($selector->select(ladderRep(3, 15), ladderWord(2), pro(), new LearnerExerciseSettings(audio: false), 'lesson'))->toBe(ExerciseType::Reverse)
            ->and($selector->select(ladderRep(3, 15), ladderWord(3), pro(), new LearnerExerciseSettings(reverse: false), 'lesson'))->toBe(ExerciseType::Audio)
            ->and($selector->select(ladderRep(3, 15), ladderWord(3), pro(), new LearnerExerciseSettings(reverse: false, audio: false), 'lesson'))->toBe(ExerciseType::Flash);
    });

    it('gives a group lesson reverse only on the upper rungs, never audio or typing', function (): void {
        $selector = ladderSelector();
        $all = new LearnerExerciseSettings;

        for ($id = 1; $id <= 10; $id++) {
            expect($selector->select(ladderRep(3, 15), ladderWord($id), pro(), $all, 'group_lesson'))->toBe(ExerciseType::Reverse)
                ->and($selector->select(ladderRep(5, 60), ladderWord($id), pro(), $all, 'group_lesson'))->toBe(ExerciseType::Reverse);
        }
        expect($selector->select(ladderRep(5, 60), ladderWord(1), pro(), new LearnerExerciseSettings(reverse: false), 'group_lesson'))->toBe(ExerciseType::Flash);
    });

    it('uses the example of this serve, not the anchor, to decide on gap_fill', function (): void {
        $selector = ladderSelector();
        $word = ladderWord(2, 'walk', 'A paraphrase without the word.');

        expect($selector->select(ladderRep(4, 37), $word, pro(), new LearnerExerciseSettings, 'lesson'))->toBe(ExerciseType::Spelling)
            ->and($selector->select(ladderRep(4, 37), $word, pro(), new LearnerExerciseSettings, 'lesson', 'We walk every day.'))->toBe(ExerciseType::GapFill);
    });

    it('serves the pretest to a new word when the flag is on, but not in a group lesson', function (): void {
        $selector = ladderSelector();
        config(['learning.pretest.enabled' => true]);
        $selector = new ExerciseSelector(new GapFillBuilder, LadderPolicy::fromConfig());

        expect($selector->select(ladderRep(0, 0, [], RepetitionState::New), ladderWord(1), Entitlement::free(), new LearnerExerciseSettings, 'lesson'))->toBe(ExerciseType::Pretest)
            ->and($selector->select(ladderRep(0, 0, [], RepetitionState::New), ladderWord(1), Entitlement::free(), new LearnerExerciseSettings, 'group_lesson'))->toBe(ExerciseType::Flash)
            ->and($selector->select(ladderRep(0, 0, [], RepetitionState::Learning), ladderWord(1), Entitlement::free(), new LearnerExerciseSettings, 'lesson'))->toBe(ExerciseType::Flash);
    });

    it('falls back to the previous rotation when the ladder is off', function (): void {
        $selector = ladderSelector(['enabled' => false]);
        $all = new LearnerExerciseSettings;

        expect($selector->select(ladderRep(1, 1), ladderWord(1), pro(), $all, 'lesson'))->toBe(ExerciseType::Flash)
            ->and($selector->select(ladderRep(3, 15), ladderWord(1), pro(), $all, 'lesson'))->toBe(ExerciseType::GapFill) // every third pass
            ->and($selector->select(ladderRep(6, 90), ladderWord(1, 'go', null), pro(), $all, 'lesson'))->toBe(ExerciseType::Spelling);

        $seen = [];
        for ($id = 1; $id <= 60; $id++) {
            $seen[$selector->select(ladderRep(2, 6), ladderWord($id), Entitlement::free(), $all, 'lesson')->value] = true;
        }
        expect(array_keys($seen))->toEqualCanonicalizing(['flash', 'reverse', 'audio']);
    });
});

describe('ExerciseType', function (): void {
    it('knows who grades what and what the card must hide', function (): void {
        expect(ExerciseType::Flash->selfGraded())->toBeTrue()
            ->and(ExerciseType::Reverse->isChoice())->toBeTrue()
            ->and(ExerciseType::Reverse->serverGraded())->toBeFalse()
            ->and(ExerciseType::Pretest->isChoice())->toBeTrue()
            ->and(ExerciseType::Pretest->serverGraded())->toBeTrue()
            ->and(ExerciseType::Pretest->hidesTranslation())->toBeTrue()
            ->and(ExerciseType::Spelling->hidesWord())->toBeTrue()
            ->and(ExerciseType::GapFill->hidesWord())->toBeTrue()
            ->and(ExerciseType::Audio->hidesWord())->toBeFalse()
            ->and(ExerciseType::Flash->rung())->toBe(LadderPolicy::RUNG_FLASH)
            ->and(ExerciseType::Audio->rung())->toBe(LadderPolicy::RUNG_CHOICE)
            ->and(ExerciseType::GapFill->rung())->toBe(LadderPolicy::RUNG_TYPED)
            ->and((new LearnerExerciseSettings(false, false, false, false))->allows(ExerciseType::Pretest))->toBeTrue();
    });
});
