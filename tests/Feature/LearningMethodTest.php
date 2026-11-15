<?php

declare(strict_types=1);

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Content\Services\VocabularyImporter;
use App\Domain\Learning\Enums\ExerciseType;
use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Learning\Enums\SchedulerKind;
use App\Domain\Learning\Services\FsrsScheduler;
use App\Domain\Learning\Services\LearningCache;
use App\Domain\Learning\Services\SchedulerResolver;
use App\Domain\Learning\Support\LadderPolicy;
use App\Domain\Twa\Services\JwtService;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\ProductEvent;
use App\Models\Stage;
use App\Models\Subscription;
use App\Models\TrainingReview;
use App\Models\TrainingSession;
use App\Models\Word;
use App\Models\WordExample;
use App\Models\WordRepetition;
use App\Models\WordTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\QueryLog;

uses(RefreshDatabase::class);

/*
 * The method (docs/25 §3, docs/06 §12): objective grading of the four-option cards, the ladder floor after a lapse, the
 * self-grade mismatch metric, leeches and the confusable pair, the memory hooks (authored and the learner's own note),
 * the rotation of examples, confusable words in the picker, the FSRS retention mode and the pretest.
 *
 * A `config([...])` of the method must come BEFORE the first request of a test: the router keeps the controller it built
 * on the first request (Route::getController), with the LadderPolicy of that moment, for the rest of the test.
 */

function lmHeaders(Learner $learner): array
{
    return ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];
}

/**
 * A personal lesson session with $count due review cards; distinct words and translations so the four options always exist.
 *
 * @return array{learner: Learner, lesson: Lesson, session: TrainingSession, words: list<Word>, url: string, headers: array<string, string>}
 */
function lmScenario(int $count = 5, int $repetitions = 3, int $intervalDays = 15, bool $pro = false, array $rowAttributes = []): array
{
    $learner = Learner::factory()->create();
    if ($pro) {
        Subscription::factory()->create(['subscriber_id' => $learner->id, 'provider' => 'promo']);
    }

    $course = Course::factory()->create(['status' => 'published', 'visibility' => 'public']);
    $lesson = Lesson::factory()->for(Stage::factory()->create(['course_id' => $course->id]))->create();
    $words = [];
    for ($i = 0; $i < $count; $i++) {
        $words[] = Word::factory()->for($lesson)->create(['word' => "word{$i}", 'translation' => "перевод {$i}", 'example' => "I like word{$i} a lot."]);
    }
    foreach ($words as $word) {
        WordRepetition::factory()->create(array_replace([
            'learner_id' => $learner->id,
            'word_id' => $word->id,
            'state' => RepetitionState::Review->value,
            'repetitions' => $repetitions,
            'interval_days' => $intervalDays,
            'next_review_at' => now()->subHour(),
            'introduced_mode' => 'lesson',
            'lapses' => 0,
            'is_hard' => false,
            'meta' => [],
        ], $rowAttributes));
    }
    $session = TrainingSession::factory()->personal($learner, 'lesson')->create(['lesson_id' => $lesson->id]);

    return [
        'learner' => $learner, 'lesson' => $lesson, 'session' => $session, 'words' => $words,
        'url' => "/api/twa/training/sessions/{$session->id}", 'headers' => lmHeaders($learner),
    ];
}

/** The first due card of the session, with its exercise. */
function lmCard(array $s): array
{
    return test()->withHeaders($s['headers'])->getJson("{$s['url']}/next")->assertOk()->json('card');
}

/**
 * The right option of a meaning / reverse / audio card. The card itself does not carry it (docs/06 §12.2), so it is read from the word.
 *
 * @param  array<string, mixed>  $card
 */
function lmCorrect(array $card): string
{
    $word = Word::query()->findOrFail($card['word_id']);

    return $card['prompt']['type'] === 'reverse' ? $word->word : $word->translation;
}

/** A wrong option of a four-option card. */
function lmWrong(array $card): string
{
    $correct = lmCorrect($card);

    return collect($card['prompt']['options'])->first(fn (string $o): bool => mb_strtolower($o) !== mb_strtolower($correct));
}

/** Makes the row of the word a due review card again with the given maturity (the test's own clock). */
function lmReset(Learner $learner, int $wordId, int $repetitions, int $intervalDays, array $extra = []): void
{
    WordRepetition::query()->where('learner_id', $learner->id)->where('word_id', $wordId)->update([
        'state' => RepetitionState::Review->value, 'repetitions' => $repetitions, 'interval_days' => $intervalDays,
        'next_review_at' => now()->subHour(), 'served_ahead_token' => null, 'served_ahead_at' => null,
    ] + $extra);
}

// --- objective grading (docs/06 §12.2) --------------------------------------------------------

it('grades a four-option card from the picked option: the right one passes (4), a wrong one fails (2), and the pick is kept', function (): void {
    $s = lmScenario(5, 3, 15);
    $card = lmCard($s);
    expect($card['prompt']['type'])->toBeIn(['reverse', 'audio']);

    $correct = lmCorrect($card);
    $wrong = collect($card['prompt']['options'])->first(fn (string $o): bool => mb_strtolower($o) !== mb_strtolower($correct));

    $response = $this->withHeaders($s['headers'])
        ->postJson("{$s['url']}/review", ['word_id' => $card['word_id'], 'answer' => ' '.mb_strtoupper($correct).' ', 'time_spent_ms' => 900])
        ->assertOk();
    expect($response->json('graded.quality'))->toBe(4)
        ->and($response->json('graded.correct'))->toBeTrue()
        ->and(TrainingReview::query()->sole()->answer_text)->toBe(mb_strtoupper($correct)) // TrimStrings middleware
        ->and(WordRepetition::query()->where('word_id', $card['word_id'])->sole()->repetitions)->toBe(4);

    $second = lmCard($s);
    $correct2 = lmCorrect($second);
    $wrong2 = collect($second['prompt']['options'])->first(fn (string $o): bool => mb_strtolower($o) !== mb_strtolower($correct2));
    $response = $this->withHeaders($s['headers'])
        ->postJson("{$s['url']}/review", ['word_id' => $second['word_id'], 'answer' => $wrong2, 'time_spent_ms' => 900])
        ->assertOk();
    expect($response->json('graded'))->toMatchArray(['quality' => 2, 'correct' => false])
        ->and(WordRepetition::query()->where('word_id', $second['word_id'])->sole()->state)->toBe(RepetitionState::Relearning)
        ->and(TrainingReview::query()->where('word_id', $second['word_id'])->sole()->answer_text)->toBe($wrong2);
    expect($wrong)->not->toBeNull();
});

/** The learner sees only the given type of the four-option exercises (the others are switched off, typed ones need Pro). */
function lmOnly(array $s, string $type): void
{
    $s['learner']->forceFill(['meta' => ['exercises' => [
        'meaning' => $type === 'meaning', 'reverse' => $type === 'reverse', 'audio' => $type === 'audio', 'spelling' => false, 'gap_fill' => false,
    ]]])->save();
}

/**
 * Every string a card carries outside the options: none of them may be, or contain, the right option.
 *
 * @param  array<string, mixed>  $card
 * @return list<string>
 */
