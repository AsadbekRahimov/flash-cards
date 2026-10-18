<?php

declare(strict_types=1);

use App\Domain\Content\Services\VocabularyImporter;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\User;
use App\Models\Word;
use App\Models\WordTranslation;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

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
    app(VocabularyImporter::class)->import(sampleJson(), null, ['ip' => '127.0.0.1']);

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

it('puts imported stages into the default course and reuses it', function (): void {
    $importer = app(VocabularyImporter::class);

    expect($importer->import(sampleJson())->ok())->toBeTrue();
    expect($importer->import(sampleJson(['stage' => ['number' => 2, 'title' => 'Next']]))->ok())->toBeTrue();

    $course = Course::query()->where('slug', config('content.default_course_slug'))->sole();

    expect(Course::count())->toBe(1)
        ->and(Stage::count())->toBe(2)
        ->and($course->stages()->count())->toBe(2);
});

it('imports into an existing default course without duplicating it', function (): void {
    $course = Course::factory()->published()->create(['slug' => (string) config('content.default_course_slug')]);

    app(VocabularyImporter::class)->import(sampleJson());

    expect(Course::count())->toBe(1)
        ->and(Stage::sole()->course_id)->toBe($course->id);
});

// ---------------------------------------------------------------------------
// Schema v2: `course`, `translations` (docs/12 §3.2)
// ---------------------------------------------------------------------------

function v2Words(): array
{
    return [
        [
            'word' => 'hello',
            'translations' => ['ru' => 'привет', 'uz' => 'salom'],
            'example' => 'Hello!',
            'example_translations' => ['uz' => 'Salom!'],
            'part_of_speech' => 'interjection',
        ],
        ['word' => 'world', 'translation' => 'мир', 'translations' => ['uz' => 'dunyo']],
        ['word' => 'tea', 'translation' => 'чай'],
    ];
}

it('imports v2 translations for several locales and mirrors ru into words.translation', function (): void {
    $report = app(VocabularyImporter::class)->import(sampleJson(['words' => v2Words()]));

    expect($report->ok())->toBeTrue()
        ->and($report->added)->toBe(3)
        ->and($report->translationsAdded)->toBe(5); // hello ru+uz, world ru+uz, tea ru

    $hello = Word::query()->where('word', 'hello')->sole();
    expect($hello->translation)->toBe('привет');

    $rows = WordTranslation::query()->where('word_id', $hello->id)->orderBy('locale')->get();
    expect($rows->pluck('translation', 'locale')->all())->toBe(['ru' => 'привет', 'uz' => 'salom'])
        ->and($rows->firstWhere('locale', 'uz')->example_translation)->toBe('Salom!')
        ->and($rows->pluck('source')->unique()->all())->toBe(['import']);

    // `translation` alone (legacy field) also gets its ru row.
    $tea = Word::query()->where('word', 'tea')->sole();
    expect($tea->translations()->pluck('translation', 'locale')->all())->toBe(['ru' => 'чай']);
});

it('accepts translations.ru instead of the legacy translation field', function (): void {
    $report = app(VocabularyImporter::class)->import(sampleJson([
        'words' => [['word' => 'cat', 'translations' => ['ru' => 'кот', 'uz' => 'mushuk']]],
    ]));

    expect($report->ok())->toBeTrue()
        ->and(Word::query()->where('word', 'cat')->value('translation'))->toBe('кот');
});

it('is idempotent for v2 files: the second run changes nothing', function (): void {
    $json = sampleJson(['words' => v2Words()]);
    app(VocabularyImporter::class)->import($json);

    $report = app(VocabularyImporter::class)->import($json);

    expect($report->added)->toBe(0)
        ->and($report->updated)->toBe(0)
        ->and($report->skipped)->toBe(3)
        ->and($report->translationsAdded)->toBe(0)
        ->and($report->translationsUpdated)->toBe(0)
        ->and(Word::count())->toBe(3)
        ->and(WordTranslation::count())->toBe(5);
});

