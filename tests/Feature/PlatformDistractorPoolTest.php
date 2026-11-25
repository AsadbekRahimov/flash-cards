<?php

declare(strict_types=1);

use App\Domain\Content\DTO\ResolvedTranslation;
use App\Domain\Content\Services\LessonContentRefresher;
use App\Domain\Content\Services\PlatformDistractorPool;
use App\Domain\Game\DTO\RunLimits;
use App\Domain\Game\Enums\RunKind;
use App\Domain\Game\Services\Runs\RunSnapshotBuilder;
use App\Domain\Game\Support\RunComposer;
use App\Domain\Learning\Enums\ExerciseType;
use App\Domain\Learning\Services\ExercisePromptBuilder;
use App\Domain\Operations\Support\PureCache;
use App\Domain\Practice\DTO\LocaleSet;
use App\Domain\Practice\DTO\SnapshotElement;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\Game\GameKit;
use Tests\Support\Practice\PracticeKit;
use Tests\Support\QueryLog;

/*
 * The third layer of the options of a card and the public tier of the distractors of a run (docs/06 §12.2, docs/18 §12.3.1,
 * docs/38 «Дополнение: варианты ответа»): one shared pool of the public courses of the platform per kind of card
 * (PlatformDistractorPool), kept in the pure cache.
 *
 * - it answers what the two former queries answered (`ORDER BY id LIMIT 40` over every word of the public courses);
 * - the words of a learner's deck, of a center, of a draft or an unlisted course are never in it, whatever their ids;
 * - it is read only when the nearer layers ran short - a card with enough options in its lesson or course, and a run whose words
 *   find their distractors in the run and the course, do not touch it;
 * - no statement on a hit, one on a miss; whatever changes words, lessons or courses drops it.
 */

uses(RefreshDatabase::class);

/**
 * A course with `$lessons` lessons of `$perLesson` words `<prefix><n>` (translation `<prefix>-<n>`), in module 1.
 *
 * @param  array<string, mixed>  $attributes
 * @return array{0: Course, 1: list<Lesson>}
 */