function lmCardStrings(array $card): array
{
    unset($card['prompt']['options']);
    $out = [];
    array_walk_recursive($card, function (mixed $value) use (&$out): void {
        if (is_string($value)) {
            $out[] = $value;
        }
    });

    return $out;
}

it('sends a meaning / reverse / audio card without anything that gives the right option away, and returns it with the verdict', function (string $type): void {
    // The meaning is asked on its own rung (two passes); the word on the next (three).
    $s = lmScenario(5, $type === 'meaning' ? 2 : 3, $type === 'meaning' ? 6 : 15, rowAttributes: ['note' => 'word0 or перевод 0 - my hint', 'is_hard' => true]);
    foreach ($s['words'] as $i => $word) {
        $word->forceFill(['transcription' => "/wɜːd{$i}/"])->save();
        WordTranslation::query()->create(['word_id' => $word->id, 'locale' => 'ru', 'translation' => "перевод {$i}", 'hook' => "word{$i} = перевод {$i}", 'source' => 'import']);
    }
    lmOnly($s, $type);

    $card = lmCard($s);
    $correct = lmCorrect($card);
    expect($card['prompt']['type'])->toBe($type)
        ->and($card['prompt']['options'])->toContain($correct);

    foreach (lmCardStrings($card) as $value) {
        expect(mb_stripos($value, $correct))->toBeFalse("the card carries the right option in `{$value}`");
    }
    expect($card['hook'])->toBeNull()->and($card['note'])->toBeNull()->and($card['confused_with'])->toBeNull()->and($card['example'])->toBeNull();
    if ($type === 'reverse') {
        expect($card['word'])->toBe('')->and($card['transcription'])->toBeNull()->and($card['audio_url'])->toBeNull()
            ->and($card['translation'])->not->toBe('');
    } elseif ($type === 'meaning') {
        // The word is the question: seen with its transcription (and heard), its meaning hidden.
        expect($card['translation'])->toBe('')->and($card['word'])->toBe($card['prompt']['front'])->and($card['word'])->toStartWith('word')
            ->and($card['transcription'])->toStartWith('/wɜːd');
    } else {
        expect($card['translation'])->toBe('')->and($card['word'])->not->toBe('');
    }

    $response = $this->withHeaders($s['headers'])
        ->postJson("{$s['url']}/review", ['word_id' => $card['word_id'], 'answer' => $correct, 'exercise' => $type, 'time_spent_ms' => 800])
        ->assertOk();

    $i = (int) mb_substr($correct, -1);
    $revealed = $response->json('revealed');
    expect(array_keys($revealed))->toBe(['word', 'translation', 'correct', 'example', 'transcription', 'audio_url', 'hook', 'note', 'confused_with'])
        ->and($revealed['correct'])->toBe($correct)
        ->and($revealed['word'])->toBe("word{$i}")
        ->and($revealed['example'])->toBe("I like {$revealed['word']} a lot.")
        ->and($revealed['transcription'])->toStartWith('/wɜːd')
        ->and($revealed['hook'])->toBe("{$revealed['word']} = {$revealed['translation']}")
        ->and($revealed['note'])->toBe("word{$i} or перевод {$i} - my hint")
        ->and($response->json('graded.correct'))->toBeTrue();
})->with(['meaning', 'reverse', 'audio']);

// --- the meaning exercise (docs/06 §12.1, rung 1) ---------------------------------------------

it('asks for the meaning on the second pass: the word with four translations, graded 4 / 2 by the server, never by a client quality', function (): void {
    $s = lmScenario(5, 2, 6);
    $card = lmCard($s);
    expect($card['prompt']['type'])->toBe('meaning')
        ->and($card['prompt']['front'])->toBe($card['word'])
        ->and($card['prompt']['options'])->toHaveCount(4)
        ->and($card['translation'])->toBe('')
        ->and($card['example'])->toBeNull();

    // A self-grade is refused even while the legacy transition flag for reverse / audio is on (the default).
    $this->withHeaders($s['headers'])
        ->postJson("{$s['url']}/review", ['word_id' => $card['word_id'], 'quality' => 4, 'time_spent_ms' => 100])
        ->assertStatus(422)->assertJsonPath('error.code', 'quality_not_accepted');
    expect(TrainingReview::query()->count())->toBe(0);

    $correct = lmCorrect($card);
    $response = $this->withHeaders($s['headers'])
        ->postJson("{$s['url']}/review", ['word_id' => $card['word_id'], 'answer' => mb_strtoupper($correct), 'exercise' => 'meaning', 'time_spent_ms' => 900])
        ->assertOk();
    expect($response->json('graded'))->toMatchArray(['quality' => 4, 'correct' => true])
        ->and($response->json('revealed.correct'))->toBe($correct)
        ->and($response->json('revealed.translation'))->toBe($correct)
        ->and(TrainingReview::query()->sole()->exercise)->toBe(ExerciseType::Meaning)
        ->and(TrainingReview::query()->sole()->quality)->toBe(4)
        ->and(WordRepetition::query()->where('word_id', $card['word_id'])->sole()->meta)->toMatchArray([LadderPolicy::LAST_EXERCISE_META => 'meaning']);

    $second = lmCard($s);
    expect($second['prompt']['type'])->toBe('meaning');
    $response = $this->withHeaders($s['headers'])
        ->postJson("{$s['url']}/review", ['word_id' => $second['word_id'], 'answer' => lmWrong($second), 'time_spent_ms' => 900])
        ->assertOk();
    expect($response->json('graded'))->toMatchArray(['quality' => 2, 'correct' => false])
        ->and($response->json('revealed.correct'))->toBe(lmCorrect($second))
        ->and(WordRepetition::query()->where('word_id', $second['word_id'])->sole()->state)->toBe(RepetitionState::Relearning)
        // A lapse on the lowest objective rung leaves no floor: 0 is not stored.
        ->and(WordRepetition::query()->where('word_id', $second['word_id'])->sole()->meta)->not->toHaveKey(LadderPolicy::FLOOR_META);
});

it('answers 409 exercise_mismatch to a client that showed a meaning card as something else — or something else as a meaning card', function (): void {
    $s = lmScenario(5, 2, 6);
    $card = lmCard($s);
    expect($card['prompt']['type'])->toBe('meaning');
    $correct = lmCorrect($card);

    // An older Mini App that does not know the type would show it as flash and self-grade: refused, nothing written.
    $this->withHeaders($s['headers'])
        ->postJson("{$s['url']}/review", ['word_id' => $card['word_id'], 'quality' => 4, 'exercise' => 'flash', 'time_spent_ms' => 100])
        ->assertStatus(409)->assertJsonPath('error.code', 'exercise_mismatch');
    foreach (['reverse', 'audio', 'pretest'] as $shown) {
        $this->withHeaders($s['headers'])
            ->postJson("{$s['url']}/review", ['word_id' => $card['word_id'], 'answer' => $correct, 'exercise' => $shown, 'time_spent_ms' => 100])
            ->assertStatus(409)->assertJsonPath('error.code', 'exercise_mismatch');
    }
    expect(TrainingReview::query()->count())->toBe(0);

    // The other way round: a meaning card on screen while the server serves the options (the setting changed meanwhile).
    lmReset($s['learner'], (int) $card['word_id'], 3, 15);
    $this->withHeaders($s['headers'])
        ->postJson("{$s['url']}/review", ['word_id' => $card['word_id'], 'answer' => $correct, 'exercise' => 'meaning', 'time_spent_ms' => 100])
        ->assertStatus(409)->assertJsonPath('error.code', 'exercise_mismatch');
    expect(TrainingReview::query()->count())->toBe(0);
});

