<?php

declare(strict_types=1);

/*
 * Learner decks (task 2D.1, docs/04 A.10): /api/twa/decks*, plan limits, access (somebody else's deck is
 * a 404 everywhere), archiving that keeps the progress, and a deck taking part in daily_review and the
 * Free new-word quota like any course. The parser itself is covered by DeckImportParserTest.
 */

use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Content\Exceptions\DeckException;
use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Content\Services\LearnerDeckService;
use App\Domain\Learning\Support\TrainingScope;
use App\Domain\Twa\Services\JwtService;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\Subscription;
use App\Models\TrainingSession;
use App\Models\Word;
use App\Models\WordRepetition;
use App\Models\WordTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(fn () => Cache::flush());

/** @return array<string, string> */
function dkHeaders(Learner $learner): array
{
    return ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];
}

function dkLearner(array $attributes = []): Learner
{
    return Learner::factory()->onboarded()->create($attributes);
}

function dkPro(Learner $learner): Learner
{
    Subscription::factory()->create(['subscriber_type' => 'learner', 'subscriber_id' => $learner->id]);
    app(EntitlementResolver::class)->forget($learner);

    return $learner;
}

/** @param array<string, mixed>|null $body */
function dkCall(object $test, Learner $learner, string $method, string $uri, ?array $body = null): TestResponse
{
    return $test->withHeaders(dkHeaders($learner))->json($method, '/api/twa'.$uri, $body ?? []);
}

function dkCreate(object $test, Learner $learner, string $title = 'Мои слова'): string
{
    return (string) dkCall($test, $learner, 'POST', '/decks', ['title' => $title])->assertCreated()->json('slug');
}

/** Words "w1x — слово1" ... as a list text. */
function dkList(int $count, string $prefix = 'w'): string
{
    return implode("\n", array_map(fn (int $i): string => "{$prefix}{$i}x — слово{$i}", range(1, $count)));
}

function dkImport(object $test, Learner $learner, string $slug, string $text, string $format = 'list'): TestResponse
{
    return dkCall($test, $learner, 'POST', "/decks/{$slug}/words", ['text' => $text, 'format' => $format]);
}

function dkDeck(string $slug): Course
{
    return Course::query()->where('slug', $slug)->firstOrFail();
}

/** @return list<int> */
function dkWordIds(string $slug): array
{
    return Word::query()
        ->whereHas('lesson.stage', fn ($q) => $q->where('course_id', dkDeck($slug)->id))
        ->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
}

// --- config ----------------------------------------------------------------

it('ships the documented limits: Free 1 deck x 50 words, Pro 20 x 2000', function (): void {
    expect(config('decks.limits'))->toBe([
        'free' => ['decks' => 1, 'deck_words' => 50],
        'pro' => ['decks' => 20, 'deck_words' => 2000],
    ]);
});

// --- create / list / rename --------------------------------------------------

it('creates a private published learner deck with its stage', function (): void {
    $learner = dkLearner();

    $response = dkCall($this, $learner, 'POST', '/decks', ['title' => '  <b>Мои</b>   слова  '])
        ->assertCreated()
        ->assertExactJsonStructure(['slug', 'title', 'words_count', 'is_active', 'progress' => ['done', 'total']])
        ->assertJsonPath('title', 'Мои слова')
        ->assertJsonPath('words_count', 0)
        ->assertJsonPath('is_active', false);

    $deck = dkDeck($response->json('slug'));
    expect($deck->owner_type)->toBe('learner')
        ->and($deck->owner_id)->toBe($learner->id)
        ->and($deck->source)->toBe('learner')
        ->and($deck->visibility)->toBe('private')
        ->and($deck->status)->toBe('published')
        ->and($deck->title)->toBe(['ru' => 'Мои слова', 'uz' => 'Мои слова'])
        ->and($deck->slug)->toStartWith("deck-{$learner->id}-")
        ->and($deck->stages()->count())->toBe(1)
        ->and($deck->stages()->firstOrFail()->title)->toBe('Моя колода');
});

it('lists only the own, non-archived decks with the limits of the plan', function (): void {
    $learner = dkLearner();
    $mine = dkCreate($this, $learner, 'Первая');
    Course::factory()->deckOf(dkLearner()->id)->create(['slug' => 'somebody-elses']);
    Course::factory()->deckOf($learner->id)->create(['slug' => 'archived-deck', 'status' => 'archived']);
    Course::factory()->published()->create(['slug' => 'platform-course']);

    dkCall($this, $learner, 'GET', '/decks')->assertOk()
        ->assertExactJsonStructure([
            'decks' => ['*' => ['slug', 'title', 'words_count', 'is_active', 'progress' => ['done', 'total']]],
            'limits' => ['decks', 'deck_words', 'used_decks'],
        ])
        ->assertJsonCount(1, 'decks')
        ->assertJsonPath('decks.0.slug', $mine)
        ->assertJsonPath('limits', ['decks' => 1, 'deck_words' => 50, 'used_decks' => 1]);

    dkPro($learner);
    dkCall($this, $learner, 'GET', '/decks')->assertJsonPath('limits', ['decks' => 20, 'deck_words' => 2000, 'used_decks' => 1]);
});

