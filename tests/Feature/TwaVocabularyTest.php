<?php

declare(strict_types=1);

/*
 * "My vocabulary" of the Mini App (docs/40 §2.3, docs/04 A.25): only the learner's graded rows over active words, the filters by the
 * memory strength (the SQL rule agrees with MemoryStrength::of), the search by the word and the translation (LIKE escaped, the case
 * folded), keyset pages without duplicates or gaps, the counts on the first page only, the errors. sqlite's `lower()` folds ASCII only,
 * so the case is checked with Latin letters; Cyrillic in upper case only on PostgreSQL (the last test, skipped on sqlite).
 */

use App\Domain\Content\Services\WordAudioService;
use App\Domain\Learning\DTO\VocabularyCursor;
use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Learning\Support\MemoryStrength;
use App\Domain\Twa\Services\JwtService;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Word;
use App\Models\WordRepetition;
use App\Models\WordTranslation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\HotPathFixtures as Fx;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-27 09:00:00');
    config(['learning.stages' => ['fresh_review_days' => 3, 'known_from_interval_days' => 7, 'mastered_from_interval_days' => 21]]);
    $this->lesson = Fx::course([1 => [1 => 0]])['lessons']['1.1'];
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * A word of the lesson and the learner's row of it.
 *
 * @param  array<string, mixed>  $row  attributes of the row of `word_repetitions`
 * @param  array<string, mixed>  $word  attributes of the word
 */
function vocWord(Lesson $lesson, Learner $learner, string $text, array $row = [], array $word = []): Word
{
    $model = Word::factory()->create(['lesson_id' => $lesson->id, 'word' => $text, 'translation' => 'т-'.$text, 'transcription' => null, 'audio_path' => null, ...$word]);
    WordRepetition::factory()->create([
        'learner_id' => $learner->id,
        'word_id' => $model->id,
        'state' => RepetitionState::Review->value,
        'repetitions' => 2,
        'interval_days' => 10,
        'last_quality' => 4,
        'last_reviewed_at' => now()->subDay(),
        'next_review_at' => now()->addDays(5),
        'is_hard' => false,
        ...$row,
    ]);

    return $model;
}

/** @param  array<string, int|string>  $query */
function vocGet(object $test, Learner $learner, array $query = []): TestResponse
{
    $headers = ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];

    return $test->withHeaders($headers)->getJson('/api/twa/vocabulary'.($query === [] ? '' : '?'.http_build_query($query)));
}

/**
 * @param  array<string, int|string>  $query
 * @return list<string>
 */
function vocWords(object $test, Learner $learner, array $query = []): array
{
    return array_column(vocGet($test, $learner, $query)->assertOk()->json('items'), 'word');
}

it('answers an empty vocabulary with zero counts, no rows and no cursor', function (): void {
    $learner = Learner::factory()->create();

    vocGet($this, $learner)->assertOk()->assertExactJson([
        'total' => 0, 'counts' => ['all' => 0, 'weak' => 0, 'strong' => 0], 'items' => [], 'next_cursor' => null,
    ]);
});

it('lists only the learner\'s own graded rows over active words, alphabetically, with the shape of the contract', function (): void {
    $learner = Learner::factory()->create(['interface_locale' => 'ru']);
    $other = Learner::factory()->create();

    $tea = vocWord($this->lesson, $learner, 'tea', ['interval_days' => 12, 'next_review_at' => now()->addDays(12)], ['transcription' => '/tiː/']);
    vocWord($this->lesson, $learner, 'Apple', ['is_hard' => true, 'state' => 'learning', 'repetitions' => 0, 'interval_days' => 0, 'last_quality' => 2, 'next_review_at' => now()->addMinutes(10)]);
    vocWord($this->lesson, $learner, 'served', ['last_reviewed_at' => null, 'state' => 'new', 'last_quality' => null, 'repetitions' => 0, 'interval_days' => 0]); // served, never graded
    vocWord($this->lesson, $learner, 'gone', [], ['is_active' => false]); // the course switched the word off
    vocWord($this->lesson, $other, 'stranger'); // somebody else's

    $response = vocGet($this, $learner)->assertOk()
        ->assertExactJsonStructure(['total', 'counts' => ['all', 'weak', 'strong'], 'items' => ['*' => ['word_id', 'word', 'translation', 'translation_fallback', 'transcription', 'audio_url', 'strength', 'stage', 'next_in_days', 'is_hard']], 'next_cursor']);

    expect($response->json('total'))->toBe(2)
        ->and($response->json('counts'))->toBe(['all' => 2, 'weak' => 1, 'strong' => 1])
        ->and($response->json('next_cursor'))->toBeNull()
        ->and($response->json('items'))->toBe([
            ['word_id' => $response->json('items.0.word_id'), 'word' => 'Apple', 'translation' => 'т-Apple', 'translation_fallback' => false, 'transcription' => null, 'audio_url' => null,
                'strength' => 1, 'stage' => 'new', 'next_in_days' => null, 'is_hard' => true],
            ['word_id' => $tea->id, 'word' => 'tea', 'translation' => 'т-tea', 'translation_fallback' => false, 'transcription' => '/tiː/', 'audio_url' => null,
                'strength' => 4, 'stage' => 'known', 'next_in_days' => 12, 'is_hard' => false],
        ]);
});

