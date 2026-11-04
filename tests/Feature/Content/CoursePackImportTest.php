<?php

declare(strict_types=1);

use App\Domain\Content\Services\CoursePackImporter;
use App\Domain\Content\Services\CoursePublisher;
use App\Domain\Content\Services\VocabularyImporter;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\User;
use App\Models\Word;
use App\Models\WordTranslation;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

/*
 * The course pack `english-a1-a2` (database/data/courses/english-a1-a2, docs/CONTENT_A1_A2.md) and its importer
 * `content:import-course`. The invariants are checked on the files that are actually there; the full course is
 * pinned at 20 modules / 100 lessons / 1 000 words, each half (A1: modules 1-10, A2: modules 11-20) at exactly 500.
 * Its practice packs (database/data/practice/english-a1-a2*.json) are PracticePackTest.
 */

const CPI_DIR = 'database/data/courses/english-a1-a2';

/** @return list<string> */
function cpi_lesson_files(): array
{
    $files = glob(base_path(CPI_DIR).'/m*-l*.json') ?: [];
    natsort($files);

    return array_values($files);
}

/** @return array<string, mixed> */
function cpi_read(string $file): array
{
    return json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
}

function cpi_import(): void
{
    $report = app(CoursePackImporter::class)->import(base_path(CPI_DIR), create: true);
    expect($report->errors)->toBe([])->and($report->ok())->toBeTrue();
}

it('has five lessons of ten words in every module, numbered without gaps', function (): void {
    $modules = [];
    foreach (cpi_lesson_files() as $file) {
        $data = cpi_read($file);
        expect($data['words'])->toHaveCount(10)
            ->and(basename($file))->toBe(sprintf('m%02d-l%d.json', $data['stage']['number'], $data['lesson']['number']))
            ->and($data['course'])->toBe('english-a1-a2')
            ->and(trim($data['stage']['title']))->not->toBe('')
            ->and(trim($data['lesson']['title']))->not->toBe('');
        $modules[$data['stage']['number']][] = $data['lesson']['number'];
    }

    ksort($modules);
    expect(array_keys($modules))->toBe(range(1, count($modules)));
    foreach ($modules as $lessons) {
        sort($lessons);
        expect($lessons)->toBe([1, 2, 3, 4, 5]);
    }
});

it('has exactly 500 words in the A1 part (modules 1-10) and 500 in the A2 part (modules 11-20)', function (): void {
    $a1 = 0;
    $a2 = 0;
    foreach (cpi_lesson_files() as $file) {
        $data = cpi_read($file);
        if ($data['stage']['number'] <= 10) {
            $a1 += count($data['words']);
        } else {
            $a2 += count($data['words']);
        }
    }

    expect($a1)->toBe(500)->and($a2)->toBe(500);
});

it('keeps USED_WORDS.txt in step with the lesson files: sorted, no repeats, exactly the words of the course', function (): void {
    $words = [];
    foreach (cpi_lesson_files() as $file) {
        foreach (cpi_read($file)['words'] as $w) {
            $words[] = $w['word'];
        }
    }
    $used = array_values(array_filter(array_map('trim', file(base_path(CPI_DIR).'/USED_WORDS.txt') ?: [])));
    sort($words, SORT_STRING);

    expect($used)->toHaveCount(1000)->and($used)->toBe($words);
});

it('gives every word a ru and uz translation, an example with both translations and a known part of speech; no word repeats in the course', function (): void {
    $seen = [];
    foreach (cpi_lesson_files() as $file) {
        foreach (cpi_read($file)['words'] as $w) {
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
                ->and(str_word_count($w['example']))->toBeBetween(3, 10, $where)
                // Uzbek is Latin script with the ASCII apostrophe: no typographic quotes, no Cyrillic.
                ->and($w['translations']['uz'].$w['example_translations']['uz'])->not->toMatch('/[’ʻʼ‘`]|\p{Cyrillic}/u', $where);

            expect($seen)->not->toHaveKey($word, "{$where} repeats {$word} of ".($seen[$word] ?? ''));
            $seen[$word] = basename($file);
        }
    }
});

it('imports the whole pack: modules, lessons, words and both translations of every word', function (): void {
    cpi_import();

    $files = cpi_lesson_files();
    $course = Course::query()->where('slug', 'english-a1-a2')->firstOrFail();
    $expectedWords = array_sum(array_map(fn (string $f): int => count(cpi_read($f)['words']), $files));

    expect($files)->toHaveCount(100)->and($expectedWords)->toBe(1000);

    expect($course->status)->toBe(CoursePublisher::DRAFT)
        ->and($course->published_at)->toBeNull()
        ->and($course->level)->toBe('A1')
        ->and($course->exam_tag)->toBe('general')
        ->and($course->title)->toBe(['ru' => 'Английский A1–A2: базовые слова', 'uz' => "Ingliz tili A1–A2: asosiy so'zlar"])
        ->and(Stage::query()->where('course_id', $course->id)->count())->toBe((int) ($expectedWords / 50))
        ->and(Lesson::query()->whereHas('stage', fn ($q) => $q->where('course_id', $course->id))->count())->toBe(count($files))
        ->and(Word::query()->whereHas('lesson.stage', fn ($q) => $q->where('course_id', $course->id))->count())->toBe($expectedWords)
        ->and($course->words_count)->toBe($expectedWords);

    $wordIds = Word::query()->whereHas('lesson.stage', fn ($q) => $q->where('course_id', $course->id))->pluck('id');
    expect(WordTranslation::query()->whereIn('word_id', $wordIds)->where('locale', 'ru')->count())->toBe($expectedWords)
        ->and(WordTranslation::query()->whereIn('word_id', $wordIds)->where('locale', 'uz')->count())->toBe($expectedWords)
        ->and(WordTranslation::query()->whereIn('word_id', $wordIds)->whereNull('example_translation')->count())->toBe(0)
        ->and(Word::query()->whereIn('id', $wordIds)->whereNull('example')->count())->toBe(0)
        ->and(Word::query()->whereIn('id', $wordIds)->whereNull('part_of_speech')->count())->toBe(0);
});