it('renames a deck and rejects an unusable title', function (): void {
    $learner = dkLearner();
    $slug = dkCreate($this, $learner);

    dkCall($this, $learner, 'PATCH', "/decks/{$slug}", ['title' => 'Новое имя'])->assertOk()->assertJsonPath('title', 'Новое имя');
    expect(dkDeck($slug)->title)->toBe(['ru' => 'Новое имя', 'uz' => 'Новое имя']);

    dkCall($this, $learner, 'PATCH', "/decks/{$slug}", ['title' => '12345'])
        ->assertStatus(422)->assertJsonPath('error.code', 'deck_title_invalid');
    dkCall($this, $learner, 'PATCH', "/decks/{$slug}", ['title' => str_repeat('я', 61)])
        ->assertStatus(422)->assertJsonValidationErrors('title');
    dkCall($this, $learner, 'POST', '/decks', [])->assertStatus(422)->assertJsonValidationErrors('title');
    expect(dkDeck($slug)->title['ru'])->toBe('Новое имя');
});

it('requires a TWA token', function (): void {
    $this->getJson('/api/twa/decks')->assertUnauthorized();
    $this->postJson('/api/twa/decks', ['title' => 'x'])->assertUnauthorized();
});

// --- limits ------------------------------------------------------------------

it('lets Free have one deck: the second is a 422 paywall reason', function (): void {
    $learner = dkLearner();
    dkCreate($this, $learner);

    dkCall($this, $learner, 'POST', '/decks', ['title' => 'Вторая'])
        ->assertStatus(422)
        ->assertExactJsonStructure(['error' => ['code', 'message']])
        ->assertJsonPath('error.code', 'deck_limit_reached');
    expect(Course::query()->where('owner_type', 'learner')->count())->toBe(1);
});

it('lets Pro have 20 decks and not the 21st', function (): void {
    $learner = dkPro(dkLearner());
    foreach (range(1, 20) as $i) {
        dkCreate($this, $learner, "Колода {$i}");
    }

    dkCall($this, $learner, 'POST', '/decks', ['title' => 'Лишняя'])
        ->assertStatus(422)->assertJsonPath('error.code', 'deck_limit_reached');
    expect(Course::query()->where('owner_id', $learner->id)->count())->toBe(20);
});

it('does not count an archived deck against the limit', function (): void {
    $learner = dkLearner();
    $slug = dkCreate($this, $learner);

    dkCall($this, $learner, 'DELETE', "/decks/{$slug}")->assertNoContent();

    dkCreate($this, $learner, 'Новая');
});

it('counts decks per learner, not globally', function (): void {
    dkCreate($this, dkLearner());
    dkCreate($this, dkLearner());
});

it('keeps existing decks but blocks new ones after Pro ends', function (): void {
    $learner = dkLearner();
    $subscription = Subscription::factory()->create(['subscriber_type' => 'learner', 'subscriber_id' => $learner->id]);
    $first = dkCreate($this, $learner, 'Одна');
    dkCreate($this, $learner, 'Две');

    $subscription->update(['status' => 'expired', 'ended_at' => now()->subDay(), 'current_period_ends_at' => now()->subDay()]);
    app(EntitlementResolver::class)->forget($learner);

    dkCall($this, $learner, 'POST', '/decks', ['title' => 'Три'])->assertStatus(422)->assertJsonPath('error.code', 'deck_limit_reached');
    dkCall($this, $learner, 'GET', "/decks/{$first}")->assertOk();
});

it('accepts exactly 50 words on Free and refuses the 51st as a paywall reason', function (): void {
    $learner = dkLearner();
    $slug = dkCreate($this, $learner);

    dkImport($this, $learner, $slug, dkList(50))->assertOk()->assertJsonPath('added', 50)->assertJsonPath('deck.words_count', 50);

    dkImport($this, $learner, $slug, 'extra — лишнее')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'deck_words_limit_reached');
    expect(dkDeck($slug)->words_count)->toBe(50)->and(count(dkWordIds($slug)))->toBe(50);
});

it('writes nothing when an import does not fit: all or nothing', function (): void {
    $learner = dkLearner();
    $slug = dkCreate($this, $learner);
    dkImport($this, $learner, $slug, dkList(45))->assertOk();

    dkImport($this, $learner, $slug, dkList(10, 'n'))->assertStatus(422)->assertJsonPath('error.code', 'deck_words_limit_reached');

    expect(count(dkWordIds($slug)))->toBe(45);
    // What fits still goes in afterwards.
    dkImport($this, $learner, $slug, dkList(5, 'n'))->assertOk()->assertJsonPath('added', 5);
});

