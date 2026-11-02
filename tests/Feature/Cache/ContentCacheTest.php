<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Services\CheckoutService;
use App\Domain\Billing\Services\LearnerBillingService;
use App\Domain\Content\DTO\LessonProgress;
use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Content\Services\CoursePublisher;
use App\Domain\Content\Services\LessonContentRefresher;
use App\Domain\Content\Services\VocabularyImporter;
use App\Domain\Content\Services\WordTranslationResolver;
use App\Domain\Content\Services\WordTranslationWriter;
use App\Domain\Operations\Support\PureCache;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Offer;
use App\Models\Stage;
use App\Models\User;
use App\Models\Word;
use App\Models\WordRepetition;
use App\Models\WordTranslation;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeBillingProvider;
use Tests\Support\HotPathFixtures as Fx;
use Tests\Support\QueryLog;

/*
 * The content caches (docs/19 K3, K4, K5): the tree of a course, the translation rows of words, and the static part of the
 * billing catalog - which is NOT a Redis key, see the last tests. Each cached key has a test of the kind "write, and the old
 * value is not served", per path that writes.
 */

uses(RefreshDatabase::class);

/**
 * The page of a course as rows `[stage, lesson, total, done]`.
 *
 * @return list<array{0: int, 1: int, 2: int, 3: int}>
 */
function coursePageRows(Course $course, Learner $learner): array
{
    return array_map(
        fn (LessonProgress $l): array => [$l->stageNumber, $l->lessonNumber, $l->progress->total, $l->progress->done],
        app(CourseCatalogService::class)->lessonsWithProgress($course, $learner),
    );
}

// --- K3: the tree of a course ---------------------------------------------------------------------------

it('builds the page of a course from a cached tree and the learner\'s own progress: the second page costs one query', function (): void {
    $fx = Fx::course([1 => [1 => 3, 2 => 2], 2 => [1 => 4]]);
    $learner = Learner::factory()->create();
    foreach (array_slice($fx['words']['1.1']['active'], 0, 2) as $wordId) {
        WordRepetition::factory()->create(['learner_id' => $learner->id, 'word_id' => $wordId, 'repetitions' => 1]);
    }
    $catalog = app(CourseCatalogService::class);

    $first = QueryLog::during(fn () => $catalog->detail($fx['course'], $learner));
    $second = QueryLog::during(fn () => $catalog->detail($fx['course'], $learner));

    expect($first)->toHaveCount(2, 'the tree and the learner\'s done')
        ->and($second)->toHaveCount(1)
        ->and($second[0])->toContain('word_repetitions')
        ->and(coursePageRows($fx['course'], $learner))->toBe([[1, 1, 3, 2], [1, 2, 2, 0], [2, 1, 4, 0]]);

    $detail = $catalog->detail($fx['course'], $learner);
    expect($detail->progress->done)->toBe(2)->and($detail->progress->total)->toBe(9);

    // The same page from an empty cache: the cache changes nothing about what the learner reads.
    PureCache::store()->flush();
    Cache::flush();
    expect(coursePageRows($fx['course'], $learner))->toBe([[1, 1, 3, 2], [1, 2, 2, 0], [2, 1, 4, 0]]);
});

it('keeps one entry per course and locale, with the data version in the key, arrays only', function (): void {
    $fx = Fx::course([1 => [1 => 2]]);
    $ru = Learner::factory()->create(['interface_locale' => 'ru']);
    $uz = Learner::factory()->create(['interface_locale' => 'uz']);
    $catalog = app(CourseCatalogService::class);

    $catalog->detail($fx['course'], $ru);
    $catalog->detail($fx['course'], $uz);

    $id = $fx['course']->id;
    $keyRu = CourseCatalogService::courseTreeKey($id, 'ru');
    $keyUz = CourseCatalogService::courseTreeKey($id, 'uz');

    // The version is the number of times the course's data was changed (the fixture's own writes included).
    expect($keyRu)->toStartWith("catalog:course:v1:{$id}:ru:n")
        ->and($keyUz)->toStartWith("catalog:course:v1:{$id}:uz:n")
        ->and(PureCache::store()->has($keyRu))->toBeTrue()
        ->and(PureCache::store()->has($keyUz))->toBeTrue()
        ->and(PureCache::store()->get($keyRu))->toBeArray()->each->toBeArray();
});

