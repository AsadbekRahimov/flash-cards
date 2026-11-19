<?php

declare(strict_types=1);

use App\Domain\Content\Services\WordAudioCoverageService;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Config::set('twa.tts.voice', 'en-US-AriaNeural');
    Config::set('twa.tts.audio_version', 1);
    Storage::fake('public');
});

function coveragePath(string $text): string
{
    return 'audio/'.sha1(mb_strtolower($text).'|en-US-AriaNeural|1').'.mp3';
}

/** A word in module $number of the course; `voiced` records the current path and writes the file. */
function coverageWord(Course $course, int $number, string $text, bool $voiced = false, array $attrs = []): Word
{
    $stage = Stage::query()->firstOrCreate(['course_id' => $course->id, 'number' => $number], ['title' => "Module {$number}"]);
    $lesson = Lesson::query()->firstOrCreate(['stage_id' => $stage->id, 'number' => 1]);
    if ($voiced) {
        $attrs['audio_path'] = coveragePath($text);
        Storage::disk('public')->put($attrs['audio_path'], 'mp3-bytes');
    }

    return Word::factory()->for($lesson)->create(['word' => $text] + $attrs);
}

it('reports full coverage by module and in total', function (): void {
    $course = Course::factory()->create(['slug' => 'english-c1']);
    coverageWord($course, 1, 'apple', voiced: true);
    coverageWord($course, 1, 'pear', voiced: true);
    coverageWord($course, 2, 'plum', voiced: true);

    $report = app(WordAudioCoverageService::class)->report($course);

    expect($report->words)->toBe(3)
        ->and($report->withAudio)->toBe(3)
        ->and($report->verified)->toBeNull()
        ->and($report->percent())->toBe(100.0)
        ->and($report->stages)->toBe([
            ['number' => 1, 'title' => 'Module 1', 'words' => 2, 'with_audio' => 2, 'verified' => null],
            ['number' => 2, 'title' => 'Module 2', 'words' => 1, 'with_audio' => 1, 'verified' => null],
        ]);

    $this->artisan('audio:coverage', ['course' => 'english-c1', '--min' => 100])
        ->expectsOutputToContain('english-c1: 3 of 3 active words voiced (100.0%)')
        ->assertExitCode(0);
});

it('counts the module rows in one grouped statement', function (): void {
    $course = Course::factory()->create();
    coverageWord($course, 1, 'apple', voiced: true);
    coverageWord($course, 2, 'pear');
    coverageWord($course, 3, 'plum');

    DB::enableQueryLog();
    app(WordAudioCoverageService::class)->report($course);

    expect(DB::getQueryLog())->toHaveCount(1);
});

it('reports partial coverage, ignores inactive words and other courses', function (): void {
    $course = Course::factory()->create(['slug' => 'english-b1']);
    coverageWord($course, 1, 'apple', voiced: true);
    coverageWord($course, 1, 'pear');
    coverageWord($course, 2, 'plum');
    coverageWord($course, 2, 'fig', attrs: ['audio_path' => '']);
    coverageWord($course, 2, 'kiwi', attrs: ['is_active' => false]);
    coverageWord(Course::factory()->create(), 1, 'lime', voiced: true);

    $report = app(WordAudioCoverageService::class)->report($course);

    expect($report->words)->toBe(4)
        ->and($report->withAudio)->toBe(1)
        ->and($report->percent())->toBe(25.0)
        ->and(array_column($report->stages, 'with_audio'))->toBe([1, 0])
        ->and(array_column($report->stages, 'words'))->toBe([2, 2]);

    $this->artisan('audio:coverage', ['course' => 'english-b1'])
        ->expectsOutputToContain('english-b1: 1 of 4 active words voiced (25.0%), by the DB')
        ->assertExitCode(0);
});