it('does not count duplicates against the limit and counts removed words as free room', function (): void {
    $learner = dkLearner();
    $slug = dkCreate($this, $learner);
    dkImport($this, $learner, $slug, dkList(50))->assertOk();

    dkImport($this, $learner, $slug, dkList(3))->assertOk()
        ->assertJsonPath('added', 0)->assertJsonPath('duplicates', 3);

    $wordId = dkWordIds($slug)[0];
    dkCall($this, $learner, 'DELETE', "/decks/{$slug}/words/{$wordId}")->assertNoContent();
    dkImport($this, $learner, $slug, 'fresh — свежее')->assertOk()->assertJsonPath('added', 1);
    expect(dkDeck($slug)->words_count)->toBe(50);
});

it('applies the Pro word limit from config', function (): void {
    config(['decks.limits.pro.deck_words' => 60]);
    $learner = dkPro(dkLearner());
    $slug = dkCreate($this, $learner);

    dkImport($this, $learner, $slug, dkList(60))->assertOk();
    dkImport($this, $learner, $slug, 'one — один')->assertStatus(422)->assertJsonPath('error.code', 'deck_words_limit_reached');
});

// --- import ------------------------------------------------------------------

it('imports a list, reports junk and duplicates, and stores the translations', function (): void {
    $learner = dkLearner();
    $slug = dkCreate($this, $learner);

    $text = "apple — яблоко\nPear;груша\nhttp://spam.example — ссылка\nApple - ещё яблоко\nbroken\n\nplum\tслива";
    $response = dkImport($this, $learner, $slug, $text)->assertOk()
        ->assertExactJsonStructure([
            'added', 'reactivated', 'duplicates',
            'rejected' => ['*' => ['line', 'reason']],
            'deck' => ['slug', 'title', 'words_count', 'is_active', 'progress' => ['done', 'total']],
        ])
        ->assertJsonPath('added', 3)
        ->assertJsonPath('deck.words_count', 3);

    expect($response->json('rejected'))->toBe([
        ['line' => 3, 'reason' => 'junk'],
        ['line' => 4, 'reason' => 'duplicate'],
        ['line' => 5, 'reason' => 'empty_translation'],
    ]);

    $apple = Word::query()->where('word', 'apple')->firstOrFail();
    expect($apple->translation)->toBe('яблоко')
        ->and($apple->is_active)->toBeTrue()
        ->and($apple->translations()->where('locale', 'ru')->value('translation'))->toBe('яблоко')
        ->and($apple->translations()->where('locale', 'ru')->value('source'))->toBe('learner');
});

it('stores the uz translation of an uz learner list', function (): void {
    $learner = dkLearner(['interface_locale' => 'uz']);
    $slug = dkCreate($this, $learner);

    dkImport($this, $learner, $slug, 'apple - olma')->assertOk();

    $apple = Word::query()->where('word', 'apple')->firstOrFail();
    expect($apple->translations()->where('locale', 'uz')->value('translation'))->toBe('olma');
});

it('imports csv with uz translations and examples', function (): void {
    $learner = dkLearner();
    $slug = dkCreate($this, $learner);

    $csv = "word,translation_ru,translation_uz,example\napple,яблоко,olma,\"I eat an apple, daily\"\npear,груша,nok,";
    dkImport($this, $learner, $slug, $csv, 'csv')->assertOk()->assertJsonPath('added', 2);

    $detail = dkCall($this, $learner, 'GET', "/decks/{$slug}")->assertOk()
        ->assertExactJsonStructure([
            'slug', 'title', 'words_count', 'is_active', 'progress' => ['done', 'total'],
            'words' => ['*' => ['id', 'word', 'translation', 'translation_uz', 'example']],
        ]);

    expect($detail->json('words.0'))->toMatchArray([
        'word' => 'apple', 'translation' => 'яблоко', 'translation_uz' => 'olma', 'example' => 'I eat an apple, daily',
    ])->and($detail->json('words.1.example'))->toBeNull();
});

it('splits a deck into lessons of 50 words, "Часть N", in order', function (): void {
    $learner = dkPro(dkLearner());
    $slug = dkCreate($this, $learner);

    dkImport($this, $learner, $slug, dkList(120))->assertOk();
    // A later import continues the last part instead of opening a new one.
    dkImport($this, $learner, $slug, dkList(10, 'm'))->assertOk();

    $lessons = Lesson::query()
        ->whereHas('stage', fn ($q) => $q->where('course_id', dkDeck($slug)->id))
        ->withCount('words')->orderBy('number')->get();

    expect($lessons->pluck('number')->all())->toBe([1, 2, 3])
        ->and($lessons->pluck('title')->all())->toBe(['Часть 1', 'Часть 2', 'Часть 3'])
        ->and($lessons->pluck('words_count')->all())->toBe([50, 50, 30]);
});