it('never caches the progress: another learner, and the next answer, show at once', function (): void {
    $fx = Fx::course([1 => [1 => 3]]);
    $a = Learner::factory()->create();
    $b = Learner::factory()->create();
    expect(coursePageRows($fx['course'], $a))->toBe([[1, 1, 3, 0]]);

    WordRepetition::factory()->create(['learner_id' => $a->id, 'word_id' => $fx['words']['1.1']['active'][0], 'repetitions' => 2]);
    WordRepetition::factory()->create(['learner_id' => $b->id, 'word_id' => $fx['words']['1.1']['active'][1], 'repetitions' => 1]);
    WordRepetition::factory()->create(['learner_id' => $b->id, 'word_id' => $fx['words']['1.1']['active'][2], 'repetitions' => 1]);
    // A card seen but not yet answered (repetitions 0) is not learned.
    WordRepetition::factory()->create(['learner_id' => $a->id, 'word_id' => $fx['words']['1.1']['active'][2], 'repetitions' => 0]);

    expect(coursePageRows($fx['course'], $a))->toBe([[1, 1, 3, 1]])
        ->and(coursePageRows($fx['course'], $b))->toBe([[1, 1, 3, 2]]);
});

it('drops the tree when the words of the course change: an import, a switched word, a recount', function (): void {
    $fx = Fx::course([1 => [1 => 2]]);
    $learner = Learner::factory()->create();
    expect(coursePageRows($fx['course'], $learner))->toBe([[1, 1, 2, 0]]);

    // The panel switches a word off and calls the refresher after the write (WordsRelationManager, EditWord).
    $word = Word::query()->findOrFail($fx['words']['1.1']['active'][0]);
    $word->update(['is_active' => false]);
    expect(coursePageRows($fx['course'], $learner))->toBe([[1, 1, 2, 0]], 'nothing told the cache yet');
    app(LessonContentRefresher::class)->wordChanged($word);
    expect(coursePageRows($fx['course'], $learner))->toBe([[1, 1, 1, 0]]);

    // An import of a new lesson and of more words into an existing one.
    $json = fn (int $lesson, array $words): string => (string) json_encode([
        'course' => $fx['course']->slug,
        'stage' => ['number' => 1],
        'lesson' => ['number' => $lesson],
        'words' => array_map(fn (string $w): array => ['word' => $w, 'translation' => "т-{$w}"], $words),
    ]);
    $report = app(VocabularyImporter::class)->importAsSystem($json(2, ['alpha', 'beta', 'gamma']));
    expect($report->aborted)->toBeFalse()
        ->and(coursePageRows($fx['course'], $learner))->toBe([[1, 1, 1, 0], [1, 2, 3, 0]]);

    app(VocabularyImporter::class)->importAsSystem($json(2, ['delta']));
    expect(coursePageRows($fx['course'], $learner))->toBe([[1, 1, 1, 0], [1, 2, 4, 0]]);

    // The repair command's path.
    DB::table('words')->where('lesson_id', $fx['lessons']['1.1']->id)->update(['is_active' => true]);
    app(CoursePublisher::class)->recount($fx['course']);
    expect(coursePageRows($fx['course'], $learner))->toBe([[1, 1, 2, 0], [1, 2, 4, 0]]);
});

it('drops the tree when a module or a lesson is created, renamed, moved or deleted', function (): void {
    $fx = Fx::course([1 => [1 => 2], 2 => [1 => 1]]);
    $learner = Learner::factory()->create();
    $catalog = app(CourseCatalogService::class);
    $titles = fn (): array => array_map(fn (LessonProgress $l): array => [$l->stageTitle, $l->lessonTitle, $l->lessonNumber], $catalog->lessonsWithProgress($fx['course'], $learner));

    // The factories give names; a module and a lesson without one are what the test reads.
    Stage::query()->where('course_id', $fx['course']->id)->get()->each->update(['title' => null]);
    foreach ($fx['lessons'] as $lesson) {
        $lesson->update(['title' => null]);
    }

    expect($titles())->toBe([[null, null, 1], [null, null, 1]]);

    // A module renamed in the panel.
    Stage::query()->where('course_id', $fx['course']->id)->where('number', 1)->firstOrFail()->update(['title' => 'Basics']);
    expect($titles())->toBe([['Basics', null, 1], [null, null, 1]]);

    // A lesson renamed, then a new one in module 2, then one deleted.
    $fx['lessons']['1.1']->update(['title' => 'Greetings']);
    expect($titles())->toBe([['Basics', 'Greetings', 1], [null, null, 1]]);

    $stage2 = Stage::query()->where('course_id', $fx['course']->id)->where('number', 2)->firstOrFail();
    $new = Lesson::factory()->create(['stage_id' => $stage2->id, 'number' => 2, 'title' => 'Numbers']);
    expect($titles())->toBe([['Basics', 'Greetings', 1], [null, null, 1], [null, 'Numbers', 2]]);

    $new->delete();
    expect($titles())->toBe([['Basics', 'Greetings', 1], [null, null, 1]]);

    // A lesson moved to another module: both pages of the tree change in one.
    $moving = Lesson::factory()->create(['stage_id' => $stage2->id, 'number' => 5, 'title' => 'Moving']);
    expect(count($titles()))->toBe(3);
    $moving->update(['stage_id' => Stage::query()->where('course_id', $fx['course']->id)->where('number', 1)->firstOrFail()->id]);
    expect($titles())->toBe([['Basics', 'Greetings', 1], ['Basics', 'Moving', 5], [null, null, 1]]);

    // A module deleted with its lessons (the foreign keys cascade).
    $stage2->delete();
    expect($titles())->toBe([['Basics', 'Greetings', 1], ['Basics', 'Moving', 5]]);
});

