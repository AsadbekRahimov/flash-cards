<?php

declare(strict_types=1);

use App\Domain\Content\DTO\CoursePackReport;
use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Content\Services\CoursePackImporter;
use App\Domain\Content\Services\CoursePublisher;
use App\Domain\Content\Services\VocabularyImporter;
use App\Domain\Learning\Services\GapFillBuilder;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\User;
use App\Models\Word;
use App\Models\WordTranslation;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * The level packs `english-b1` and `english-b2` (database/data/courses/*): 20 modules x 5 lessons x 10 words each,
 * the same format as `english-a1-a2` (CoursePackImportTest). The importer itself is covered there; here the
 * files of each pack are checked, then an import / idempotence / publication pass per pack, and all three
 * packs together (3 000 different words in one database).
 */

const LCP_A1_A2 = 'english-a1-a2';

/** @return list<string> */
function lcp_lesson_files(string $slug): array
{
    $files = glob(base_path("database/data/courses/{$slug}").'/m*-l*.json') ?: [];
    natsort($files);

    return array_values($files);
}

/** @return array<string, mixed> */
function lcp_read(string $file): array
{
    return json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
}

/** @return array<string, mixed> */
function lcp_manifest(string $slug): array
{
    return lcp_read(base_path("database/data/courses/{$slug}/course.json"));
}

/** @return list<string> */
function lcp_words(string $slug): array
{
    $words = [];
    foreach (lcp_lesson_files($slug) as $file) {
        foreach (lcp_read($file)['words'] as $w) {
            $words[] = $w['word'];
        }
    }

    return $words;
}

function lcp_import(string $slug): CoursePackReport
{
    $report = app(CoursePackImporter::class)->import(base_path("database/data/courses/{$slug}"), create: true);
    expect($report->errors)->toBe([])->and($report->ok())->toBeTrue();

    return $report;
}

dataset('lcp_packs', [
    'b1' => ['english-b1'],
    'b2' => ['english-b2'],
]);

// slug => the manifest values the packs are meant to carry (level, position), the rest is shared.
dataset('lcp_manifests', [
    'b1' => ['english-b1', 'B1', 20],
    'b2' => ['english-b2', 'B2', 30],
]);

it('has five lessons of ten words in every one of 20 modules, numbered without gaps', function (string $slug): void {
    $files = lcp_lesson_files($slug);
    expect($files)->toHaveCount(100);

    $modules = [];
    $stageTitles = [];
    foreach ($files as $file) {
        $data = lcp_read($file);
        $n = $data['stage']['number'];
        expect($data['words'])->toHaveCount(10)
            ->and(basename($file))->toBe(sprintf('m%02d-l%d.json', $n, $data['lesson']['number']))
            ->and($data['course'])->toBe($slug)
            ->and(trim($data['stage']['title']))->not->toBe('', basename($file))
            ->and(trim($data['stage']['title_uz']))->not->toBe('', basename($file))
            ->and(trim($data['lesson']['title']))->not->toBe('', basename($file))
            ->and(trim($data['lesson']['title_uz']))->not->toBe('', basename($file));
        $modules[$n][] = $data['lesson']['number'];
        $stageTitles[$n][] = $data['stage']['title'].'|'.$data['stage']['title_uz'];
    }

    ksort($modules);
    expect(array_keys($modules))->toBe(range(1, 20));
    foreach ($modules as $n => $lessons) {
        sort($lessons);
        expect($lessons)->toBe([1, 2, 3, 4, 5])
            ->and(array_unique($stageTitles[$n]))->toHaveCount(1, "module {$n} has differing stage titles");
    }
})->with('lcp_packs');

it('keeps USED_WORDS.txt in step with the lesson files: sorted, 1000 lines, exactly the words of the course', function (string $slug): void {
    $words = lcp_words($slug);
    $used = array_values(array_filter(array_map('trim', file(base_path("database/data/courses/{$slug}/USED_WORDS.txt")) ?: [])));
    sort($words, SORT_STRING);

    expect($used)->toHaveCount(1000)
        ->and($used)->toBe($words)
        ->and($used)->toBe(array_values(array_unique($used)));
})->with('lcp_packs');

it('gives every word a ru and uz translation, an example usable for gap-fill, and a known part of speech; no word repeats', function (string $slug): void {
    $gap = new GapFillBuilder;
    $seen = [];
    foreach (lcp_lesson_files($slug) as $file) {
        foreach (lcp_read($file)['words'] as $w) {
            $word = $w['word'];
            $where = basename($file).' / '.$word;

            expect($word)->toBe(mb_strtolower(trim($word)), $where)
                ->and($w['part_of_speech'])->toBeIn(VocabularyImporter::ALLOWED_POS, $where)
                ->and($w['translation'])->toBe($w['translations']['ru'], $where)
                ->and(trim($w['translations']['ru']))->not->toBe('', $where)
                ->and(trim($w['translations']['uz']))->not->toBe('', $where)
                ->and(trim($w['example']))->not->toBe('', $where)
                ->and(trim($w['example_translations']['ru']))->not->toBe('', $where)
                ->and(trim($w['example_translations']['uz']))->not->toBe('', $where)
                ->and(str_word_count($w['example']))->toBeBetween(5, 10, $where)
                // Uzbek is Latin script with the ASCII apostrophe: no typographic quotes, no Cyrillic.
                ->and($w['translations']['uz'].$w['example_translations']['uz'])->not->toMatch('/[’ʻʼ‘`]|\p{Cyrillic}/u', $where)
                ->and($w['translations']['ru'])->toMatch('/\p{Cyrillic}/u', $where)
                ->and($w['example_translations']['ru'])->toMatch('/\p{Cyrillic}/u', $where)
                // The example holds the word or a regular form of it, so the gap-fill exercise can be built.
                ->and($gap->fromText($word, $w['example']))->not->toBeNull("{$where}: example has no form of the word");

            expect($seen)->not->toHaveKey($word, "{$where} repeats {$word} of ".($seen[$word] ?? ''));
            $seen[$word] = basename($file);
        }
    }
})->with('lcp_packs');

it('shares no word between english-a1-a2, english-b1 and english-b2: 3 000 different words', function (): void {
    $all = [];
    foreach ([LCP_A1_A2, 'english-b1', 'english-b2'] as $slug) {
        foreach (lcp_words($slug) as $word) {
            expect($all)->not->toHaveKey($word, "{$slug} repeats {$word} of ".($all[$word] ?? ''));
            $all[$word] = $slug;
        }
    }

    expect($all)->toHaveCount(3000);
});

it('imports the whole pack as a draft, and a second run adds nothing', function (string $slug, string $level, int $position): void {
    $manifest = lcp_manifest($slug);
    expect($manifest['level'])->toBe($level)
        ->and($manifest['position'])->toBe($position)
        ->and($manifest['exam_tag'])->toBe('general')
        ->and($manifest['visibility'])->toBe('public')
        ->and($manifest['is_featured'])->toBeFalse();
    lcp_import($slug);

    $course = Course::query()->where('slug', $slug)->firstOrFail();
    expect($course->status)->toBe(CoursePublisher::DRAFT)
        ->and($course->published_at)->toBeNull()
        ->and($course->level)->toBe($manifest['level'])
        ->and($course->exam_tag)->toBe($manifest['exam_tag'])
        ->and($course->title)->toBe($manifest['title'])
        ->and($course->visibility)->toBe($manifest['visibility'])
        ->and($course->position)->toBe($manifest['position'])
        ->and($course->is_featured)->toBe($manifest['is_featured'])
        ->and(Stage::query()->where('course_id', $course->id)->count())->toBe(20)
        ->and(Lesson::query()->whereHas('stage', fn ($q) => $q->where('course_id', $course->id))->count())->toBe(100)
        ->and(Word::query()->whereHas('lesson.stage', fn ($q) => $q->where('course_id', $course->id))->count())->toBe(1000)
        ->and($course->words_count)->toBe(1000);

    $wordIds = Word::query()->whereHas('lesson.stage', fn ($q) => $q->where('course_id', $course->id))->pluck('id');
    expect(WordTranslation::query()->whereIn('word_id', $wordIds)->where('locale', 'ru')->count())->toBe(1000)
        ->and(WordTranslation::query()->whereIn('word_id', $wordIds)->where('locale', 'uz')->count())->toBe(1000)
        ->and(WordTranslation::query()->whereIn('word_id', $wordIds)->whereNull('example_translation')->count())->toBe(0)
        ->and(Word::query()->whereIn('id', $wordIds)->whereNull('example')->count())->toBe(0)
        ->and(Word::query()->whereIn('id', $wordIds)->whereNull('part_of_speech')->count())->toBe(0);

    $second = app(CoursePackImporter::class)->import(base_path("database/data/courses/{$slug}"), create: true);
    expect($second->ok())->toBeTrue()
        ->and($second->courseCreated)->toBeFalse()
        ->and($second->totals())->toBe(['added' => 0, 'updated' => 0, 'skipped' => 1000])
        ->and(Course::query()->where('slug', $slug)->count())->toBe(1);
})->with('lcp_manifests');

it('passes the publication check: full translation coverage, and the course can be published by an admin', function (string $slug): void {
    lcp_import($slug);
    $this->seed(RolePermissionSeeder::class);
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');
    $course = Course::query()->where('slug', $slug)->firstOrFail();

    $publisher = app(CoursePublisher::class);
    $coverage = $publisher->translationCoverage($course);
    expect($coverage['total'])->toBe($course->words_count)
        ->and($coverage['ratio'])->toBeGreaterThanOrEqual((float) config('content.publish_translation_threshold'));

    $publisher->transition($course, CoursePublisher::REVIEW, $admin);
    $publisher->transition($course, CoursePublisher::PUBLISHED, $admin);

    expect($course->refresh()->status)->toBe(CoursePublisher::PUBLISHED);
})->with('lcp_packs');

it('imports all three level packs into one database: 3 courses, 1 000 words each, 3 000 in total', function (): void {
    $slugs = [LCP_A1_A2, 'english-b1', 'english-b2'];
    foreach ($slugs as $slug) {
        lcp_import($slug);
    }

    expect(Course::query()->count())->toBe(3)
        ->and(Word::query()->count())->toBe(3000);

    foreach ($slugs as $slug) {
        $course = Course::query()->where('slug', $slug)->firstOrFail();
        expect($course->words_count)->toBe(1000, $slug)
            ->and(Word::query()->whereHas('lesson.stage', fn ($q) => $q->where('course_id', $course->id))->count())->toBe(1000, $slug);
    }
});

it('recommends the level course for a general goal from the real manifests of the three packs', function (): void {
    foreach ([LCP_A1_A2, 'english-b1', 'english-b2'] as $slug) {
        $m = lcp_manifest($slug);
        Course::factory()->published()->create([
            'slug' => $m['slug'],
            'title' => $m['title'],
            'level' => $m['level'],
            'exam_tag' => $m['exam_tag'],
            'visibility' => $m['visibility'],
            'position' => $m['position'],
            'is_featured' => $m['is_featured'],
        ]);
    }

    $catalog = app(CourseCatalogService::class);

    expect($catalog->recommend('general', 'A1')?->slug)->toBe(LCP_A1_A2)
        ->and($catalog->recommend('general', 'A2')?->slug)->toBe(LCP_A1_A2)
        ->and($catalog->recommend('general', null)?->slug)->toBe(LCP_A1_A2)
        ->and($catalog->recommend('general', 'B1')?->slug)->toBe('english-b1')
        ->and($catalog->recommend('general', 'B2')?->slug)->toBe('english-b2');
});