function pdpCourse(string $prefix, int $lessons, int $perLesson, array $attributes = [], ?string $partOfSpeech = null): array
{
    $course = Course::factory()->create(['status' => 'published', ...$attributes]);
    $stage = Stage::factory()->create(['course_id' => $course->id, 'number' => 1]);
    $made = [];
    $n = 0;
    for ($l = 1; $l <= $lessons; $l++) {
        $lesson = Lesson::factory()->create(['stage_id' => $stage->id, 'number' => $l]);
        $rows = [];
        for ($i = 0; $i < $perLesson; $i++) {
            $n++;
            $rows[] = ['lesson_id' => $lesson->id, 'word' => "{$prefix}{$n}", 'translation' => "{$prefix}-{$n}", 'part_of_speech' => $partOfSpeech, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('words')->insert($rows);
        $made[] = $lesson;
    }

    return [$course, $made];
}

/** The former third layer of a card: the 40 lowest ids of the public courses of the kind, other than the card. @return list<int> */
function pdpFormerCardLayer(Word $word, bool $phrases): array
{
    return Word::query()->where('is_active', true)->whereKeyNot($word->id)->orderBy('id')->limit(40)
        ->whereIn('lesson_id', Lesson::query()->select('id')->whereIn('stage_id', Stage::query()->select('id')
            ->whereIn('course_id', Course::query()->select('id')->publicPlatform()->ofCardKind($phrases))))
        ->pluck('id')->map(fn ($id): int => (int) $id)->all();
}

/** The former public branch of the distractors of a run: the 40 lowest ids of the public courses of the kind outside the course. @return list<int> */
function pdpFormerRunTier(Course $course, bool $phrases): array
{
    return Word::query()->join('lessons', 'lessons.id', '=', 'words.lesson_id')->join('stages', 'stages.id', '=', 'lessons.stage_id')
        ->where('words.is_active', true)->orderBy('words.id')->limit(40)
        ->whereIn('stages.course_id', Course::query()->publicPlatform()->ofCardKind($phrases)->where('courses.id', '<>', $course->id)->select('courses.id'))
        ->pluck('words.id')->map(fn ($id): int => (int) $id)->all();
}

/** @param  list<array{id: int, ...}>  $rows  @return list<int> */
function pdpIds(array $rows): array
{
    return array_map(static fn (array $row): int => $row['id'], $rows);
}

function pdpCached(bool $phrases = false): bool
{
    return PureCache::store()->has(PlatformDistractorPool::key($phrases));
}

/** @return list<string> the statements that fill the pool */
function pdpPoolStatements(array $queries): array
{
    return QueryLog::matching($queries, '/row_number\(\) over/i');
}

it('answers a card and a run with the rows of the former queries: the lowest ids of the public courses of the kind', function (): void {
    // Decks and private courses stand BEFORE and BETWEEN the public ones: lower ids must not bring them in.
    pdpCourse('deck', 1, 30, ['owner_type' => 'learner', 'visibility' => 'private']);
    [$first, $firstLessons] = pdpCourse('first', 3, 25);
    pdpCourse('center', 1, 20, ['owner_type' => 'organization', 'visibility' => 'private']);
    [$phraseCourse] = pdpCourse('say this ', 2, 30, ['exam_tag' => 'phrases'], 'phrase');
    [$second] = pdpCourse('second', 2, 30);
    pdpCourse('draft', 1, 20, ['status' => 'draft']);
    [$morePhrases] = pdpCourse('tell that ', 3, 20, ['exam_tag' => 'phrases'], 'phrase');
    [$third] = pdpCourse('third', 2, 30);
    Word::query()->where('lesson_id', $firstLessons[0]->id)->orderBy('id')->limit(3)->update(['is_active' => false]);

    $pool = app(PlatformDistractorPool::class);

    $deckWord = Word::query()->where('word', 'deck1')->sole();
    foreach ([Word::query()->where('word', 'first5')->sole(), Word::query()->where('word', 'first70')->sole(), Word::query()->where('word', 'third9')->sole(), $deckWord] as $card) {
        expect(pdpIds($pool->nearest(false, 40, exceptWordId: (int) $card->id)))->toBe(pdpFormerCardLayer($card, false))->toHaveCount(40);
    }
    $phrase = Word::query()->where('word', 'say this 2')->sole();
    expect(pdpIds($pool->nearest(true, 40, exceptWordId: (int) $phrase->id)))->toBe(pdpFormerCardLayer($phrase, true))->toHaveCount(40);

    foreach ([$first, $second, $third] as $course) {
        expect(pdpIds($pool->nearest(false, 40, exceptCourseId: (int) $course->id)))->toBe(pdpFormerRunTier($course, false))->toHaveCount(40);
    }
    foreach ([$phraseCourse, $morePhrases] as $course) {
        expect(pdpIds($pool->nearest(true, 40, exceptCourseId: (int) $course->id)))->toBe(pdpFormerRunTier($course, true))->toHaveCount(40);
    }

    // A kind never holds the other one, and an entry is a short list whatever the courses hold.
    expect(array_unique(array_column($pool->rows(false), 'part_of_speech')))->toBe([null])
        ->and(array_unique(array_column($pool->rows(true), 'part_of_speech')))->toBe(['phrase'])
        ->and(count($pool->rows(false)))->toBeLessThanOrEqual(82)
        ->and(count($pool->rows(true)))->toBeLessThanOrEqual(82);
});

it('never holds a word of a course outside the whitelist, whatever its ids', function (string $status, string $visibility, string $ownerType): void {
    // The outsider comes first (the lowest ids of all), then a public course, then the outsider's twin with the highest ids.
    pdpCourse('outsider', 2, 20, ['status' => $status, 'visibility' => $visibility, 'owner_type' => $ownerType]);
    pdpCourse('outphrase ', 1, 10, ['status' => $status, 'visibility' => $visibility, 'owner_type' => $ownerType, 'exam_tag' => 'phrases'], 'phrase');
    pdpCourse('public', 2, 30);
    pdpCourse('pubphrase ', 1, 10, ['exam_tag' => 'phrases'], 'phrase');
    pdpCourse('late', 1, 20, ['status' => $status, 'visibility' => $visibility, 'owner_type' => $ownerType]);

    $pool = app(PlatformDistractorPool::class);

    foreach ([false, true] as $phrases) {
        $rows = $pool->rows($phrases);
        expect($rows)->not->toBeEmpty();
        foreach ($rows as $row) {
            expect($row['word'])->toStartWith($phrases ? 'pubphrase ' : 'public');
        }
    }
})->with([
    'a deck of a learner' => ['published', 'private', 'learner'],
    'a deck of a learner, were it ever public' => ['published', 'public', 'learner'],
    'a course of a center' => ['published', 'public', 'organization'],
    'a draft' => ['draft', 'public', 'platform'],
    'in review' => ['review', 'public', 'platform'],
    'archived' => ['archived', 'public', 'platform'],
    'private' => ['published', 'private', 'platform'],
    'unlisted' => ['published', 'unlisted', 'platform'],
]);

it('never shows a word of somebody else\'s deck among the options of a card or of a run', function (): void {
    $owner = Learner::factory()->create();
    // The deck of another learner is older than everything else and bigger than the public course.
    pdpCourse('secret', 2, 40, ['owner_type' => 'learner', 'owner_id' => $owner->id, 'visibility' => 'private']);
    pdpCourse('open', 1, 12);
    [, $mine] = pdpCourse('mine', 1, 1, ['owner_type' => 'learner', 'owner_id' => Learner::factory()->create()->id, 'visibility' => 'private']);
    $card = Word::query()->where('lesson_id', $mine[0]->id)->sole();
    $builder = app(ExercisePromptBuilder::class);

    foreach ([ExerciseType::Reverse, ExerciseType::Meaning, ExerciseType::Audio, ExerciseType::Pretest] as $type) {
        foreach (range(1, 25) as $seed) {
            $prompt = $builder->build($type, $card, new ResolvedTranslation('моё', 'ru', false), 'ru', null, $seed);
            expect($prompt->type)->toBe($type)->and($prompt->options)->toHaveCount(4);
            foreach ($prompt->options as $option) {
                expect($option)->toMatch($type === ExerciseType::Reverse ? '/^(mine1|open\d+)$/' : '/^(моё|open-\d+)$/');
            }
        }
    }

    // The run: every word of the stop has one translation, so no word finds a distractor in the run - the public tier is asked.
    GameKit::openAccess();
    GameKit::noEvents();
    [$course, $lesson] = PracticeKit::course('game-course');
    $learner = Learner::factory()->create();
    foreach (range(1, 8) as $i) {
        GameKit::word($lesson, $learner, "same{$i}", 'одно и то же');
    }

    $snapshot = app(RunSnapshotBuilder::class)->forStop($learner, $course, $lesson, LocaleSet::of('ru'), 'seed-of-the-privacy-test-aaaaaaaaaaaa');

    $texts = json_encode($snapshot->elements, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    expect($texts)->toContain('open')->not->toContain('secret');
});

it('cuts an entry where the words outside the biggest course are enough for a run of any course', function (): void {
    $rows = static function (array $courses): array {
        $list = [];
        foreach ($courses as $id => $course) {
            $list[] = ['id' => $id + 1, 'course_id' => $course];
        }

        return $list;
    };

    // A big course, then another: its 41 lowest ids (a card of the course drops itself) and 40 of the next one.
    $two = PlatformDistractorPool::reduce($rows([...array_fill(0, 41, 1), ...array_fill(0, 41, 2), ...array_fill(0, 41, 3)]));
    expect($two)->toHaveCount(81)->and(array_count_values(array_column($two, 'course_id')))->toBe([1 => 41, 2 => 40]);

    // Small courses: the walk goes on until the others hold 40 without the biggest of them.
    $small = PlatformDistractorPool::reduce($rows([...array_fill(0, 30, 1), ...array_fill(0, 10, 2), ...array_fill(0, 20, 3), ...array_fill(0, 20, 4)]));
    expect($small)->toHaveCount(70)->and(array_count_values(array_column($small, 'course_id')))->toBe([1 => 30, 2 => 10, 3 => 20, 4 => 10]);

    // Words of two courses written in turns.
    $mixed = [];
    for ($i = 0; $i < 82; $i++) {
        $mixed[] = 1 + $i % 2;
    }
    expect(PlatformDistractorPool::reduce($rows($mixed)))->toHaveCount(80);

    // Not enough of them at all: everything there is; nothing: nothing.
    expect(PlatformDistractorPool::reduce($rows(array_fill(0, 12, 1))))->toHaveCount(12)
        ->and(PlatformDistractorPool::reduce([]))->toBe([]);
});

it('does not read the pool for a card that finds its options in the lesson or in the course', function (): void {
    pdpCourse('public', 1, 30);
    [, $rich] = pdpCourse('rich', 1, 6);
    [, $thin] = pdpCourse('thin', 2, 2); // two words in the lesson, two more in the course: three distractors without the platform
    $builder = app(ExercisePromptBuilder::class);

    foreach ([$rich[0], $thin[0]] as $lesson) {
        $card = Word::query()->where('lesson_id', $lesson->id)->orderBy('id')->firstOrFail();
        $translation = new ResolvedTranslation($card->translation, 'ru', false);

        foreach ([ExerciseType::Reverse, ExerciseType::Meaning, ExerciseType::Audio, ExerciseType::Pretest] as $type) {
            $queries = QueryLog::during(function () use ($builder, $type, $card, $translation, &$prompt, &$effective): void {
                $effective = $builder->effectiveType($type, $card, 'ru', 3);
                $prompt = $builder->build($type, $card, $translation, 'ru', null, 3);
            });

            expect($effective)->toBe($type)->and($prompt->options)->toHaveCount(4)
                ->and(pdpPoolStatements($queries))->toBe([])
                ->and(QueryLog::matching($queries, '/["`]courses["`]/'))->toBe([]);
            foreach ($prompt->options as $option) {
                expect($option)->not->toContain('public');
            }
        }
    }

    expect(pdpCached())->toBeFalse()->and(pdpCached(true))->toBeFalse();
});

it('reads the pool with one statement for a card of a lonely deck, with none once it is cached, and shows the same options either way', function (): void {
    pdpCourse('public', 2, 30);
    [, $lonely] = pdpCourse('lonely', 1, 1, ['owner_type' => 'learner', 'visibility' => 'private']);
    $card = Word::query()->where('lesson_id', $lonely[0]->id)->sole();
    $translation = new ResolvedTranslation('одинокое', 'ru', false);
    $builder = app(ExercisePromptBuilder::class);
    // The lesson pool and the translations of the candidates are shared caches of their own: warm them, drop the pool alone.
    $builder->build(ExerciseType::Reverse, $card, $translation, 'ru', null, 11);
    PlatformDistractorPool::forget();
    expect(pdpCached())->toBeFalse();

    $cold = QueryLog::during(function () use ($builder, $card, $translation, &$first): void {
        $first = $builder->build(ExerciseType::Reverse, $card, $translation, 'ru', null, 11);
    });
    $warm = QueryLog::during(function () use ($builder, $card, $translation, &$second): void {
        $second = $builder->build(ExerciseType::Reverse, $card, $translation, 'ru', null, 11);
    });
    PlatformDistractorPool::forget();
    $again = $builder->build(ExerciseType::Reverse, $card, $translation, 'ru', null, 11);

    expect(pdpPoolStatements($cold))->toHaveCount(1)
        ->and(pdpPoolStatements($warm))->toBe([])
        ->and(count($warm))->toBe(count($cold) - 1)
        // On a hit the platform layer costs nothing: what is left is the course of the card and the course layer.
        ->and(QueryLog::matching($warm, '/["`]courses["`]/'))->toBe([])
        ->and($first->options)->toHaveCount(4)
        ->and($second->options)->toBe($first->options)
        ->and($again->options)->toBe($first->options)
        // Another serve of the card (another seed) is another choice from the same forty.
        ->and($builder->build(ExerciseType::Reverse, $card, $translation, 'ru', null, 12)->options)->not->toBe($first->options);
});

it('keeps a phrase to the phrases and a word to the words in the third layer, each from its own entry', function (): void {
    pdpCourse('public', 1, 20);
    pdpCourse('say this ', 1, 20, ['exam_tag' => 'phrases'], 'phrase');
    [, $lonelyWords] = pdpCourse('alone', 1, 1);
    [, $lonelyPhrases] = pdpCourse('tell that ', 1, 1, ['exam_tag' => 'phrases'], 'phrase');
    $builder = app(ExercisePromptBuilder::class);

    $word = Word::query()->where('lesson_id', $lonelyWords[0]->id)->sole();
    $phrase = Word::query()->where('lesson_id', $lonelyPhrases[0]->id)->sole();
    $forWord = $builder->build(ExerciseType::Reverse, $word, new ResolvedTranslation('слово', 'ru', false), 'ru', null, 5);
    $forPhrase = $builder->build(ExerciseType::Reverse, $phrase, new ResolvedTranslation('фраза', 'ru', false), 'ru', null, 5);

    expect($forWord->options)->toHaveCount(4)->and($forPhrase->options)->toHaveCount(4);
    foreach ($forWord->options as $option) {
        expect($option)->toMatch('/^(alone1|public\d+)$/');
    }
    foreach ($forPhrase->options as $option) {
        expect($option)->toMatch('/^(tell that 1|say this \d+)$/');
    }
    expect(pdpCached())->toBeTrue()->and(pdpCached(true))->toBeTrue();
});

it('does not read the pool at the start of a run whose words find their distractors in the run and the course', function (): void {
    GameKit::openAccess();
    GameKit::noEvents();
    pdpCourse('public', 2, 30);
    [$learner, $course, $lesson] = GameKit::stop(8);
    $builder = app(RunSnapshotBuilder::class);
    $locales = LocaleSet::of('ru');
    $seed = 'seed-of-the-lazy-tier-test-aaaaaaaaaaaaa';

    $queries = QueryLog::during(function () use ($builder, $learner, $course, $lesson, $locales, $seed, &$pool, &$composition): void {
        $pool = $builder->poolForStop($learner, $course, $lesson, $lesson->stage, $locales, $seed, RunLimits::for(RunKind::Stop));
        $composition = RunComposer::compose($pool, $seed, RunLimits::for(RunKind::Stop));
    });

    expect($composition->count())->toBe(8)
        ->and($pool->public)->not->toBeNull()
        ->and($pool->public?->wasRead())->toBeFalse()
        ->and(pdpCached())->toBeFalse()
        ->and(pdpPoolStatements($queries))->toBe([])
        // The words of the course are one plain query now, not a UNION with every word of the public courses.
        ->and(QueryLog::matching($queries, '/union/i'))->toBe([])
        ->and(QueryLog::matching($queries, '/["`]courses["`]\.["`]owner_type["`]/'))->toBe([]);

    // The request itself: no statement of the pool, and nothing cached by it.
    $start = QueryLog::during(fn () => test()->withHeaders(PracticeKit::headers($learner))->postJson('/api/twa/game/runs', ['kind' => 'stop', 'course' => $course->slug, 'lesson_id' => $lesson->id])->assertCreated());
    expect(pdpPoolStatements($start))->toBe([])->and(QueryLog::matching($start, '/union/i'))->toBe([])->and(pdpCached())->toBeFalse();
});

it('reads the pool once for a run that needs the public tier: one statement on a miss, none on a hit, the same composition', function (): void {
    GameKit::openAccess();
    GameKit::noEvents();
    pdpCourse('public', 2, 30);
    [$course, $lesson] = PracticeKit::course('game-course');
    $learner = Learner::factory()->create();
    foreach (range(1, 8) as $i) {
        GameKit::word($lesson, $learner, "same{$i}", 'одно и то же');
    }
    $builder = app(RunSnapshotBuilder::class);
    $locales = LocaleSet::of('ru');
    $seed = 'seed-of-the-public-tier-test-aaaaaaaaaaa';
    $shape = static fn (array $elements): array => array_map(static fn (SnapshotElement $e): array => [$e->kind, $e->get('word_id'), array_column((array) $e->get('options'), 'text')], $elements);
    // The translations of the candidates are a cache of their own: warm it, then drop the pool alone.
    $builder->forStop($learner, $course, $lesson, $locales, $seed);
    PlatformDistractorPool::forget();

    $cold = QueryLog::during(function () use ($builder, $learner, $course, $lesson, $locales, $seed, &$first): void {
        $first = $builder->forStop($learner, $course, $lesson, $locales, $seed);
    });
    $warm = QueryLog::during(function () use ($builder, $learner, $course, $lesson, $locales, $seed, &$second): void {
        $second = $builder->forStop($learner, $course, $lesson, $locales, $seed);
    });

    expect(pdpPoolStatements($cold))->toHaveCount(1)
        ->and(pdpPoolStatements($warm))->toBe([])
        ->and(count($warm))->toBe(count($cold) - 1)
        ->and(pdpCached())->toBeTrue()
        ->and($shape($second->elements))->toBe($shape($first->elements))
        ->and(json_encode($first->elements, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))->toContain('public');
});

it('gives a run of a public course the words of the OTHER public courses only', function (): void {
    [$own] = pdpCourse('own', 2, 30);
    pdpCourse('other', 2, 30);
    $builder = app(RunSnapshotBuilder::class);
    $method = new ReflectionMethod($builder, 'publicDistractors');

    $words = $method->invoke($builder, $own, LocaleSet::of('ru'))->all();

    expect($words)->toHaveCount(40);
    foreach ($words as $word) {
        expect($word->word->word)->toStartWith('other')->and($word->tier)->toBe(2);
    }
});

it('drops the pool when a word, a lesson or a course changes: the old rows are not served', function (): void {
    [$course, $lessons] = pdpCourse('public', 2, 10);
    [$other] = pdpCourse('other', 1, 10);
    $pool = app(PlatformDistractorPool::class);
    $words = static fn (): array => array_column($pool->rows(false), 'word');

    // A word switched off in the panel (the refresher is what the panel calls after its UPDATE).
    expect($words())->toContain('public1');
    $word = Word::query()->where('word', 'public1')->sole();
    $word->update(['is_active' => false]);
    app(LessonContentRefresher::class)->wordChanged($word);
    expect($words())->not->toContain('public1')->toContain('public2');

    // A word renamed.
    $renamed = Word::query()->where('word', 'public2')->sole();
    $renamed->update(['word' => 'renamed2']);
    app(LessonContentRefresher::class)->wordChanged($renamed);
    expect($words())->toContain('renamed2')->not->toContain('public2');

    // A lesson removed.
    expect($words())->toContain('public11');
    $lessons[1]->delete();
    expect($words())->not->toContain('public11');

    // A course hidden: not public any more - its words leave the options of everybody.
    expect($words())->toContain('other1');
    $other->update(['visibility' => 'private']);
    expect($words())->not->toContain('other1')->toContain('public3');

    // ... published again, then turned into a course of phrases: it moves to the other entry.
    $other->update(['visibility' => 'public']);
    expect($words())->toContain('other1')->and(array_column($pool->rows(true), 'word'))->toBe([]);
    $other->update(['exam_tag' => 'phrases']);
    expect($words())->not->toContain('other1')->and(array_column($pool->rows(true), 'word'))->toContain('other1');

    // A course removed.
    expect($words())->toContain('public3');
    $course->delete();
    expect($words())->toBe([]);
});

it('keeps an entry as plain rows under content:platform-distractors:v1:{kind} for ten minutes', function (): void {
    config(['cache.serializable_classes' => false, 'cache.stores.array.serialize' => true]);
    Cache::purge();
    pdpCourse('public', 1, 5, [], 'noun');
    DB::table('words')->where('word', 'public1')->update(['example' => 'An example.', 'audio_path' => 'audio/public1.mp3']);
    $pool = app(PlatformDistractorPool::class);

    $fresh = $pool->rows(false);
    $read = $pool->rows(false);

    expect(PlatformDistractorPool::key(false))->toBe('content:platform-distractors:v1:words')
        ->and(PlatformDistractorPool::key(true))->toBe('content:platform-distractors:v1:phrases')
        ->and($read)->toBe($fresh)->toHaveCount(5)
        ->and($read[0])->toBe(['id' => $read[0]['id'], 'lesson_id' => $read[0]['lesson_id'], 'course_id' => $read[0]['course_id'], 'word' => 'public1', 'translation' => 'public-1', 'audio_path' => 'audio/public1.mp3', 'example' => 'An example.', 'part_of_speech' => 'noun'])
        ->and($read[1]['audio_path'])->toBeNull()->and($read[1]['example'])->toBeNull();

    $this->travel(601)->seconds();
    expect(pdpCached())->toBeFalse();
});