it('with --verify lists the words whose file is missing, empty or of an old text', function (): void {
    $course = Course::factory()->create(['slug' => 'english-c2']);
    coverageWord($course, 1, 'apple', voiced: true);
    $missing = coverageWord($course, 1, 'pear', attrs: ['audio_path' => coveragePath('pear')]);
    $empty = coverageWord($course, 2, 'plum', attrs: ['audio_path' => coveragePath('plum')]);
    Storage::disk('public')->put(coveragePath('plum'), '');
    Storage::disk('public')->put(coveragePath('fig'), 'mp3-bytes');
    $stale = coverageWord($course, 2, 'figs', attrs: ['audio_path' => coveragePath('fig')]);
    coverageWord($course, 2, 'kiwi');

    $report = app(WordAudioCoverageService::class)->report($course, verify: true);

    expect($report->words)->toBe(5)
        ->and($report->withAudio)->toBe(4)
        ->and($report->verified)->toBe(1)
        ->and($report->percent())->toBe(20.0)
        ->and(array_column($report->stages, 'verified'))->toBe([1, 0])
        ->and($report->problemCount)->toBe(3)
        ->and($report->problems)->toBe([
            ['id' => $missing->id, 'word' => 'pear', 'reason' => 'missing', 'path' => coveragePath('pear')],
            ['id' => $empty->id, 'word' => 'plum', 'reason' => 'empty', 'path' => coveragePath('plum')],
            ['id' => $stale->id, 'word' => 'figs', 'reason' => 'stale', 'path' => coveragePath('fig')],
        ]);

    $this->artisan('audio:coverage', ['course' => 'english-c2', '--verify' => true, '--limit' => 2])
        ->expectsOutputToContain('Words with audio recorded but no usable file: 3')
        ->expectsOutputToContain('pear')
        ->expectsOutputToContain('... and 1 more')
        ->expectsOutputToContain('english-c2: 1 of 5 active words voiced (20.0%), files checked')
        ->assertExitCode(0);
});

it('counts every word that shares one content-addressed file', function (): void {
    $course = Course::factory()->create();
    coverageWord($course, 1, 'apple', voiced: true);
    coverageWord($course, 2, 'Apple', attrs: ['audio_path' => coveragePath('apple')]);

    $report = app(WordAudioCoverageService::class)->report($course, verify: true);

    expect($report->verified)->toBe(2)
        ->and($report->problemCount)->toBe(0);
});

it('fails the --min gate below the threshold, checked on the files with --verify', function (): void {
    $course = Course::factory()->create(['slug' => 'english-a1']);
    coverageWord($course, 1, 'apple', voiced: true);
    coverageWord($course, 1, 'pear', attrs: ['audio_path' => coveragePath('pear')]);

    $this->artisan('audio:coverage', ['course' => 'english-a1', '--min' => 100])->assertExitCode(0);
    $this->artisan('audio:coverage', ['course' => 'english-a1', '--min' => 100, '--verify' => true])
        ->expectsOutputToContain('Coverage 50.0% is below --min=100%')
        ->assertExitCode(1);
    $this->artisan('audio:coverage', ['course' => 'english-a1', '--min' => 50, '--verify' => true])->assertExitCode(0);
});

it('does not round a coverage just under the threshold up to it', function (): void {
    $course = Course::factory()->create(['slug' => 'english-a2']);
    foreach (range(1, 3) as $i) {
        coverageWord($course, 1, "word{$i}", voiced: $i <= 2);
    }

    $this->artisan('audio:coverage', ['course' => 'english-a2', '--min' => 66.67])->assertExitCode(1);
    $this->artisan('audio:coverage', ['course' => 'english-a2', '--min' => 66.6])
        ->expectsOutputToContain('(66.6%)')
        ->assertExitCode(0);
});

it('fails the gate for a course without active words', function (): void {
    Course::factory()->create(['slug' => 'empty-course']);

    $this->artisan('audio:coverage', ['course' => 'empty-course', '--min' => 95])->assertExitCode(1);
    $this->artisan('audio:coverage', ['course' => 'empty-course'])->assertExitCode(0);
});

it('fails for an unknown course', function (): void {
    $this->artisan('audio:coverage', ['course' => 'nope'])
        ->expectsOutputToContain('Course not found: nope')
        ->assertExitCode(1);
});

it('rejects a bad --min or --limit', function (string $option, string $value): void {
    Course::factory()->create(['slug' => 'english-b2']);

    $this->artisan('audio:coverage', ['course' => 'english-b2', $option => $value])->assertExitCode(2);
})->with([
    ['--min', 'abc'],
    ['--min', '101'],
    ['--min', '-1'],
    ['--limit', '-5'],
]);