it('shows the stage and the parts of a deck in the interface language of the learner', function (): void {
    $uz = dkPro(dkLearner(['interface_locale' => 'uz']));
    $ru = dkPro(dkLearner(['interface_locale' => 'ru']));
    foreach ([$uz, $ru] as $learner) {
        $slug = dkCreate($this, $learner);
        dkImport($this, $learner, $slug, dkList(60))->assertOk();
        $detail = dkCall($this, $learner, 'GET', "/courses/{$slug}")->assertOk();

        if ($learner->is($uz)) {
            $detail->assertJsonPath('stages.0.title', "Mening lug'atim")
                ->assertJsonPath('stages.0.lessons.0.title', '1-qism')
                ->assertJsonPath('stages.0.lessons.1.title', '2-qism');
        } else {
            $detail->assertJsonPath('stages.0.title', 'Моя колода')
                ->assertJsonPath('stages.0.lessons.1.title', 'Часть 2');
        }
    }
});

it('revives a removed word with the new translation', function (): void {
    $learner = dkLearner();
    $slug = dkCreate($this, $learner);
    dkImport($this, $learner, $slug, 'apple — яблоко')->assertOk();
    $wordId = dkWordIds($slug)[0];
    dkCall($this, $learner, 'DELETE', "/decks/{$slug}/words/{$wordId}")->assertNoContent();
    expect(dkDeck($slug)->words_count)->toBe(0);

    dkImport($this, $learner, $slug, 'APPLE — красное яблоко')->assertOk()
        ->assertJsonPath('added', 0)->assertJsonPath('reactivated', 1)->assertJsonPath('deck.words_count', 1);

    $word = Word::query()->findOrFail($wordId);
    expect($word->is_active)->toBeTrue()
        ->and($word->translation)->toBe('красное яблоко')
        ->and(WordTranslation::query()->where('word_id', $wordId)->where('locale', 'ru')->value('translation'))->toBe('красное яблоко');
});

it('answers 422 deck_import_empty when no line survives', function (): void {
    $learner = dkLearner();
    $slug = dkCreate($this, $learner);

    dkImport($this, $learner, $slug, "123 — число\nnonsense")->assertStatus(422)->assertJsonPath('error.code', 'deck_import_empty');
    dkImport($this, $learner, $slug, "\n  \n")->assertStatus(422);
});

it('validates the import body', function (array $body, string $field): void {
    $learner = dkLearner();
    $slug = dkCreate($this, $learner);

    dkCall($this, $learner, 'POST', "/decks/{$slug}/words", $body)->assertStatus(422)->assertJsonValidationErrors($field);
})->with([
    'no text' => [['format' => 'list'], 'text'],
    'no format' => [['text' => 'a — б'], 'format'],
    'unknown format' => [['text' => 'a — б', 'format' => 'xml'], 'format'],
    'array text' => [['text' => ['a'], 'format' => 'list'], 'text'],
    'over 256 KB' => [['text' => str_repeat('a', 256 * 1024 + 1), 'format' => 'list'], 'text'],
]);

it('takes a text of exactly 256 KB', function (): void {
    $learner = dkLearner();
    $slug = dkCreate($this, $learner);
    $text = 'apple — яблоко'.str_repeat(' ', 256 * 1024 - strlen('apple — яблоко'));

    dkImport($this, $learner, $slug, $text)->assertOk()->assertJsonPath('added', 1);
});

it('stops at 500 lines and reports the rest as over_limit', function (): void {
    $learner = dkPro(dkLearner());
    $slug = dkCreate($this, $learner);

    $response = dkImport($this, $learner, $slug, dkList(520))->assertOk()->assertJsonPath('added', 500);

    expect($response->json('rejected'))->toHaveCount(20)
        ->and($response->json('rejected.0'))->toBe(['line' => 501, 'reason' => 'over_limit']);
});

it('never stores markup from a title, word or translation', function (): void {
    $learner = dkLearner();
    $slug = dkCreate($this, $learner, '<script>alert(1)</script>Колода');
    dkImport($this, $learner, $slug, '<img src=x onerror=alert(1)>apple — <b>яблоко</b>')->assertOk();

    expect(dkDeck($slug)->title['ru'])->toBe('alert(1)Колода')
        ->and(Word::query()->where('word', 'apple')->value('translation'))->toBe('яблоко');
});

// --- access ------------------------------------------------------------------

it('answers 404 deck_not_found for somebody else\'s, archived and unknown decks on every route', function (): void {
    $owner = dkLearner();
    $slug = dkCreate($this, $owner);
    dkImport($this, $owner, $slug, 'apple — яблоко')->assertOk();
    $wordId = dkWordIds($slug)[0];
    $stranger = dkPro(dkLearner());

    $archived = dkCreate($this, $stranger, 'Старая');
    dkCall($this, $stranger, 'DELETE', "/decks/{$archived}")->assertNoContent();

    foreach ([
        ['GET', "/decks/{$slug}", null],
        ['PATCH', "/decks/{$slug}", ['title' => 'Взлом']],
        ['DELETE', "/decks/{$slug}", null],
        ['POST', "/decks/{$slug}/words", ['text' => 'x — у', 'format' => 'list']],
        ['DELETE', "/decks/{$slug}/words/{$wordId}", null],
        ['GET', "/decks/{$archived}", null],
        ['GET', '/decks/no-such-deck', null],
    ] as [$method, $uri, $body]) {
        dkCall($this, $stranger, $method, $uri, $body)
            ->assertNotFound()
            ->assertExactJsonStructure(['error' => ['code', 'message']])
            ->assertJsonPath('error.code', 'deck_not_found');
    }

    // Nothing of the owner's deck changed.
    $deck = dkDeck($slug);
    expect($deck->status)->toBe('published')->and($deck->title['ru'])->toBe('Мои слова')
        ->and(Word::query()->findOrFail($wordId)->is_active)->toBeTrue()
        ->and(dkCall($this, $stranger, 'GET', '/decks')->json('decks.*.slug'))->not->toContain($slug);
});