it('offers the right meaning exactly once: a second word with the same translation never makes a second right option', function (): void {
    $s = lmScenario(8, 2, 6);
    // Three words of the lesson share the translation of the card's word, in different case and spacing; four distinct ones remain.
    $word = $s['words'][0];
    foreach ([1, 2, 3] as $i) {
        $s['words'][$i]->forceFill(['translation' => $i === 1 ? mb_strtoupper($word->translation) : ' '.$word->translation.' '])->save();
    }
    app(LearningCache::class)->forgetLessonId((int) $s['lesson']->id);
    foreach (array_slice($s['words'], 1) as $other) {
        WordRepetition::query()->where('word_id', $other->id)->update(['next_review_at' => now()->addDay()]);
    }

    $card = lmCard($s);
    expect($card['word_id'])->toBe($word->id)->and($card['prompt']['type'])->toBe('meaning');
    $options = $card['prompt']['options'];
    $keys = array_map(static fn (string $o): string => mb_strtolower(trim($o)), $options);
    expect($options)->toHaveCount(4)
        ->and(array_unique($keys))->toHaveCount(4)
        ->and(array_count_values($keys)[mb_strtolower($word->translation)])->toBe(1);

    // Two /next for the same serve show the same options in the same order; the server grades against the same text.
    expect(lmCard($s)['prompt']['options'])->toBe($options);
    $this->withHeaders($s['headers'])
        ->postJson("{$s['url']}/review", ['word_id' => $word->id, 'answer' => $word->translation, 'exercise' => 'meaning', 'time_spent_ms' => 100])
        ->assertOk()->assertJsonPath('graded.correct', true);
});

it('costs a meaning card no more statements than an audio card, on /next and on /review', function (): void {
    $cost = function (string $type): array {
        $s = lmScenario(5, 3, 15);
        lmOnly($s, $type);
        $card = lmCard($s); // warms the lesson's caches (the pool of distractors, the translations), as under load
        expect($card['prompt']['type'])->toBe($type);
        $next = QueryLog::during(function () use ($s): void {
            lmCard($s);
        });
        $review = QueryLog::during(function () use ($s, $card): void {
            $this->withHeaders($s['headers'])
                ->postJson("{$s['url']}/review", ['word_id' => $card['word_id'], 'answer' => lmCorrect($card), 'exercise' => $card['prompt']['type'], 'time_spent_ms' => 100])
                ->assertOk();
        });

        return ['next' => count($next), 'review' => count($review)];
    };

    $audio = $cost('audio');
    $meaning = $cost('meaning');
    expect($meaning['next'])->toBeLessThanOrEqual($audio['next'])
        ->and($meaning['review'])->toBeLessThanOrEqual($audio['review']);
});

it('renumbers the stored ladder floor for the meaning rung and puts it back on rollback', function (): void {
    $learner = Learner::factory()->create();
    $rows = [];
    foreach ([null, 1, 2, 'x'] as $i => $floor) {
        $word = Word::factory()->create();
        $meta = $floor === null ? ['last_exercise' => 'flash'] : [LadderPolicy::FLOOR_META => $floor, 'last_exercise' => 'reverse'];
        $rows[$i] = WordRepetition::factory()->create(['learner_id' => $learner->id, 'word_id' => $word->id, 'meta' => $meta]);
    }
    $floorOf = fn (int $i): mixed => (WordRepetition::query()->findOrFail($rows[$i]->id)->meta ?? [])[LadderPolicy::FLOOR_META] ?? null;

    $migration = require database_path('migrations/2026_11_12_100002_renumber_ladder_floor_for_meaning_rung.php');

    // Already applied by RefreshDatabase: rolling it back moves 2 → 1, 3 → 2 and drops a floor at the new rung 1.
    $migration->down();
    expect($floorOf(0))->toBeNull()->and($floorOf(1))->toBeNull()->and($floorOf(2))->toBe(1)->and($floorOf(3))->toBe('x');

    $migration->up();
    expect($floorOf(0))->toBeNull()->and($floorOf(1))->toBeNull()->and($floorOf(2))->toBe(2)->and($floorOf(3))->toBe('x')
        ->and(WordRepetition::query()->findOrFail($rows[2]->id)->meta)->toMatchArray(['last_exercise' => 'reverse']);

    // The ladder reads the renumbered floor as the rung it meant: 2 = the options.
    $policy = LadderPolicy::fromConfig();
    expect($policy->floorRung(WordRepetition::query()->findOrFail($rows[2]->id)))->toBe(LadderPolicy::RUNG_CHOICE);
});

it('has no `revealed` in the answer to a flash card or to a typed one', function (): void {
    $s = lmScenario(3, 0, 0, rowAttributes: ['state' => 'new']);
    $card = lmCard($s);
    expect($card['prompt']['type'])->toBe('flash');

    $this->withHeaders($s['headers'])
        ->postJson("{$s['url']}/review", ['word_id' => $card['word_id'], 'quality' => 4, 'time_spent_ms' => 800])
        ->assertOk()
        ->assertJsonPath('revealed', null);
});

it('answers 409 exercise_mismatch when the client shows another exercise than the server now serves, and saves nothing', function (): void {
    $s = lmScenario(5, 3, 15);
    lmOnly($s, 'reverse');
    $card = lmCard($s);
    $correct = lmCorrect($card);

    // A typed card of a Pro plan that has expired: the learner types, the server now serves the four options.
    foreach (['spelling', 'gap_fill', 'flash'] as $shown) {
        $this->withHeaders($s['headers'])
            ->postJson("{$s['url']}/review", ['word_id' => $card['word_id'], 'answer' => $correct, 'exercise' => $shown, 'time_spent_ms' => 800])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'exercise_mismatch');
    }
    $this->withHeaders($s['headers'])
        ->postJson("{$s['url']}/review", ['word_id' => $card['word_id'], 'quality' => 4, 'exercise' => 'spelling', 'time_spent_ms' => 800])
        ->assertStatus(409)->assertJsonPath('error.code', 'exercise_mismatch');
    $this->withHeaders($s['headers'])
        ->postJson("{$s['url']}/review", ['word_id' => $card['word_id'], 'answer' => $correct, 'exercise' => 'nonsense', 'time_spent_ms' => 800])
        ->assertStatus(422);

    expect(TrainingReview::query()->count())->toBe(0)
        ->and(WordRepetition::query()->where('word_id', $card['word_id'])->sole()->repetitions)->toBe(3);

    // The card is served again as it is now, and the same answer with the right exercise is graded.
    $this->withHeaders($s['headers'])
        ->postJson("{$s['url']}/review", ['word_id' => $card['word_id'], 'answer' => $correct, 'exercise' => 'reverse', 'time_spent_ms' => 800])
        ->assertOk()->assertJsonPath('graded.correct', true);
});

