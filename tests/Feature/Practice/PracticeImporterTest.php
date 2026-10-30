<?php

declare(strict_types=1);

use App\Domain\Content\DTO\ImportIssue;
use App\Domain\Practice\DTO\PracticeImportReport;
use App\Domain\Practice\Enums\AudioStatus;
use App\Domain\Practice\Enums\PracticeItemStatus;
use App\Domain\Practice\Enums\VersionState;
use App\Domain\Practice\Services\PracticeImporter;
use App\Domain\Practice\Services\PracticeItemService;
use App\Jobs\GeneratePracticeAudioJob;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\PracticeItem;
use App\Models\PracticeItemVersion;
use App\Models\PracticeItemWord;
use App\Models\Stage;
use App\Models\User;
use App\Models\Word;
use Database\Seeders\DemoContentSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
 * PracticeImporter (docs/16 §15.1): the JSON import of the tasks. Format, limits, `key`, a repeated file, "publish at once",
 * every code of refusal with the position of the task, all or nothing, the audit trail and the jobs of the audio after the
 * commit; and the pilot package of the coordinator, whole, into the demo content.
 */

beforeEach(function (): void {
    Queue::fake();
    $this->seed(RolePermissionSeeder::class);
});

/**
 * A published course `imp` with module 1 / lesson 1 (mother, father, family) and lesson 2 (family, tomorrow, ghost - inactive).
 *
 * @return array{0: Course, 1: Lesson, 2: Lesson}
 */
function pim_course(string $slug = 'imp'): array
{
    $course = Course::factory()->published()->create(['slug' => $slug]);
    $stage = Stage::factory()->create(['course_id' => $course->id, 'number' => 1]);
    $one = Lesson::factory()->for($stage)->create(['number' => 1]);
    $two = Lesson::factory()->for($stage)->create(['number' => 2]);

    foreach (['mother', 'father', 'family'] as $word) {
        Word::factory()->for($one)->create(['word' => $word]);
    }
    foreach (['family', 'tomorrow'] as $word) {
        Word::factory()->for($two)->create(['word' => $word]);
    }
    Word::factory()->for($two)->create(['word' => 'ghost', 'is_active' => false]);

    return [$course, $one, $two];
}

/**
 * A task of the file.
 *
 * @param  array<string, mixed>  $override
 * @return array<string, mixed>
 */
function pim_item(string $type, array $override = []): array
{
    $base = match ($type) {
        'sentence' => [
            'key' => 'sent-1', 'type' => 'sentence', 'lesson' => ['stage' => 1, 'number' => 1], 'level' => 'A1', 'target_words' => ['mother'],
            'prompt' => ['ru' => 'Моя мама — учитель.', 'uz' => "Onam o'qituvchi."],
            'fragments' => ['my', 'mother', 'is', 'a', 'teacher'],
            'accepted' => [['my', 'mother', 'is', 'a', 'teacher']],
            'display' => 'My mother is a teacher.',
            'explanation' => ['ru' => 'Порядок слов фиксирован.', 'uz' => "So'z tartibi qat'iy."],
        ],
        'collocation' => [
            'key' => 'col-1', 'type' => 'collocation', 'lesson' => ['stage' => 1, 'number' => 2], 'level' => 'A2', 'target_words' => ['tomorrow'],
            'text' => "Let's ___ a meeting tomorrow.", 'options' => ['have', 'make', 'do'], 'correct' => ['have'],
            'translation' => ['ru' => 'Давайте проведём встречу завтра.', 'uz' => "Ertaga uchrashuv o'tkazaylik."],
            'explanation' => ['ru' => 'have a meeting.', 'uz' => 'have a meeting.'],
        ],
        default => [
            'key' => 'dic-1', 'type' => 'dictation', 'lesson' => ['stage' => 1, 'number' => 2], 'level' => 'A1', 'target_words' => ['tomorrow'],
            'text' => 'See you tomorrow', 'accepted' => ['See you tomorrow', 'See ya tomorrow'],
            'translation' => ['ru' => 'До завтра', 'uz' => 'Ertagacha'],
        ],
    };

    return [...$base, ...$override];
}

/** @return list<array<string, mixed>> one task of each type */
function pim_items(): array
{
    return [pim_item('sentence'), pim_item('collocation'), pim_item('dictation')];
}

/**
 * The JSON of a file.
 *
 * @param  list<mixed>  $items
 * @param  array<string, mixed>  $root  overrides of the keys of the file (a key set to null is dropped)
 */
