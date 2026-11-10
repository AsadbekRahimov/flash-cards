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
 * lowers the ceiling, what Free and Pro get on each rung, how the settings and the session mode narrow the choice — and
 * how a rung without an exercise is served one rung lower, never two.
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
    config(['learning.ladder' => array_replace([
        'enabled' => true, 'meaning_enabled' => true, 'meaning_from_repetitions' => 2, 'choice_from_repetitions' => 3, 'typed_from_interval_days' => 21, 'typed_recall_in_free' => false,
    ], $config)]);

    return new ExerciseSelector(new GapFillBuilder, LadderPolicy::fromConfig());
}

function pro(): Entitlement
{
    return Entitlement::fromPlan(Plan::Pro, Entitlement::SOURCE_SUBSCRIPTION, null, 1);
}

describe('LadderPolicy', function (): void {
    it('reads the config and clamps the thresholds to at least one', function (): void {
        config(['learning.ladder' => ['enabled' => false, 'meaning_enabled' => false, 'meaning_from_repetitions' => 0, 'choice_from_repetitions' => 0, 'typed_from_interval_days' => -5, 'typed_recall_in_free' => true]]);
        config(['learning.leech' => ['threshold' => 0]]);
        config(['learning.pretest' => ['enabled' => true]]);
        config(['learning.objective_grading' => ['accept_legacy_quality' => false]]);

        $policy = LadderPolicy::fromConfig();

        expect($policy->ladderEnabled)->toBeFalse()
            ->and($policy->meaningEnabled)->toBeFalse()
            ->and($policy->meaningFromRepetitions)->toBe(1)
            ->and($policy->choiceFromRepetitions)->toBe(1)
            ->and($policy->typedFromIntervalDays)->toBe(1)
            ->and($policy->typedRecallInFree)->toBeTrue()
            ->and($policy->leechThreshold)->toBe(0)
            ->and($policy->pretestEnabled)->toBeTrue()
            ->and($policy->acceptLegacyChoiceQuality)->toBeFalse()
            ->and($policy->isLeech(ladderRep(0, 0, lapses: 99)))->toBeFalse(); // threshold 0 = never
    });

    it('numbers the rungs bottom to top: flash, meaning, the options, typing', function (): void {
        expect([LadderPolicy::RUNG_FLASH, LadderPolicy::RUNG_MEANING, LadderPolicy::RUNG_CHOICE, LadderPolicy::RUNG_TYPED])->toBe([0, 1, 2, 3])
            ->and(LadderPolicy::RUNG_TOP)->toBe(LadderPolicy::RUNG_TYPED)
            ->and(ExerciseType::Meaning->rung())->toBe(1)
            ->and(ExerciseType::Reverse->rung())->toBe(2);
    });

    it('puts a card on the rung its maturity earned: passes for the meaning and the options, the interval for typing', function (): void {
        $policy = new LadderPolicy(meaningFromRepetitions: 2, choiceFromRepetitions: 3, typedFromIntervalDays: 21);

        expect($policy->maturityRung(ladderRep(1, 1)))->toBe(LadderPolicy::RUNG_FLASH)
            ->and($policy->maturityRung(ladderRep(2, 6)))->toBe(LadderPolicy::RUNG_MEANING)
            ->and($policy->maturityRung(ladderRep(3, 15)))->toBe(LadderPolicy::RUNG_CHOICE)
            ->and($policy->maturityRung(ladderRep(3, 20)))->toBe(LadderPolicy::RUNG_CHOICE)
            ->and($policy->maturityRung(ladderRep(4, 21)))->toBe(LadderPolicy::RUNG_TYPED)
            // A long interval alone is maturity (FSRS can grow it fast); few passes with a short one is not.
            ->and($policy->maturityRung(ladderRep(1, 30)))->toBe(LadderPolicy::RUNG_TYPED)
            // The meaning rung is the maturity whether or not the exercise is on: the selector serves it lower when it is off.
            ->and((new LadderPolicy(meaningEnabled: false))->maturityRung(ladderRep(2, 6)))->toBe(LadderPolicy::RUNG_MEANING);
    });

    it('never serves below the floor a lapse left, clamps garbage, and the floor cannot exceed the top rung', function (): void {
        $policy = new LadderPolicy;

        expect($policy->rungOf(ladderRep(1, 1, [LadderPolicy::FLOOR_META => 1])))->toBe(LadderPolicy::RUNG_MEANING)
            ->and($policy->rungOf(ladderRep(1, 1, [LadderPolicy::FLOOR_META => 2])))->toBe(LadderPolicy::RUNG_CHOICE)
            ->and($policy->rungOf(ladderRep(1, 1, [LadderPolicy::FLOOR_META => 3])))->toBe(LadderPolicy::RUNG_TYPED)
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
            expect($selector->select(ladderRep(5, 40, [LadderPolicy::FLOOR_META => 3], $state), ladderWord(1), pro(), new LearnerExerciseSettings, 'lesson'))->toBe(ExerciseType::Flash);
        }
    });

    it('climbs flash → meaning → options → typed with the maturity of a Pro card', function (): void {
        $selector = ladderSelector();
        $all = new LearnerExerciseSettings;

        expect($selector->select(ladderRep(1, 1), ladderWord(2), pro(), $all, 'lesson'))->toBe(ExerciseType::Flash)
            ->and($selector->select(ladderRep(2, 6), ladderWord(2), pro(), $all, 'lesson'))->toBe(ExerciseType::Meaning)
            ->and($selector->select(ladderRep(2, 6), ladderWord(3), pro(), $all, 'lesson'))->toBe(ExerciseType::Meaning) // no alternation on this rung
            ->and($selector->select(ladderRep(3, 15), ladderWord(2), pro(), $all, 'lesson'))->toBe(ExerciseType::Audio) // (3 + 2) odd → audio
            ->and($selector->select(ladderRep(3, 15), ladderWord(3), pro(), $all, 'lesson'))->toBe(ExerciseType::Reverse) // (3 + 3) even → reverse
            ->and($selector->select(ladderRep(4, 37), ladderWord(2), pro(), $all, 'lesson'))->toBe(ExerciseType::GapFill) // even → gap_fill first
            ->and($selector->select(ladderRep(4, 37), ladderWord(3), pro(), $all, 'lesson'))->toBe(ExerciseType::Spelling) // odd → spelling first
            ->and($selector->select(ladderRep(4, 37), ladderWord(2, 'go', 'She went home.'), pro(), $all, 'lesson'))->toBe(ExerciseType::Spelling); // no form in the example
    });

    it('is deterministic: the same inputs give the same type, and only the two upper rungs alternate', function (): void {
        $selector = ladderSelector();
        $all = new LearnerExerciseSettings;
        $seen = [];
        for ($id = 1; $id <= 40; $id++) {
            $rep = ladderRep(2, 6);
            $first = $selector->select($rep, ladderWord($id), Entitlement::free(), $all, 'daily_review');
            expect($selector->select($rep, ladderWord($id), Entitlement::free(), $all, 'daily_review'))->toBe($first);
            $seen[$first->value] = true;
        }
        expect(array_keys($seen))->toBe(['meaning']);
    });

    it('serves a mature card at the floor rung after a lapse and lets maturity take over again', function (): void {
        $selector = ladderSelector();
        $all = new LearnerExerciseSettings;

        // Relearned after failing a typed card: interval 1, one pass — but the floor keeps it at the options.
        expect($selector->select(ladderRep(1, 1, [LadderPolicy::FLOOR_META => LadderPolicy::RUNG_CHOICE]), ladderWord(3), pro(), $all, 'lesson'))->toBe(ExerciseType::Reverse)
            // Failed again at the options: the floor is the meaning.
            ->and($selector->select(ladderRep(1, 1, [LadderPolicy::FLOOR_META => LadderPolicy::RUNG_MEANING]), ladderWord(3), pro(), $all, 'lesson'))->toBe(ExerciseType::Meaning)
            // Failed at the meaning: the floor is gone, back to flash.
            ->and($selector->select(ladderRep(1, 1), ladderWord(3), pro(), $all, 'lesson'))->toBe(ExerciseType::Flash);
    });

    it('tops out at the options for Free unless typed recall is opened to Free', function (): void {
        $all = new LearnerExerciseSettings;

        $closed = ladderSelector();
        expect($closed->select(ladderRep(4, 37), ladderWord(2), Entitlement::free(), $all, 'lesson'))->toBe(ExerciseType::Reverse)
            ->and($closed->select(ladderRep(4, 37), ladderWord(3), Entitlement::free(), $all, 'lesson'))->toBe(ExerciseType::Audio)
            ->and($closed->select(ladderRep(2, 6), ladderWord(3), Entitlement::free(), $all, 'lesson'))->toBe(ExerciseType::Meaning) // the meaning is for every plan
            ->and($closed->typedAvailableToPlan(Entitlement::free()))->toBeFalse();

        $open = ladderSelector(['typed_recall_in_free' => true]);
        expect($open->select(ladderRep(4, 37), ladderWord(2), Entitlement::free(), $all, 'lesson'))->toBe(ExerciseType::GapFill)
            ->and($open->select(ladderRep(4, 37), ladderWord(3), Entitlement::free(), $all, 'lesson'))->toBe(ExerciseType::Spelling)
            ->and($open->typedAvailableToPlan(Entitlement::free()))->toBeTrue()
            // The learner's own switch still wins.
            ->and($open->select(ladderRep(4, 37), ladderWord(2), Entitlement::free(), new LearnerExerciseSettings(spelling: false, gapFill: false), 'lesson'))->toBe(ExerciseType::Reverse);
    });

    it('serves a rung without an exercise one rung lower, never two: options off → meaning, meaning off → flash', function (): void {
        $selector = ladderSelector();

        expect($selector->select(ladderRep(3, 15), ladderWord(2), pro(), new LearnerExerciseSettings(audio: false), 'lesson'))->toBe(ExerciseType::Reverse)
            ->and($selector->select(ladderRep(3, 15), ladderWord(3), pro(), new LearnerExerciseSettings(reverse: false), 'lesson'))->toBe(ExerciseType::Audio)
            ->and($selector->select(ladderRep(3, 15), ladderWord(3), pro(), new LearnerExerciseSettings(reverse: false, audio: false), 'lesson'))->toBe(ExerciseType::Meaning)
            ->and($selector->select(ladderRep(3, 15), ladderWord(3), pro(), new LearnerExerciseSettings(reverse: false, audio: false, meaning: false), 'lesson'))->toBe(ExerciseType::Flash)
            // The meaning rung with the meaning off: flash, not the options above it.
            ->and($selector->select(ladderRep(2, 6), ladderWord(3), pro(), new LearnerExerciseSettings(meaning: false), 'lesson'))->toBe(ExerciseType::Flash)
            // The typed rung of a learner who typed nothing on: the options, those off: the meaning.
            ->and($selector->select(ladderRep(4, 37), ladderWord(3), pro(), new LearnerExerciseSettings(spelling: false, gapFill: false), 'lesson'))->toBe(ExerciseType::Audio) // (4 + 3) odd
            ->and($selector->select(ladderRep(4, 37), ladderWord(3), pro(), new LearnerExerciseSettings(reverse: false, audio: false, spelling: false, gapFill: false), 'lesson'))->toBe(ExerciseType::Meaning);
    });

    it('drops the meaning exercise everywhere when it is off in the config, leaving the rest of the ladder as it was', function (): void {
        $selector = ladderSelector(['meaning_enabled' => false]);
        $all = new LearnerExerciseSettings;

        expect($selector->select(ladderRep(2, 6), ladderWord(3), pro(), $all, 'lesson'))->toBe(ExerciseType::Flash)
            ->and($selector->select(ladderRep(3, 15), ladderWord(3), pro(), $all, 'lesson'))->toBe(ExerciseType::Reverse)
            ->and($selector->select(ladderRep(3, 15), ladderWord(3), pro(), new LearnerExerciseSettings(reverse: false, audio: false), 'lesson'))->toBe(ExerciseType::Flash)
            // A floor left at the meaning rung (a lapse at the options) is served as flash too.
            ->and($selector->select(ladderRep(1, 1, [LadderPolicy::FLOOR_META => LadderPolicy::RUNG_MEANING]), ladderWord(3), pro(), $all, 'lesson'))->toBe(ExerciseType::Flash)
            ->and($selector->available(ExerciseType::Meaning, pro(), $all))->toBeFalse();
    });

    it('moves the thresholds with the config: the meaning rung can start later or be empty', function (): void {
        $later = ladderSelector(['meaning_from_repetitions' => 3, 'choice_from_repetitions' => 5]);
        $all = new LearnerExerciseSettings;
        expect($later->select(ladderRep(2, 6), ladderWord(3), pro(), $all, 'lesson'))->toBe(ExerciseType::Flash)
            ->and($later->select(ladderRep(3, 15), ladderWord(3), pro(), $all, 'lesson'))->toBe(ExerciseType::Meaning)
            ->and($later->select(ladderRep(4, 20), ladderWord(3), pro(), $all, 'lesson'))->toBe(ExerciseType::Meaning)
            ->and($later->select(ladderRep(5, 20), ladderWord(3), pro(), $all, 'lesson'))->toBe(ExerciseType::Reverse);

        // The meaning threshold at or above the options' one: no card ever lands on the meaning rung by maturity.
        $empty = ladderSelector(['meaning_from_repetitions' => 3, 'choice_from_repetitions' => 3]);
        expect($empty->select(ladderRep(2, 6), ladderWord(3), pro(), $all, 'lesson'))->toBe(ExerciseType::Flash)
            ->and($empty->select(ladderRep(3, 15), ladderWord(3), pro(), $all, 'lesson'))->toBe(ExerciseType::Reverse);
    });

    it('gives a group lesson the meaning on its rung and reverse above it, never audio or typing', function (): void {
        $selector = ladderSelector();
        $all = new LearnerExerciseSettings;

        for ($id = 1; $id <= 10; $id++) {
            expect($selector->select(ladderRep(2, 6), ladderWord($id), pro(), $all, 'group_lesson'))->toBe(ExerciseType::Meaning)
                ->and($selector->select(ladderRep(3, 15), ladderWord($id), pro(), $all, 'group_lesson'))->toBe(ExerciseType::Reverse)
                ->and($selector->select(ladderRep(5, 60), ladderWord($id), pro(), $all, 'group_lesson'))->toBe(ExerciseType::Reverse);
        }
        // Reverse off in a group: the rung below (the meaning), then flash.
        expect($selector->select(ladderRep(5, 60), ladderWord(1), pro(), new LearnerExerciseSettings(reverse: false), 'group_lesson'))->toBe(ExerciseType::Meaning)
            ->and($selector->select(ladderRep(5, 60), ladderWord(1), pro(), new LearnerExerciseSettings(reverse: false, meaning: false), 'group_lesson'))->toBe(ExerciseType::Flash);
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

    it('falls back to the previous rotation when the ladder is off, in which the meaning takes no part', function (): void {
        $selector = ladderSelector(['enabled' => false]);
        $all = new LearnerExerciseSettings;

        expect($selector->select(ladderRep(1, 1), ladderWord(1), pro(), $all, 'lesson'))->toBe(ExerciseType::Flash)
            ->and($selector->select(ladderRep(3, 15), ladderWord(1), pro(), $all, 'lesson'))->toBe(ExerciseType::GapFill) // every third pass
            ->and($selector->select(ladderRep(6, 90), ladderWord(1, 'go', null), pro(), $all, 'lesson'))->toBe(ExerciseType::Spelling);

        $seen = [];
        for ($id = 1; $id <= 60; $id++) {
            $seen[$selector->select(ladderRep(2, 6), ladderWord($id), Entitlement::free(), $all, 'lesson')->value] = true;
            $seen[$selector->select(ladderRep(4, 6), ladderWord($id), Entitlement::free(), $all, 'group_lesson')->value] = true;
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
            ->and((new LearnerExerciseSettings(false, false, false, false, false))->allows(ExerciseType::Pretest))->toBeTrue();
    });

    it('treats the meaning as a four-option card the server grades, with no legacy quality and the translation hidden', function (): void {
        expect(ExerciseType::Meaning->isChoice())->toBeTrue()
            ->and(ExerciseType::Meaning->serverGraded())->toBeTrue()
            ->and(ExerciseType::Meaning->hidesChoiceAnswer())->toBeTrue()
            ->and(ExerciseType::Meaning->hidesWord())->toBeFalse()
            ->and(ExerciseType::Meaning->hidesTranslation())->toBeFalse() // the choice-card rule hides it, not the pretest's
            ->and(ExerciseType::Meaning->selfGraded())->toBeFalse()
            ->and(ExerciseType::Meaning->feature())->toBeNull()
            ->and(ExerciseType::Meaning->rung())->toBe(LadderPolicy::RUNG_MEANING)
            ->and(ExerciseType::values())->toContain('meaning')
            ->and((new LearnerExerciseSettings)->allows(ExerciseType::Meaning))->toBeTrue()
            ->and((new LearnerExerciseSettings(meaning: false))->allows(ExerciseType::Meaning))->toBeFalse()
            ->and(LearnerExerciseSettings::fromArray(['meaning' => false])->toArray())->toBe(['meaning' => false, 'reverse' => true, 'audio' => true, 'spelling' => true, 'gap_fill' => true])
            ->and(LearnerExerciseSettings::KEYS)->toBe(['meaning', 'reverse', 'audio', 'spelling', 'gap_fill']);
    });
});