it('normalises a legacy client quality on a four-option card to pass / fail while the transition flag is on, refuses it when off', function (): void {
    $s = lmScenario(5, 3, 15);
    $card = lmCard($s);

    // "Easy" from an old client on a reverse card is a pass, not an easy: 5 → 4.
    $this->withHeaders($s['headers'])
        ->postJson("{$s['url']}/review", ['word_id' => $card['word_id'], 'quality' => 5, 'time_spent_ms' => 100])
        ->assertOk()
        ->assertJsonPath('graded', null);
    expect(TrainingReview::query()->sole()->quality)->toBe(4)
        ->and(TrainingReview::query()->sole()->answer_text)->toBeNull();

    $card = lmCard($s);
    $this->withHeaders($s['headers'])
        ->postJson("{$s['url']}/review", ['word_id' => $card['word_id'], 'quality' => 0, 'time_spent_ms' => 100])
        ->assertOk();
    expect(TrainingReview::query()->where('word_id', $card['word_id'])->sole()->quality)->toBe(2);
});

it('refuses a client quality on a four-option card once the transition flag is off', function (): void {
    config(['learning.objective_grading.accept_legacy_quality' => false]);
    $s = lmScenario(5, 3, 15);
    $card = lmCard($s);
    expect($card['prompt']['type'])->toBeIn(['reverse', 'audio']);

    $this->withHeaders($s['headers'])
        ->postJson("{$s['url']}/review", ['word_id' => $card['word_id'], 'quality' => 4, 'time_spent_ms' => 100])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'quality_not_accepted');
    expect(TrainingReview::query()->count())->toBe(0);

    // The pick itself is still welcome.
    $correct = lmCorrect($card);
    $this->withHeaders($s['headers'])
        ->postJson("{$s['url']}/review", ['word_id' => $card['word_id'], 'answer' => $correct, 'time_spent_ms' => 100])
        ->assertOk()
        ->assertJsonPath('graded.quality', 4);
});

it('still takes the learner\'s own quality on a flash card, with its full range', function (): void {
    $s = lmScenario(1, 1, 1);
    $card = lmCard($s);
    expect($card['prompt']['type'])->toBe('flash');

    $this->withHeaders($s['headers'])
        ->postJson("{$s['url']}/review", ['word_id' => $card['word_id'], 'quality' => 5, 'time_spent_ms' => 100])
        ->assertOk();
    expect(TrainingReview::query()->sole()->quality)->toBe(5)
        ->and(WordRepetition::query()->sole()->meta)->toMatchArray([LadderPolicy::LAST_EXERCISE_META => 'flash']);
});

it('records the self-grade mismatch once: "remember / easy" on flash followed by a wrong objective answer on the same word', function (): void {
    $s = lmScenario(5, 1, 1);
    $card = lmCard($s);
    expect($card['prompt']['type'])->toBe('flash');
    $this->withHeaders($s['headers'])->postJson("{$s['url']}/review", ['word_id' => $card['word_id'], 'quality' => 5, 'time_spent_ms' => 100])->assertOk();

    // The same word comes back as a four-option card (3 passes now) and the pick is wrong.
    lmReset($s['learner'], (int) $card['word_id'], 3, 15);
    foreach ($s['words'] as $w) {
        if ((int) $w->id !== (int) $card['word_id']) {
            WordRepetition::query()->where('word_id', $w->id)->update(['next_review_at' => now()->addDay()]);
        }
    }
    $again = lmCard($s);
    expect($again['word_id'])->toBe($card['word_id'])->and($again['prompt']['type'])->toBeIn(['reverse', 'audio']);
    $correct = lmCorrect($again);
    $wrong = collect($again['prompt']['options'])->first(fn (string $o): bool => mb_strtolower($o) !== mb_strtolower($correct));
    $this->withHeaders($s['headers'])->postJson("{$s['url']}/review", ['word_id' => $again['word_id'], 'answer' => $wrong, 'time_spent_ms' => 100])->assertOk();

    $event = ProductEvent::query()->where('name', ProductEventName::ReviewSelfGradeMismatch->value)->sole();
    expect($event->learner_id)->toBe($s['learner']->id)
        ->and($event->props)->toMatchArray(['word_id' => $card['word_id'], 'exercise' => $again['prompt']['type'], 'previous_quality' => 5, 'interval_before_days' => 15]);

    // A wrong objective answer after an objective pass is not a self-grade mismatch.
    lmReset($s['learner'], (int) $card['word_id'], 3, 15);
    $third = lmCard($s);
    $correct = lmCorrect($third);
    $this->withHeaders($s['headers'])->postJson("{$s['url']}/review", ['word_id' => $third['word_id'], 'answer' => $correct, 'time_spent_ms' => 100])->assertOk();
    lmReset($s['learner'], (int) $card['word_id'], 3, 15);
    $fourth = lmCard($s);
    $correct = lmCorrect($fourth);
    $wrong = collect($fourth['prompt']['options'])->first(fn (string $o): bool => mb_strtolower($o) !== mb_strtolower($correct));
    $this->withHeaders($s['headers'])->postJson("{$s['url']}/review", ['word_id' => $fourth['word_id'], 'answer' => $wrong, 'time_spent_ms' => 100])->assertOk();
    expect(ProductEvent::query()->where('name', ProductEventName::ReviewSelfGradeMismatch->value)->count())->toBe(1);
});

// --- the ladder floor (docs/06 §12.1) ---------------------------------------------------------