it('does not remove a word of another deck through the own deck URL', function (): void {
    $owner = dkPro(dkLearner());
    $a = dkCreate($this, $owner, 'А');
    $b = dkCreate($this, $owner, 'Б');
    dkImport($this, $owner, $a, 'apple — яблоко')->assertOk();
    $wordId = dkWordIds($a)[0];

    dkCall($this, $owner, 'DELETE', "/decks/{$b}/words/{$wordId}")
        ->assertNotFound()->assertJsonPath('error.code', 'deck_word_not_found');
    expect(Word::query()->findOrFail($wordId)->is_active)->toBeTrue();
});

it('hides a deck from the course catalog routes of everybody but the owner', function (): void {
    $owner = dkLearner();
    $slug = dkCreate($this, $owner);
    dkImport($this, $owner, $slug, 'apple — яблоко')->assertOk();
    $stranger = dkLearner();

    dkCall($this, $stranger, 'GET', "/courses/{$slug}")->assertNotFound()->assertJsonPath('error.code', 'course_not_found');
    dkCall($this, $stranger, 'POST', '/me/course', ['slug' => $slug])->assertNotFound()->assertJsonPath('error.code', 'course_not_found');
    expect($stranger->fresh()->active_course_id)->toBeNull();

    dkCall($this, $owner, 'GET', "/courses/{$slug}")->assertOk()->assertJsonPath('words_count', 1);

    $catalog = fn (Learner $l): array => dkCall($this, $l, 'GET', '/courses')->json('courses.*.slug');
    expect($catalog($owner))->not->toContain($slug)->and($catalog($stranger))->not->toContain($slug);
});

it('lets the owner choose the deck as the active course, which then shows in the catalog and on Today', function (): void {
    $owner = dkLearner();
    $slug = dkCreate($this, $owner, 'Моя');
    dkImport($this, $owner, $slug, dkList(3))->assertOk();

    dkCall($this, $owner, 'POST', '/me/course', ['slug' => $slug])->assertOk()
        ->assertJsonPath('slug', $slug)->assertJsonPath('is_active', true);

    expect($owner->fresh()->active_course_id)->toBe(dkDeck($slug)->id);
    dkCall($this, $owner, 'GET', '/courses')->assertJsonPath('courses.0.slug', $slug)->assertJsonPath('courses.0.title', 'Моя');
    dkCall($this, $owner, 'GET', '/today')->assertOk()->assertJsonPath('active_course.slug', $slug);
    dkCall($this, $owner, 'GET', '/decks')->assertJsonPath('decks.0.is_active', true);
    dkCall($this, $owner, 'GET', '/me')->assertJsonPath('settings.active_course.slug', $slug);
});

it('does not use a private deck as the active course of somebody else', function (): void {
    $owner = dkLearner();
    $slug = dkCreate($this, $owner);
    dkImport($this, $owner, $slug, dkList(3))->assertOk();
    // Forced past the API (a stale id, a bug elsewhere): the picker must still refuse it.
    $stranger = dkLearner(['active_course_id' => dkDeck($slug)->id]);

    dkCall($this, $stranger, 'GET', '/today')->assertOk()->assertJsonPath('active_course', null);
    dkCall($this, $stranger, 'GET', '/me')->assertJsonPath('settings.active_course', null);

    $open = dkCall($this, $stranger, 'POST', '/training/sessions', ['mode' => 'daily_review'])->assertOk();
    expect($open->json('card'))->toBeNull()
        ->and(TrainingSession::query()->findOrFail($open->json('session_id'))->course_id)->toBeNull()
        ->and(WordRepetition::query()->where('learner_id', $stranger->id)->count())->toBe(0);
});

it('opens lesson sessions of the own deck only', function (): void {
    $owner = dkLearner();
    $slug = dkCreate($this, $owner);
    dkImport($this, $owner, $slug, dkList(3))->assertOk();
    $lessonId = (int) Lesson::query()->whereHas('stage', fn ($q) => $q->where('course_id', dkDeck($slug)->id))->value('id');

    dkCall($this, $owner, 'POST', '/training/sessions', ['mode' => 'lesson', 'lesson_id' => $lessonId])
        ->assertOk()->assertJsonPath('total_words', 3)->assertJsonPath('card.card_kind', 'new');

    dkCall($this, dkLearner(), 'POST', '/training/sessions', ['mode' => 'lesson', 'lesson_id' => $lessonId])
        ->assertNotFound()->assertJsonPath('error.code', 'lesson_not_found');
});

