<?php

declare(strict_types=1);

/*
 * F2 docs/35 §3.3 b, docs/06 §16: a phrase climbs the same ladder as a word, but its typed rung is the gap-fill of its key word in the
 * phrase itself - `spelling` is never served to a phrase, by no ladder and in no mode; a phrase without a valid key word has no typed
 * rung. A word with the same rows is served as before (the control).
 */

use App\Domain\Billing\DTO\Entitlement;
use App\Domain\Billing\Enums\Plan;
use App\Domain\Content\DTO\ResolvedTranslation;
use App\Domain\Learning\DTO\LearnerExerciseSettings;
use App\Domain\Learning\Enums\ExerciseType;
use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Learning\Services\ExercisePromptBuilder;
use App\Domain\Learning\Services\ExerciseSelector;
use App\Domain\Learning\Services\GapFillBuilder;
use App\Domain\Learning\Support\LadderPolicy;
use App\Models\TrainingSession;
use App\Models\Word;
use App\Models\WordRepetition;

function phlPhrase(int $id = 7, ?string $gap = 'go', string $text = 'easy come, easy go'): Word
{
    $word = new Word(['word' => $text, 'part_of_speech' => 'phrase', 'example' => 'Oh well, easy come, easy go.', 'meta' => ['phrase' => array_filter(['kind' => 'proverb', 'register' => 'informal', 'gap' => $gap])]]);
    $word->id = $id;

    return $word;
}

function phlWord(int $id = 7): Word
{
    // The same row as a word: spelling is possible, the example has no form of it for a gap-fill.
    $word = new Word(['word' => 'come', 'part_of_speech' => 'verb', 'example' => 'Oh well.', 'meta' => []]);
    $word->id = $id;

    return $word;
}

function phlSelector(array $ladder = []): ExerciseSelector
{
    config(['learning.ladder' => array_replace([
        'enabled' => true, 'meaning_enabled' => true, 'meaning_from_repetitions' => 2, 'choice_from_repetitions' => 3, 'typed_from_interval_days' => 6, 'typed_recall_in_free' => false,
        'early' => ['enabled' => true, 'recognition' => 'meaning,reverse,audio', 'form' => 'reverse,audio,flash', 'audio_requires_file' => true],
    ], $ladder)]);

    return new ExerciseSelector(new GapFillBuilder, LadderPolicy::fromConfig());
}

function phlPro(): Entitlement
{
    return Entitlement::fromPlan(Plan::Pro, Entitlement::SOURCE_SUBSCRIPTION, null, 1);
}

/**
 * The exercises of `$grades` grades in a row of a card on the typed rung (interval 8 days), each grade remembered as the last.
 *
 * @return list<string>
 */
function phlServe(ExerciseSelector $selector, Word $word, string $mode = TrainingSession::MODE_DAILY_REVIEW, int $grades = 6, int $interval = 8): array
{
    $served = [];
    $meta = [];
    for ($i = 0; $i < $grades; $i++) {
        $card = new WordRepetition(['repetitions' => 4 + $i, 'interval_days' => $interval, 'state' => RepetitionState::Review->value, 'meta' => $meta, 'lapses' => 0]);
        $type = $selector->select($card, $word, phlPro(), new LearnerExerciseSettings, $mode);
        $served[] = $type->value;
        $meta = [LadderPolicy::LAST_EXERCISE_META => $type->value];
    }

    return $served;
}

it('serves the gap-fill of the key word on the typed rung of a phrase and never spelling, whatever the ladder', function (array $ladder, string $mode): void {
    $phrase = phlServe(phlSelector($ladder), phlPhrase(), $mode, grades: 8);
    $word = phlServe(phlSelector($ladder), phlWord(), $mode, grades: 8);

    expect($phrase)->not->toContain('spelling')
        ->and($phrase)->toContain('gap_fill')
        // The control: the same rows of a word reach spelling (its example has no form of it, so no gap-fill).
        ->and($word)->toContain('spelling')
        ->and($word)->not->toContain('gap_fill');
})->with([
    'early ladder' => [[], TrainingSession::MODE_DAILY_REVIEW],
    'classic ladder' => [['early' => ['enabled' => false]], TrainingSession::MODE_DAILY_REVIEW],
    'round of hard words' => [[], TrainingSession::MODE_HARD_REVIEW],
]);

it('never serves spelling to a phrase under the rules before the ladder either', function (): void {
    $selector = phlSelector(['enabled' => false]);
    $served = [];
    foreach ([3, 6, 9, 12] as $repetitions) {
        $card = new WordRepetition(['repetitions' => $repetitions, 'interval_days' => 8, 'state' => RepetitionState::Review->value, 'meta' => [], 'lapses' => 0]);
        $served[] = $selector->select($card, phlPhrase(), phlPro(), new LearnerExerciseSettings, TrainingSession::MODE_DAILY_REVIEW)->value;
        $served[] = $selector->select($card, phlPhrase(gap: null), phlPro(), new LearnerExerciseSettings, TrainingSession::MODE_DAILY_REVIEW)->value;
    }

    expect($served)->not->toContain('spelling')->and($served)->toContain('gap_fill');
});

it('gives a phrase without a valid key word no typed rung at all: it is served a rung lower', function (?string $gap): void {
    $served = phlServe(phlSelector(), phlPhrase(gap: $gap), grades: 8);

    expect($served)->not->toContain('spelling')
        ->and($served)->not->toContain('gap_fill')
        ->and(array_unique($served))->each->toBeIn(['reverse', 'meaning', 'audio', 'flash']);
})->with(['no key word' => [null], 'a key word that is not in the phrase' => ['went'], 'a key word twice' => ['easy']]);

it('shows the phrase with its key word masked and the translation as the question of a gap-fill', function (): void {
    $builder = app(ExercisePromptBuilder::class);
    $translation = new ResolvedTranslation('легко досталось — легко потерялось', 'ru', false);

    $prompt = $builder->build(ExerciseType::GapFill, phlPhrase(), $translation, 'ru', null, 42, 'Some rotated example.');

    expect($prompt->type)->toBe(ExerciseType::GapFill)
        ->and($prompt->front)->toBe('легко досталось — легко потерялось')
        ->and($prompt->maskedExample)->toBe('easy come, easy ____')
        ->and($prompt->answerLength)->toBe(2)
        ->and($builder->effectiveType(ExerciseType::GapFill, phlPhrase(), 'ru', 42))->toBe(ExerciseType::GapFill)
        ->and($builder->effectiveType(ExerciseType::GapFill, phlPhrase(gap: 'went'), 'ru', 42))->toBe(ExerciseType::Flash);
});

it('replaces the flash of the form rung of a phrase with the checked choice while the points record, as for a word', function (): void {
    config(['leagues.scoring.mode' => 'live']);
    $served = phlServe(phlSelector(), phlPhrase(), grades: 6, interval: 3);

    expect($served)->not->toContain('flash')
        ->and($served)->toBe(['reverse', 'meaning', 'reverse', 'meaning', 'reverse', 'meaning']);
});