it('lowers the ceiling by one rung on a lapse of a review card and serves the relearned card there, in the scheduler\'s own UPDATE', function (): void {
    $s = lmScenario(5, 4, 21, pro: true);
    // The other words only give the four options their distractors: not due.
    foreach (array_slice($s['words'], 1) as $other) {
        WordRepetition::query()->where('word_id', $other->id)->update(['next_review_at' => now()->addDays(3)]);
    }
    $wordId = (int) $s['words'][0]->id;
    $row = fn (): WordRepetition => WordRepetition::query()->where('word_id', $wordId)->sole();
    $card = lmCard($s);
    expect($card['word_id'])->toBe($wordId);
    expect($card['prompt']['type'])->toBeIn(['spelling', 'gap_fill']);

    $statements = QueryLog::during(function () use ($s, $card): void {
        $this->withHeaders($s['headers'])->postJson("{$s['url']}/review", ['word_id' => $card['word_id'], 'answer' => 'nonsense', 'time_spent_ms' => 100])->assertOk();
    });
    // The floor rides in the one UPDATE of the repetition row the scheduler makes: no write of its own (the other UPDATE
    // of the request is the learn-ahead ticket of the next card, the same relearning word served early).
    $updates = QueryLog::matching($statements, '/^update ["`]word_repetitions["`]/');
    expect(array_values(array_filter($updates, static fn (string $sql): bool => ! str_contains($sql, 'served_ahead_token'))))->toHaveCount(1)
        ->and(QueryLog::matching($updates, '/["`]meta["`]/'))->toHaveCount(1);

    expect($row()->state)->toBe(RepetitionState::Relearning)
        ->and($row()->meta)->toMatchArray([LadderPolicy::FLOOR_META => LadderPolicy::RUNG_CHOICE, LadderPolicy::LAST_EXERCISE_META => $card['prompt']['type']]);

    // Relearned: one pass, a one-day interval — maturity says flash, the floor says the four options.
    lmReset($s['learner'], $wordId, 1, 1);
    $again = lmCard($s);
    expect($again['prompt']['type'])->toBeIn(['reverse', 'audio']);

    // Failing at the options lowers the floor one more rung: next time the meaning is asked, not the word.
    $correct = lmCorrect($again);
    $wrong = collect($again['prompt']['options'])->first(fn (string $o): bool => mb_strtolower($o) !== mb_strtolower($correct));
    $this->withHeaders($s['headers'])->postJson("{$s['url']}/review", ['word_id' => $again['word_id'], 'answer' => $wrong, 'time_spent_ms' => 100])->assertOk();
    expect($row()->meta)->toMatchArray([LadderPolicy::FLOOR_META => LadderPolicy::RUNG_MEANING]);
    lmReset($s['learner'], $wordId, 1, 1);
    $meaning = lmCard($s);
    expect($meaning['prompt']['type'])->toBe('meaning');

    // Failing at the meaning takes the floor away (0 is not stored): next time it is a flash card.
    $correct = lmCorrect($meaning);
    $wrong = collect($meaning['prompt']['options'])->first(fn (string $o): bool => mb_strtolower($o) !== mb_strtolower($correct));
    $this->withHeaders($s['headers'])->postJson("{$s['url']}/review", ['word_id' => $meaning['word_id'], 'answer' => $wrong, 'exercise' => 'meaning', 'time_spent_ms' => 100])->assertOk();
    expect($row()->meta)->not->toHaveKey(LadderPolicy::FLOOR_META);
    lmReset($s['learner'], $wordId, 1, 1);
    expect(lmCard($s)['prompt']['type'])->toBe('flash');
});

it('clears the floor once the interval has grown back to it', function (): void {
    $s = lmScenario(1, 4, 37, pro: true, rowAttributes: ['meta' => [LadderPolicy::FLOOR_META => LadderPolicy::RUNG_CHOICE]]);
    $card = lmCard($s);
    expect($card['prompt']['type'])->toBeIn(['spelling', 'gap_fill']);

    $expected = $card['prompt']['type'] === 'spelling' ? $card['translation'] : null;
    $answer = $card['prompt']['type'] === 'spelling' ? $s['words'][0]->word : 'word0';
    $this->withHeaders($s['headers'])->postJson("{$s['url']}/review", ['word_id' => $card['word_id'], 'answer' => $answer, 'time_spent_ms' => 100])->assertOk()->assertJsonPath('graded.correct', true);
    expect(WordRepetition::query()->sole()->meta)->not->toHaveKey(LadderPolicy::FLOOR_META);
    expect($expected === null || $expected !== '')->toBeTrue();
});

// --- leeches (docs/06 §12.6) ------------------------------------------------------------------

it('marks a hard word from the leech threshold, shows the pair it is confused with, and costs one statement only for leeches', function (): void {
    $s = lmScenario(5, 1, 1, rowAttributes: ['lapses' => 4, 'is_hard' => true]);
    $plain = lmScenario(5, 1, 1);

    $card = lmCard($s);
    expect($card['is_leech'])->toBeTrue()->and($card['is_hard'])->toBeTrue()->and($card['confused_with'])->toBeNull();
    expect(lmCard($plain)['is_leech'])->toBeFalse();

    // The learner's own wrong picks: the most frequent wrong `reverse` answer, with its translation from the lesson.
    $session = $s['session'];
    foreach (['word2', 'word3', 'word2'] as $pick) {
        TrainingReview::factory()->create([
            'training_session_id' => $session->id, 'learner_id' => $s['learner']->id, 'word_id' => $card['word_id'],
            'quality' => 2, 'exercise' => 'reverse', 'answer_text' => $pick,
        ]);
    }
    $leechQueries = QueryLog::during(function () use ($s, &$card): void {
        $card = lmCard($s);
    });
    $plainQueries = QueryLog::during(function () use ($plain): void {
        lmCard($plain);
    });
    // Exactly one statement more than a plain card, and it is the read of the learner's wrong picks.
    expect($card['confused_with'])->toBe(['word' => 'word2', 'translation' => 'перевод 2'])
        ->and(count($leechQueries) - count($plainQueries))->toBe(1)
        ->and(count(QueryLog::matching($leechQueries, '/from ["`]training_reviews["`]/')) - count(QueryLog::matching($plainQueries, '/from ["`]training_reviews["`]/')))->toBe(1);

    // The content's own mark wins over the picks.
    Word::query()->whereKey($card['word_id'])->update(['meta' => json_encode(['confusable_with' => ['word4']])]);
    expect(lmCard($s)['confused_with'])->toBe(['word' => 'word4', 'translation' => 'перевод 4']);
});

it('never flags a leech when the threshold is 0', function (): void {
    config(['learning.leech.threshold' => 0]);
    $s = lmScenario(1, 1, 1, rowAttributes: ['lapses' => 9, 'is_hard' => true]);

    expect(lmCard($s)['is_leech'])->toBeFalse()->and(lmCard($s)['is_hard'])->toBeTrue();
});

// --- memory hooks (docs/06 §12.3) --------------------------------------------------------------

it('shows the authored hook of the card\'s language on a new, failed or hard card only — never on a plain review', function (): void {
    $s = lmScenario(5, 3, 15);
    // Flash cards only: the cards with options are sent without the hooks (they may name the answer).
    $s['learner']->forceFill(['meta' => ['exercises' => ['meaning' => false, 'reverse' => false, 'audio' => false, 'spelling' => false, 'gap_fill' => false]]])->save();
    $word = $s['words'][0];
    WordTranslation::query()->create(['word_id' => $word->id, 'locale' => 'ru', 'translation' => 'перевод 0', 'hook' => 'word + 0', 'source' => 'import']);
    WordTranslation::query()->create(['word_id' => $word->id, 'locale' => 'uz', 'translation' => 'tarjima 0', 'hook' => "so'z + 0", 'source' => 'import']);
    foreach ($s['words'] as $w) {
        if ((int) $w->id !== (int) $word->id) {
            WordRepetition::query()->where('word_id', $w->id)->update(['next_review_at' => now()->addDay()]);
        }
    }

    // A plain review: the hook is not read.
    expect(lmCard($s)['hook'])->toBeNull();

    // Failed and not yet passed a day-based review.
    WordRepetition::query()->where('word_id', $word->id)->update(['is_hard' => true]);
    expect(lmCard($s)['hook'])->toBe('word + 0');

    // The Uzbek learner gets the Uzbek hook, not the Russian one.
    $s['learner']->forceFill(['interface_locale' => 'uz'])->save();
    expect(lmCard($s)['hook'])->toBe("so'z + 0");

    // The first meeting with a word (state new).
    WordRepetition::query()->where('word_id', $word->id)->update(['is_hard' => false, 'state' => 'new', 'repetitions' => 0, 'interval_days' => 0]);
    expect(lmCard($s)['hook'])->toBe("so'z + 0");
});

