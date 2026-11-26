<?php

declare(strict_types=1);

/*
 * F2 docs/35 §3.3 b, c, d, docs/06 §16: the exercises of a phrase through the API - the typed rung is the gap-fill of the key word in
 * the phrase itself (the mask, the check by SpellingGrader with its typo), never spelling; the options of a phrase are phrases and
 * the options of a word are words; the card carries the labels of the phrase and hides the literal translation while it would give
 * the answer away; and a phrase costs no statement more than a word on `/next`, `/review` and on the third layer of options.
 */

use App\Domain\Content\Services\PlatformDistractorPool;
use App\Domain\Content\Services\VocabularyImporter;
use App\Domain\Content\Services\WordTranslationResolver;
use App\Domain\Learning\Enums\ExerciseType;
use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Learning\Services\ExercisePromptBuilder;
use App\Domain\Learning\Services\ReviewSubmissionService;
use App\Domain\Twa\Services\JwtService;
use App\Models\Course;
use App\Models\Learner;
use App\Models\TrainingSession;
use App\Models\Word;
use App\Models\WordRepetition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CardAnswers;
use Tests\Support\HotPathFixtures as Fx;
use Tests\Support\PhraseKit;
use Tests\Support\QueryLog;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['learning.ladder.typed_recall_in_free' => true]);
});