it('drops the tree on a change of status and leaves the trees of other courses alone', function (): void {
    app(RolePermissionSeeder::class)->run();
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');
    $one = Fx::course([1 => [1 => 2]], ['status' => 'draft']);
    $two = Fx::course([1 => [1 => 3]]);
    $learner = Learner::factory()->create();
    coursePageRows($one['course'], $learner);
    coursePageRows($two['course'], $learner);

    $keyOne = CourseCatalogService::courseTreeKey($one['course']->id, 'ru');
    $keyTwo = CourseCatalogService::courseTreeKey($two['course']->id, 'ru');

    app(CoursePublisher::class)->transition($one['course'], 'review', $admin);

    expect(CourseCatalogService::courseTreeKey($one['course']->id, 'ru'))->not->toBe($keyOne, 'a new version of the first')
        ->and(CourseCatalogService::courseTreeKey($two['course']->id, 'ru'))->toBe($keyTwo)
        ->and(PureCache::store()->has($keyTwo))->toBeTrue();
});

it('does not drop the tree for a change that was rolled back', function (): void {
    $fx = Fx::course([1 => [1 => 2]]);
    $learner = Learner::factory()->create();
    coursePageRows($fx['course'], $learner);
    $key = CourseCatalogService::courseTreeKey($fx['course']->id, 'ru');

    try {
        DB::transaction(function () use ($fx): void {
            $fx['lessons']['1.1']->update(['title' => 'Never saved']);

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }

    expect(CourseCatalogService::courseTreeKey($fx['course']->id, 'ru'))->toBe($key)
        ->and(PureCache::store()->has($key))->toBeTrue();
});

it('does not cache a learner\'s own deck: it is private, edited all the time and its titles follow the reader', function (): void {
    $owner = Learner::factory()->create(['interface_locale' => 'uz']);
    $fx = Fx::course([1 => [1 => 2]], ['owner_type' => 'learner', 'owner_id' => $owner->id, 'visibility' => 'private']);
    $catalog = app(CourseCatalogService::class);

    $lessons = $catalog->lessonsWithProgress($fx['course'], $owner);

    expect(PureCache::store()->has(CourseCatalogService::courseTreeKey($fx['course']->id, 'uz')))->toBeFalse()
        ->and($lessons[0]->stageTitle)->toBe(__('decks.stage', [], 'uz'))
        ->and($lessons[0]->lessonTitle)->toBe(__('decks.part', ['n' => 1], 'uz'));

    $owner->update(['interface_locale' => 'ru']);
    expect($catalog->lessonsWithProgress($fx['course'], $owner->refresh())[0]->stageTitle)->toBe(__('decks.stage', [], 'ru'));
});

// --- K4: translations of words, in batches --------------------------------------------------------------

/** @return array{0: Word, 1: Word, 2: Word} one word with ru + uz rows, one with a ru row only, one with none (legacy column) */
function translatedWords(): array
{
    $lesson = Lesson::factory()->create();
    $a = Word::factory()->create(['lesson_id' => $lesson->id, 'translation' => 'а-legacy']);
    $b = Word::factory()->create(['lesson_id' => $lesson->id, 'translation' => 'б-legacy']);
    $c = Word::factory()->create(['lesson_id' => $lesson->id, 'translation' => 'в-legacy']);
    WordTranslation::factory()->create(['word_id' => $a->id, 'locale' => 'ru', 'translation' => 'а-ru']);
    WordTranslation::factory()->uz()->create(['word_id' => $a->id, 'translation' => 'a-uz']);
    WordTranslation::factory()->create(['word_id' => $b->id, 'locale' => 'ru', 'translation' => 'б-ru']);

    return [$a, $b, $c];
}

it('reads translations of a batch with one Cache::many, and the words it has not seen with one query', function (): void {
    [$a, $b, $c] = translatedWords();
    $resolver = app(WordTranslationResolver::class);

    DB::enableQueryLog();
    $first = $resolver->map([$a, $b, $c], 'uz');
    $coldQueries = DB::getQueryLog();
    DB::flushQueryLog();
    $second = $resolver->map([$a, $b, $c], 'uz');
    $warmQueries = DB::getQueryLog();
    DB::flushQueryLog();

    expect($first)->toBe([$a->id => 'a-uz', $b->id => 'б-ru', $c->id => 'в-legacy'])
        ->and($second)->toBe($first)
        ->and($coldQueries)->toHaveCount(1)
        ->and($warmQueries)->toBe([]);

    // Only the word the cache has not seen goes to the database.
    $d = Word::factory()->create(['lesson_id' => $a->lesson_id]);
    WordTranslation::factory()->uz()->create(['word_id' => $d->id, 'translation' => 'd-uz']);
    DB::flushQueryLog();
    $third = $resolver->map([$a, $d], 'uz');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($third)->toBe([$a->id => 'a-uz', $d->id => 'd-uz'])
        ->and($queries)->toHaveCount(1)
        ->and($queries[0]['bindings'])->toContain($d->id)->not->toContain($a->id);
});

it('keeps the rows of a word under a key of its own per locale, a word with no rows as an empty array', function (): void {
    [$a, , $c] = translatedWords();
    app(WordTranslationResolver::class)->map([$a, $c], 'uz');

    expect(WordTranslationResolver::cacheKey($a->id, 'uz'))->toBe("word:tr:v1:{$a->id}:uz")
        ->and(PureCache::store()->get(WordTranslationResolver::cacheKey($a->id, 'uz')))->toBe(['ru' => 'а-ru', 'uz' => 'a-uz'])
        ->and(PureCache::store()->get(WordTranslationResolver::cacheKey($c->id, 'uz')))->toBe([])
        ->and(PureCache::store()->has(WordTranslationResolver::cacheKey($a->id, 'ru')))->toBeFalse('no one asked for ru');
});

it('shows a manual edit of a translation at once, in its locale and in the locales that fall back to it', function (): void {
    [$a, $b] = translatedWords();
    $resolver = app(WordTranslationResolver::class);
    $writer = app(WordTranslationWriter::class);

    expect($resolver->for($a, 'uz'))->toBe('a-uz')
        ->and($resolver->for($b, 'uz'))->toBe('б-ru', 'b falls back to its ru row')
        ->and($resolver->for($b, 'ru'))->toBe('б-ru');

    $writer->save($a, 'uz', 'a-uz-new');
    expect($resolver->for($a, 'uz'))->toBe('a-uz-new');

    // The ru row is what a uz reader sees for b: editing it must reach the uz entry too.
    $writer->save($b, 'ru', 'б-ru-new');
    expect($resolver->for($b, 'ru'))->toBe('б-ru-new')
        ->and($resolver->for($b, 'uz'))->toBe('б-ru-new');

    // The ru edit of the word itself is mirrored into its ru row by syncDefaultFromWord.
    $a->forceFill(['translation' => 'а-edited'])->save();
    $writer->syncDefaultFromWord($a);
    expect($resolver->for($a, 'ru'))->toBe('а-edited');
});

it('shows the legacy words.translation at once: only the rows are cached, never that column', function (): void {
    [, , $c] = translatedWords();
    $resolver = app(WordTranslationResolver::class);
    expect($resolver->for($c, 'uz'))->toBe('в-legacy');

    $c->forceFill(['translation' => 'в-edited'])->save();

    expect($resolver->for($c, 'uz'))->toBe('в-edited')
        ->and($resolver->for($c->refresh(), 'ru'))->toBe('в-edited');
});

it('shows a re-import that changed a translation, and a translation that was added or deleted', function (): void {
    $course = Course::factory()->published()->create();
    $json = fn (string $uz): string => (string) json_encode([
        'course' => $course->slug,
        'stage' => ['number' => 1],
        'lesson' => ['number' => 1],
        'words' => [['word' => 'apple', 'translation' => 'яблоко', 'translations' => ['ru' => 'яблоко', 'uz' => $uz]]],
    ]);
    app(VocabularyImporter::class)->importAsSystem($json('olma'));
    $word = Word::query()->where('word', 'apple')->firstOrFail();
    $resolver = app(WordTranslationResolver::class);
    expect($resolver->for($word, 'uz'))->toBe('olma');

    app(VocabularyImporter::class)->importAsSystem($json('olma-2'));
    expect($resolver->for($word, 'uz'))->toBe('olma-2');

    WordTranslation::query()->where('word_id', $word->id)->where('locale', 'uz')->firstOrFail()->delete();
    expect($resolver->for($word, 'uz'))->toBe('яблоко', 'falls back to ru once the uz row is gone');

    WordTranslation::factory()->uz()->create(['word_id' => $word->id, 'translation' => 'olma-3']);
    expect($resolver->for($word, 'uz'))->toBe('olma-3');
});

it('does not drop the rows of a word for a write that was rolled back', function (): void {
    [$a] = translatedWords();
    $resolver = app(WordTranslationResolver::class);
    $resolver->for($a, 'uz');

    try {
        DB::transaction(function () use ($a): void {
            app(WordTranslationWriter::class)->save($a, 'uz', 'never saved');

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }

    expect(PureCache::store()->has(WordTranslationResolver::cacheKey($a->id, 'uz')))->toBeTrue()
        ->and($resolver->for($a, 'uz'))->toBe('a-uz');
});

// --- K5: the static part of the billing catalog is not a Redis key -------------------------------------

it('keeps the static part of the billing catalog out of Redis: it is config reads, and the paywall of /me costs no query', function (): void {
    FakeBillingProvider::register(SubscriptionProvider::TelegramStars);
    FakeBillingProvider::register(SubscriptionProvider::Payme);
    config(['billing.paywall.mode' => 'purchase', 'billing.providers.payme.enabled' => true, 'billing.providers.telegram_stars.enabled' => true]);
    $learner = Learner::factory()->create();
    $billing = app(LearnerBillingService::class);

    $queries = QueryLog::during(fn () => expect($billing->paywall($learner)['mode'])->toBe('purchase'));

    expect($queries)->toBe([], 'the offers and the live subscription of the learner are not read to know whether anything is for sale');

    // Nothing to sell: no provider, then no price in the currency of the one that is on.
    config(['billing.providers.payme.enabled' => false, 'billing.providers.telegram_stars.enabled' => false]);
    expect($billing->paywall($learner)['mode'])->toBe('coming_soon');
    config(['billing.providers.telegram_stars.enabled' => true, 'billing.prices.pro.month.XTR' => 0, 'billing.prices.pro.year.XTR' => 0]);
    expect($billing->paywall($learner)['mode'])->toBe('coming_soon');
    config(['billing.prices.pro.year.XTR' => 1300]);
    expect($billing->paywall($learner)['mode'])->toBe('purchase');
});

it('still gives each learner their own offer and subscription in the catalog: the personal part is never shared', function (): void {
    FakeBillingProvider::register(SubscriptionProvider::TelegramStars);
    FakeBillingProvider::register(SubscriptionProvider::Payme);
    config(['billing.paywall.mode' => 'purchase', 'billing.providers.payme.enabled' => true, 'billing.providers.telegram_stars.enabled' => true]);
    $withOffer = Learner::factory()->create();
    $without = Learner::factory()->create();
    Offer::factory()->create(['learner_id' => $withOffer->id, 'discount_pct' => 30, 'period' => 'month']);
    $checkout = app(CheckoutService::class);

    $mine = $checkout->catalog($withOffer);
    $other = $checkout->catalog($without);

    expect($mine->offer['discount_pct'] ?? null)->toBe(30)
        ->and($other->offer)->toBeNull()
        ->and($mine->providers)->toBe($other->providers, 'providers and list prices are the shared, static half')
        ->and($mine->items[0]['list_prices'])->toBe($other->items[0]['list_prices'])
        ->and($mine->items[0]['prices'])->not->toBe($other->items[0]['prices'])
        ->and($other->items[0]['prices'])->toBe($other->items[0]['list_prices']);
});