it('keeps private decks out of the availability rule for groups and links', function (): void {
    $owner = dkLearner();
    $deck = Course::factory()->deckOf($owner->id)->create();
    $catalog = app(CourseCatalogService::class);

    expect($catalog->isAvailable($deck))->toBeFalse()
        ->and($catalog->isAvailable($deck, $owner))->toBeTrue()
        ->and($catalog->isAvailable($deck, dkLearner()))->toBeFalse()
        ->and($catalog->findAvailable($deck->slug))->toBeNull()
        ->and($catalog->findAvailable($deck->slug, $owner)?->id)->toBe($deck->id)
        ->and($catalog->findAvailable($deck->slug, dkLearner()))->toBeNull()
        ->and(Course::query()->available()->whereKey($deck->id)->exists())->toBeFalse()
        ->and(Course::query()->availableTo($owner->id)->whereKey($deck->id)->exists())->toBeTrue()
        ->and(Course::query()->availableTo($owner->id + 999)->whereKey($deck->id)->exists())->toBeFalse();

    $deck->update(['status' => 'archived']);
    expect($catalog->isAvailable($deck, $owner))->toBeFalse();

    // A private PLATFORM course stays closed even to a learner whose id equals its owner_id.
    $private = Course::factory()->published()->create(['visibility' => 'private', 'owner_type' => 'organization', 'owner_id' => $owner->id]);
    expect($catalog->isAvailable($private, $owner))->toBeFalse();
});

it('offers the new words of a deck to its owner only in the training scope, even for a stale course snapshot', function (): void {
    $owner = dkLearner();
    $slug = dkCreate($this, $owner);
    dkImport($this, $owner, $slug, dkList(3))->assertOk();
    $scope = TrainingScope::daily(dkDeck($slug)->id);

    expect($scope->newWordCandidates($owner->id)?->count())->toBe(3)
        ->and($scope->newWordCandidates(dkLearner()->id)?->count())->toBe(0);

    dkDeck($slug)->update(['status' => 'archived']);
    expect($scope->newWordCandidates($owner->id)?->count())->toBe(0);
});

// --- archive ------------------------------------------------------------------

it('archives on delete: words deactivated, progress kept, active course cleared', function (): void {
    $learner = dkLearner();
    $slug = dkCreate($this, $learner);
    dkImport($this, $learner, $slug, dkList(4))->assertOk();
    dkCall($this, $learner, 'POST', '/me/course', ['slug' => $slug])->assertOk();

    $ids = dkWordIds($slug);
    foreach (array_slice($ids, 0, 2) as $id) {
        WordRepetition::factory()->create(['learner_id' => $learner->id, 'word_id' => $id, 'repetitions' => 3, 'interval_days' => 6]);
    }
    $repetitions = WordRepetition::query()->where('learner_id', $learner->id)->orderBy('id')->get(['id', 'word_id', 'repetitions', 'interval_days'])->toArray();

    dkCall($this, $learner, 'DELETE', "/decks/{$slug}")->assertNoContent();

    $deck = dkDeck($slug);
    expect($deck->status)->toBe('archived')
        ->and($deck->words_count)->toBe(0)
        ->and(Word::query()->whereIn('id', $ids)->where('is_active', true)->count())->toBe(0)
        ->and(Word::query()->whereIn('id', $ids)->count())->toBe(4)
        ->and(WordRepetition::query()->where('learner_id', $learner->id)->orderBy('id')->get(['id', 'word_id', 'repetitions', 'interval_days'])->toArray())->toBe($repetitions)
        ->and($learner->fresh()->active_course_id)->toBeNull();

    dkCall($this, $learner, 'GET', "/decks/{$slug}")->assertNotFound();
    dkCall($this, $learner, 'GET', "/courses/{$slug}")->assertNotFound();
    dkCall($this, $learner, 'DELETE', "/decks/{$slug}")->assertNotFound();
    dkCall($this, $learner, 'POST', '/me/course', ['slug' => $slug])->assertNotFound();
    dkCall($this, $learner, 'GET', '/decks')->assertJsonCount(0, 'decks')->assertJsonPath('limits.used_decks', 0);
});

it('does not touch the progress of other learners or other decks when archiving', function (): void {
    $learner = dkPro(dkLearner());
    $other = dkLearner();
    $a = dkCreate($this, $learner, 'А');
    $b = dkCreate($this, $learner, 'Б');
    dkImport($this, $learner, $a, 'apple — яблоко')->assertOk();
    dkImport($this, $learner, $b, 'pear — груша')->assertOk();

    dkCall($this, $learner, 'DELETE', "/decks/{$a}")->assertNoContent();

    expect(dkDeck($b)->status)->toBe('published')
        ->and(Word::query()->where('word', 'pear')->value('is_active'))->toBeTrue()
        ->and(dkDeck($b)->words_count)->toBe(1)
        ->and(dkCall($this, $other, 'GET', '/decks')->json('limits.used_decks'))->toBe(0);
});

