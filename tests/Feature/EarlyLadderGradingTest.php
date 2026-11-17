<?php

declare(strict_types=1);

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Learning\Support\LadderPolicy;
use App\Domain\Twa\Services\JwtService;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\ProductEvent;
use App\Models\Stage;
use App\Models\TrainingReview;
use App\Models\Word;
use App\Models\WordRepetition;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Grading over `POST /review` with the early ladder on (docs/06 §13.1, the default): the floor a lapse leaves follows the
 * rung the CARD stood on, whichever format of that rung it failed in; a hard word shows the pair it is confused with on
 * the card or in the verdict; the self-grade honesty metric counts a wrong objective check after a self-graded pass of a
 * day-based card.
 */

function elHeaders(Learner $learner): array
{
    return ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];
}

/**
 * A learner with a daily session over `$count` due review cards of one lesson, at the form rung (three passes, two days),
 * the first one due now and the others tomorrow (they only lend their texts to the options).
 *
 * @return array{learner: Learner, words: list<Word>, url: string, headers: array<string, string>, session_id: int}
 */
function elScenario(int $count = 6, int $repetitions = 3, int $intervalDays = 2, array $row = []): array
{
    $learner = Learner::factory()->create(['active_course_id' => null, 'timezone' => 'Asia/Tashkent']);
    $course = Course::factory()->published()->create(['visibility' => 'public']);
    $lesson = Lesson::factory()->for(Stage::factory()->create(['course_id' => $course->id]))->create();
    $words = [];
    for ($i = 0; $i < $count; $i++) {
        $words[] = Word::factory()->for($lesson)->create(['word' => "word{$i}", 'translation' => "перевод {$i}", 'example' => "I like word{$i} a lot."]);
    }
    foreach ($words as $i => $word) {
        WordRepetition::factory()->create(array_replace([
            'learner_id' => $learner->id, 'word_id' => $word->id, 'state' => RepetitionState::Review->value,
            'repetitions' => $repetitions, 'interval_days' => $intervalDays, 'next_review_at' => $i === 0 ? now()->subHour() : now()->addDay(),
            'introduced_mode' => 'daily_review', 'lapses' => 0, 'is_hard' => false, 'meta' => [LadderPolicy::LAST_EXERCISE_META => 'flash'],
            'served_ahead_token' => null, 'created_at' => now()->subDays(5),
        ], $row));
    }
    $headers = elHeaders($learner);
    $sessionId = (int) test()->withHeaders($headers)->postJson('/api/twa/training/sessions', ['mode' => 'daily_review'])->assertOk()->json('session_id');

    return ['learner' => $learner, 'words' => $words, 'url' => "/api/twa/training/sessions/{$sessionId}", 'headers' => $headers, 'session_id' => $sessionId];
}

/** @return array<string, mixed> the card of `/next` */
function elCard(array $s): array
{
    return test()->withHeaders($s['headers'])->getJson("{$s['url']}/next")->assertOk()->json('card');
}

function elRow(array $s, int $index = 0): WordRepetition
{
    return WordRepetition::query()->where('learner_id', $s['learner']->id)->where('word_id', $s['words'][$index]->id)->sole();
}

/** Makes the first word a due review card again with this maturity and last exercise. */
function elReset(array $s, int $repetitions, int $intervalDays, string $lastExercise, array $extra = []): void
{
    $row = elRow($s);
    $row->forceFill(array_replace([
        'state' => RepetitionState::Review->value, 'repetitions' => $repetitions, 'interval_days' => $intervalDays,
        'next_review_at' => now()->subHour(), 'served_ahead_token' => null, 'served_ahead_at' => null,
        'meta' => array_replace(is_array($row->meta) ? $row->meta : [], [LadderPolicy::LAST_EXERCISE_META => $lastExercise]),
    ], $extra))->save();
}

// --- the floor after a lapse: one rule, the card's rung ---------------------------------------------------

it('leaves the same floor whether the card on the form rung fails its reverse or its flash: one rung below the card', function (): void {
    // Reverse first (the list reverse, audio, flash; audio has no file): wrong → the floor is the meaning rung.
    $s = elScenario();
    $card = elCard($s);
    expect($card['word_id'])->toBe($s['words'][0]->id)->and($card['prompt']['type'])->toBe('reverse');
    $this->withHeaders($s['headers'])
        ->postJson("{$s['url']}/review", ['word_id' => $card['word_id'], 'answer' => 'word3', 'exercise' => 'reverse', 'time_spent_ms' => 100])
        ->assertOk()->assertJsonPath('graded.correct', false);
    $row = elRow($s);
    expect($row->state)->toBe(RepetitionState::Relearning)->and($row->is_hard)->toBeTrue()
        ->and($row->meta[LadderPolicy::FLOOR_META] ?? null)->toBe(LadderPolicy::RUNG_MEANING)
        ->and(app(LadderPolicy::class)->rungOf($row))->toBe(LadderPolicy::RUNG_MEANING);

    // The same card, the same rung, the flash format of the list this time (after a reverse): a fail leaves the SAME floor.
    $t = elScenario();
    elReset($t, 3, 2, 'reverse');
    $flash = elCard($t);
    expect($flash['prompt']['type'])->toBe('flash');
    $this->withHeaders($t['headers'])
        ->postJson("{$t['url']}/review", ['word_id' => $flash['word_id'], 'quality' => 2, 'exercise' => 'flash', 'time_spent_ms' => 100])
        ->assertOk();
    expect(elRow($t)->meta[LadderPolicy::FLOOR_META] ?? null)->toBe(LadderPolicy::RUNG_MEANING);

    // A card on the typed rung served a reverse (Free has no typed rung) and failed: the floor is the choice rung - the card's, not the exercise's.
    $u = elScenario(6, 4, 21);
    $typed = elCard($u);
    expect($typed['prompt']['type'])->toBe('reverse');
    $this->withHeaders($u['headers'])
        ->postJson("{$u['url']}/review", ['word_id' => $typed['word_id'], 'answer' => 'word3', 'exercise' => 'reverse', 'time_spent_ms' => 100])
        ->assertOk();
    expect(elRow($u)->meta[LadderPolicy::FLOOR_META] ?? null)->toBe(LadderPolicy::RUNG_CHOICE);
});