/** @return array{0: Learner, 1: array<string, string>} */
function phxLearner(): array
{
    $learner = Learner::factory()->onboarded()->create(['interface_locale' => 'ru', 'timezone' => 'Asia/Tashkent']);

    return [$learner, ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']]];
}

/** Due day-based cards of the learner on the typed rung (interval 8 days). @param list<Word> $words */
function phxDue(Learner $learner, array $words, int $interval = 8, int $repetitions = 4): void
{
    foreach ($words as $word) {
        WordRepetition::factory()->create([
            'learner_id' => $learner->id, 'word_id' => $word->id, 'state' => RepetitionState::Review->value, 'repetitions' => $repetitions,
            'interval_days' => $interval, 'last_reviewed_at' => now()->subDays($interval)->subHour(), 'next_review_at' => now()->subHour(),
            'easiness_factor' => 2.5, 'is_hard' => false, 'last_quality' => 4, 'lapses' => 0, 'meta' => [],
        ]);
    }
}

/** A published platform course of phrases (`exam_tag = phrases`) with one lesson of the given phrases [text, gap]. @param list<array{0: string, 1: string}> $phrases */
function phxPhraseCourse(string $slug, array $phrases): Course
{
    $course = Course::factory()->published()->create(['slug' => $slug, 'exam_tag' => 'phrases']);
    $report = app(VocabularyImporter::class)->importAsSystem(json_encode([
        'course' => $slug,
        'stage' => ['number' => 1],
        'lesson' => ['number' => 1],
        'words' => array_map(fn (array $p): array => [
            'word' => $p[0], 'part_of_speech' => 'phrase', 'translations' => ['ru' => "смысл фразы «{$p[0]}»"],
            'phrase' => ['kind' => 'idiom', 'register' => 'neutral', 'gap' => $p[1]],
        ], $phrases),
    ], JSON_THROW_ON_ERROR));
    expect($report->ok())->toBeTrue();

    return $course;
}

it('asks the key word of a phrase on the typed rung: the phrase masked, the translation shown, never spelling', function (): void {
    [, , $phrases] = PhraseKit::import();
    [$learner, $h] = phxLearner();
    phxDue($learner, $phrases);

    $opened = $this->withHeaders($h)->postJson('/api/twa/training/sessions', ['mode' => 'daily_review'])->assertOk()->json();
    $card = $opened['card'];
    $types = [];
    for ($guard = 0; $card !== null && $guard < 40; $guard++) {
        $type = CardAnswers::typeOf($card);
        $types[] = $type->value;
        $word = Word::query()->findOrFail($card['word_id']);
        if ($type === ExerciseType::GapFill) {
            $gap = $word->meta['phrase']['gap'];
            expect($card['prompt']['masked_example'])->toBe(preg_replace('/(?<![\p{L}\'])'.$gap.'(?![\p{L}\'])/iu', '____', $word->word))
                ->and($card['prompt']['front'])->toBe(app(WordTranslationResolver::class)->resolve($word, 'ru')->text)
                ->and($card['prompt']['answer_length'])->toBe(mb_strlen($gap))
                // The answer is in the phrase: the card shows neither the phrase nor its literal translation.
                ->and($card['word'])->toBe('')
                ->and($card['example'])->toBeNull()
                ->and($card['phrase'])->toMatchArray(['kind' => $word->meta['phrase']['kind'], 'literal' => null]);
        }
        $answered = $this->withHeaders($h)->postJson("/api/twa/training/sessions/{$opened['session_id']}/review", CardAnswers::body($card))->assertOk()->json();
        if ($type === ExerciseType::GapFill) {
            expect($answered['graded'])->toMatchArray(['correct' => true, 'quality' => 5, 'expected' => mb_strtolower($word->meta['phrase']['gap'])]);
        }
        $card = $answered['card'];
    }

    expect(array_count_values($types)['gap_fill'] ?? 0)->toBe(12)
        ->and($types)->not->toContain('spelling');
});

it('grades the key word with the spelling grader: a typo passes as a typo, the whole phrase or another word fails', function (string $answer, int $quality): void {
    [, , $phrases] = PhraseKit::import();
    [$learner] = phxLearner();
    $phrase = collect($phrases)->firstWhere('word', 'no worries');
    phxDue($learner, [$phrase]);
    $session = TrainingSession::factory()->personal($learner, 'daily_review')->create();

    $accepted = app(ReviewSubmissionService::class)->submitAnswer($session, $learner->fresh(), $phrase->id, $answer, 900);

    expect($accepted->exercise)->toBe(ExerciseType::GapFill)
        ->and($accepted->verdict?->quality)->toBe($quality)
        ->and($accepted->verdict?->normalizedExpected)->toBe('worries');
})->with([
    'exact' => ['worries', 5],
    'case' => ['Worries', 5],
    'a typo' => ['woories', 4],
    'the whole phrase' => ['no worries', 1],
    'another word' => ['problems', 1],
]);

it('puts only phrases among the options of a phrase and only words among the options of a word', function (): void {
    PhraseKit::import();
    // A public platform course of words with plenty of distractors: the nearest courses of the cards below are short of them.
    Fx::course([1 => [1 => 30]], ['slug' => 'words-public']);
    $small = phxPhraseCourse('phrases-small', [['break a leg', 'leg'], ['spill the beans', 'beans']]);
    ['words' => $smallWords] = Fx::course([1 => [1 => 2]], ['slug' => 'words-small']);

    $builder = app(ExercisePromptBuilder::class);
    $resolver = app(WordTranslationResolver::class);
    $phrase = Word::query()->where('word', 'break a leg')->sole();
    $word = Word::query()->findOrFail($smallWords['1.1']['active'][0]);
    $phraseTexts = Word::query()->where('part_of_speech', 'phrase')->pluck('word')->all();
    $phraseMeanings = Word::query()->where('part_of_speech', 'phrase')->get()->map(fn (Word $w): string => $resolver->resolve($w, 'ru')->text)->all();

    foreach (range(1, 8) as $seed) {
        foreach ([ExerciseType::Meaning, ExerciseType::Reverse] as $type) {
            $forPhrase = $builder->build($type, $phrase, $resolver->resolve($phrase, 'ru'), 'ru', null, $seed);
            $forWord = $builder->build($type, $word, $resolver->resolve($word, 'ru'), 'ru', null, $seed);

            expect($forPhrase->type)->toBe($type)
                ->and($forPhrase->options)->toHaveCount(4)
                ->and($forWord->type)->toBe($type)
                ->and($forWord->options)->toHaveCount(4);
            $phraseOptions = $type === ExerciseType::Reverse ? $phraseTexts : $phraseMeanings;
            foreach ($forPhrase->options as $option) {
                expect($option)->toBeIn($phraseOptions);
            }
            foreach ($forWord->options as $option) {
                expect($option)->not->toBeIn($phraseOptions);
            }
        }
    }
    expect($small->exam_tag)->toBe('phrases');
});

it('carries the labels of the phrase on the card, hides the literal while it gives the answer away and reveals it after the pick', function (): void {
    [, $lesson] = PhraseKit::import();
    [, $h] = phxLearner();

    $opened = $this->withHeaders($h)->postJson('/api/twa/training/sessions', ['mode' => 'lesson', 'lesson_id' => $lesson->id])->assertOk()->json();
    $card = $opened['card'];
    $seen = ['flash' => false, 'choice' => false];
    for ($guard = 0; $card !== null && $guard < 60; $guard++) {
        $word = Word::query()->findOrFail($card['word_id']);
        $type = CardAnswers::typeOf($card);
        expect($card['phrase'])->toMatchArray(['kind' => $word->meta['phrase']['kind'], 'register' => $word->meta['phrase']['register'], 'variant' => $word->meta['phrase']['variant']]);
        if ($type === ExerciseType::Flash) {
            // The first meeting teaches the phrase: everything is shown.
            expect($card['phrase']['literal'])->toBe($word->meta['phrase']['literal']['ru']);
            $seen['flash'] = true;
        }
        $answered = $this->withHeaders($h)->postJson("/api/twa/training/sessions/{$opened['session_id']}/review", CardAnswers::body($card))->assertOk()->json();
        if ($type->isChoice()) {
            expect($card['phrase']['literal'])->toBeNull()
                ->and($answered['revealed']['phrase'])->toBe(['kind' => $word->meta['phrase']['kind'], 'register' => $word->meta['phrase']['register'], 'variant' => $word->meta['phrase']['variant'], 'literal' => $word->meta['phrase']['literal']['ru']]);
            $seen['choice'] = true;
        }
        $card = $answered['card'];
    }

    expect($seen)->toBe(['flash' => true, 'choice' => true]);
});

it('reveals the phrase with its literal after a gap-fill answer, right or wrong, without the example', function (int $quality): void {
    [, , $phrases] = PhraseKit::import();
    [$learner, $h] = phxLearner();
    phxDue($learner, $phrases);

    $opened = $this->withHeaders($h)->postJson('/api/twa/training/sessions', ['mode' => 'daily_review'])->assertOk()->json();
    $card = $opened['card'];
    $seen = 0;
    for ($guard = 0; $card !== null && $guard < 40; $guard++) {
        $word = Word::query()->findOrFail($card['word_id']);
        $type = CardAnswers::typeOf($card);
        $answered = $this->withHeaders($h)->postJson("/api/twa/training/sessions/{$opened['session_id']}/review", CardAnswers::body($card, $quality))->assertOk()->json();
        if ($type === ExerciseType::GapFill) {
            expect($card['phrase']['literal'])->toBeNull()
                ->and($answered['revealed'])->toMatchArray([
                    'word' => $word->word,
                    'translation' => app(WordTranslationResolver::class)->resolve($word, 'ru')->text,
                    // No options on a gap-fill: the whole phrase the key word was cut from.
                    'correct' => trim($word->word),
                    'example' => null, 'example_translation' => null, 'example_audio_url' => null, 'hook' => null, 'confused_with' => null,
                    'phrase' => ['kind' => $word->meta['phrase']['kind'], 'register' => $word->meta['phrase']['register'], 'variant' => $word->meta['phrase']['variant'], 'literal' => $word->meta['phrase']['literal']['ru'] ?? null],
                ]);
            $seen += isset($word->meta['phrase']['literal']['ru']) ? 1 : 0;
        }
        $card = $answered['card'];
    }

    expect($seen)->toBeGreaterThan(0);
})->with(['right' => [4], 'wrong' => [1]]);

it('reveals the phrase with its literal after a pretest pick: the translation is the right option', function (): void {
    config(['learning.pretest.enabled' => true]);
    [, $lesson, $phrases] = PhraseKit::import();
    [, $h] = phxLearner();

    $opened = $this->withHeaders($h)->postJson('/api/twa/training/sessions', ['mode' => 'lesson', 'lesson_id' => $lesson->id])->assertOk()->json();
    $card = $opened['card'];
    expect(CardAnswers::typeOf($card))->toBe(ExerciseType::Pretest);
    $word = Word::query()->findOrFail($card['word_id']);
    $translation = app(WordTranslationResolver::class)->resolve($word, 'ru')->text;

    $answered = $this->withHeaders($h)->postJson("/api/twa/training/sessions/{$opened['session_id']}/review", CardAnswers::body($card))->assertOk()->json();

    expect($card['phrase']['literal'])->toBeNull()
        ->and($answered['graded']['correct'])->toBeTrue()
        ->and($answered['revealed'])->toMatchArray([
            'word' => $word->word, 'translation' => $translation, 'correct' => trim($translation), 'example' => null, 'hook' => null,
            'phrase' => ['kind' => $word->meta['phrase']['kind'], 'register' => $word->meta['phrase']['register'], 'variant' => $word->meta['phrase']['variant'], 'literal' => $word->meta['phrase']['literal']['ru'] ?? null],
        ]);
});

it('keeps `revealed` null after a gap-fill, spelling or pretest answer of a word', function (): void {
    config(['learning.pretest.enabled' => true]);
    expect(ExerciseType::GapFill->revealsAfterAnswer(false))->toBeFalse()
        ->and(ExerciseType::Pretest->revealsAfterAnswer(false))->toBeFalse()
        ->and(ExerciseType::Spelling->revealsAfterAnswer(true))->toBeFalse()
        ->and(ExerciseType::GapFill->revealsAfterAnswer(true))->toBeTrue()
        ->and(ExerciseType::Pretest->revealsAfterAnswer(true))->toBeTrue()
        ->and(ExerciseType::Meaning->revealsAfterAnswer(false))->toBeTrue();

    ['lessons' => $lessons] = Fx::course([1 => [1 => 4]]);
    [, $h] = phxLearner();
    $opened = $this->withHeaders($h)->postJson('/api/twa/training/sessions', ['mode' => 'lesson', 'lesson_id' => $lessons['1.1']->id])->assertOk()->json();
    $card = $opened['card'];
    expect(CardAnswers::typeOf($card))->toBe(ExerciseType::Pretest)
        ->and($card['phrase'])->toBeNull();

    $this->withHeaders($h)->postJson("/api/twa/training/sessions/{$opened['session_id']}/review", CardAnswers::body($card))
        ->assertOk()->assertJsonPath('revealed', null);
});

it('sends no phrase block on the card of a word', function (): void {
    ['lessons' => $lessons] = Fx::course([1 => [1 => 5]]);
    [, $h] = phxLearner();

    $opened = $this->withHeaders($h)->postJson('/api/twa/training/sessions', ['mode' => 'lesson', 'lesson_id' => $lessons['1.1']->id])->assertOk()->json();

    expect($opened['card'])->toHaveKey('phrase')
        ->and($opened['card']['phrase'])->toBeNull();
});

/**
 * The statements of the steps of a lesson session of 25 cards (the shape of TrainingCycleQueryBudgetTest), words or phrases.
 *
 * @return array{open: int, next: int, review: int, ahead: int}
 */
function phxCycle(object $test, bool $phrases): array
{
    config()->set('billing.plans.free.limits.new_words_per_day', 20);
    ['course' => $course, 'lessons' => $lessons, 'words' => $words] = Fx::course([1 => [1 => 25]]);
    if ($phrases) {
        $course->update(['exam_tag' => 'phrases']);
        foreach ($words['1.1']['active'] as $i => $id) {
            $gap = 'word'.strtr((string) $i, '0123456789', 'abcdefghij');
            DB::table('words')->where('id', $id)->update(['word' => "say it {$gap}", 'part_of_speech' => 'phrase', 'meta' => json_encode(['phrase' => ['kind' => 'formula', 'register' => 'neutral', 'variant' => 'both', 'gap' => $gap, 'literal' => ['ru' => "буквально {$i}"]]])]);
        }
    }
    $lessonId = (int) $lessons['1.1']->id;

    // Another learner warms what is shared (the lesson's word count, the translations of its first cards).
    [, $other] = phxLearner();
    $opened = $test->withHeaders($other)->postJson('/api/twa/training/sessions', ['mode' => 'lesson', 'lesson_id' => $lessonId, 'source' => 'self'])->assertOk()->json();
    $card = $opened['card'];
    for ($i = 0; $i < 4; $i++) {
        $card = $test->withHeaders($other)->postJson("/api/twa/training/sessions/{$opened['session_id']}/review", CardAnswers::body($card, 4, 100))->assertOk()->json('card');
    }

    [, $h] = phxLearner();
    $test->withHeaders($h)->getJson('/api/twa/me')->assertOk();

    $opened = null;
    $open = QueryLog::during(function () use (&$opened, $test, $h, $lessonId): void {
        $opened = $test->withHeaders($h)->postJson('/api/twa/training/sessions', ['mode' => 'lesson', 'lesson_id' => $lessonId, 'source' => 'self'])->assertOk()->json();
    });
    $base = "/api/twa/training/sessions/{$opened['session_id']}";
    $card = $opened['card'];
    $next = QueryLog::during(function () use (&$card, $test, $h, $base): void {
        $card = $test->withHeaders($h)->getJson("{$base}/next")->assertOk()->json('card');
    });
    $card = $test->withHeaders($h)->postJson("{$base}/review", CardAnswers::body($card, 4, 100))->assertOk()->json('card');
    $review = QueryLog::during(function () use (&$card, $test, $h, $base): void {
        $card = $test->withHeaders($h)->postJson("{$base}/review", CardAnswers::body($card, 4, 100))->assertOk()->json('card');
    });
    for ($i = 0; $i < 18; $i++) {
        $card = $test->withHeaders($h)->postJson("{$base}/review", CardAnswers::body($card, 4, 100))->assertOk()->json('card');
    }
    $body = CardAnswers::body($card, 4, 100);
    $ahead = QueryLog::during(function () use (&$card, $test, $h, $base, $body): void {
        $card = $test->withHeaders($h)->postJson("{$base}/review", $body)->assertOk()->json('card');
    });
    if ($phrases) {
        expect($card['phrase'])->not->toBeNull();
    }

    return ['open' => count($open), 'next' => count($next), 'review' => count($review), 'ahead' => count($ahead)];
}

it('costs a phrase no statement more than a word on every step of the training cycle', function (bool $phrasesFirst): void {
    $first = phxCycle($this, $phrasesFirst);
    $second = phxCycle($this, ! $phrasesFirst);
    [$phrase, $word] = $phrasesFirst ? [$first, $second] : [$second, $first];

    expect($phrase)->toBe($word)
        // The ceilings of TrainingCycleQueryBudgetTest.
        ->and($phrase['open'])->toBeLessThanOrEqual(10)
        ->and($phrase['next'])->toBeLessThanOrEqual(5)
        ->and($phrase['review'])->toBeLessThanOrEqual(14)
        ->and($phrase['ahead'])->toBeLessThanOrEqual(14);
})->with(['phrases first' => [true], 'words first' => [false]]);

it('reads the third layer of options of a phrase from its own entry of the platform pool, as for a word: one statement on a miss, none on a hit', function (): void {
    PhraseKit::import();
    Fx::course([1 => [1 => 30]], ['slug' => 'words-public']);
    phxPhraseCourse('phrases-small', [['break a leg', 'leg'], ['spill the beans', 'beans']]);
    ['words' => $smallWords] = Fx::course([1 => [1 => 2]], ['slug' => 'words-small']);
    $builder = app(ExercisePromptBuilder::class);
    $resolver = app(WordTranslationResolver::class);
    $phrase = Word::query()->where('word', 'break a leg')->sole();
    $word = Word::query()->findOrFail($smallWords['1.1']['active'][0]);
    $phraseTranslation = $resolver->resolve($phrase, 'ru');
    $wordTranslation = $resolver->resolve($word, 'ru');
    // Warm the lesson pools and the translations of the candidates: what is measured is the walk over the layers.
    $builder->build(ExerciseType::Reverse, $phrase, $phraseTranslation, 'ru', null, 1);
    $builder->build(ExerciseType::Reverse, $word, $wordTranslation, 'ru', null, 1);

    // The pool of the platform is cached (docs/38, «Дополнение: варианты ответа»): the course layer is the only statement on `words`.
    $forPhrase = QueryLog::during(fn () => $builder->build(ExerciseType::Reverse, $phrase, $phraseTranslation, 'ru', null, 1));
    $forWord = QueryLog::during(fn () => $builder->build(ExerciseType::Reverse, $word, $wordTranslation, 'ru', null, 1));

    expect(count($forPhrase))->toBe(count($forWord))->toBe(3) // the course of the card (two key lookups), the course layer
        ->and(QueryLog::matching($forPhrase, '/from ["`]words["`]/'))->toHaveCount(1)
        ->and(QueryLog::matching($forPhrase, '/["`]courses["`]/'))->toBe([])
        ->and(QueryLog::matching($forWord, '/["`]courses["`]/'))->toBe([]);

    // On a miss each kind fills its own entry with one statement, by its own filter of the courses.
    PlatformDistractorPool::forget();
    $coldPhrase = QueryLog::during(fn () => $builder->build(ExerciseType::Reverse, $phrase, $phraseTranslation, 'ru', null, 1));
    $coldWord = QueryLog::during(fn () => $builder->build(ExerciseType::Reverse, $word, $wordTranslation, 'ru', null, 1));

    expect(count($coldPhrase))->toBe(count($coldWord))->toBe(4)
        ->and(QueryLog::matching($coldPhrase, '/["`]exam_tag["`] = \?/'))->toHaveCount(1)
        ->and(QueryLog::matching($coldWord, '/["`]exam_tag["`] is null or ["`]courses["`]\.["`]exam_tag["`] <> \?/'))->toHaveCount(1);
});
