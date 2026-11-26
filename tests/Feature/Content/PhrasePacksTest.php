<?php

declare(strict_types=1);

use App\Domain\Content\Services\CoursePackImporter;
use App\Domain\Content\Services\CoursePublisher;
use App\Domain\Content\Support\LexemeKey;
use App\Domain\Content\Support\Phrase;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Lexeme;
use App\Models\Stage;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * The phrase pack `english-phrases` (database/data/courses/english-phrases, docs/35 §3.4, F7): 20 modules of 5 lessons of 10 phrases,
 * 1 000 phrases, the plan docs/content-authoring/PHRASE_PLAN.md. The pack is written by the content writers (F7); until its directory
 * exists, its rows are skipped. The same checks run on the good pack of the authors' checker (`check_phrases.py`, 2 lessons), so the
 * test itself is exercised and the checker's idea of a good pack is held to the real import. The importer itself is covered by
 * PhraseImportTest; here the files of the pack are checked, then an import / idempotence pass.
 */

/** @return array<string, array{0: string, 1: int, 2: int, 3: int}> name => [directory, modules, lessons a module, phrases a lesson] */
function phk_packs(): array
{
    return [
        'english-phrases' => ['database/data/courses/english-phrases', 20, 5, 10],
        'checker fixture' => ['tests/fixtures/phrases/check/good/english-phrases-good', 1, 2, 10],
    ];
}

dataset('phk_packs', phk_packs());

/** Why the row of a pack is skipped: the pack is not written yet (null - it is there). */
function phk_missing(string $dir): ?string
{
    return is_dir(base_path($dir)) ? null : "The phrase pack {$dir} is not written yet (F7 docs/35, plan docs/content-authoring/PHRASE_PLAN.md).";
}

/** @return list<string> */
function phk_lesson_files(string $dir): array
{
    $files = glob(base_path($dir).'/m*-l*.json') ?: [];
    natsort($files);

    return array_values($files);
}

/** @return array<string, mixed> */
function phk_read(string $file): array
{
    return json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
}

/** @return list<array<string, mixed>> every entry of the pack, with `_file` = the name of its lesson file */
function phk_entries(string $dir): array
{
    $entries = [];
    foreach (phk_lesson_files($dir) as $file) {
        foreach (phk_read($file)['words'] as $w) {
            $entries[] = $w + ['_file' => basename($file)];
        }
    }

    return $entries;
}

it('has the lessons of the plan: numbered without gaps, named after their numbers, of exactly 10 phrases', function (string $dir, int $modules, int $lessons, int $perLesson): void {
    if (($missing = phk_missing($dir)) !== null) {
        $this->markTestSkipped($missing);
    }
    $manifest = phk_read(base_path("{$dir}/course.json"));
    $files = phk_lesson_files($dir);
    expect($files)->toHaveCount($modules * $lessons);

    $seen = [];
    $stageTitles = [];
    foreach ($files as $file) {
        $data = phk_read($file);
        $n = $data['stage']['number'];
        expect(basename($file))->toBe(sprintf('m%02d-l%d.json', $n, $data['lesson']['number']))
            ->and($data['course'])->toBe($manifest['slug'], basename($file))
            ->and($data['words'])->toHaveCount($perLesson, basename($file))
            ->and(trim($data['stage']['title']))->not->toBe('', basename($file))
            ->and(trim($data['stage']['title_uz']))->not->toBe('', basename($file))
            ->and(trim($data['lesson']['title']))->not->toBe('', basename($file))
            ->and(trim($data['lesson']['title_uz']))->not->toBe('', basename($file));
        $seen[$n][] = $data['lesson']['number'];
        $stageTitles[$n][] = $data['stage']['title'].'|'.$data['stage']['title_uz'];
    }

    ksort($seen);
    expect(array_keys($seen))->toBe(range(1, $modules));
    foreach ($seen as $n => $numbers) {
        sort($numbers);
        expect($numbers)->toBe(range(1, $lessons), "module {$n}")
            ->and(array_unique($stageTitles[$n]))->toHaveCount(1, "module {$n} has differing stage titles");
    }
})->with('phk_packs');

it('is a course of phrases: exam_tag phrases, public, not featured, with its levels and both languages', function (string $dir): void {
    if (($missing = phk_missing($dir)) !== null) {
        $this->markTestSkipped($missing);
    }
    $manifest = phk_read(base_path("{$dir}/course.json"));

    expect($manifest['slug'])->toBe(basename($dir))
        ->and($manifest['slug'])->toStartWith('english-phrases')
        ->and(Phrase::isPhraseCourse($manifest['exam_tag']))->toBeTrue()
        ->and($manifest['visibility'])->toBe('public')
        ->and($manifest['is_featured'])->toBeFalse()
        ->and(trim($manifest['level_label']))->not->toBe('')
        ->and(trim($manifest['title']['ru']))->not->toBe('')
        ->and(trim($manifest['title']['uz']))->not->toBe('')
        ->and(trim($manifest['description']['ru']))->not->toBe('')
        ->and(trim($manifest['description']['uz']))->not->toBe('');
})->with('phk_packs');