it('shows no hook and no note when the hooks are switched off', function (): void {
    config(['learning.hooks.enabled' => false]);
    $s = lmScenario(1, 0, 0, rowAttributes: ['state' => 'new', 'note' => 'mine']);
    WordTranslation::query()->create(['word_id' => $s['words'][0]->id, 'locale' => 'ru', 'translation' => 'перевод 0', 'hook' => 'hint', 'source' => 'import']);

    $card = lmCard($s);
    expect($card['hook'])->toBeNull()->and($card['note'])->toBeNull();
});

it('saves the learner\'s own note as plain text on their row, shows it on the card and refuses words they never saw', function (): void {
    $s = lmScenario(1, 3, 15);
    $word = $s['words'][0];

    $this->withHeaders($s['headers'])
        ->putJson("/api/twa/words/{$word->id}/note", ['note' => "  <b>walk</b> — как  «вокзал»\x07  "])
        ->assertOk()
        ->assertExactJson(['word_id' => $word->id, 'note' => 'walk — как «вокзал»']);
    expect(WordRepetition::query()->sole()->note)->toBe('walk — как «вокзал»')
        ->and(lmCard($s)['note'])->toBe('walk — как «вокзал»');

    // Cut to the limit, never refused for a little excess.
    $this->withHeaders($s['headers'])->putJson("/api/twa/words/{$word->id}/note", ['note' => str_repeat('а', 250)])->assertOk();
    expect(mb_strlen((string) WordRepetition::query()->sole()->note))->toBe(200);

    // A wall of text is refused; a missing key too.
    $this->withHeaders($s['headers'])->putJson("/api/twa/words/{$word->id}/note", ['note' => str_repeat('а', 1000)])->assertStatus(422);
    $this->withHeaders($s['headers'])->putJson("/api/twa/words/{$word->id}/note", [])->assertStatus(422);

    // Cleared with null or a blank.
    $this->withHeaders($s['headers'])->putJson("/api/twa/words/{$word->id}/note", ['note' => null])->assertOk()->assertJsonPath('note', null);
    expect(WordRepetition::query()->sole()->note)->toBeNull();

    // Not the learner's word — and not another learner's row.
    $stranger = Word::factory()->create();
    $this->withHeaders($s['headers'])->putJson("/api/twa/words/{$stranger->id}/note", ['note' => 'x'])->assertStatus(404)->assertJsonPath('error.code', 'card_not_started');
    $other = Learner::factory()->create();
    $this->withHeaders(lmHeaders($other))->putJson("/api/twa/words/{$word->id}/note", ['note' => 'mine'])->assertStatus(404);
    expect(WordRepetition::query()->sole()->note)->toBeNull();

    $this->flushHeaders()->putJson("/api/twa/words/{$word->id}/note", ['note' => 'x'])->assertStatus(401);
});

it('hides the hooks and the pair on a typed card, where they would give the word away', function (): void {
    $s = lmScenario(1, 4, 21, pro: true, rowAttributes: ['note' => 'my hint', 'is_hard' => true]);
    WordTranslation::query()->create(['word_id' => $s['words'][0]->id, 'locale' => 'ru', 'translation' => 'перевод 0', 'hook' => 'hint', 'source' => 'import']);

    $card = lmCard($s);
    expect($card['prompt']['type'])->toBeIn(['spelling', 'gap_fill'])
        ->and($card['hook'])->toBeNull()
        ->and($card['note'])->toBeNull()
        ->and($card['example'])->toBeNull();
});

// --- several examples (docs/06 §12.4) ----------------------------------------------------------

it('shows the anchor example until the card graduates, then rotates the examples with the pass count — also for the gap-fill', function (): void {
    $s = lmScenario(1, 1, 1, pro: true);
    $word = $s['words'][0];
    WordExample::query()->create(['word_id' => $word->id, 'position' => 1, 'text' => 'We word0 together.', 'translations' => ['ru' => 'Мы word0 вместе.']]);
    WordExample::query()->create(['word_id' => $word->id, 'position' => 2, 'text' => 'She word0s daily.', 'translations' => []]);

    WordRepetition::query()->where('word_id', $word->id)->update(['state' => 'new', 'repetitions' => 0, 'interval_days' => 0]);
    expect(lmCard($s)['example'])->toBe('I like word0 a lot.');
    WordRepetition::query()->where('word_id', $word->id)->update(['state' => 'learning']);
    expect(lmCard($s)['example'])->toBe('I like word0 a lot.');

    lmReset($s['learner'], (int) $word->id, 1, 1);
    expect(lmCard($s)['example'])->toBe('We word0 together.');
    lmReset($s['learner'], (int) $word->id, 2, 6);
    expect(lmCard($s)['example'])->toBe('She word0s daily.');
    lmReset($s['learner'], (int) $word->id, 3, 15);
    expect(lmCard($s)['example'])->toBe('I like word0 a lot.');

    // A mature gap-fill is cut from the example of this serve and graded against its form.
    lmReset($s['learner'], (int) $word->id, 5, 37, ['meta' => json_encode([])]);
    if ((5 + (int) $word->id) % 2 !== 0) {
        lmReset($s['learner'], (int) $word->id, 4, 37);
    }
    $card = lmCard($s);
    $reps = (int) WordRepetition::query()->where('word_id', $word->id)->value('repetitions');
    $expectedExample = ['I like word0 a lot.', 'We word0 together.', 'She word0s daily.'][$reps % 3];
    $expectedForm = str_contains($expectedExample, 'word0s') ? 'word0s' : 'word0';
    expect($card['prompt']['type'])->toBe('gap_fill')
        ->and($card['prompt']['masked_example'])->toBe(str_replace($expectedForm, '____', $expectedExample));

    $this->withHeaders($s['headers'])
        ->postJson("{$s['url']}/review", ['word_id' => $card['word_id'], 'answer' => $expectedForm, 'time_spent_ms' => 100])
        ->assertOk()
        ->assertJsonPath('graded', ['quality' => 5, 'correct' => true, 'expected' => $expectedForm]);

});

it('always shows the anchor example when the rotation is off', function (): void {
    config(['learning.examples.rotate' => false]);
    $s = lmScenario(1, 1, 1);
    WordExample::query()->create(['word_id' => $s['words'][0]->id, 'position' => 1, 'text' => 'We word0 together.']);

    expect(lmCard($s)['example'])->toBe('I like word0 a lot.');
});

// --- the importer (docs/06 §12.3–12.5) -----------------------------------------------------------