// --- the hard word and the pair it is confused with -------------------------------------------------------

it('shows the pair a hard word is confused with on a flash card, and in the verdict of a reverse card', function (): void {
    $s = elScenario(6, 3, 2, ['lapses' => 4, 'is_hard' => true]);
    foreach (['word2', 'word3', 'word2'] as $pick) {
        TrainingReview::factory()->create([
            'training_session_id' => $s['session_id'], 'learner_id' => $s['learner']->id, 'word_id' => $s['words'][0]->id,
            'quality' => 2, 'exercise' => 'reverse', 'answer_text' => $pick,
        ]);
    }

    // The reverse card hides what gives the word away - the pair among it - and the verdict brings it.
    $card = elCard($s);
    expect($card['prompt']['type'])->toBe('reverse')->and($card['is_leech'])->toBeTrue()->and($card['is_hard'])->toBeTrue()->and($card['confused_with'])->toBeNull();
    $this->withHeaders($s['headers'])
        ->postJson("{$s['url']}/review", ['word_id' => $card['word_id'], 'answer' => 'word0', 'exercise' => 'reverse', 'time_spent_ms' => 100])
        ->assertOk()->assertJsonPath('graded.correct', true)
        ->assertJsonPath('revealed.confused_with', ['word' => 'word2', 'translation' => 'перевод 2']);

    // The flash format of the same rung carries it on the card.
    elReset($s, 3, 2, 'reverse');
    $flash = elCard($s);
    expect($flash['prompt']['type'])->toBe('flash')->and($flash['confused_with'])->toBe(['word' => 'word2', 'translation' => 'перевод 2']);
});

// --- the honesty of the self-grade ---------------------------------------------------------------------

it('records the self-grade mismatch for a day-based card: "remembered" on its flash check, then a wrong reverse', function (): void {
    $s = elScenario();
    elReset($s, 3, 2, 'reverse'); // the flash format of the form rung comes after a reverse
    $flash = elCard($s);
    expect($flash['prompt']['type'])->toBe('flash');
    $this->withHeaders($s['headers'])
        ->postJson("{$s['url']}/review", ['word_id' => $flash['word_id'], 'quality' => 4, 'exercise' => 'flash', 'time_spent_ms' => 100])
        ->assertOk();
    expect(ProductEvent::query()->where('name', ProductEventName::ReviewSelfGradeMismatch->value)->count())->toBe(0);

    // Due again: the reverse (after a flash), answered wrong - the metric fires once, with the self-grade it contradicts.
    elReset($s, 3, 6, 'flash');
    $reverse = elCard($s);
    expect($reverse['prompt']['type'])->toBe('reverse');
    $this->withHeaders($s['headers'])
        ->postJson("{$s['url']}/review", ['word_id' => $reverse['word_id'], 'answer' => 'word4', 'exercise' => 'reverse', 'time_spent_ms' => 100])
        ->assertOk()->assertJsonPath('graded.correct', false);

    $event = ProductEvent::query()->where('name', ProductEventName::ReviewSelfGradeMismatch->value)->sole();
    expect($event->learner_id)->toBe($s['learner']->id)
        ->and($event->props)->toMatchArray(['word_id' => $s['words'][0]->id, 'exercise' => 'reverse', 'previous_quality' => 4, 'interval_before_days' => 6]);

    // The relearning check that follows (a card in minute steps) is no self-grade to measure, right or wrong.
    $check = elCard($s);
    expect($check['card_kind'])->toBe('learning')->and($check['prompt']['type'])->not->toBe('flash');
    $this->withHeaders($s['headers'])
        ->postJson("{$s['url']}/review", ['word_id' => $check['word_id'], 'answer' => 'zzz', 'exercise' => $check['prompt']['type'], 'serve_token' => $check['serve_token'], 'time_spent_ms' => 100])
        ->assertOk();
    expect(ProductEvent::query()->where('name', ProductEventName::ReviewSelfGradeMismatch->value)->count())->toBe(1);
});