it('removes a single word without deleting the row or its repetitions', function (): void {
    $learner = dkLearner();
    $slug = dkCreate($this, $learner);
    dkImport($this, $learner, $slug, dkList(3))->assertOk();
    $wordId = dkWordIds($slug)[0];
    WordRepetition::factory()->create(['learner_id' => $learner->id, 'word_id' => $wordId, 'repetitions' => 2]);

    dkCall($this, $learner, 'DELETE', "/decks/{$slug}/words/{$wordId}")->assertNoContent();

    expect(Word::query()->findOrFail($wordId)->is_active)->toBeFalse()
        ->and(WordRepetition::query()->where('word_id', $wordId)->count())->toBe(1)
        ->and(dkDeck($slug)->words_count)->toBe(2)
        ->and(dkCall($this, $learner, 'GET', "/decks/{$slug}")->json('words.*.id'))->not->toContain($wordId);

    dkCall($this, $learner, 'DELETE', "/decks/{$slug}/words/{$wordId}")->assertNotFound()->assertJsonPath('error.code', 'deck_word_not_found');
});

// --- daily_review and the quota ---------------------------------------------------

it('serves new words of the active deck in daily_review in the order they were added', function (): void {
    $learner = dkLearner();
    $slug = dkCreate($this, $learner);
    dkImport($this, $learner, $slug, "alpha — а\nbeta — б\ngamma — в")->assertOk();
    dkCall($this, $learner, 'POST', '/me/course', ['slug' => $slug])->assertOk();

    $open = dkCall($this, $learner, 'POST', '/training/sessions', ['mode' => 'daily_review'])->assertOk();
    $sessionId = (int) $open->json('session_id');

    expect(TrainingSession::query()->findOrFail($sessionId)->course_id)->toBe(dkDeck($slug)->id)
        ->and($open->json('card.card_kind'))->toBe('new')
        ->and($open->json('card.word'))->toBe('alpha');

    $second = dkCall($this, $learner, 'POST', "/training/sessions/{$sessionId}/review", [
        'word_id' => $open->json('card.word_id'), 'quality' => 5, 'time_spent_ms' => 300,
    ])->assertOk();
    expect($second->json('card.word'))->toBe('beta');
});

it('applies the Free quota of 20 new words a day to a deck, and not to Pro', function (): void {
    $free = dkLearner();
    $slug = dkCreate($this, $free);
    dkImport($this, $free, $slug, dkList(30))->assertOk();
    dkCall($this, $free, 'POST', '/me/course', ['slug' => $slug])->assertOk();

    $step = dkCall($this, $free, 'POST', '/training/sessions', ['mode' => 'daily_review'])->assertOk();
    $sessionId = (int) $step->json('session_id');
    $served = 0;
    while ($step->json('card') !== null) {
        $served++;
        $step = dkCall($this, $free, 'POST', "/training/sessions/{$sessionId}/review", [
            'word_id' => $step->json('card.word_id'), 'quality' => 5, 'time_spent_ms' => 300,
        ])->assertOk();
    }

    expect($served)->toBe(20)
        ->and($step->json('done_reason'))->toBe('new_quota_exhausted')
        ->and(WordRepetition::query()->where('learner_id', $free->id)->count())->toBe(20);

    $pro = dkPro(dkLearner());
    $proSlug = dkCreate($this, $pro);
    dkImport($this, $pro, $proSlug, dkList(30))->assertOk();
    dkCall($this, $pro, 'POST', '/me/course', ['slug' => $proSlug])->assertOk();

    $proStep = dkCall($this, $pro, 'POST', '/training/sessions', ['mode' => 'daily_review'])->assertOk();
    expect($proStep->json('card.card_kind'))->toBe('new')->and($proStep->json('quota.new_words_remaining'))->toBeNull();
});

it('serves nothing from an archived deck: its words are inactive', function (): void {
    $learner = dkLearner();
    $slug = dkCreate($this, $learner);
    dkImport($this, $learner, $slug, dkList(2))->assertOk();
    dkCall($this, $learner, 'POST', '/me/course', ['slug' => $slug])->assertOk();
    $open = dkCall($this, $learner, 'POST', '/training/sessions', ['mode' => 'daily_review'])->assertOk();
    expect($open->json('card.card_kind'))->toBe('new');

    dkCall($this, $learner, 'DELETE', "/decks/{$slug}")->assertNoContent();

    $again = dkCall($this, $learner, 'POST', '/training/sessions', ['mode' => 'daily_review'])->assertOk();
    expect($again->json('card'))->toBeNull();
});