function pim_file(array $items, array $root = []): string
{
    $file = array_filter([...['format' => 'lexiflow-practice/1', 'course' => 'imp', 'items' => $items], ...$root], static fn (mixed $v): bool => $v !== null);

    return json_encode($file, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
}

function pim_import(string $json, bool $publish = false): PracticeImportReport
{
    return app(PracticeImporter::class)->importAsSystem($json, $publish);
}

/** @return list<array{string, int|null, string|null}> [code, position, key] of every issue of the report */
function pim_issues(PracticeImportReport $report): array
{
    return array_map(
        static fn (ImportIssue $issue): array => [$issue->code, $issue->context['position'] ?? null, $issue->context['key'] ?? null],
        $report->issues,
    );
}

function pim_nothing_written(): bool
{
    return PracticeItem::query()->count() === 0
        && PracticeItemVersion::query()->count() === 0
        && PracticeItemWord::query()->count() === 0
        && AuditLog::query()->where('action', 'practice.imported')->count() === 0;
}

// --- success ----------------------------------------------------------------------------------------------------------

it('imports a file: tasks as drafts with their versions, lessons, levels and target words', function (): void {
    [$course, $one, $two] = pim_course();

    $report = pim_import(pim_file(pim_items()));

    expect($report->ok())->toBeTrue()
        ->and($report->toArray())->toBe(['added' => 3, 'updated' => 0, 'skipped' => 0, 'published' => 0, 'errors' => [], 'aborted' => false]);

    $sentence = PracticeItem::query()->where('key', 'sent-1')->firstOrFail();
    $collocation = PracticeItem::query()->where('key', 'col-1')->firstOrFail();
    $version = PracticeItemVersion::query()->findOrFail($sentence->current_version_id);

    expect(PracticeItem::query()->count())->toBe(3)
        ->and($sentence->course_id)->toBe($course->id)
        ->and($sentence->lesson_id)->toBe($one->id)
        ->and($collocation->lesson_id)->toBe($two->id)
        ->and($sentence->level)->toBe('A1')
        ->and($sentence->status)->toBe(PracticeItemStatus::Draft)
        ->and($sentence->published_version_id)->toBeNull()
        ->and($sentence->created_by_user_id)->toBeNull()
        ->and($version->state)->toBe(VersionState::Draft)
        ->and($version->number)->toBe(1)
        ->and($version->content['accepted'])->toBe([[0, 1, 2, 3, 4]])
        ->and(PracticeItemVersion::query()->findOrFail($collocation->current_version_id)->content['correct'])->toBe([0])
        ->and($sentence->words()->pluck('word')->all())->toBe(['mother']);
});

it('imports the file of a person and writes who did it into the audit trail', function (): void {
    pim_course();
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');

    $report = app(PracticeImporter::class)->import(pim_file(pim_items()), $admin, false, ['ip' => '203.0.113.7']);

    $audit = AuditLog::query()->where('action', 'practice.imported')->sole();
    expect($report->ok())->toBeTrue()
        ->and($audit->user_id)->toBe($admin->id)
        ->and($audit->ip)->toBe('203.0.113.7')
        ->and($audit->entity_type)->toBe(Course::class)
        ->and($audit->meta)->toMatchArray(['actor' => 'user', 'course_slug' => 'imp', 'publish' => false])
        ->and($audit->meta['report'])->toMatchArray(['added' => 3, 'updated' => 0, 'skipped' => 0, 'published' => 0])
        ->and($audit->entity_id)->toBe(Course::query()->where('slug', 'imp')->value('id'))
        ->and(PracticeItem::query()->first()?->created_by_user_id)->toBe($admin->id);
});

it('marks the audit row of a system import with the actor system and no user', function (): void {
    pim_course();

    pim_import(pim_file(pim_items()));

    $audit = AuditLog::query()->where('action', 'practice.imported')->sole();
    expect($audit->user_id)->toBeNull()
        ->and($audit->meta['actor'])->toBe('system');
});

it('refuses a person who may not import and writes nothing', function (): void {
    pim_course();
    $teacher = User::factory()->create(['is_active' => true]);
    $teacher->assignRole('teacher');
    $inactive = User::factory()->create(['is_active' => false]);
    $inactive->assignRole('admin');

    foreach ([$teacher, $inactive, User::factory()->create(['is_active' => true])] as $user) {
        $report = app(PracticeImporter::class)->import(pim_file(pim_items()), $user, false);

        expect(pim_issues($report))->toBe([['not_allowed', null, null]])
            ->and($report->aborted)->toBeTrue();
    }

    expect(pim_nothing_written())->toBeTrue();
    Queue::assertNothingPushed();
});

it('queues the voicing of every new dictation version after the commit, and of nothing else', function (): void {
    pim_course();

    pim_import(pim_file(pim_items()));

    $version = PracticeItemVersion::query()->where('audio_status', AudioStatus::Pending->value)->sole();
    Queue::assertPushed(GeneratePracticeAudioJob::class, 1);
    Queue::assertPushed(GeneratePracticeAudioJob::class, fn (GeneratePracticeAudioJob $job): bool => $job->versionId === $version->id && $job->afterCommit === true);
});

it('resolves the target words without regard to case, in the lesson of the task first, and never an inactive word', function (): void {
    [, $one, $two] = pim_course();

    $report = pim_import(pim_file([
        pim_item('sentence', ['key' => 'a', 'lesson' => ['stage' => 1, 'number' => 2], 'target_words' => ['FAMILY', ' Mother ']]),
        pim_item('sentence', ['key' => 'b', 'lesson' => ['stage' => 1, 'number' => 1], 'target_words' => ['family']]),
        pim_item('sentence', ['key' => 'c', 'lesson' => null, 'target_words' => ['family']]),
        pim_item('sentence', ['key' => 'd', 'lesson' => null, 'target_words' => []]),
    ]));

    $lessonOf = static fn (string $key): array => PracticeItem::query()->where('key', $key)->firstOrFail()->words()->pluck('words.lesson_id')->sort()->values()->all();

    expect($report->ok())->toBeTrue()
        // «family» is in both lessons: the task of lesson 2 takes lesson 2's word (and «mother» only has lesson 1's)
        ->and($lessonOf('a'))->toBe([$one->id, $two->id])
        ->and($lessonOf('b'))->toBe([$one->id])
        // a task of the course takes the earliest in the study order
        ->and($lessonOf('c'))->toBe([$one->id])
        ->and($lessonOf('d'))->toBe([]);
});

it('imports a task of the course with no lesson and no level', function (): void {
    pim_course();

    $report = pim_import(pim_file([pim_item('sentence', ['lesson' => null, 'level' => null])]));

    $item = PracticeItem::query()->sole();
    expect($report->ok())->toBeTrue()
        ->and($item->lesson_id)->toBeNull()
        ->and($item->level)->toBeNull();
});

it('reads a file with a byte order mark', function (): void {
    pim_course();

    $report = pim_import("\xEF\xBB\xBF".pim_file(pim_items()));

    expect($report->added)->toBe(3);
});

// --- the same file again, a changed task ------------------------------------------------------------------------------

it('skips what a repeated file already has: no second version, no second voicing', function (): void {
    pim_course();
    pim_import(pim_file(pim_items()));
    Queue::fake();

    $report = pim_import(pim_file(pim_items()));

    expect($report->ok())->toBeTrue()
        ->and($report->toArray())->toBe(['added' => 0, 'updated' => 0, 'skipped' => 3, 'published' => 0, 'errors' => [], 'aborted' => false])
        ->and(PracticeItem::query()->count())->toBe(3)
        ->and(PracticeItemVersion::query()->count())->toBe(3)
        ->and(AuditLog::query()->where('action', 'practice.imported')->count())->toBe(2);
    Queue::assertNothingPushed();
});

it('writes a changed task as a new version and leaves the rest', function (): void {
    pim_course();
    pim_import(pim_file(pim_items()));
    $item = PracticeItem::query()->where('key', 'col-1')->firstOrFail();
    $first = $item->current_version_id;

    $changed = pim_items();
    $changed[1]['text'] = "Let's ___ a party tomorrow.";
    $report = pim_import(pim_file($changed));

    $item->refresh();
    expect($report->toArray())->toMatchArray(['added' => 0, 'updated' => 1, 'skipped' => 2])
        ->and($item->current_version_id)->not->toBe($first)
        ->and(PracticeItemVersion::query()->where('item_id', $item->id)->count())->toBe(2)
        ->and(PracticeItemVersion::query()->findOrFail($first)->content['text'])->toBe("Let's ___ a meeting tomorrow.")
        ->and(PracticeItemVersion::query()->findOrFail($item->current_version_id)->content['text'])->toBe("Let's ___ a party tomorrow.")
        ->and(PracticeItemVersion::query()->findOrFail($item->current_version_id)->number)->toBe(2)
        ->and(PracticeItem::query()->count())->toBe(3);
});

it('counts a changed target word as an update, and a changed lesson alone too, without a second version', function (): void {
    pim_course();
    pim_import(pim_file(pim_items()));
    $versionsOf = static fn (string $key): int => PracticeItemVersion::query()->where('item_id', PracticeItem::query()->where('key', $key)->value('id'))->count();

    $words = pim_items();
    $words[0]['target_words'] = ['mother', 'father'];
    $first = pim_import(pim_file($words));

    expect($first->toArray())->toMatchArray(['updated' => 1, 'skipped' => 2])
        ->and($versionsOf('sent-1'))->toBe(2);

    $lesson = $words;
    $lesson[2]['lesson'] = ['stage' => 1, 'number' => 1];
    $second = pim_import(pim_file($lesson));

    // The dictation moved to lesson 1: the task changes, the text does not, so there is no new version.
    expect($second->toArray())->toMatchArray(['updated' => 1, 'skipped' => 2])
        ->and($versionsOf('dic-1'))->toBe(1)
        ->and(PracticeItem::query()->where('key', 'dic-1')->firstOrFail()->lesson?->number)->toBe(1);
});

it('imports an edit of a published task as a draft: the learners keep the published version', function (): void {
    pim_course();
    pim_import(pim_file(pim_items()), publish: true);
    $item = PracticeItem::query()->where('key', 'sent-1')->firstOrFail();
    $published = $item->published_version_id;

    $changed = pim_items();
    $changed[0]['explanation'] = ['ru' => 'Другое пояснение.', 'uz' => 'Boshqa izoh.'];
    $report = pim_import(pim_file($changed));

    $item->refresh();
    expect($report->updated)->toBe(1)
        ->and($item->status)->toBe(PracticeItemStatus::Published)
        ->and($item->published_version_id)->toBe($published)
        ->and($item->current_version_id)->not->toBe($published)
        ->and(PracticeItemVersion::query()->findOrFail($item->current_version_id)->state)->toBe(VersionState::Draft);
});

// --- publish at once ----------------------------------------------------------------------------------------------------

it('writes the versions as published when asked, with the previous published version superseded on a change', function (): void {
    pim_course();

    $report = pim_import(pim_file(pim_items()), publish: true);

    $item = PracticeItem::query()->where('key', 'sent-1')->firstOrFail();
    $version = PracticeItemVersion::query()->findOrFail($item->current_version_id);
    expect($report->toArray())->toMatchArray(['added' => 3, 'published' => 3])
        ->and($item->status)->toBe(PracticeItemStatus::Published)
        ->and($item->published_version_id)->toBe($version->id)
        ->and($version->state)->toBe(VersionState::Published)
        ->and($version->published_at)->not->toBeNull()
        ->and(PracticeItem::query()->where('status', 'published')->count())->toBe(3);

    $changed = pim_items();
    $changed[0]['explanation'] = ['ru' => 'Другое пояснение.', 'uz' => 'Boshqa izoh.'];
    $again = pim_import(pim_file($changed), publish: true);

    $item->refresh();
    expect($again->toArray())->toMatchArray(['added' => 0, 'updated' => 1, 'skipped' => 2, 'published' => 1])
        ->and($item->published_version_id)->not->toBe($version->id)
        ->and($version->fresh()?->state)->toBe(VersionState::Superseded)
        ->and(PracticeItemVersion::query()->findOrFail($item->published_version_id)->state)->toBe(VersionState::Published);
});

it('publishes the tasks a file left as drafts when it is imported again with publishing', function (): void {
    pim_course();
    pim_import(pim_file(pim_items()));

    $report = pim_import(pim_file(pim_items()), publish: true);

    // Nothing in the file changed (skipped), and the option asked for publishing: the drafts are published.
    expect($report->toArray())->toMatchArray(['added' => 0, 'updated' => 0, 'skipped' => 3, 'published' => 3])
        ->and(PracticeItem::query()->where('status', 'published')->count())->toBe(3)
        ->and(PracticeItemVersion::query()->count())->toBe(3);
});

it('does not publish through the file: a field "publish" is unknown, not a switch', function (): void {
    pim_course();

    $report = pim_import(pim_file(pim_items(), ['publish' => true]));

    expect(pim_issues($report))->toBe([['field_unknown', null, null]])
        ->and($report->issues[0]->context)->toMatchArray(['field' => 'publish', 'cause' => 'file'])
        ->and(pim_nothing_written())->toBeTrue();
});

// --- refusals of the file -----------------------------------------------------------------------------------------------

it('refuses a file that is not a practice import, with a code and nothing written', function (string $json, string $code): void {
    pim_course();
    Course::factory()->deckOf(Learner::factory()->create()->id)->create(['slug' => 'deck']);

    $report = pim_import($json);

    expect($report->aborted)->toBeTrue()
        ->and($report->issues[0]->code)->toBe($code)
        ->and($report->errors)->not->toBe([])
        ->and($report->added)->toBe(0)
        ->and(pim_nothing_written())->toBeTrue();
    Queue::assertNothingPushed();
})->with([
    'not JSON' => ['not-json', 'invalid_json'],
    'broken JSON' => ['{"format": ', 'invalid_json'],
    'a list' => ['[1, 2]', 'root_not_object'],
    'a number' => ['5', 'root_not_object'],
    'no format' => ['{"course": "imp", "items": [{"key": "a"}]}', 'format_unsupported'],
    'another format' => ['{"format": "lexiflow-practice/2", "course": "imp", "items": [{"key": "a"}]}', 'format_unsupported'],
    'no course' => ['{"format": "lexiflow-practice/1", "items": [{"key": "a"}]}', 'course_not_found'],
    'a course that does not exist' => ['{"format": "lexiflow-practice/1", "course": "nope", "items": [{"key": "a"}]}', 'course_not_found'],
    'a course that is no text' => ['{"format": "lexiflow-practice/1", "course": 5, "items": [{"key": "a"}]}', 'course_not_found'],
    'the deck of a learner' => ['{"format": "lexiflow-practice/1", "course": "deck", "items": [{"key": "a"}]}', 'course_invalid'],
    'no items' => ['{"format": "lexiflow-practice/1", "course": "imp"}', 'items_required'],
    'empty items' => ['{"format": "lexiflow-practice/1", "course": "imp", "items": []}', 'items_required'],
    'items that are an object' => ['{"format": "lexiflow-practice/1", "course": "imp", "items": {"a": 1}}', 'items_required'],
]);

it('says what is wrong with a file that is not JSON', function (string $json, string $cause): void {
    pim_course();

    $report = pim_import($json);

    expect($report->issues[0]->context['cause'] ?? null)->toBe($cause);
})->with([
    'syntax' => ['{"a": }', 'syntax'],
    'encoding' => ["{\"a\": \"\xB1\xB2\"}", 'encoding'],
    'depth' => [str_repeat('[', 40).str_repeat(']', 40), 'depth'],
]);

it('refuses a file over the size limit and a file with too many tasks', function (): void {
    pim_course();

    config(['practice.import.max_bytes' => 100]);
    $big = pim_import(pim_file(pim_items()));
    config(['practice.import.max_bytes' => 2 * 1024 * 1024, 'practice.import.max_items' => 2]);
    $many = pim_import(pim_file(pim_items()));

    expect(pim_issues($big))->toBe([['file_too_large', null, null]])
        ->and($big->issues[0]->context)->toMatchArray(['megabytes' => 100 / 1024 / 1024])
        ->and(pim_issues($many))->toBe([['items_too_many', null, null]])
        ->and($many->issues[0]->context)->toMatchArray(['count' => 3, 'max' => 2])
        ->and(pim_nothing_written())->toBeTrue();

    config(['practice.import.max_items' => 3]);
    expect(pim_import(pim_file(pim_items()))->added)->toBe(3);
});

it('keeps the size limit of the class as the default', function (): void {
    expect(PracticeImporter::MAX_BYTES)->toBe(2 * 1024 * 1024)
        ->and((int) config('practice.import.max_bytes'))->toBe(PracticeImporter::MAX_BYTES)
        ->and((int) config('practice.import.max_items'))->toBe(300);
});

// --- refusals of a task: the code, the position, the key ---------------------------------------------------------------------

it('refuses a task with its code, its position in the file and its key, and writes nothing', function (string $type, array $override, string $code, ?string $field): void {
    pim_course();
    $items = [pim_item('sentence', ['key' => 'ok-1']), pim_item($type, ['key' => 'bad-2', ...$override]), pim_item('dictation', ['key' => 'ok-3'])];

    $report = pim_import(pim_file($items));

    expect(pim_issues($report))->toBe([[$code, 2, 'bad-2']])
        ->and($report->issues[0]->context['field'] ?? null)->toBe($field)
        ->and($report->issues[0]->message)->toContain('Task 2')->toContain('bad-2')
        ->and($report->added)->toBe(0)
        ->and(pim_nothing_written())->toBeTrue();
    Queue::assertNothingPushed();
})->with([
    // the identity and the place
    'type outside the three' => ['sentence', ['type' => 'pairs'], 'type_invalid', null],
    'no type' => ['sentence', ['type' => null], 'type_invalid', null],
    'lesson that the course lacks' => ['sentence', ['lesson' => ['stage' => 9, 'number' => 9]], 'lesson_not_in_course', 'lesson'],
    'lesson of the wrong shape' => ['sentence', ['lesson' => 'one'], 'field_invalid', 'lesson'],
    'lesson with a text for a number' => ['sentence', ['lesson' => ['stage' => '1', 'number' => 1]], 'field_invalid', 'lesson'],
    'level outside A1..C2' => ['sentence', ['level' => 'D1'], 'level_invalid', 'level'],
    'level that is no text' => ['sentence', ['level' => 1], 'field_invalid', 'level'],
    'target word that the course lacks' => ['sentence', ['target_words' => ['mother', 'zebra']], 'target_word_unknown', 'target_words'],
    'target word that is inactive' => ['sentence', ['target_words' => ['ghost']], 'target_word_unknown', 'target_words'],
    'target words that are no list' => ['sentence', ['target_words' => 'mother'], 'field_invalid', 'target_words'],
    'target word that is no text' => ['sentence', ['target_words' => [5]], 'field_invalid', 'target_words'],
    // fields the format does not have, fields of the wrong shape
    'a misspelled field' => ['sentence', ['prompts' => ['ru' => 'x']], 'field_unknown', 'prompts'],
    'a field of another type' => ['sentence', ['options' => ['a', 'b', 'c']], 'field_unknown', 'options'],
    'a language the platform lacks' => ['sentence', ['prompt' => ['ru' => 'Мама', 'uz' => 'Ona', 'en' => 'Mother']], 'field_unknown', 'prompt.en'],
    'a language text that is no text' => ['sentence', ['prompt' => ['ru' => 'Мама', 'uz' => ['Ona']]], 'field_invalid', 'prompt.uz'],
    'fragments that are no list' => ['sentence', ['fragments' => 'my mother'], 'field_invalid', 'fragments'],
    'a prompt that is a list' => ['sentence', ['prompt' => ['Моя мама']], 'field_invalid', 'prompt'],
    'a display that is a list' => ['sentence', ['display' => ['My mother']], 'field_invalid', 'display'],
    // sentence
    'no prompt' => ['sentence', ['prompt' => null], 'text_required', 'prompt'],
    'prompt over 200 characters' => ['sentence', ['prompt' => ['ru' => str_repeat('я', 201), 'uz' => 'x']], 'text_too_long', 'prompt'],
    'two fragments' => ['sentence', ['fragments' => ['my', 'mother'], 'accepted' => [['my', 'mother']], 'display' => null], 'fragments_count', 'fragments'],
    'no fragments' => ['sentence', ['fragments' => null], 'fragments_count', 'fragments'],
    'an empty fragment' => ['sentence', ['fragments' => ['my', '', 'is', 'a', 'teacher']], 'fragment_invalid', 'fragments'],
    'a fragment over 40 characters' => ['sentence', ['fragments' => ['my', 'mother', 'is', 'a', str_repeat('a', 41)]], 'fragment_invalid', 'fragments'],
    'a fragment with a line break' => ['sentence', ['fragments' => ['my', "moth\ner", 'is', 'a', 'teacher']], 'fragment_invalid', 'fragments'],
    'no accepted orders' => ['sentence', ['accepted' => []], 'accepted_required', 'accepted'],
    'an accepted order that is no permutation' => ['sentence', ['accepted' => [['my', 'mother', 'is', 'a', 'doctor']]], 'accepted_not_permutation', 'accepted'],
    'one accepted order twice' => ['sentence', ['accepted' => [['my', 'mother', 'is', 'a', 'teacher'], ['my', 'mother', 'is', 'a', 'teacher']]], 'accepted_duplicate', 'accepted'],
    'seven accepted orders' => ['sentence', [
        'fragments' => ['a', 'b', 'c', 'd'],
        'accepted' => [['a', 'b', 'c', 'd'], ['a', 'b', 'd', 'c'], ['a', 'c', 'b', 'd'], ['a', 'c', 'd', 'b'], ['a', 'd', 'b', 'c'], ['a', 'd', 'c', 'b'], ['b', 'a', 'c', 'd']],
        'display' => null,
    ], 'accepted_too_many', 'accepted'],
    'a display that reads otherwise' => ['sentence', ['display' => 'My mother is a doctor.'], 'display_mismatch', 'display'],
    'a display over 200 characters' => ['sentence', ['display' => str_repeat('a', 201)], 'text_too_long', 'display'],
    // collocation
    'no text' => ['collocation', ['text' => null], 'text_required', 'text'],
    'text over 160 characters' => ['collocation', ['text' => '___'.str_repeat('a', 158)], 'text_too_long', 'text'],
    'text without a gap' => ['collocation', ['text' => 'Let us have a meeting.'], 'placeholder_invalid', 'text'],
    'text with two gaps' => ['collocation', ['text' => "Let's ___ a ___."], 'placeholder_invalid', 'text'],
    'two options' => ['collocation', ['options' => ['have', 'make']], 'options_count', 'options'],
    'options that read the same' => ['collocation', ['options' => ['have', 'HAVE', 'do']], 'options_duplicate', 'options'],
    'no correct option' => ['collocation', ['correct' => []], 'correct_invalid', 'correct'],
    'a correct option outside the options' => ['collocation', ['correct' => ['take']], 'correct_invalid', 'correct'],
    'every option correct' => ['collocation', ['correct' => ['have', 'make', 'do']], 'all_options_correct', 'correct'],
    // dictation
    'a dictation without text' => ['dictation', ['text' => ''], 'text_required', 'text'],
    'a dictation text over 80 characters' => ['dictation', ['text' => str_repeat('a', 81), 'accepted' => []], 'text_too_long', 'text'],
    'an empty accepted writing' => ['dictation', ['accepted' => ['See you tomorrow', ' ']], 'dictation_accepted_invalid', 'accepted'],
    'six other accepted writings' => ['dictation', ['accepted' => ['a one', 'a two', 'a three', 'a four', 'a five', 'a six']], 'dictation_accepted_invalid', 'accepted'],
]);

it('refuses a key that is missing or malformed, and names the task by the key it has', function (array $override, string $code, string $shown): void {
    pim_course();

    $report = pim_import(pim_file([pim_item('sentence'), pim_item('collocation', $override)]));

    expect(pim_issues($report))->toBe([[$code, 2, $shown]])
        ->and($report->issues[0]->context['field'] ?? null)->toBe('key')
        ->and(pim_nothing_written())->toBeTrue();
})->with([
    'no key' => [['key' => null], 'key_invalid', '—'],
    'a key that is no text' => [['key' => 5], 'field_invalid', '—'],
    'a key with a capital' => [['key' => 'Bad-Key'], 'key_invalid', 'Bad-Key'],
    'a key with a space' => [['key' => 'a b'], 'key_invalid', 'a b'],
    'a key over 64 characters' => [['key' => str_repeat('a', 65)], 'key_invalid', str_repeat('a', 65)],
]);

it('refuses a key twice in the file, with the position of the first', function (): void {
    pim_course();
    $items = [pim_item('sentence', ['key' => 'same']), pim_item('collocation'), pim_item('dictation', ['key' => 'same'])];

    $report = pim_import(pim_file($items));

    expect(pim_issues($report))->toBe([['key_duplicate_in_file', 3, 'same']])
        ->and($report->issues[0]->context)->toMatchArray(['first_position' => 1]);
});

it('refuses a task that is not an object', function (): void {
    pim_course();

    $report = pim_import(pim_file([pim_item('sentence'), 'a text', [1, 2], pim_item('dictation')]));

    expect(pim_issues($report))->toBe([['item_invalid', 2, '—'], ['item_invalid', 3, '—']]);
});

it('refuses the key of a task of another type and the key of an archived task', function (): void {
    pim_course();
    pim_import(pim_file(pim_items()));
    $archived = PracticeItem::query()->where('key', 'dic-1')->firstOrFail();
    app(PracticeItemService::class)->archive($archived, User::factory()->create());

    $report = pim_import(pim_file([
        pim_item('sentence', ['key' => 'col-1']),
        pim_item('dictation'),
    ]));

    expect(pim_issues($report))->toBe([['key_type_mismatch', 1, 'col-1'], ['item_archived', 2, 'dic-1']])
        ->and($report->issues[0]->context)->toMatchArray(['existing_type' => 'collocation'])
        ->and(PracticeItemVersion::query()->count())->toBe(3);
});

it('reports every faulty task of the file at once, with the unknown words one by one', function (): void {
    pim_course();
    $items = [
        pim_item('sentence', ['key' => 'one', 'target_words' => ['zebra', 'llama']]),
        pim_item('collocation', ['key' => 'two']),
        pim_item('dictation', ['key' => 'three', 'level' => 'Z9']),
        pim_item('sentence', ['key' => 'four', 'fragments' => ['my']]),
    ];

    $report = pim_import(pim_file($items));

    expect(pim_issues($report))->toBe([
        ['target_word_unknown', 1, 'one'],
        ['target_word_unknown', 1, 'one'],
        ['level_invalid', 3, 'three'],
        ['fragments_count', 4, 'four'],
    ])->and([$report->issues[0]->context['word'], $report->issues[1]->context['word']])->toBe(['zebra', 'llama'])
        ->and(pim_nothing_written())->toBeTrue();
});

it('is all or nothing: one faulty task among good ones writes none of them', function (): void {
    pim_course();

    $report = pim_import(pim_file([...pim_items(), pim_item('sentence', ['key' => 'late', 'level' => 'Z9'])]));

    expect($report->aborted)->toBeTrue()
        ->and($report->added)->toBe(0)
        ->and(pim_nothing_written())->toBeTrue();
    Queue::assertNothingPushed();
});

it('is all or nothing for an unexpected failure while writing, too', function (): void {
    pim_course();
    // The third version fails to be written: the first two must not stay.
    $writes = 0;
    PracticeItemVersion::creating(function () use (&$writes): void {
        if (++$writes === 3) {
            throw new RuntimeException('disk full');
        }
    });

    try {
        expect(fn () => pim_import(pim_file(pim_items())))->toThrow(RuntimeException::class, 'disk full')
            ->and(pim_nothing_written())->toBeTrue();
    } finally {
        PracticeItemVersion::flushEventListeners();
    }
});

it('refuses to publish a task without a target word or a text in a language, with the position', function (): void {
    pim_course();

    $report = pim_import(pim_file([
        pim_item('sentence', ['key' => 'a', 'target_words' => []]),
        pim_item('collocation', ['key' => 'b', 'translation' => ['ru' => 'Только по-русски']]),
        pim_item('dictation', ['key' => 'c']),
    ]), publish: true);

    expect(pim_issues($report))->toBe([['target_words_required', 1, 'a'], ['locale_text_required', 2, 'b']])
        ->and($report->issues[1]->context)->toMatchArray(['field' => 'translation', 'locale' => 'uz'])
        ->and(pim_nothing_written())->toBeTrue();

    // The same file as drafts is fine: the stricter rules are those of publishing.
    expect(pim_import(pim_file([
        pim_item('sentence', ['key' => 'a', 'target_words' => []]),
        pim_item('collocation', ['key' => 'b', 'translation' => ['ru' => 'Только по-русски']]),
    ]))->added)->toBe(2);
});

// --- the pilot package ------------------------------------------------------------------------------------------------------

it('imports the whole pilot package into the demo content without a single refusal: 90 tasks added', function (): void {
    $this->seed(DemoContentSeeder::class);
    $json = (string) file_get_contents(base_path('database/data/practice/pilot-a1-a2.json'));

    $report = pim_import($json);

    expect($report->errors)->toBe([])
        ->and($report->toArray())->toBe(['added' => 90, 'updated' => 0, 'skipped' => 0, 'published' => 0, 'errors' => [], 'aborted' => false])
        ->and(PracticeItem::query()->count())->toBe(90)
        ->and(PracticeItem::query()->where('type', 'sentence')->count())->toBe(30)
        ->and(PracticeItem::query()->where('type', 'collocation')->count())->toBe(30)
        ->and(PracticeItem::query()->where('type', 'dictation')->count())->toBe(30)
        ->and(PracticeItem::query()->whereNull('lesson_id')->count())->toBe(0)
        ->and(PracticeItem::query()->whereNull('level')->count())->toBe(0)
        // every task has at least one target word
        ->and(PracticeItem::query()->whereDoesntHave('words')->count())->toBe(0)
        ->and(PracticeItemVersion::query()->where('audio_status', AudioStatus::Pending->value)->count())->toBe(30);
    Queue::assertPushed(GeneratePracticeAudioJob::class, 30);

    // The package is a repeatable import.
    expect(pim_import($json)->toArray())->toMatchArray(['added' => 0, 'updated' => 0, 'skipped' => 90]);
});

it('publishes the whole pilot package when asked: every task passes the stricter rules', function (): void {
    $this->seed(DemoContentSeeder::class);

    $report = pim_import((string) file_get_contents(base_path('database/data/practice/pilot-a1-a2.json')), publish: true);

    expect($report->errors)->toBe([])
        ->and($report->toArray())->toMatchArray(['added' => 90, 'published' => 90])
        ->and(PracticeItem::query()->where('status', 'published')->count())->toBe(90)
        ->and(PracticeItem::query()->whereNull('published_version_id')->count())->toBe(0);
});

it('keeps the texts of the pilot package as the coordinator wrote them: the stored text is the written text', function (): void {
    $this->seed(DemoContentSeeder::class);
    $raw = (string) file_get_contents(base_path('database/data/practice/pilot-a1-a2.json'));
    pim_import($raw);

    $file = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    foreach ($file['items'] as $task) {
        $content = PracticeItemVersion::query()->findOrFail(PracticeItem::query()->where('key', $task['key'])->firstOrFail()->current_version_id)->content;

        match ($task['type']) {
            'sentence' => expect($content['prompt'])->toBe($task['prompt'])->and($content['fragments'])->toBe($task['fragments'])->and($content['display'])->toBe($task['display']),
            'collocation' => expect($content['text'])->toBe($task['text'])->and($content['options'])->toBe($task['options']),
            default => expect($content['text'])->toBe($task['text'])->and($content['accepted'][0])->toBe($task['text']),
        };
    }
});

// --- artisan ---------------------------------------------------------------------------------------------------------------------

/** A file in the system temp folder with the JSON; the caller removes it. */
function pim_temp(string $json): string
{
    $path = sys_get_temp_dir().'/practice-import-'.bin2hex(random_bytes(6)).'.json';
    file_put_contents($path, $json);

    return $path;
}

it('runs practice:import: a table of what was done, exit code 0', function (): void {
    pim_course();
    $path = pim_temp(pim_file(pim_items()));

    $code = Artisan::call('practice:import', ['file' => $path]);
    $output = Artisan::output();

    expect($code)->toBe(0)
        ->and($output)->toContain('Added')->toContain('Skipped')->toContain('Practice import finished.')
        ->and(PracticeItem::query()->where('status', 'draft')->count())->toBe(3);

    expect(Artisan::call('practice:import', ['file' => $path, '--publish' => true]))->toBe(0);
    unlink($path);

    expect(PracticeItem::query()->where('status', 'published')->count())->toBe(3)
        ->and(AuditLog::query()->where('action', 'practice.imported')->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'practice.imported')->latest('id')->firstOrFail()->meta)->toMatchArray(['actor' => 'system', 'publish' => true]);
});