it('gives the url of the pre-generated audio of the word, as the training card does', function (): void {
    $learner = Learner::factory()->create();
    $path = app(WordAudioService::class)->pathFor('tea');
    $word = vocWord($this->lesson, $learner, 'tea', [], ['audio_path' => $path]);
    vocWord($this->lesson, $learner, 'stale', [], ['audio_path' => $path]); // the file of another text: no url

    $items = vocGet($this, $learner)->assertOk()->json('items');

    expect($items[1]['word'])->toBe('tea')
        ->and($items[1]['audio_url'])->toBe(app(WordAudioService::class)->urlFor($word))->not->toBeNull()
        ->and($items[0]['audio_url'])->toBeNull();
});

it('filters weak and strong by the memory strength, and the SQL rule agrees with MemoryStrength for every kind of row', function (): void {
    $learner = Learner::factory()->create();
    $rows = [
        'new-graded' => ['state' => 'new', 'repetitions' => 0, 'interval_days' => 0, 'last_quality' => 1],
        'learning' => ['state' => 'learning', 'repetitions' => 0, 'interval_days' => 0, 'last_quality' => 3],
        'relearning' => ['state' => 'relearning', 'repetitions' => 0, 'interval_days' => 0, 'last_quality' => 2],
        'review-1' => ['interval_days' => 1], 'review-2' => ['interval_days' => 2], 'review-3' => ['interval_days' => 3],
        'review-6' => ['interval_days' => 6], 'review-7' => ['interval_days' => 7], 'review-20' => ['interval_days' => 20],
        'review-21' => ['interval_days' => 21], 'review-90' => ['interval_days' => 90],
    ];
    foreach ($rows as $text => $row) {
        vocWord($this->lesson, $learner, $text, $row);
    }

    $all = collect(vocGet($this, $learner)->assertOk()->json('items'))->keyBy('word');
    foreach ($rows as $text => $row) {
        $stored = WordRepetition::query()->where('learner_id', $learner->id)->where('word_id', $all[$text]['word_id'])->firstOrFail();
        expect($all[$text]['strength'])->toBe(MemoryStrength::ofRepetition($stored), $text);
    }
    expect($all->pluck('strength', 'word')->all())->toBe([
        'learning' => 1, 'new-graded' => 1, 'relearning' => 1, 'review-1' => 2, 'review-2' => 2, 'review-20' => 4, 'review-21' => 5,
        'review-3' => 3, 'review-6' => 3, 'review-7' => 4, 'review-90' => 5,
    ]);

    $weak = vocWords($this, $learner, ['filter' => 'weak']);
    $strong = vocWords($this, $learner, ['filter' => 'strong']);
    expect($weak)->toBe($all->filter(fn (array $i): bool => $i['strength'] <= 2)->keys()->all())
        ->and($strong)->toBe($all->filter(fn (array $i): bool => $i['strength'] >= 4)->keys()->all())
        ->and(vocGet($this, $learner, ['filter' => 'weak'])->json('counts'))->toBe(['all' => 11, 'weak' => count($weak), 'strong' => count($strong)])
        ->and(vocWords($this, $learner, ['filter' => 'all']))->toBe($all->keys()->all());
});