it('survives an archived deck as the course snapshot of an open daily session', function (): void {
    $learner = dkLearner();
    $slug = dkCreate($this, $learner);
    dkImport($this, $learner, $slug, dkList(3))->assertOk();
    dkCall($this, $learner, 'POST', '/me/course', ['slug' => $slug])->assertOk();
    $open = dkCall($this, $learner, 'POST', '/training/sessions', ['mode' => 'daily_review'])->assertOk();
    $sessionId = (int) $open->json('session_id');

    dkCall($this, $learner, 'DELETE', "/decks/{$slug}")->assertNoContent();

    dkCall($this, $learner, 'GET', "/training/sessions/{$sessionId}/next")->assertOk()->assertJsonPath('card', null);
});

// --- service ----------------------------------------------------------------------

it('refuses to work on a deck the learner does not own at the service level', function (): void {
    $service = app(LearnerDeckService::class);
    $stranger = dkLearner();
    $deck = Course::factory()->deckOf(dkLearner()->id)->create();

    foreach ([
        fn () => $service->rename($stranger, $deck, 'Взлом'),
        fn () => $service->importText($stranger, $deck, 'a — б', 'list'),
        fn () => $service->removeWord($stranger, $deck, 1),
        fn () => $service->archive($stranger, $deck),
    ] as $call) {
        expect($call)->toThrow(DeckException::class);
    }
    expect($deck->fresh()->status)->toBe('published');
});

it('never lets a platform course be edited as a deck', function (): void {
    $learner = dkLearner();
    $platform = Course::factory()->published()->create();

    expect(fn () => app(LearnerDeckService::class)->importText($learner, $platform, 'a — б', 'list'))->toThrow(DeckException::class)
        ->and(fn () => app(LearnerDeckService::class)->archive($learner, $platform))->toThrow(DeckException::class);
});

// --- throttle -----------------------------------------------------------------------

it('throttles deck writes at 30 a minute and imports at 10 a minute per learner', function (): void {
    $learner = dkLearner();
    $slug = dkCreate($this, $learner);

    foreach (range(1, 9) as $i) {
        dkImport($this, $learner, $slug, "t{$i}x — т{$i}")->assertOk();
    }
    dkImport($this, $learner, $slug, 'last — последний')->assertOk();
    dkImport($this, $learner, $slug, 'over — сверх')->assertStatus(429);

    // Imports have their own bucket: renaming still works, and another learner is not affected.
    dkCall($this, $learner, 'PATCH', "/decks/{$slug}", ['title' => 'Имя'])->assertOk();
    $other = dkLearner();
    dkCreate($this, $other);
});

it('throttles renames after 30 writes a minute', function (): void {
    $learner = dkLearner();
    $slug = dkCreate($this, $learner);

    foreach (range(1, 29) as $i) {
        dkCall($this, $learner, 'PATCH', "/decks/{$slug}", ['title' => "Имя {$i}"])->assertOk();
    }
    dkCall($this, $learner, 'PATCH', "/decks/{$slug}", ['title' => 'Имя'])->assertStatus(429);
});

it('shows the own-deck technical titles in the learner locale in /today and the lesson start', function (): void {
    $owner = dkLearner(['interface_locale' => 'uz']);
    $slug = dkCreate($this, $owner);
    dkImport($this, $owner, $slug, dkList(3))->assertOk();
    $lesson = Lesson::query()->whereHas('stage', fn ($q) => $q->where('course_id', dkDeck($slug)->id))->firstOrFail();
    expect($lesson->title)->toBe('Часть 1');
    $owner->forceFill(['active_course_id' => dkDeck($slug)->id])->save();

    dkCall($this, $owner, 'GET', '/today')->assertOk()->assertJsonPath('active_course.next_lesson.title', '1-qism');
    dkCall($this, $owner, 'POST', '/training/sessions', ['mode' => 'lesson', 'lesson_id' => $lesson->id])
        ->assertOk()->assertJsonPath('lesson.title', '1-qism');

    $ru = dkLearner(['interface_locale' => 'ru']);
    $ruSlug = dkCreate($this, $ru);
    dkImport($this, $ru, $ruSlug, dkList(3))->assertOk();
    $ru->forceFill(['active_course_id' => dkDeck($ruSlug)->id])->save();
    dkCall($this, $ru, 'GET', '/today')->assertJsonPath('active_course.next_lesson.title', 'Часть 1');
});

it('keeps the own title of a regular course for an uz learner', function (): void {
    $course = Course::factory()->published()->create();
    $stage = Stage::factory()->create(['course_id' => $course->id, 'number' => 1]);
    $lesson = Lesson::factory()->create(['stage_id' => $stage->id, 'number' => 1, 'title' => 'Basics']);
    Word::factory()->count(2)->for($lesson)->create();
    $learner = dkLearner(['interface_locale' => 'uz', 'active_course_id' => $course->id]);

    dkCall($this, $learner, 'GET', '/today')->assertOk()->assertJsonPath('active_course.next_lesson.title', 'Basics');
    dkCall($this, $learner, 'POST', '/training/sessions', ['mode' => 'lesson', 'lesson_id' => $lesson->id])
        ->assertOk()->assertJsonPath('lesson.title', 'Basics');
});