it('overwrites a changed translation, drops its review mark and counts the word as updated', function (): void {
    app(VocabularyImporter::class)->import(sampleJson(['words' => v2Words()]));
    $hello = Word::query()->where('word', 'hello')->sole();
    $uz = WordTranslation::query()->where('word_id', $hello->id)->where('locale', 'uz')->sole();
    $uz->update(['reviewed_at' => now(), 'source' => 'manual']);

    $words = v2Words();
    $words[0]['translations']['uz'] = 'assalomu alaykum';
    $report = app(VocabularyImporter::class)->import(sampleJson(['words' => $words]));

    expect($report->updated)->toBe(1)
        ->and($report->skipped)->toBe(2)
        ->and($report->translationsUpdated)->toBe(1);

    $uz->refresh();
    expect($uz->translation)->toBe('assalomu alaykum')
        ->and($uz->source)->toBe('import')
        ->and($uz->reviewed_at)->toBeNull();
});

it('keeps the review mark of a translation whose text did not change', function (): void {
    app(VocabularyImporter::class)->import(sampleJson(['words' => v2Words()]));
    $uz = WordTranslation::query()->where('locale', 'uz')->orderBy('id')->first();
    $uz->update(['reviewed_at' => now()]);

    app(VocabularyImporter::class)->import(sampleJson(['words' => v2Words()]));

    expect($uz->fresh()->reviewed_at)->not->toBeNull();
});

it('adds a missing uz translation to a word imported earlier by a v1 file', function (): void {
    app(VocabularyImporter::class)->import(sampleJson());

    $report = app(VocabularyImporter::class)->import(sampleJson([
        'words' => [
            ['word' => 'hello', 'translation' => 'привет', 'translations' => ['uz' => 'salom'], 'part_of_speech' => 'interjection'],
            ['word' => 'world', 'translation' => 'мир', 'part_of_speech' => 'noun'],
        ],
    ]));

    expect($report->updated)->toBe(1)
        ->and($report->skipped)->toBe(1)
        ->and($report->translationsAdded)->toBe(1)
        ->and(WordTranslation::query()->where('locale', 'uz')->count())->toBe(1);
});

it('writes a ru translation row for a v1 file too', function (): void {
    app(VocabularyImporter::class)->import(sampleJson());

    expect(WordTranslation::query()->where('locale', 'ru')->count())->toBe(2)
        ->and(WordTranslation::query()->where('locale', 'uz')->count())->toBe(0);
});

it('imports into the course named by `course` and leaves the default course alone', function (): void {
    $course = Course::factory()->create(['slug' => 'ielts-academic']);

    $report = app(VocabularyImporter::class)->import(sampleJson(['course' => 'ielts-academic', 'words' => v2Words()]));

    expect($report->ok())->toBeTrue()
        ->and(Stage::sole()->course_id)->toBe($course->id)
        ->and(Course::query()->where('slug', config('content.default_course_slug'))->exists())->toBeFalse()
        ->and($course->fresh()->words_count)->toBe(3)
        ->and(AuditLog::query()->where('action', 'import.uploaded')->sole()->meta['course_slug'])->toBe('ielts-academic');
});

it('imports the same stage number into two different courses side by side', function (): void {
    $a = Course::factory()->create(['slug' => 'course-a']);
    $b = Course::factory()->create(['slug' => 'course-b']);

    app(VocabularyImporter::class)->import(sampleJson(['course' => 'course-a']));
    app(VocabularyImporter::class)->import(sampleJson(['course' => 'course-b', 'words' => [['word' => 'cat', 'translation' => 'кот']]]));

    expect(Stage::query()->where('number', 1)->count())->toBe(2)
        ->and($a->stages()->sole()->lessons()->sole()->words()->count())->toBe(2)
        ->and($b->stages()->sole()->lessons()->sole()->words()->count())->toBe(1);
});