it('runs practice:import on a file with a fault: the issues in a table, a code that is not zero and nothing written', function (): void {
    pim_course();
    $path = pim_temp(pim_file([pim_item('sentence'), pim_item('collocation', ['key' => 'bad', 'level' => 'Z9'])]));

    $code = Artisan::call('practice:import', ['file' => $path]);
    $output = Artisan::output();
    unlink($path);

    expect($code)->toBe(1)
        ->and($output)->toContain('level_invalid')->toContain('bad')->toContain('nothing was written')
        ->and(pim_nothing_written())->toBeTrue();
});

it('runs practice:import with a path from the project root, and fails on a file that is not there', function (): void {
    pim_course();

    expect(Artisan::call('practice:import', ['file' => 'storage/app/no-such-practice-file.json']))->toBe(1)
        ->and(Artisan::output())->toContain('File not found');

    $relative = 'storage/framework/testing/practice-import-test.json';
    file_put_contents(base_path($relative), pim_file(pim_items()));

    expect(Artisan::call('practice:import', ['file' => $relative]))->toBe(0);
    unlink(base_path($relative));
    expect(PracticeItem::query()->count())->toBe(3);
});

it('refuses a file over the size limit on the command line without reading it whole', function (): void {
    pim_course();
    config(['practice.import.max_bytes' => 100]);
    $path = pim_temp(pim_file(pim_items()));

    expect(Artisan::call('practice:import', ['file' => $path]))->toBe(1)
        ->and(Artisan::output())->toContain('file_too_large');
    unlink($path);
});