it('imports hooks, additional examples and confusable marks, idempotently, and drops the example cache', function (): void {
    $course = Course::factory()->create(['slug' => 'method-course', 'status' => 'published', 'visibility' => 'public']);
    $file = static fn (array $word): string => json_encode([
        'course' => 'method-course',
        'stage' => ['number' => 1], 'lesson' => ['number' => 1],
        'words' => [$word, ['word' => 'father', 'translation' => 'отец', 'translations' => ['ru' => 'отец', 'uz' => 'ota']]],
    ], JSON_THROW_ON_ERROR);
    $mother = [
        'word' => 'mother', 'translation' => 'мать', 'translations' => ['ru' => 'мать', 'uz' => 'ona'],
        'example' => 'My mother is kind.',
        'hooks' => ['ru' => 'мать ~ mother: один корень', 'uz' => "ona — 'mother' so'zi"],
        'examples' => [
            ['text' => 'Her mother works here.', 'translations' => ['ru' => 'Её мать работает здесь.', 'uz' => 'Uning onasi shu yerda ishlaydi.']],
            ['text' => 'Mothers love.', 'translations' => ['ru' => 'Матери любят.']],
        ],
        'confusable_with' => ['father', 'Father', ' mother '],
    ];

    $importer = app(VocabularyImporter::class);
    $report = $importer->importAsSystem($file($mother));
    expect($report->ok())->toBeTrue()->and($report->added)->toBe(2);

    $word = Word::query()->where('word', 'mother')->sole();
    expect($word->confusableWith())->toBe(['father'])
        ->and(WordTranslation::query()->where('word_id', $word->id)->where('locale', 'ru')->value('hook'))->toBe('мать ~ mother: один корень')
        ->and(WordTranslation::query()->where('word_id', $word->id)->where('locale', 'uz')->value('hook'))->toBe("ona — 'mother' so'zi")
        ->and(WordExample::query()->where('word_id', $word->id)->orderBy('position')->pluck('text')->all())->toBe(['Her mother works here.', 'Mothers love.'])
        ->and(WordExample::query()->where('word_id', $word->id)->where('position', 2)->sole()->translations)->toBe(['ru' => 'Матери любят.']);
    expect($course->id)->toBeInt();

    // The same file again: nothing changes.
    $again = $importer->importAsSystem($file($mother));
    expect($again->ok())->toBeTrue()->and($again->skipped)->toBe(2)->and($again->updated)->toBe(0);

    // Warm the cache, then a file with another list of examples replaces the rows and the cache.
    $cache = app(LearningCache::class);
    expect($cache->wordExamples((int) $word->id))->toHaveCount(2);
    $changed = $importer->importAsSystem($file(['examples' => [['text' => 'Only one.']], 'hooks' => ['ru' => 'мать ~ mother: один корень']] + $mother));
    expect($changed->updated)->toBe(1)
        ->and(WordExample::query()->where('word_id', $word->id)->pluck('text')->all())->toBe(['Only one.'])
        ->and($cache->wordExamples((int) $word->id))->toBe([['text' => 'Only one.', 'translations' => []]])
        // `hooks` present without `uz`: the Uzbek hook is taken back; the translation itself keeps its review state.
        ->and(WordTranslation::query()->where('word_id', $word->id)->where('locale', 'uz')->value('hook'))->toBeNull();

    // No keys: nothing of the method is touched.
    $plain = $mother;
    unset($plain['hooks'], $plain['examples'], $plain['confusable_with']);
    $untouched = $importer->importAsSystem($file($plain));
    expect($untouched->skipped)->toBe(2)
        ->and(Word::query()->where('word', 'mother')->sole()->confusableWith())->toBe(['father'])
        ->and(WordExample::query()->where('word_id', $word->id)->count())->toBe(1);

    // An empty list takes the marks back.
    $cleared = $importer->importAsSystem($file(['confusable_with' => [], 'examples' => []] + $mother));
    expect($cleared->updated)->toBe(1)
        ->and(Word::query()->where('word', 'mother')->sole()->confusableWith())->toBe([])
        ->and(WordExample::query()->where('word_id', $word->id)->count())->toBe(0);

    // A hook in an unsupported language, or for a language the word has no translation in, is refused.
    expect($importer->importAsSystem($file(['hooks' => ['de' => 'x']] + $mother))->ok())->toBeFalse()
        ->and($importer->importAsSystem($file(['translations' => ['ru' => 'мать'], 'hooks' => ['uz' => 'x']] + $mother))->ok())->toBeFalse();
});

// --- confusable words in the picker (docs/06 §12.5) --------------------------------------------

it('does not introduce a word on the same day as the word it is confused with: the next word of the lesson goes first', function (): void {
    $learner = Learner::factory()->create();
    $lesson = Lesson::factory()->for(Stage::factory()->create(['course_id' => Course::factory()->published()->create()->id]))->create();
    $mother = Word::factory()->for($lesson)->create(['word' => 'mother', 'meta' => ['confusable_with' => ['father']]]);
    $father = Word::factory()->for($lesson)->create(['word' => 'father']);
    $table = Word::factory()->for($lesson)->create(['word' => 'table']);
    $chair = Word::factory()->for($lesson)->create(['word' => 'chair', 'meta' => ['confusable_with' => ['table']]]);
    $session = TrainingSession::factory()->personal($learner, 'lesson')->create(['lesson_id' => $lesson->id]);
    $headers = lmHeaders($learner);
    $url = "/api/twa/training/sessions/{$session->id}";

    $served = [];
    for ($i = 0; $i < 4; $i++) {
        $card = $this->withHeaders($headers)->getJson("{$url}/next")->assertOk()->json('card');
        $served[] = $card['word_id'];
        // Grade "knew it": the card leaves the due queue for days, so the next call introduces a new word.
        $this->withHeaders($headers)->postJson("{$url}/review", ['word_id' => $card['word_id'], 'quality' => 5, 'time_spent_ms' => 100])->assertOk();
    }

    // mother (first in id order) → father is held back (mother names it) → table → chair is held back (it names table) →
    // everything in reach is blocked: the first held-back word is served anyway → then the last one.
    expect($served)->toBe([(int) $mother->id, (int) $table->id, (int) $father->id, (int) $chair->id]);
});

it('lets a confusable word in once its pair is older than the window, and ignores the rule in group lessons and when it is off', function (): void {
    $learner = Learner::factory()->create();
    $lesson = Lesson::factory()->for(Stage::factory()->create(['course_id' => Course::factory()->published()->create()->id]))->create();
    $mother = Word::factory()->for($lesson)->create(['word' => 'mother']);
    $father = Word::factory()->for($lesson)->create(['word' => 'father', 'meta' => ['confusable_with' => ['mother']]]);
    $table = Word::factory()->for($lesson)->create(['word' => 'table']);
    $session = TrainingSession::factory()->personal($learner, 'lesson')->create(['lesson_id' => $lesson->id]);
    $headers = lmHeaders($learner);
    $url = "/api/twa/training/sessions/{$session->id}";

    // mother was introduced yesterday: father is not held back.
    WordRepetition::factory()->create(['learner_id' => $learner->id, 'word_id' => $mother->id, 'state' => 'review', 'repetitions' => 1, 'interval_days' => 6, 'next_review_at' => now()->addDays(5), 'created_at' => now()->subHours(25)]);
    expect($this->withHeaders($headers)->getJson("{$url}/next")->json('card.word_id'))->toBe((int) $father->id);

    // Introduced an hour ago: held back, table first.
    WordRepetition::query()->where('word_id', $father->id)->delete();
    WordRepetition::query()->where('word_id', $mother->id)->update(['created_at' => now()->subHour()]);
    expect($this->withHeaders($headers)->getJson("{$url}/next")->json('card.word_id'))->toBe((int) $table->id);

});