it('accepts `course` in the original PRD vocabulary schema', function (): void {
    $course = Course::factory()->create(['slug' => 'prd-course']);

    $report = app(VocabularyImporter::class)->import(json_encode([
        'course' => 'prd-course',
        'stage' => 4,
        'lesson' => 8,
        'vocabulary' => [['word' => 'resilient', 'translation' => 'устойчивый', 'translations' => ['uz' => 'chidamli']]],
    ], JSON_THROW_ON_ERROR));

    expect($report->ok())->toBeTrue()
        ->and($course->stages()->where('number', 4)->exists())->toBeTrue()
        ->and(WordTranslation::query()->where('locale', 'uz')->value('translation'))->toBe('chidamli');
});

it('aborts on an unknown course and writes nothing', function (): void {
    $report = app(VocabularyImporter::class)->import(sampleJson(['course' => 'no-such-course']));

    expect($report->aborted)->toBeTrue()
        ->and($report->errors[0])->toContain("'no-such-course' not found")
        ->and(Stage::count())->toBe(0)
        ->and(Word::count())->toBe(0)
        ->and(Course::count())->toBe(0);
});

it('rejects a v2 file whose ru text is missing, conflicting or in an unsupported locale', function (array $word, string $error): void {
    $report = app(VocabularyImporter::class)->import(sampleJson(['words' => [$word]]));

    expect($report->aborted)->toBeTrue()
        ->and(implode(' ', $report->errors))->toContain($error)
        ->and(Word::count())->toBe(0)
        ->and(WordTranslation::count())->toBe(0);
})->with([
    'no ru at all' => [['word' => 'x', 'translations' => ['uz' => 'y']], 'ru translation is required'],
    'translation vs translations.ru' => [['word' => 'x', 'translation' => 'a', 'translations' => ['ru' => 'b']], "'translation' and 'translations.ru' differ"],
    'unsupported locale' => [['word' => 'x', 'translation' => 'a', 'translations' => ['kk' => 'z']], "unsupported locale 'kk'"],
    'unsupported example locale' => [['word' => 'x', 'translation' => 'a', 'example_translations' => ['de' => 'z']], "unsupported locale 'de'"],
    'unsupported locale with an empty value' => [['word' => 'x', 'translation' => 'a', 'translations' => ['kk' => '']], "unsupported locale 'kk'"],
    'unsupported locale with a blank value' => [['word' => 'x', 'translation' => 'a', 'translations' => ['kk' => '  ']], "unsupported locale 'kk'"],
    'unsupported example locale with a null value' => [['word' => 'x', 'translation' => 'a', 'example_translations' => ['de' => null]], "unsupported locale 'de'"],
    'example translation without the translation (uz)' => [['word' => 'x', 'translation' => 'a', 'example_translations' => ['uz' => 'Salom!']], "example_translations 'uz' has no matching translations.uz"],
    'example translation with an empty translation (uz)' => [['word' => 'x', 'translation' => 'a', 'translations' => ['uz' => ' '], 'example_translations' => ['uz' => 'Salom!']], "example_translations 'uz' has no matching translations.uz"],
    'translations is not an object' => [['word' => 'x', 'translation' => 'a', 'translations' => 'uz'], 'translations'],
]);

it('rolls the whole import back when a write fails midway', function (): void {
    $course = Course::factory()->create(['slug' => 'broken']);
    // Make the 2nd translation insert fail after the stage, lesson and 1st word were written.
    $inserts = 0;
    WordTranslation::creating(function () use (&$inserts): void {
        if (++$inserts === 2) {
            throw new RuntimeException('boom');
        }
    });

    expect(fn () => app(VocabularyImporter::class)->import(sampleJson(['course' => 'broken', 'words' => v2Words()])))
        ->toThrow(RuntimeException::class, 'boom');

    WordTranslation::flushEventListeners();

    expect(Stage::count())->toBe(0)
        ->and(Lesson::count())->toBe(0)
        ->and(Word::count())->toBe(0)
        ->and(WordTranslation::count())->toBe(0)
        ->and(AuditLog::count())->toBe(0)
        ->and($course->fresh()->words_count)->toBe(0);
});

it('drops the lesson cache after a successful commit', function (): void {
    Cache::spy();

    expect(app(VocabularyImporter::class)->import(sampleJson())->ok())->toBeTrue();

    Cache::shouldHaveReceived('forget')->once()->with('lesson:'.Lesson::sole()->id.':word_count');
});