it('searches by the word and by the translation, in the learner\'s language with the ru fallback', function (): void {
    $learner = Learner::factory()->create(['interface_locale' => 'uz']);
    $tea = vocWord($this->lesson, $learner, 'tea', [], ['translation' => 'чай']);
    $milk = vocWord($this->lesson, $learner, 'milk', [], ['translation' => 'молоко']);
    vocWord($this->lesson, $learner, 'bread', [], ['translation' => 'хлеб']);
    WordTranslation::query()->create(['word_id' => $tea->id, 'locale' => 'uz', 'translation' => 'choy']);

    expect(vocWords($this, $learner, ['q' => 'ea']))->toBe(['bread', 'tea'])
        ->and(vocWords($this, $learner, ['q' => 'choy']))->toBe(['tea'])     // the uz translation
        ->and(vocWords($this, $learner, ['q' => 'молок']))->toBe(['milk'])  // no uz translation: the ru text is shown and searched
        ->and(vocWords($this, $learner, ['q' => 'чай']))->toBe(['tea'])     // the ru text of a word that has an uz one too
        ->and(vocWords($this, $learner, ['q' => 'coffee']))->toBe([]);

    $items = collect(vocGet($this, $learner)->assertOk()->json('items'))->keyBy('word');
    expect($items['tea']['translation'])->toBe('choy')
        ->and($items['tea']['translation_fallback'])->toBeFalse()
        ->and($items['milk']['translation'])->toBe('молоко')
        ->and($items['milk']['translation_fallback'])->toBeTrue()
        ->and($milk->id)->toBe($items['milk']['word_id']);
});

it('folds the case of the search and of the words, and treats % _ and \\ as plain characters', function (): void {
    $learner = Learner::factory()->create();
    foreach (['TEA party', 'a_b', 'axb', '100%', '1000', 'back\\slash'] as $text) {
        vocWord($this->lesson, $learner, $text);
    }

    expect(vocWords($this, $learner, ['q' => 'tea']))->toBe(['TEA party'])
        ->and(vocWords($this, $learner, ['q' => 'TeA PaR']))->toBe(['TEA party'])
        ->and(vocWords($this, $learner, ['q' => '_']))->toBe(['a_b'])
        ->and(vocWords($this, $learner, ['q' => 'a_b']))->toBe(['a_b'])
        ->and(vocWords($this, $learner, ['q' => '%']))->toBe(['100%'])
        ->and(vocWords($this, $learner, ['q' => '\\']))->toBe(['back\\slash'])
        ->and(vocWords($this, $learner, ['q' => '   ']))->toHaveCount(6); // blank after the trim: no search
});