it('passes the publication check: full translation coverage, and the course can be published by an admin', function (): void {
    cpi_import();
    $this->seed(RolePermissionSeeder::class);
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');
    $course = Course::query()->where('slug', 'english-a1-a2')->firstOrFail();

    $coverage = app(CoursePublisher::class)->translationCoverage($course);
    expect($coverage['total'])->toBe($course->words_count)
        ->and($coverage['ratio'])->toBeGreaterThanOrEqual((float) config('content.publish_translation_threshold'));

    $publisher = app(CoursePublisher::class);
    $publisher->transition($course, CoursePublisher::REVIEW, $admin);
    $publisher->transition($course, CoursePublisher::PUBLISHED, $admin);

    expect($course->refresh()->status)->toBe(CoursePublisher::PUBLISHED);
});

it('is idempotent: a second run adds nothing and skips every word', function (): void {
    cpi_import();
    $second = app(CoursePackImporter::class)->import(base_path(CPI_DIR), create: true);

    $words = Word::query()->count();
    expect($second->ok())->toBeTrue()
        ->and($second->courseCreated)->toBeFalse()
        ->and($second->totals())->toBe(['added' => 0, 'updated' => 0, 'skipped' => $words])
        ->and(Course::query()->where('slug', 'english-a1-a2')->count())->toBe(1);
});

it('runs content:import-course: refuses without --create, creates a draft with it, and succeeds again', function (): void {
    expect(Artisan::call('content:import-course', ['dir' => CPI_DIR]))->toBe(1)
        ->and(Course::query()->count())->toBe(0);

    expect(Artisan::call('content:import-course', ['dir' => CPI_DIR, '--create' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('Course created as a draft')
        ->and(Course::query()->where('slug', 'english-a1-a2')->value('status'))->toBe('draft');

    expect(Artisan::call('content:import-course', ['dir' => CPI_DIR]))->toBe(0)
        ->and(Artisan::output())->toContain('words added: 0');
});

it('fails on a missing directory, and on a pack with a broken or foreign file while the good files still land', function (): void {
    expect(Artisan::call('content:import-course', ['dir' => 'database/data/courses/nope']))->toBe(1);

    $dir = sys_get_temp_dir().'/course-pack-'.bin2hex(random_bytes(6));
    File::makeDirectory($dir);
    try {
        File::put($dir.'/course.json', (string) json_encode(['slug' => 'tiny', 'title' => ['ru' => 'Малый', 'uz' => 'Kichik']]));
        $word = ['word' => 'cat', 'translation' => 'кошка', 'translations' => ['ru' => 'кошка', 'uz' => 'mushuk']];
        File::put($dir.'/m01-l1.json', (string) json_encode(['course' => 'tiny', 'stage' => ['number' => 1], 'lesson' => ['number' => 1], 'words' => [$word]]));
        File::put($dir.'/m01-l2.json', (string) json_encode(['course' => 'tiny', 'stage' => ['number' => 1], 'lesson' => ['number' => 2], 'words' => [['word' => 'dog']]]));
        File::put($dir.'/m01-l3.json', (string) json_encode(['course' => 'other', 'stage' => ['number' => 1], 'lesson' => ['number' => 3], 'words' => [$word]]));

        $report = app(CoursePackImporter::class)->import($dir, create: true);

        expect($report->ok())->toBeFalse()
            ->and($report->files['m01-l1.json']->ok())->toBeTrue()
            ->and($report->files['m01-l2.json']->ok())->toBeFalse()
            ->and($report->errors)->toHaveCount(1)
            ->and(Word::query()->pluck('word')->all())->toBe(['cat']);
    } finally {
        File::deleteDirectory($dir);
    }
});

it('refuses a pack without a usable manifest', function (): void {
    $dir = sys_get_temp_dir().'/course-pack-'.bin2hex(random_bytes(6));
    File::makeDirectory($dir);
    try {
        expect(app(CoursePackImporter::class)->import($dir, create: true)->errors)->toHaveCount(1);

        File::put($dir.'/course.json', (string) json_encode(['slug' => 'Bad Slug', 'title' => ['ru' => 'x', 'uz' => 'y']]));
        expect(app(CoursePackImporter::class)->import($dir, create: true)->errors)->toHaveCount(1);

        File::put($dir.'/course.json', (string) json_encode(['slug' => 'ok-slug', 'title' => ['ru' => 'x']]));
        expect(app(CoursePackImporter::class)->import($dir, create: true)->errors)->toBe(["course.json: 'title.uz' is required."])
            ->and(Course::query()->count())->toBe(0);
    } finally {
        File::deleteDirectory($dir);
    }
});