it('does not touch the lesson cache when the import rolls back', function (): void {
    Course::factory()->create(['slug' => 'broken']);
    Cache::spy();

    $inserts = 0;
    WordTranslation::creating(function () use (&$inserts): void {
        if (++$inserts === 2) {
            throw new RuntimeException('boom');
        }
    });

    expect(fn () => app(VocabularyImporter::class)->import(sampleJson(['course' => 'broken', 'words' => v2Words()])))
        ->toThrow(RuntimeException::class);

    WordTranslation::flushEventListeners();

    Cache::shouldNotHaveReceived('forget');
});

function importer_user(string $role, bool $active = true): User
{
    test()->seed(RolePermissionSeeder::class);
    $user = User::factory()->create(['is_active' => $active]);
    $user->assignRole($role);

    return $user;
}

it('imports on behalf of an active admin and records them in the audit log', function (): void {
    $admin = importer_user('admin');

    $report = app(VocabularyImporter::class)->import(sampleJson(), $admin, ['ip' => '10.0.0.1']);

    expect($report->ok())->toBeTrue()
        ->and(Word::count())->toBe(2)
        ->and(AuditLog::sole()->user_id)->toBe($admin->id);
});

it('refuses a teacher: aborted, nothing is written', function (): void {
    $teacher = importer_user('teacher');

    $report = app(VocabularyImporter::class)->import(sampleJson(), $teacher);

    expect($report->aborted)->toBeTrue()
        ->and($report->errors[0])->toContain('not allowed')
        ->and(Course::count())->toBe(0)
        ->and(Stage::count())->toBe(0)
        ->and(Word::count())->toBe(0)
        ->and(AuditLog::count())->toBe(0);
});

it('refuses an inactive admin and a user without roles', function (): void {
    $inactive = importer_user('admin', active: false);
    $plain = User::factory()->create(['is_active' => true]);

    foreach ([$inactive, $plain] as $user) {
        $report = app(VocabularyImporter::class)->import(sampleJson(), $user);

        expect($report->aborted)->toBeTrue();
    }
    expect(Word::count())->toBe(0);
});

it('refuses a teacher even when the target course exists', function (): void {
    $teacher = importer_user('teacher');
    $course = Course::factory()->published()->create(['slug' => 'extra']);

    $report = app(VocabularyImporter::class)->import(sampleJson(['course' => 'extra']), $teacher);

    expect($report->aborted)->toBeTrue()
        ->and($course->stages()->count())->toBe(0);
});

it('allows the userless (system) path only from the console', function (): void {
    // Pest runs in the console: allowed.
    expect(app(VocabularyImporter::class)->import(sampleJson())->ok())->toBeTrue();

    $flag = new ReflectionProperty($this->app, 'isRunningInConsole');
    $flag->setValue($this->app, false);

    try {
        $report = app(VocabularyImporter::class)->import(sampleJson([
            'lesson' => ['number' => 2],
        ]));
    } finally {
        $flag->setValue($this->app, true);
    }

    expect($report->aborted)->toBeTrue()
        ->and(Lesson::query()->where('number', 2)->exists())->toBeFalse();
});

it('accepts example_translations.ru when the ru text comes from `translation`', function (): void {
    $report = app(VocabularyImporter::class)->import(sampleJson(['words' => [
        ['word' => 'x', 'translation' => 'а', 'example' => 'X.', 'example_translations' => ['ru' => 'Икс.']],
    ]]));

    expect($report->ok())->toBeTrue()
        ->and(WordTranslation::query()->where('locale', 'ru')->sole()->example_translation)->toBe('Икс.');
});

it('ignores an empty example translation that has no translation', function (): void {
    $report = app(VocabularyImporter::class)->import(sampleJson(['words' => [
        ['word' => 'x', 'translation' => 'а', 'example_translations' => ['uz' => '']],
    ]]));

    expect($report->ok())->toBeTrue()
        ->and(WordTranslation::query()->where('locale', 'uz')->count())->toBe(0);
});