it('walks 120 words in pages of 50 without duplicates or gaps, the counts on the first page only', function (): void {
    $learner = Learner::factory()->create();
    $expected = [];
    for ($i = 1; $i <= 120; $i++) {
        // Upper and lower case interleaved, and pairs of the same word (two lessons): the order is lower(word), then the row id.
        $text = ($i % 2 === 0 ? 'Word' : 'word').str_pad((string) intdiv($i + 1, 2), 3, '0', STR_PAD_LEFT);
        vocWord($i <= 60 ? $this->lesson : ($this->lesson2 ??= Fx::course([1 => [1 => 0]])['lessons']['1.1']), $learner, $text);
    }
    $expected = WordRepetition::query()->where('learner_id', $learner->id)->join('words', 'words.id', '=', 'word_repetitions.word_id')
        ->orderByRaw('lower(words.word)')->orderBy('word_repetitions.id')->pluck('words.word')->all();

    $first = vocGet($this, $learner)->assertOk();
    expect($first->json('items'))->toHaveCount(50)
        ->and($first->json('total'))->toBe(120)
        ->and($first->json('next_cursor'))->toBeString()->not->toBeEmpty();

    $second = vocGet($this, $learner, ['after' => $first->json('next_cursor')])->assertOk();
    expect($second->json('total'))->toBeNull()
        ->and($second->json('counts'))->toBeNull()
        ->and($second->json('items'))->toHaveCount(50);

    $third = vocGet($this, $learner, ['after' => $second->json('next_cursor')])->assertOk();
    expect($third->json('items'))->toHaveCount(20)
        ->and($third->json('next_cursor'))->toBeNull();

    $walked = array_column([...$first->json('items'), ...$second->json('items'), ...$third->json('items')], 'word');
    expect($walked)->toBe($expected)
        ->and(array_unique(array_column([...$first->json('items'), ...$second->json('items'), ...$third->json('items')], 'word_id')))->toHaveCount(120);

    // Exactly a page of rows: no next page. One more: the 51st row gives the cursor.
    $small = Learner::factory()->create();
    for ($i = 1; $i <= 50; $i++) {
        vocWord($this->lesson, $small, 'x'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
    }
    expect(vocGet($this, $small)->json('next_cursor'))->toBeNull();
    vocWord($this->lesson, $small, 'x51');
    $page = vocGet($this, $small)->assertOk();
    expect($page->json('next_cursor'))->not->toBeNull()
        ->and(vocWords($this, $small, ['after' => $page->json('next_cursor')]))->toBe(['x51']);

    // A smaller page and a cursor carried over to another filter (keyset does not depend on it).
    $five = vocGet($this, $learner, ['limit' => 5])->assertOk();
    expect(array_column($five->json('items'), 'word'))->toBe(array_slice($expected, 0, 5))
        ->and(vocWords($this, $learner, ['limit' => 5, 'filter' => 'strong', 'after' => $five->json('next_cursor')]))->toBe(array_slice($expected, 5, 5));
});

it('continues the search on the next page with the same query', function (): void {
    $learner = Learner::factory()->create();
    for ($i = 1; $i <= 7; $i++) {
        vocWord($this->lesson, $learner, "cat{$i}");
        vocWord($this->lesson, $learner, "dog{$i}");
    }

    $page = vocGet($this, $learner, ['q' => 'cat', 'limit' => 4])->assertOk();
    expect(array_column($page->json('items'), 'word'))->toBe(['cat1', 'cat2', 'cat3', 'cat4'])
        ->and($page->json('counts'))->toBe(['all' => 14, 'weak' => 0, 'strong' => 14]) // the chips do not depend on the search
        ->and(vocWords($this, $learner, ['q' => 'cat', 'limit' => 4, 'after' => $page->json('next_cursor')]))->toBe(['cat5', 'cat6', 'cat7']);
});

it('refuses a broken cursor with 422 invalid_cursor and a cursor too long, a bad limit or filter with a validation 422', function (string $after): void {
    $learner = Learner::factory()->create();
    vocWord($this->lesson, $learner, 'tea');

    vocGet($this, $learner, ['after' => $after])->assertStatus(422)->assertJsonPath('error.code', 'invalid_cursor');
})->with([
    'not base64' => ['***'],
    'not json' => [rtrim(strtr(base64_encode('tea'), '+/', '-_'), '=')],
    'other keys' => [rtrim(strtr(base64_encode('{"p":1,"l":2,"d":3,"id":4}'), '+/', '-_'), '=')],
    'id as a string' => [rtrim(strtr(base64_encode('{"w":"tea","id":"4"}'), '+/', '-_'), '=')],
    'id zero' => [rtrim(strtr(base64_encode('{"w":"tea","id":0}'), '+/', '-_'), '=')],
    'word as a number' => [rtrim(strtr(base64_encode('{"w":5,"id":4}'), '+/', '-_'), '=')],
]);

it('refuses a cursor longer than the limit, a limit out of 1..50 and an unknown filter by validation', function (array $query): void {
    $learner = Learner::factory()->create();

    vocGet($this, $learner, $query)->assertStatus(422)->assertJsonValidationErrors([array_key_first($query)]);
})->with([
    'cursor too long' => [['after' => str_repeat('a', VocabularyCursor::MAX_LENGTH + 1)]],
    'limit 0' => [['limit' => 0]],
    'limit 51' => [['limit' => 51]],
    'limit not a number' => [['limit' => 'ten']],
    'filter' => [['filter' => 'medium']],
    'query too long' => [['q' => str_repeat('a', 51)]],
]);

it('round-trips a cursor of a long word with non-ASCII letters within the limit', function (): void {
    $cursor = new VocabularyCursor(str_repeat('é', 100), PHP_INT_MAX);
    $encoded = $cursor->encode();

    expect(strlen($encoded))->toBeLessThanOrEqual(VocabularyCursor::MAX_LENGTH)
        ->and(VocabularyCursor::decode($encoded))->toEqual($cursor);
});

it('answers 401 without a token', function (): void {
    $this->getJson('/api/twa/vocabulary')->assertUnauthorized();
});

it('puts card.memory on the training card: a new card, a card in the steps and a review card, before the grade', function (): void {
    ['course' => $course, 'words' => $words] = Fx::course([1 => [1 => 3]]);
    $learner = Learner::factory()->onboarded()->create(['active_course_id' => $course->id]);
    $headers = ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];
    [$reviewId, $stepsId] = $words['1.1']['active'];

    // A review card due now (12 days), then - after it is graded - nothing else is due: the picker brings a new word.
    DB::table('word_repetitions')->insert([
        ['learner_id' => $learner->id, 'word_id' => $reviewId, 'state' => 'review', 'repetitions' => 3, 'interval_days' => 12, 'last_quality' => 4,
            'last_reviewed_at' => now()->subDays(12), 'next_review_at' => now()->subHour(), 'is_hard' => false, 'learning_step' => 0, 'lapses' => 0,
            'easiness_factor' => 2.5, 'introduced_mode' => 'lesson', 'meta' => '{}', 'created_at' => now()->subDays(20), 'updated_at' => now()],
        ['learner_id' => $learner->id, 'word_id' => $stepsId, 'state' => 'learning', 'repetitions' => 0, 'interval_days' => 0, 'last_quality' => 3,
            'last_reviewed_at' => now()->subMinutes(20), 'next_review_at' => now()->addDays(2), 'is_hard' => false, 'learning_step' => 1, 'lapses' => 0,
            'easiness_factor' => 2.5, 'introduced_mode' => 'lesson', 'meta' => '{}', 'created_at' => now()->subDay(), 'updated_at' => now()],
    ]);

    $opened = $this->withHeaders($headers)->postJson('/api/twa/training/sessions', ['mode' => 'daily_review'])->assertOk()->json();
    expect($opened['card']['word_id'])->toBe($reviewId)
        ->and($opened['card']['memory'])->toBe(['strength' => 4, 'stage' => 'known', 'next_in_days' => 0]);

    $next = $this->withHeaders($headers)->getJson("/api/twa/training/sessions/{$opened['session_id']}/next")->assertOk()->json('card');
    expect($next['memory'])->toBe(['strength' => 4, 'stage' => 'known', 'next_in_days' => 0]); // the same card, nothing graded yet

    // Another learner with only a card in the steps, due now: strength 1, no day count.
    $inSteps = Learner::factory()->onboarded()->create(['active_course_id' => $course->id]);
    DB::table('word_repetitions')->insert(['learner_id' => $inSteps->id, 'word_id' => $stepsId, 'state' => 'learning', 'repetitions' => 0, 'interval_days' => 0,
        'last_quality' => 3, 'last_reviewed_at' => now()->subMinutes(20), 'next_review_at' => now()->subMinute(), 'is_hard' => false, 'learning_step' => 1,
        'lapses' => 0, 'easiness_factor' => 2.5, 'introduced_mode' => 'lesson', 'meta' => '{}', 'created_at' => now()->subDay(), 'updated_at' => now()]);
    $steps = $this->withHeaders(['Authorization' => 'Bearer '.app(JwtService::class)->issue($inSteps->id)['token']])
        ->postJson('/api/twa/training/sessions', ['mode' => 'daily_review'])->assertOk()->json('card');
    expect($steps['word_id'])->toBe($stepsId)
        ->and($steps['memory'])->toBe(['strength' => 1, 'stage' => 'new', 'next_in_days' => null]);

    // A fresh learner: the first card is a new word - strength 0, stage new.
    $fresh = Learner::factory()->onboarded()->create(['active_course_id' => $course->id]);
    $card = $this->withHeaders(['Authorization' => 'Bearer '.app(JwtService::class)->issue($fresh->id)['token']])
        ->postJson('/api/twa/training/sessions', ['mode' => 'daily_review'])->assertOk()->json('card');
    expect($card['card_kind'])->toBe('new')
        ->and($card['memory'])->toBe(['strength' => 0, 'stage' => 'new', 'next_in_days' => null]);
});

it('folds the case of Cyrillic and of Uzbek letters on PostgreSQL', function (): void {
    $learner = Learner::factory()->create(['interface_locale' => 'ru']);
    vocWord($this->lesson, $learner, 'milk', [], ['translation' => 'Молоко']);
    vocWord($this->lesson, $learner, 'Ölmak', [], ['translation' => 'умирать']);

    expect(vocWords($this, $learner, ['q' => 'МОЛОК']))->toBe(['milk'])
        ->and(vocWords($this, $learner, ['q' => 'молоко']))->toBe(['milk'])
        ->and(vocWords($this, $learner, ['q' => 'УМИР']))->toBe(['Ölmak'])
        ->and(vocWords($this, $learner, ['q' => 'ölm']))->toBe(['Ölmak']);
})->skip((getenv('DB_CONNECTION') ?: 'sqlite') !== 'pgsql', 'sqlite lower() folds ASCII only (docs/40 §2.3)');