it('gives every phrase the rules of the import and of the content: a valid block, a key word to mask, ru and uz, an example, no rude register', function (string $dir): void {
    if (($missing = phk_missing($dir)) !== null) {
        $this->markTestSkipped($missing);
    }
    foreach (phk_entries($dir) as $w) {
        $word = $w['word'];
        $where = $w['_file'].' / '.$word;
        $block = $w['phrase'];

        expect(Phrase::isPhrase($w['part_of_speech']))->toBeTrue($where)
            ->and(mb_strlen($word))->toBeLessThanOrEqual(50, $where)
            ->and($block['kind'])->toBeIn(Phrase::KINDS, $where)
            ->and($block['register'])->toBeIn(Phrase::REGISTERS, $where)
            ->and($block['register'])->not->toBe('rude', $where)
            ->and($block['variant'] ?? Phrase::DEFAULT_VARIANT)->toBeIn(Phrase::VARIANTS, $where)
            ->and($block['cefr'])->toBeIn(Phrase::CEFR, $where)
            ->and($block['frequency'])->toBeIn(Phrase::FREQUENCIES, $where)
            ->and(Phrase::isGapWord($block['gap']))->toBeTrue($where)
            ->and(Phrase::occurrences($word, $block['gap']))->toBe(1, $where)
            ->and(Phrase::isWholePhrase($word, $block['gap']))->toBeFalse($where)
            ->and(Phrase::mask($word, $block['gap'], '____'))->not->toBeNull($where)
            ->and(trim($w['translations']['ru']))->not->toBe('', $where)
            ->and(trim($w['translations']['uz']))->not->toBe('', $where)
            ->and(mb_strlen($w['translations']['ru']))->toBeLessThanOrEqual(60, $where)
            ->and(mb_strlen($w['translations']['uz']))->toBeLessThanOrEqual(60, $where)
            ->and(trim($w['example']))->not->toBe('', $where)
            ->and(trim($w['example_translations']['ru']))->not->toBe('', $where)
            ->and(trim($w['example_translations']['uz']))->not->toBe('', $where)
            ->and(trim($w['hooks']['ru']))->not->toBe('', $where)
            ->and(trim($w['hooks']['uz']))->not->toBe('', $where)
            ->and($w['example'])->not->toMatch('/(^|\s)[A-Z]:\s/', $where)
            // Uzbek is Latin script with the ASCII apostrophe: no typographic quotes, no Cyrillic.
            ->and($w['translations']['uz'].$w['example_translations']['uz'].$w['hooks']['uz'].($block['literal']['uz'] ?? ''))
            ->not->toMatch('/[’ʻʼ‘`]|\p{Cyrillic}/u', $where)
            ->and($w['translations']['ru'])->toMatch('/\p{Cyrillic}/u', $where);
    }
})->with('phk_packs');

it('repeats no phrase, not even with an article, a capital letter, final punctuation or another apostrophe', function (string $dir, int $modules, int $lessons, int $perLesson): void {
    if (($missing = phk_missing($dir)) !== null) {
        $this->markTestSkipped($missing);
    }
    $seen = [];
    $gaps = [];
    foreach (phk_entries($dir) as $w) {
        $key = LexemeKey::of($w['word']);
        expect($key)->not->toBeNull($w['word'])
            ->and($seen)->not->toHaveKey((string) $key, "{$w['_file']} / {$w['word']} repeats ".($seen[(string) $key] ?? ''));
        $seen[(string) $key] = "{$w['_file']} / {$w['word']}";

        $gap = mb_strtolower($w['phrase']['gap']);
        expect($gaps[$w['_file']] ?? [])->not->toContain($gap, "{$w['_file']}: the key word '{$gap}' twice in the lesson");
        $gaps[$w['_file']][] = $gap;
    }

    expect($seen)->toHaveCount($modules * $lessons * $perLesson);
})->with('phk_packs');

it('imports the whole pack as a draft, one lexeme a phrase, and a second run changes nothing', function (string $dir, int $modules, int $lessons, int $perLesson): void {
    if (($missing = phk_missing($dir)) !== null) {
        $this->markTestSkipped($missing);
    }
    $slug = basename($dir);
    $total = $modules * $lessons * $perLesson;

    $report = app(CoursePackImporter::class)->import(base_path($dir), create: true);
    expect($report->errors)->toBe([])
        ->and($report->ok())->toBeTrue(json_encode(array_map(fn ($r) => $r->errors, $report->files), JSON_UNESCAPED_UNICODE) ?: '')
        ->and($report->totals())->toBe(['added' => $total, 'updated' => 0, 'skipped' => 0]);

    $course = Course::query()->where('slug', $slug)->firstOrFail();
    $wordIds = Word::query()->whereHas('lesson.stage', fn ($q) => $q->where('course_id', $course->id))->pluck('id');
    expect($course->status)->toBe(CoursePublisher::DRAFT)
        ->and(Phrase::isPhraseCourse($course->exam_tag))->toBeTrue()
        ->and(Stage::query()->where('course_id', $course->id)->count())->toBe($modules)
        ->and(Lesson::query()->whereHas('stage', fn ($q) => $q->where('course_id', $course->id))->count())->toBe($modules * $lessons)
        ->and($wordIds)->toHaveCount($total)
        ->and($course->words_count)->toBe($total)
        ->and(Word::query()->whereIn('id', $wordIds)->where('part_of_speech', Phrase::PART_OF_SPEECH)->count())->toBe($total)
        ->and(Word::query()->whereIn('id', $wordIds)->whereNull('lexeme_id')->count())->toBe(0)
        // No two phrases share a lexeme: every phrase earns its own points (docs/32 §2.1).
        ->and(Lexeme::query()->count())->toBe($total);

    foreach (Word::query()->whereIn('id', $wordIds)->get() as $word) {
        expect(Phrase::block($word))->not->toBeNull($word->word)
            ->and(Phrase::gapOf($word->getAttribute('meta')))->not->toBeNull($word->word);
    }

    $second = app(CoursePackImporter::class)->import(base_path($dir), create: true);
    expect($second->ok())->toBeTrue()
        ->and($second->courseCreated)->toBeFalse()
        ->and($second->totals())->toBe(['added' => 0, 'updated' => 0, 'skipped' => $total]);
})->with('phk_packs');