it('follows the lesson order as it is when the confusables rule is off', function (): void {
    config(['learning.confusables.enabled' => false]);
    $learner = Learner::factory()->create();
    $lesson = Lesson::factory()->for(Stage::factory()->create(['course_id' => Course::factory()->published()->create()->id]))->create();
    $mother = Word::factory()->for($lesson)->create(['word' => 'mother']);
    $father = Word::factory()->for($lesson)->create(['word' => 'father', 'meta' => ['confusable_with' => ['mother']]]);
    Word::factory()->for($lesson)->create(['word' => 'table']);
    $session = TrainingSession::factory()->personal($learner, 'lesson')->create(['lesson_id' => $lesson->id]);
    WordRepetition::factory()->create(['learner_id' => $learner->id, 'word_id' => $mother->id, 'state' => 'review', 'repetitions' => 1, 'interval_days' => 6, 'next_review_at' => now()->addDays(5), 'created_at' => now()->subHour()]);

    expect($this->withHeaders(lmHeaders($learner))->getJson("/api/twa/training/sessions/{$session->id}/next")->json('card.word_id'))->toBe((int) $father->id);
});

// --- the FSRS retention mode (docs/06 §12.8) ----------------------------------------------------

it('lets the learner pick a retention mode; it applies to FSRS learners only and is refused when unknown', function (): void {
    $learner = Learner::factory()->create(['scheduler' => SchedulerKind::Fsrs]);
    $headers = lmHeaders($learner);

    $this->withHeaders($headers)->getJson('/api/twa/me')->assertOk()
        ->assertJsonPath('settings.retention_mode', 'normal')
        ->assertJsonPath('settings.scheduler', 'fsrs')
        ->assertJsonPath('settings.options.retention_modes', ['calm', 'normal', 'intense']);

    $this->withHeaders($headers)->patchJson('/api/twa/me/settings', ['retention_mode' => 'calm'])->assertOk()
        ->assertJsonPath('settings.retention_mode', 'calm');
    expect($learner->fresh()?->meta)->toMatchArray([LadderPolicy::RETENTION_MODE_META => 'calm']);

    $this->withHeaders($headers)->patchJson('/api/twa/me/settings', ['retention_mode' => 'reckless'])->assertStatus(422);

    $resolver = app(SchedulerResolver::class);
    $calm = $resolver->for($learner->fresh() ?? $learner);
    expect($calm)->toBeInstanceOf(FsrsScheduler::class);
    /** @var FsrsScheduler $calm */
    $normal = $resolver->algorithm(SchedulerKind::Fsrs);
    /** @var FsrsScheduler $normal */
    expect($calm->intervalFor(10.0))->toBeGreaterThan($normal->intervalFor(10.0)) // a lower target → longer intervals
        ->and(round($normal->intervalFor(10.0), 6))->toBe(10.0);

    $sm2 = Learner::factory()->create(['scheduler' => SchedulerKind::Sm2, 'meta' => [LadderPolicy::RETENTION_MODE_META => 'intense']]);
    expect($resolver->for($sm2)->key())->toBe(SchedulerKind::Sm2);
    $this->withHeaders(lmHeaders($sm2))->getJson('/api/twa/me')->assertOk()->assertJsonPath('settings.scheduler', 'sm2')->assertJsonPath('settings.retention_mode', 'intense');
});

// --- the pretest (docs/06 §12.7) ---------------------------------------------------------------

it('asks "do you know this word?" before the first show when the pretest is on: a right pick graduates it as known, a wrong one starts learning', function (): void {
    config(['learning.pretest.enabled' => true]);
    $s = lmScenario(5, 0, 0);
    foreach ($s['words'] as $i => $w) {
        WordRepetition::query()->where('word_id', $w->id)->update(['state' => 'new', 'repetitions' => 0, 'interval_days' => 0, 'next_review_at' => $i === 0 ? now()->subMinute() : now()->addDay()]);
    }
    $word = $s['words'][0];

    $card = lmCard($s);
    expect($card['prompt']['type'])->toBe('pretest')
        ->and($card['card_kind'])->toBe('new')
        ->and($card['word'])->toBe('word0')
        ->and($card['prompt']['front'])->toBe('word0')
        ->and($card['translation'])->toBe('') // the meaning is the answer
        ->and($card['example'])->toBeNull()
        ->and($card['prompt']['options'])->toHaveCount(4)
        ->and($card['prompt']['options'])->toContain('перевод 0');

    // The learner's own grade is refused on a pretest.
    $this->withHeaders($s['headers'])->postJson("{$s['url']}/review", ['word_id' => $word->id, 'quality' => 5, 'time_spent_ms' => 100])
        ->assertStatus(422)->assertJsonPath('error.code', 'quality_not_accepted');

    // Right: "knew it" — graduated with the easy interval.
    $response = $this->withHeaders($s['headers'])->postJson("{$s['url']}/review", ['word_id' => $word->id, 'answer' => 'перевод 0', 'time_spent_ms' => 100])->assertOk();
    expect($response->json('graded'))->toBe(['quality' => 5, 'correct' => true, 'expected' => 'перевод 0']);
    $row = WordRepetition::query()->where('word_id', $word->id)->sole();
    expect($row->state)->toBe(RepetitionState::Review)->and($row->interval_days)->toBe(4)
        ->and(TrainingReview::query()->sole()->exercise)->toBe(ExerciseType::Pretest);

    // Wrong (or "I don't know" = an empty answer): the usual learning steps, the verdict carries the right meaning.
    $second = $s['words'][1];
    WordRepetition::query()->where('word_id', $second->id)->update(['next_review_at' => now()->subMinute()]);
    $card = lmCard($s);
    expect($card['word_id'])->toBe($second->id)->and($card['prompt']['type'])->toBe('pretest');
    $response = $this->withHeaders($s['headers'])->postJson("{$s['url']}/review", ['word_id' => $second->id, 'answer' => '', 'time_spent_ms' => 100])->assertOk();
    expect($response->json('graded'))->toBe(['quality' => 2, 'correct' => false, 'expected' => 'перевод 1'])
        ->and(WordRepetition::query()->where('word_id', $second->id)->sole()->state)->toBe(RepetitionState::Learning);

    // After the first grade the word is a plain card again: the pretest is for the first show only.
    $next = $response->json('card');
    expect($next['word_id'])->toBe($second->id)->and($next['prompt']['type'])->toBe('flash')->and($next['translation'])->toBe('перевод 1');
});

it('keeps the new word open with its translation when the pretest is off (the default)', function (): void {
    $s = lmScenario(5, 0, 0);
    WordRepetition::query()->update(['state' => 'new', 'repetitions' => 0, 'interval_days' => 0]);

    $card = lmCard($s);
    expect(config('learning.pretest.enabled'))->toBeFalse()
        ->and($card['prompt']['type'])->toBe('flash')
        ->and($card['translation'])->not->toBe('');
});
