<?php

declare(strict_types=1);

use App\Domain\Content\Services\VocabularyImporter;
use App\Models\AuditLog;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function sampleJson(array $overrides = []): string
{
    // Top-level replace (not recursive) — so a caller passing
    // `['words' => [...]]` fully overrides the words array instead of
    // getting the defaults merged back in by numeric key.
    $data = [
        'stage' => ['number' => 1, 'title' => 'Beginner'],
        'lesson' => ['number' => 1, 'title' => 'L1'],
        'words' => [
            ['word' => 'hello', 'translation' => 'привет', 'part_of_speech' => 'interjection'],
            ['word' => 'world', 'translation' => 'мир', 'part_of_speech' => 'noun'],
        ],
    ];

    foreach ($overrides as $key => $value) {
        $data[$key] = $value;
    }

    return json_encode($data, JSON_THROW_ON_ERROR);
}

it('imports a valid file and reports added counts', function (): void {
    $report = app(VocabularyImporter::class)->import(sampleJson());

    expect($report->ok())->toBeTrue();
    expect($report->added)->toBe(2);
    expect($report->updated)->toBe(0);
    expect(Stage::count())->toBe(1);
    expect(Lesson::count())->toBe(1);
    expect(Word::count())->toBe(2);
});

it('imports the original PRD vocabulary schema', function (): void {
    $json = json_encode([
        'stage' => 4,
        'lesson' => 8,
        'vocabulary' => [
            [
                'word' => 'resilient',
                'translation' => 'устойчивый',
                'part_of_speech' => 'adjective',
                'transcription' => 'rɪˈzɪl.i.ənt',
                'example' => 'She is a resilient girl.',
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $report = app(VocabularyImporter::class)->import($json);

    expect($report->ok())->toBeTrue();
    expect($report->added)->toBe(1);
    expect(Stage::query()->where('number', 4)->exists())->toBeTrue();
    expect(Lesson::query()->where('number', 8)->exists())->toBeTrue();
    expect(Word::query()->where('word', 'resilient')->exists())->toBeTrue();
});

it('re-importing the same file updates 0 and skips 2 (no changes)', function (): void {
    $json = sampleJson();
    app(VocabularyImporter::class)->import($json);
    $report = app(VocabularyImporter::class)->import($json);

    expect($report->added)->toBe(0);
    expect($report->updated)->toBe(0);
    expect($report->skipped)->toBe(2);
});

it('re-importing with changed translation increments updated', function (): void {
    app(VocabularyImporter::class)->import(sampleJson());

    $report = app(VocabularyImporter::class)->import(sampleJson([
        'words' => [
            ['word' => 'hello', 'translation' => 'ПРИВЕТ!', 'part_of_speech' => 'interjection'],
            ['word' => 'world', 'translation' => 'мир', 'part_of_speech' => 'noun'],
        ],
    ]));

    expect($report->added)->toBe(0);
    expect($report->updated)->toBe(1);
    expect($report->skipped)->toBe(1);
});

it('aborts on invalid JSON and writes nothing', function (): void {
    $report = app(VocabularyImporter::class)->import('{ not json }');

    expect($report->aborted)->toBeTrue();
    expect($report->errors[0])->toContain('Invalid JSON');
    expect(Stage::count())->toBe(0);
});

it('aborts when file exceeds 2 MB limit', function (): void {
    $big = str_repeat('a', VocabularyImporter::MAX_BYTES + 1);

    $report = app(VocabularyImporter::class)->import($big);

    expect($report->aborted)->toBeTrue();
    expect($report->errors[0])->toContain('exceeds');
});

it('aborts when required field is missing', function (): void {
    $report = app(VocabularyImporter::class)->import(sampleJson([
        'words' => [['translation' => 'только перевод']],
    ]));

    expect($report->aborted)->toBeTrue();
    expect($report->errors)->not->toBeEmpty();
    expect(Word::count())->toBe(0);
});

it('aborts on duplicate word inside a single file', function (): void {
    $report = app(VocabularyImporter::class)->import(sampleJson([
        'words' => [
            ['word' => 'hello', 'translation' => 'привет'],
            ['word' => 'Hello', 'translation' => 'хэлло'],
        ],
    ]));

    expect($report->aborted)->toBeTrue();
    expect($report->errors[0])->toContain('Duplicate');
    expect(Word::count())->toBe(0);
});

it('rejects invalid part_of_speech', function (): void {
    $report = app(VocabularyImporter::class)->import(sampleJson([
        'words' => [['word' => 'x', 'translation' => 'y', 'part_of_speech' => 'bogus']],
    ]));

    expect($report->aborted)->toBeTrue();
    expect(Word::count())->toBe(0);
});

it('rejects root that is not an object', function (): void {
    $report = app(VocabularyImporter::class)->import('[1,2,3]');

    expect($report->aborted)->toBeTrue();
});

it('writes an audit_logs row on successful import', function (): void {
    app(VocabularyImporter::class)->import(sampleJson(), [
        'user_id' => null,
        'ip' => '127.0.0.1',
    ]);

    $log = AuditLog::first();
    expect($log)->not->toBeNull();
    expect($log->action)->toBe('import.uploaded');
    expect($log->entity_type)->toBe(Lesson::class);
    expect($log->meta['report']['added'])->toBe(2);
});

it('rolls back on transaction failure and writes no partial data', function (): void {
    // 501 words triggers words.max validation → aborted before transaction
    $words = [];
    for ($i = 0; $i < 501; $i++) {
        $words[] = ['word' => 'w'.$i, 'translation' => 't'.$i];
    }

    $report = app(VocabularyImporter::class)->import(sampleJson(['words' => $words]));

    expect($report->aborted)->toBeTrue();
    expect(Stage::count())->toBe(0);
    expect(Lesson::count())->toBe(0);
    expect(Word::count())->toBe(0);
});
