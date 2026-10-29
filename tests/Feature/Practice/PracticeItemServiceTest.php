<?php

declare(strict_types=1);

use App\Domain\Practice\Enums\AudioStatus;
use App\Domain\Practice\Enums\PracticeItemStatus;
use App\Domain\Practice\Enums\VersionState;
use App\Domain\Practice\Exceptions\PracticeContentException;
use App\Domain\Practice\Exceptions\PracticeItemStateException;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Practice\PracticeKit;

uses(RefreshDatabase::class);

/*
 * PracticeItemService (docs/16 §1.3, §2.2-§2.4, §4.5): the authoring of the tasks. Append-only versions, the status as a
 * projection of them, the published pointer, archive / restore, the audit trail and EVERY validation code of §4.5.
 */

beforeEach(function (): void {
    Queue::fake();
    $this->admin = User::factory()->create();
});

/**
 * A published course with module 1 / lesson 1 and `$words` active words in it.
 *
 * @return array{0: Course, 1: Lesson, 2: list<Word>}
 */
function pis_course(int $words = 3): array
{
    [$course, $lesson] = PracticeKit::course('pis-'.strtolower(substr(md5((string) microtime(true)), 0, 6)));

    return [$course, $lesson, PracticeKit::words($lesson, $words)];
}

/**
 * The content of a valid task of the type in the form of the author (the form of the import file).
 *
 * @param  array<string, mixed>  $override
 * @return array<string, mixed>
 */
function pis_content(string $type, array $override = []): array
{
    $base = match ($type) {
        'sentence' => [
            'prompt' => ['ru' => 'Моя мама — учитель.', 'uz' => "Onam o'qituvchi."],
            'fragments' => ['my', 'mother', 'is', 'a', 'teacher'],
            'accepted' => [['my', 'mother', 'is', 'a', 'teacher']],
            'display' => 'My mother is a teacher.',
            'explanation' => ['ru' => 'Порядок слов фиксирован.', 'uz' => "So'z tartibi qat'iy."],
        ],
        'collocation' => [
            'text' => "Let's ___ a meeting on Monday.",
            'options' => ['have', 'make', 'do'],
            'correct' => ['have'],
            'translation' => ['ru' => 'Давайте проведём встречу в понедельник.', 'uz' => "Dushanba kuni uchrashuv o'tkazaylik."],
            'explanation' => ['ru' => 'have a meeting — устойчивое сочетание.', 'uz' => "have a meeting — turg'un birikma."],
        ],
        default => [
            'text' => 'See you tomorrow',
            'accepted' => ['See you tomorrow'],
            'translation' => ['ru' => 'До завтра', 'uz' => 'Ertagacha'],
        ],
    };

    return [...$base, ...$override];
}

/**
 * The data of a save: the key, the type, the place and the content.
 *
 * @param  array<string, mixed>  $content  overrides of the keys of the content
 * @param  array<string, mixed>  $data  overrides of the other keys
 * @return array<string, mixed>
 */
function pis_data(string $type, array $content = [], array $data = []): array
{
    return [
        'type' => $type,
        'key' => 'pis-'.$type,
        'lesson_id' => null,
        'level' => 'A1',
        'word_ids' => [],
        'content' => pis_content($type, $content),
        ...$data,
    ];
}

/** The task with its first version, saved through the service. */
function pis_item(Course $course, string $type = 'sentence', array $content = [], array $data = [], ?User $by = null): PracticeItem
{
    return app(PracticeItemService::class)->saveVersion(null, $course, pis_data($type, $content, $data), $by)->item()->firstOrFail();
}

/** The code and the field of the PracticeContentException a call raises (empty strings when it raises none). */
function pis_fails(Closure $call): array
{
    try {
        $call();
    } catch (PracticeContentException $e) {
        return [$e->errorCode, $e->field];
    }

    return ['', ''];
}

// --- saving: the task, the version, the words ----------------------------------------------------------------------

it('writes a new task as a draft with its first version, its target words and the stored form of the content', function (): void {
    [$course, $lesson, $words] = pis_course();

    $version = app(PracticeItemService::class)->saveVersion(null, $course, pis_data('sentence', data: ['lesson_id' => $lesson->id, 'word_ids' => [$words[1]->id, $words[0]->id]]), $this->admin);
    $item = PracticeItem::query()->sole();

    expect($version->number)->toBe(1)
        ->and($version->state)->toBe(VersionState::Draft)
        ->and($version->audio_status)->toBe(AudioStatus::None)
        ->and($version->created_by_user_id)->toBe($this->admin->id)
        ->and($item->status)->toBe(PracticeItemStatus::Draft)
        ->and($item->current_version_id)->toBe($version->id)
        ->and($item->published_version_id)->toBeNull()
        ->and($item->course_id)->toBe($course->id)
        ->and($item->lesson_id)->toBe($lesson->id)
        ->and($item->key)->toBe('pis-sentence')
        ->and($item->level)->toBe('A1')
        ->and($item->created_by_user_id)->toBe($this->admin->id)
        ->and(PracticeItemWord::query()->where('item_id', $item->id)->pluck('word_id')->sort()->values()->all())->toBe([$words[0]->id, $words[1]->id])
        ->and(ksorted($version->content))->toBe(ksorted([
            'prompt' => ['ru' => 'Моя мама — учитель.', 'uz' => "Onam o'qituvchi."],
            'fragments' => ['my', 'mother', 'is', 'a', 'teacher'],
            'accepted' => [[0, 1, 2, 3, 4]],
            'display' => 'My mother is a teacher.',
            'explanation' => ['ru' => 'Порядок слов фиксирован.', 'uz' => "So'z tartibi qat'iy."],
        ]));
});

it('turns the texts into indices: the accepted orders of a sentence, the correct options of a collocation', function (): void {
    [$course] = pis_course();

    $sentence = app(PracticeItemService::class)->saveVersion(null, $course, pis_data('sentence', [
        'fragments' => ['I', 'usually', 'drink', 'tea'],
        'accepted' => [['I', 'usually', 'drink', 'tea'], ['usually', 'I', 'drink', 'tea']],
        'display' => 'I usually drink tea.',
    ], ['key' => 's']), $this->admin);
    $collocation = app(PracticeItemService::class)->saveVersion(null, $course, pis_data('collocation', ['correct' => ['do', 'have']], ['key' => 'c']), $this->admin);

    expect($sentence->content['accepted'])->toBe([[0, 1, 2, 3], [1, 0, 2, 3]])
        ->and($collocation->content['correct'])->toBe([0, 2])
        ->and($collocation->content['options'])->toBe(['have', 'make', 'do']);
});

it('matches equal fragments in order of appearance, so the same word twice is no problem', function (): void {
    [$course] = pis_course();

    $version = app(PracticeItemService::class)->saveVersion(null, $course, pis_data('sentence', [
        'fragments' => ['the', 'cat', 'sees', 'the', 'dog'],
        'accepted' => [['the', 'cat', 'sees', 'the', 'dog'], ['the', 'dog', 'sees', 'the', 'cat']],
        'display' => 'The cat sees the dog.',
    ]), $this->admin);

    expect($version->content['accepted'])->toBe([[0, 1, 2, 3, 4], [0, 4, 2, 3, 1]]);
});

it('makes the first accepted writing of a dictation the text itself and keeps the other variants once', function (): void {
    [$course] = pis_course();

    $version = app(PracticeItemService::class)->saveVersion(null, $course, pis_data('dictation', [
        'text' => 'What time is it?',
        'accepted' => ['What time is it', "What's the time", "what's  the time!", 'What time is it?'],
        'explanation' => null,
    ]), $this->admin);

    // «What time is it» reads like the text; the third variant reads like the second.
    expect($version->content['accepted'])->toBe(['What time is it?', "What's the time"])
        ->and($version->content['explanation'])->toBeNull();
});

it('queues the voicing of every new dictation version after the commit, and of no other type', function (): void {
    [$course] = pis_course();

    $dictation = app(PracticeItemService::class)->saveVersion(null, $course, pis_data('dictation'), $this->admin);
    app(PracticeItemService::class)->saveVersion(null, $course, pis_data('sentence'), $this->admin);

    expect($dictation->audio_status)->toBe(AudioStatus::Pending)
        ->and(PracticeItemVersion::query()->whereKey($dictation->id)->value('audio_status'))->toBe(AudioStatus::Pending);
    Queue::assertPushed(GeneratePracticeAudioJob::class, 1);
    Queue::assertPushed(GeneratePracticeAudioJob::class, fn (GeneratePracticeAudioJob $job): bool => $job->versionId === $dictation->id && ! $job->force && $job->afterCommit === true);
});

// --- append-only versions ---------------------------------------------------------------------------------------------

it('writes an edit as a new version and never changes an old one', function (): void {
    [$course] = pis_course();
    $service = app(PracticeItemService::class);
    $item = pis_item($course);
    $first = PracticeItemVersion::query()->findOrFail($item->current_version_id);
    $before = $first->getAttributes();

    $second = $service->saveVersion($item, $course, pis_data('sentence', ['prompt' => ['ru' => 'Моя мама — врач.', 'uz' => 'Onam shifokor.'], 'fragments' => ['my', 'mother', 'is', 'a', 'doctor'], 'accepted' => [['my', 'mother', 'is', 'a', 'doctor']], 'display' => 'My mother is a doctor.']), $this->admin);

    expect($second->number)->toBe(2)
        ->and($second->id)->not->toBe($first->id)
        ->and($second->state)->toBe(VersionState::Draft)
        ->and($item->fresh()?->current_version_id)->toBe($second->id)
        ->and($item->fresh()?->status)->toBe(PracticeItemStatus::Draft)
        ->and(PracticeItemVersion::query()->where('item_id', $item->id)->count())->toBe(2)
        ->and(PracticeItemVersion::query()->findOrFail($first->id)->getAttributes())->toBe($before)
        ->and(PracticeItem::query()->count())->toBe(1);
});

it('writes nothing when what is saved is what is already there', function (): void {
    [$course, $lesson, $words] = pis_course();
    $service = app(PracticeItemService::class);
    $data = pis_data('dictation', data: ['lesson_id' => $lesson->id, 'word_ids' => [$words[0]->id]]);
    $item = $service->saveVersion(null, $course, $data, $this->admin)->item()->firstOrFail();
    $first = PracticeItemVersion::query()->where('item_id', $item->id)->sole();

    $again = $service->save($item, $course, $data, $this->admin);

    expect($again->versionCreated)->toBeFalse()
        ->and($again->attributesChanged)->toBeFalse()
        ->and($again->outcome())->toBe('skipped')
        ->and($again->version->id)->toBe($first->id)
        ->and(PracticeItemVersion::query()->count())->toBe(1);
    // The audio of the version is not asked for twice.
    Queue::assertPushed(GeneratePracticeAudioJob::class, 1);
});

it('writes a new version when only the target words change, and rewrites the words with it', function (): void {
    [$course, , $words] = pis_course();
    $service = app(PracticeItemService::class);
    $item = pis_item($course, data: ['word_ids' => [$words[0]->id, $words[1]->id]]);

    $saved = $service->save($item, $course, pis_data('sentence', data: ['word_ids' => [$words[1]->id, $words[2]->id]]), $this->admin);

    expect($saved->versionCreated)->toBeTrue()
        ->and($saved->version->number)->toBe(2)
        ->and(PracticeItemWord::query()->where('item_id', $item->id)->pluck('word_id')->sort()->values()->all())->toBe([$words[1]->id, $words[2]->id]);
});

it('changes the lesson and the level of the task without a new version', function (): void {
    [$course, $lesson] = pis_course();
    $service = app(PracticeItemService::class);
    $item = pis_item($course);

    $saved = $service->save($item, $course, pis_data('sentence', data: ['lesson_id' => $lesson->id, 'level' => 'B2']), $this->admin);

    expect($saved->versionCreated)->toBeFalse()
        ->and($saved->attributesChanged)->toBeTrue()
        ->and($saved->outcome())->toBe('updated')
        ->and($item->fresh()?->lesson_id)->toBe($lesson->id)
        ->and($item->fresh()?->level)->toBe('B2')
        ->and(PracticeItemVersion::query()->where('item_id', $item->id)->count())->toBe(1);
});

it('keeps the key and the type of an existing task whatever the data says, and refuses a type that differs', function (): void {
    [$course] = pis_course();
    $item = pis_item($course);

    $saved = app(PracticeItemService::class)->save($item, $course, pis_data('sentence', data: ['key' => 'another-key']), $this->admin);

    expect($saved->item->key)->toBe('pis-sentence')
        ->and(pis_fails(fn () => app(PracticeItemService::class)->saveVersion($item, $course, pis_data('dictation'), $this->admin)))->toBe(['type_invalid', 'type']);
});

it('refuses to edit an archived task', function (): void {
    [$course] = pis_course();
    $service = app(PracticeItemService::class);
    $item = pis_item($course);
    $service->archive($item, $this->admin);

    expect(fn () => $service->saveVersion($item, $course, pis_data('sentence', ['display' => null]), $this->admin))->toThrow(PracticeItemStateException::class);
});

// --- the life of a task: reviewed, published, edited, archived -------------------------------------------------------

it('walks a task through reviewed and published, one audit record for each step', function (): void {
    [$course, , $words] = pis_course();
    $service = app(PracticeItemService::class);
    $item = pis_item($course, data: ['word_ids' => [$words[0]->id]]);
    $version = PracticeItemVersion::query()->where('item_id', $item->id)->sole();

    $service->markReviewed($item, $this->admin, '203.0.113.7');
    expect($item->status)->toBe(PracticeItemStatus::Reviewed)
        ->and($item->published_version_id)->toBeNull()
        ->and($version->fresh()?->state)->toBe(VersionState::Reviewed)
        ->and($version->fresh()?->reviewed_by_user_id)->toBe($this->admin->id)
        ->and($version->fresh()?->reviewed_at)->not->toBeNull();

    $service->publish($item, $this->admin, '203.0.113.7');
    $fresh = $item->fresh();
    expect($fresh?->status)->toBe(PracticeItemStatus::Published)
        ->and($fresh?->published_version_id)->toBe($version->id)
        ->and($fresh?->current_version_id)->toBe($version->id)
        ->and($version->fresh()?->state)->toBe(VersionState::Published)
        ->and($version->fresh()?->published_by_user_id)->toBe($this->admin->id)
        ->and($version->fresh()?->published_at)->not->toBeNull();

    $reviewed = AuditLog::query()->where('action', 'practice_item.reviewed')->sole();
    $published = AuditLog::query()->where('action', 'practice_item.published')->sole();
    expect($reviewed->user_id)->toBe($this->admin->id)
        ->and($reviewed->entity_type)->toBe(PracticeItem::class)
        ->and($reviewed->entity_id)->toBe($item->id)
        ->and($reviewed->ip)->toBe('203.0.113.7')
        ->and($reviewed->meta)->toMatchArray(['key' => 'pis-sentence', 'course_id' => $course->id, 'version_id' => $version->id, 'version' => 1])
        ->and($published->meta)->toMatchArray(['version_id' => $version->id, 'superseded_version_id' => null]);
});

it('does not publish a draft directly and does not review a published version', function (): void {
    [$course, , $words] = pis_course();
    $service = app(PracticeItemService::class);
    $item = pis_item($course, data: ['word_ids' => [$words[0]->id]]);

    expect(fn () => $service->publish($item, $this->admin))->toThrow(PracticeItemStateException::class, 'reviewed')
        ->and($item->fresh()?->status)->toBe(PracticeItemStatus::Draft);

    $service->markReviewed($item, $this->admin);
    $service->publish($item, $this->admin);

    try {
        $service->markReviewed($item, $this->admin);
        $this->fail('a published version has nothing to be reviewed for');
    } catch (PracticeItemStateException $e) {
        expect($e->errorCode)->toBe('not_draft')->and($e->httpStatus())->toBe(409);
    }
});

it('takes a second "reviewed" or "publish" for the same version as done', function (): void {
    [$course, , $words] = pis_course();
    $service = app(PracticeItemService::class);
    $item = pis_item($course, data: ['word_ids' => [$words[0]->id]]);

    $service->markReviewed($item, $this->admin);
    $service->markReviewed($item, $this->admin);
    $service->publish($item, $this->admin);
    $service->publish($item, $this->admin);

    expect(AuditLog::query()->where('action', 'practice_item.reviewed')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'practice_item.published')->count())->toBe(1);
});

it('keeps handing out the published version while an edit of it is a draft, and swaps them on publishing the edit', function (): void {
    [$course, , $words] = pis_course();
    $service = app(PracticeItemService::class);
    $item = pis_item($course, data: ['word_ids' => [$words[0]->id]]);
    $first = PracticeItemVersion::query()->where('item_id', $item->id)->sole();
    $service->markReviewed($item, $this->admin);
    $service->publish($item, $this->admin);

    // The edit: a new draft; the task stays published and the learners keep the old version.
    $second = $service->saveVersion($item, $course, pis_data('sentence', ['explanation' => ['ru' => 'Новое пояснение.', 'uz' => 'Yangi izoh.']], ['word_ids' => [$words[0]->id]]), $this->admin);
    $fresh = $item->fresh();
    expect($fresh?->status)->toBe(PracticeItemStatus::Published)
        ->and($fresh?->published_version_id)->toBe($first->id)
        ->and($fresh?->current_version_id)->toBe($second->id)
        ->and($first->fresh()?->state)->toBe(VersionState::Published)
        ->and($second->state)->toBe(VersionState::Draft);

    // Reviewing the edit does not touch what is handed out either.
    $service->markReviewed($item, $this->admin);
    expect($item->fresh()?->status)->toBe(PracticeItemStatus::Published)
        ->and($item->fresh()?->published_version_id)->toBe($first->id);

    $service->publish($item, $this->admin);
    $fresh = $item->fresh();
    expect($fresh?->published_version_id)->toBe($second->id)
        ->and($first->fresh()?->state)->toBe(VersionState::Superseded)
        ->and($first->fresh()?->superseded_at)->not->toBeNull()
        ->and($second->fresh()?->state)->toBe(VersionState::Published)
        ->and(AuditLog::query()->where('action', 'practice_item.published')->orderByDesc('id')->firstOrFail()->meta)
        ->toMatchArray(['version_id' => $second->id, 'superseded_version_id' => $first->id]);
});

it('archives from any stage and keeps the published pointer, restores to what the task was, and logs both', function (): void {
    [$course, , $words] = pis_course();
    $service = app(PracticeItemService::class);

    // A published task: back to published.
    $published = pis_item($course, data: ['key' => 'a', 'word_ids' => [$words[0]->id]]);
    $service->markReviewed($published, $this->admin);
    $service->publish($published, $this->admin);
    $versionId = $published->published_version_id;

    $service->archive($published, $this->admin, '203.0.113.7');
    $archived = $published->fresh();
    expect($archived?->status)->toBe(PracticeItemStatus::Archived)
        ->and($archived?->archived_at)->not->toBeNull()
        ->and($archived?->published_version_id)->toBe($versionId);

    $service->restore($published, $this->admin);
    expect($published->fresh()?->status)->toBe(PracticeItemStatus::Published)
        ->and($published->fresh()?->archived_at)->toBeNull()
        ->and($published->fresh()?->published_version_id)->toBe($versionId);

    // A reviewed task that never was published: back to reviewed. A draft: back to draft.
    $reviewed = pis_item($course, data: ['key' => 'b']);
    $service->markReviewed($reviewed, $this->admin);
    $service->archive($reviewed, $this->admin);
    $service->restore($reviewed, $this->admin);
    $draft = pis_item($course, data: ['key' => 'c']);
    $service->archive($draft, $this->admin);
    $service->restore($draft, $this->admin);

    expect($reviewed->fresh()?->status)->toBe(PracticeItemStatus::Reviewed)
        ->and($draft->fresh()?->status)->toBe(PracticeItemStatus::Draft)
        ->and(AuditLog::query()->where('action', 'practice_item.archived')->count())->toBe(3)
        ->and(AuditLog::query()->where('action', 'practice_item.restored')->count())->toBe(3);

    $log = AuditLog::query()->where('action', 'practice_item.archived')->firstOrFail();
    expect($log->user_id)->toBe($this->admin->id)
        ->and($log->entity_id)->toBe($published->id)
        ->and($log->ip)->toBe('203.0.113.7')
        ->and($log->meta)->toMatchArray(['key' => 'a', 'from' => 'published']);
});

it('archives twice as once and refuses to restore a task that is not archived', function (): void {
    [$course] = pis_course();
    $service = app(PracticeItemService::class);
    $item = pis_item($course);

    $service->archive($item, $this->admin);
    $service->archive($item, $this->admin);

    expect(AuditLog::query()->where('action', 'practice_item.archived')->count())->toBe(1);

    $service->restore($item, $this->admin);

    try {
        $service->restore($item, $this->admin);
        $this->fail('restore of a task that is not archived must be refused');
    } catch (PracticeItemStateException $e) {
        expect($e->errorCode)->toBe('item_not_archived');
    }
});

it('refuses to review or publish an archived task', function (): void {
    [$course, , $words] = pis_course();
    $service = app(PracticeItemService::class);
    $item = pis_item($course, data: ['word_ids' => [$words[0]->id]]);
    $service->markReviewed($item, $this->admin);
    $service->archive($item, $this->admin);

    expect(fn () => $service->publish($item, $this->admin))->toThrow(PracticeItemStateException::class)
        ->and(fn () => $service->markReviewed($item, $this->admin))->toThrow(PracticeItemStateException::class)
        ->and($item->fresh()?->status)->toBe(PracticeItemStatus::Archived);
});

it('keeps the shape of the status that PostgreSQL checks: published exactly when a published version and not archived', function (): void {
    [$course, , $words] = pis_course();
    $service = app(PracticeItemService::class);
    $item = pis_item($course, data: ['word_ids' => [$words[0]->id]]);

    $states = [];
    $record = function () use ($item, &$states): void {
        $fresh = $item->fresh();
        $states[] = [$fresh?->status === PracticeItemStatus::Published, $fresh?->published_version_id !== null && $fresh?->archived_at === null];
    };

    $record();
    $service->markReviewed($item, $this->admin);
    $record();
    $service->publish($item, $this->admin);
    $record();
    $service->saveVersion($item, $course, pis_data('sentence', ['explanation' => ['ru' => 'Иначе.', 'uz' => 'Boshqacha.']], ['word_ids' => [$words[0]->id]]), $this->admin);
    $record();
    $service->archive($item, $this->admin);
    $record();
    $service->restore($item, $this->admin);
    $record();

    foreach ($states as [$isPublished, $hasPointer]) {
        expect($isPublished)->toBe($hasPointer);
    }
});

it('does not hold the publishing back for audio that is not ready', function (): void {
    [$course, , $words] = pis_course();
    $service = app(PracticeItemService::class);
    $item = pis_item($course, 'dictation', data: ['word_ids' => [$words[0]->id]]);

    $service->markReviewed($item, $this->admin);
    $service->publish($item, $this->admin);

    expect($item->fresh()?->status)->toBe(PracticeItemStatus::Published)
        ->and(PracticeItemVersion::query()->where('item_id', $item->id)->value('audio_status'))->toBe(AudioStatus::Pending);
});

it('asks for the audio of the current version again only for a dictation', function (): void {
    [$course] = pis_course();
    $service = app(PracticeItemService::class);
    $dictation = pis_item($course, 'dictation', data: ['key' => 'd']);
    $sentence = pis_item($course, 'sentence', data: ['key' => 's']);
    PracticeItemVersion::query()->where('item_id', $dictation->id)->update(['audio_status' => 'ready']);
    Queue::fake();

    $service->regenerateAudio($sentence);
    Queue::assertNothingPushed();

    $service->regenerateAudio($dictation);
    Queue::assertPushed(GeneratePracticeAudioJob::class, fn (GeneratePracticeAudioJob $job): bool => $job->force);
    expect(PracticeItemVersion::query()->where('item_id', $dictation->id)->value('audio_status'))->toBe(AudioStatus::Pending);
});

// --- the text is untrusted -----------------------------------------------------------------------------------------

it('strips tags and control characters and composes the text on the way in', function (): void {
    [$course] = pis_course();

    $version = app(PracticeItemService::class)->saveVersion(null, $course, pis_data('collocation', [
        'text' => "Let's <b>___</b> a\u{0000} meeting <script>alert(1)</script>on\tMonday.\u{200B}",
        'options' => ['<i>have</i>', "ma\u{0007}ke", 'do'],
        'correct' => ['have'],
        // «Мой» with the breve apart from the letter (и + U+0306) is composed into one letter й (U+0439).
        'translation' => ['ru' => "Мо\u{0438}\u{0306}", 'uz' => "Uchrashuv\n o'tkazaylik"],
        'explanation' => ['ru' => '<img src=x onerror=alert(1)>Пояснение', 'uz' => 'Izoh'],
    ]), $this->admin);

    expect($version->content['text'])->toBe("Let's ___ a meeting alert(1)on Monday.")
        ->and($version->content['options'])->toBe(['have', 'make', 'do'])
        ->and($version->content['translation']['ru'])->toBe("Мо\u{0439}")
        ->and($version->content['translation']['uz'])->toBe("Uchrashuv o'tkazaylik")
        ->and($version->content['explanation']['ru'])->toBe('Пояснение');
});

it('does not take a line break inside a fragment for a space', function (): void {
    [$course] = pis_course();

    expect(pis_fails(fn () => app(PracticeItemService::class)->saveVersion(null, $course, pis_data('sentence', ['fragments' => ['my', "moth\ner", 'is', 'a', 'teacher']]), $this->admin)))
        ->toBe(['fragment_invalid', 'fragments']);
});

// --- every code of docs/16 §4.5 ------------------------------------------------------------------------------------

it('refuses a text with the code of §4.5', function (string $type, array $content, string $code, string $field): void {
    [$course] = pis_course();

    expect(pis_fails(fn () => app(PracticeItemService::class)->saveVersion(null, $course, pis_data($type, $content), $this->admin)))->toBe([$code, $field])
        ->and(PracticeItem::query()->count())->toBe(0)
        ->and(PracticeItemVersion::query()->count())->toBe(0);
})->with([
    // text_required / text_too_long
    'sentence prompt empty' => ['sentence', ['prompt' => ['ru' => ' ', 'uz' => '']], 'text_required', 'prompt'],
    'sentence prompt missing' => ['sentence', ['prompt' => null], 'text_required', 'prompt'],
    'sentence prompt too long' => ['sentence', ['prompt' => ['ru' => str_repeat('я', 201), 'uz' => 'x']], 'text_too_long', 'prompt'],
    'sentence explanation too long' => ['sentence', ['explanation' => ['ru' => str_repeat('я', 301), 'uz' => 'x']], 'text_too_long', 'explanation'],
    'collocation text empty' => ['collocation', ['text' => '  '], 'text_required', 'text'],
    'collocation text only punctuation' => ['collocation', ['text' => '?!'], 'text_required', 'text'],
    'collocation text too long' => ['collocation', ['text' => '___'.str_repeat('a', 158)], 'text_too_long', 'text'],
    'collocation translation too long' => ['collocation', ['translation' => ['ru' => str_repeat('я', 201)]], 'text_too_long', 'translation'],
    'dictation text empty' => ['dictation', ['text' => ''], 'text_required', 'text'],
    'dictation text too long' => ['dictation', ['text' => str_repeat('a', 81), 'accepted' => []], 'text_too_long', 'text'],
    // fragments
    'two fragments' => ['sentence', ['fragments' => ['my', 'mother'], 'accepted' => [['my', 'mother']], 'display' => null], 'fragments_count', 'fragments'],
    'nine fragments' => ['sentence', ['fragments' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i'], 'accepted' => [['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i']], 'display' => null], 'fragments_count', 'fragments'],
    'fragments not a list' => ['sentence', ['fragments' => 'my mother is a teacher'], 'fragments_count', 'fragments'],
    'empty fragment' => ['sentence', ['fragments' => ['my', '', 'is', 'a', 'teacher']], 'fragment_invalid', 'fragments'],
    'fragment of punctuation only' => ['sentence', ['fragments' => ['my', 'mother', 'is', 'a', '—']], 'fragment_invalid', 'fragments'],
    'fragment over 40 characters' => ['sentence', ['fragments' => ['my', 'mother', 'is', 'a', str_repeat('a', 41)]], 'fragment_invalid', 'fragments'],
    'fragment is not a text' => ['sentence', ['fragments' => ['my', 'mother', 'is', 'a', 5]], 'fragment_invalid', 'fragments'],
    // accepted
    'no accepted order' => ['sentence', ['accepted' => []], 'accepted_required', 'accepted'],
    'accepted missing' => ['sentence', ['accepted' => null], 'accepted_required', 'accepted'],
    'accepted lacks a fragment' => ['sentence', ['accepted' => [['my', 'mother', 'is', 'a']]], 'accepted_not_permutation', 'accepted'],
    'accepted has a stranger' => ['sentence', ['accepted' => [['my', 'mother', 'is', 'a', 'doctor']]], 'accepted_not_permutation', 'accepted'],
    'accepted repeats a fragment' => ['sentence', ['accepted' => [['my', 'mother', 'is', 'a', 'a']]], 'accepted_not_permutation', 'accepted'],
    'second accepted order is no permutation' => ['sentence', ['accepted' => [['my', 'mother', 'is', 'a', 'teacher'], ['my', 'mother']]], 'accepted_not_permutation', 'accepted'],
    'one order written twice' => ['sentence', ['accepted' => [['my', 'mother', 'is', 'a', 'teacher'], ['My', 'Mother', 'is', 'a', 'teacher.']]], 'accepted_duplicate', 'accepted'],
    'seven distinct orders' => ['sentence', [
        'fragments' => ['a', 'b', 'c', 'd'],
        'accepted' => [['a', 'b', 'c', 'd'], ['a', 'b', 'd', 'c'], ['a', 'c', 'b', 'd'], ['a', 'c', 'd', 'b'], ['a', 'd', 'b', 'c'], ['a', 'd', 'c', 'b'], ['b', 'a', 'c', 'd']],
        'display' => null,
    ], 'accepted_too_many', 'accepted'],
    // display
    'display reads otherwise' => ['sentence', ['display' => 'My mother is a doctor.'], 'display_mismatch', 'display'],
    'display of the second order' => ['sentence', ['accepted' => [['my', 'mother', 'is', 'a', 'teacher'], ['teacher', 'my', 'mother', 'is', 'a']], 'display' => 'Teacher my mother is a.'], 'display_mismatch', 'display'],
    'display is no text' => ['sentence', ['display' => 5], 'display_mismatch', 'display'],
    'display too long' => ['sentence', ['display' => str_repeat('a', 201)], 'text_too_long', 'display'],
    // collocation
    'no gap' => ['collocation', ['text' => 'Let us have a meeting on Monday.'], 'placeholder_invalid', 'text'],
    'two gaps' => ['collocation', ['text' => "Let's ___ a ___ on Monday."], 'placeholder_invalid', 'text'],
    'a gap of four underscores' => ['collocation', ['text' => "Let's ____ a meeting."], 'placeholder_invalid', 'text'],
    'two options' => ['collocation', ['options' => ['have', 'make']], 'options_count', 'options'],
    'four options' => ['collocation', ['options' => ['have', 'make', 'do', 'take']], 'options_count', 'options'],
    'a blank option is none' => ['collocation', ['options' => ['have', 'make', ' ']], 'options_count', 'options'],
    'options that read the same' => ['collocation', ['options' => ['have', 'Have', 'do']], 'options_duplicate', 'options'],
    'option over 40 characters' => ['collocation', ['options' => ['have', 'make', str_repeat('a', 41)]], 'text_too_long', 'options'],
    'no correct option' => ['collocation', ['correct' => []], 'correct_invalid', 'correct'],
    'a correct option that is not an option' => ['collocation', ['correct' => ['take']], 'correct_invalid', 'correct'],
    'a correct option that is not a text' => ['collocation', ['correct' => [0]], 'correct_invalid', 'correct'],
    'every option correct' => ['collocation', ['correct' => ['have', 'make', 'do']], 'all_options_correct', 'correct'],
    // dictation
    'empty accepted writing' => ['dictation', ['accepted' => ['See you tomorrow', '']], 'dictation_accepted_invalid', 'accepted'],
    'accepted writing of punctuation only' => ['dictation', ['accepted' => ['?!']], 'dictation_accepted_invalid', 'accepted'],
    'accepted writing over 80 characters' => ['dictation', ['accepted' => [str_repeat('a', 81)]], 'dictation_accepted_invalid', 'accepted'],
    'six other accepted writings' => ['dictation', ['accepted' => ['a one', 'a two', 'a three', 'a four', 'a five', 'a six']], 'dictation_accepted_invalid', 'accepted'],
    'accepted writings are no list' => ['dictation', ['accepted' => 'See you tomorrow'], 'dictation_accepted_invalid', 'accepted'],
]);

it('accepts exactly the limits of §4.5', function (): void {
    [$course] = pis_course();
    $service = app(PracticeItemService::class);

    $sentence = $service->saveVersion(null, $course, pis_data('sentence', [
        'prompt' => ['ru' => str_repeat('я', 200), 'uz' => str_repeat('u', 200)],
        'explanation' => ['ru' => str_repeat('я', 300), 'uz' => str_repeat('u', 300)],
        'fragments' => ['a', 'b', 'c', str_repeat('d', 40)],
        'accepted' => [['a', 'b', 'c', str_repeat('d', 40)], ['b', 'a', 'c', str_repeat('d', 40)], ['c', 'b', 'a', str_repeat('d', 40)], ['a', 'c', 'b', str_repeat('d', 40)], ['b', 'c', 'a', str_repeat('d', 40)], ['c', 'a', 'b', str_repeat('d', 40)]],
        'display' => null,
    ], ['key' => 's']), $this->admin);
    $dictation = $service->saveVersion(null, $course, pis_data('dictation', [
        'text' => str_repeat('a', 80),
        'accepted' => ['a one', 'a two', 'a three', 'a four', 'a five'],
    ], ['key' => 'd']), $this->admin);

    expect($sentence->content['accepted'])->toHaveCount(6)
        ->and($dictation->content['accepted'])->toHaveCount(6);
});

it('refuses a place with the code of §4.5', function (array $data, string $code, string $field): void {
    [$course, $lesson, $words] = pis_course();
    $other = Course::factory()->published()->create();
    $otherLesson = Lesson::factory()->for(Stage::factory()->create(['course_id' => $other->id]))->create();
    $foreign = Word::factory()->for($otherLesson)->create();
    $inactive = Word::factory()->for($lesson)->create(['is_active' => false]);
    $service = app(PracticeItemService::class);
    $service->saveVersion(null, $course, pis_data('sentence', data: ['key' => 'taken']), $this->admin);

    // A dataset has no database: «@foreign», «@otherLesson» and the like stand for the ids made here.
    $ids = ['@foreign' => $foreign->id, '@inactive' => $inactive->id, '@otherLesson' => $otherLesson->id, '@word' => $words[0]->id];
    $resolve = static fn (mixed $value): mixed => is_string($value) && isset($ids[$value]) ? $ids[$value] : $value;
    if (isset($data['lesson_id'])) {
        $data['lesson_id'] = $resolve($data['lesson_id']);
    }
    if (isset($data['word_ids'])) {
        $data['word_ids'] = array_map($resolve, $data['word_ids']);
    }

    expect(pis_fails(fn () => $service->saveVersion(null, $course, pis_data('sentence', data: $data), $this->admin)))->toBe([$code, $field]);
})->with([
    'key with a capital' => [['key' => 'Bad'], 'key_invalid', 'key'],
    'key with a space' => [['key' => 'a b'], 'key_invalid', 'key'],
    'key starting with a dot' => [['key' => '.a'], 'key_invalid', 'key'],
    'empty key' => [['key' => ''], 'key_invalid', 'key'],
    'no key' => [['key' => null], 'key_invalid', 'key'],
    'key over 64 characters' => [['key' => str_repeat('a', 65)], 'key_invalid', 'key'],
    'key that the course has' => [['key' => 'taken'], 'key_duplicate', 'key'],
    'type outside the three' => [['type' => 'pairs'], 'type_invalid', 'type'],
    'no type' => [['type' => null], 'type_invalid', 'type'],
    'lesson of another course' => [['lesson_id' => '@otherLesson'], 'lesson_not_in_course', 'lesson'],
    'lesson that does not exist' => [['lesson_id' => 999999], 'lesson_not_in_course', 'lesson'],
    'level outside A1..C2' => [['level' => 'D1'], 'level_invalid', 'level'],
    'level in lower case' => [['level' => 'a1'], 'level_invalid', 'level'],
    'word of another course' => [['word_ids' => ['@foreign']], 'target_word_unknown', 'target_words'],
    'inactive word' => [['word_ids' => ['@inactive']], 'target_word_unknown', 'target_words'],
    'word that does not exist' => [['word_ids' => [999999]], 'target_word_unknown', 'target_words'],
    'word that is no number' => [['word_ids' => ['mother']], 'target_word_unknown', 'target_words'],
    'a good word and a bad one' => [['word_ids' => ['@word', '@foreign']], 'target_word_unknown', 'target_words'],
]);

it('keeps the key and the type exact: a type may be given as the value of the enum only', function (): void {
    [$course] = pis_course();

    expect(pis_fails(fn () => app(PracticeItemService::class)->saveVersion(null, $course, pis_data('sentence', data: ['type' => 'Sentence']), $this->admin)))->toBe(['type_invalid', 'type']);
});

it('refuses a deck of a learner as the course of a task', function (): void {
    $deck = Course::factory()->deckOf(Learner::factory()->create()->id)->create();

    expect(pis_fails(fn () => app(PracticeItemService::class)->saveVersion(null, $deck, pis_data('sentence'), $this->admin)))->toBe(['course_invalid', 'course'])
        ->and(PracticeItem::query()->count())->toBe(0);
});

it('refuses a course that is gone', function (): void {
    [$course] = pis_course();
    $gone = Course::query()->findOrFail($course->id);
    $course->delete();

    expect(pis_fails(fn () => app(PracticeItemService::class)->saveVersion(null, $gone, pis_data('sentence'), $this->admin)))->toBe(['course_invalid', 'course']);
});

it('may leave a draft without target words and with a text in one language only', function (): void {
    [$course] = pis_course();

    $version = app(PracticeItemService::class)->saveVersion(null, $course, pis_data('collocation', [
        'translation' => ['ru' => 'Только по-русски'],
        'explanation' => [],
    ]), $this->admin);

    expect($version->state)->toBe(VersionState::Draft)
        ->and($version->content['translation'])->toBe(['ru' => 'Только по-русски'])
        ->and($version->content['explanation'])->toBe([]);
});

// --- the "П" rows of §4.5: publishing is stricter -------------------------------------------------------------------

it('refuses to publish a task without a target word', function (): void {
    [$course] = pis_course();
    $service = app(PracticeItemService::class);
    $item = pis_item($course);
    $service->markReviewed($item, $this->admin);

    expect(pis_fails(fn () => $service->publish($item, $this->admin)))->toBe(['target_words_required', 'target_words'])
        ->and($item->fresh()?->status)->toBe(PracticeItemStatus::Reviewed)
        ->and(AuditLog::query()->where('action', 'practice_item.published')->count())->toBe(0);
});

it('refuses to publish a task whose target word has gone inactive meanwhile', function (): void {
    [$course, , $words] = pis_course();
    $service = app(PracticeItemService::class);
    $item = pis_item($course, data: ['word_ids' => [$words[0]->id]]);
    $service->markReviewed($item, $this->admin);
    $words[0]->update(['is_active' => false]);

    expect(pis_fails(fn () => $service->publish($item, $this->admin)))->toBe(['target_word_unknown', 'target_words']);
});

it('refuses to publish a text that lacks a language', function (string $type, array $content, string $field, string $locale): void {
    [$course, , $words] = pis_course();
    $service = app(PracticeItemService::class);
    $item = pis_item($course, $type, $content, ['word_ids' => [$words[0]->id]]);
    $service->markReviewed($item, $this->admin);

    try {
        $service->publish($item, $this->admin);
        $this->fail('a text without the language must not be published');
    } catch (PracticeContentException $e) {
        expect([$e->errorCode, $e->field, $e->context['locale'] ?? null])->toBe(['locale_text_required', $field, $locale]);
    }

    expect($item->fresh()?->status)->toBe(PracticeItemStatus::Reviewed);
})->with([
    'sentence without the uz prompt' => ['sentence', ['prompt' => ['ru' => 'Моя мама — учитель.']], 'prompt', 'uz'],
    'sentence without the ru explanation' => ['sentence', ['explanation' => ['uz' => "So'z tartibi."]], 'explanation', 'ru'],
    'sentence without any explanation' => ['sentence', ['explanation' => []], 'explanation', 'ru'],
    'collocation without the uz translation' => ['collocation', ['translation' => ['ru' => 'Встреча.']], 'translation', 'uz'],
    'collocation without the uz explanation' => ['collocation', ['explanation' => ['ru' => 'Сочетание.']], 'explanation', 'uz'],
    'dictation without the uz translation' => ['dictation', ['translation' => ['ru' => 'До завтра']], 'translation', 'uz'],
    'dictation without the ru translation' => ['dictation', ['translation' => ['uz' => 'Ertagacha']], 'translation', 'ru'],
]);

it('publishes a dictation whose explanation is left out', function (): void {
    [$course, , $words] = pis_course();
    $service = app(PracticeItemService::class);
    $item = pis_item($course, 'dictation', ['explanation' => null], ['word_ids' => [$words[0]->id]]);
    $service->markReviewed($item, $this->admin);
    $service->publish($item, $this->admin);

    expect($item->fresh()?->status)->toBe(PracticeItemStatus::Published);
});

it('writes a version as published at once when asked, and checks the stricter rules first', function (): void {
    [$course, , $words] = pis_course();
    $service = app(PracticeItemService::class);

    $saved = $service->save(null, $course, pis_data('sentence', data: ['word_ids' => [$words[0]->id]]), null, publish: true);
    $item = $saved->item->fresh();

    expect($saved->published)->toBeTrue()
        ->and($item?->status)->toBe(PracticeItemStatus::Published)
        ->and($item?->published_version_id)->toBe($saved->version->id)
        ->and($saved->version->fresh()?->state)->toBe(VersionState::Published)
        ->and($saved->version->fresh()?->reviewed_at)->toBeNull()
        ->and($saved->version->fresh()?->published_by_user_id)->toBeNull();

    expect(pis_fails(fn () => $service->save(null, $course, pis_data('sentence', data: ['key' => 'no-words']), null, publish: true)))->toBe(['target_words_required', 'target_words'])
        ->and(pis_fails(fn () => $service->save(null, $course, pis_data('sentence', ['prompt' => ['ru' => 'Только ru']], ['key' => 'no-uz', 'word_ids' => [$words[0]->id]]), null, publish: true)))->toBe(['locale_text_required', 'prompt'])
        ->and(PracticeItem::query()->count())->toBe(1);
});

it('swaps the published version at once for an edit saved with publishing', function (): void {
    [$course, , $words] = pis_course();
    $service = app(PracticeItemService::class);
    $first = $service->save(null, $course, pis_data('sentence', data: ['word_ids' => [$words[0]->id]]), null, publish: true);

    $second = $service->save($first->item, $course, pis_data('sentence', ['explanation' => ['ru' => 'Другое.', 'uz' => 'Boshqa.']], ['word_ids' => [$words[0]->id]]), null, publish: true);

    expect($second->versionCreated)->toBeTrue()
        ->and($second->item->fresh()?->published_version_id)->toBe($second->version->id)
        ->and($first->version->fresh()?->state)->toBe(VersionState::Superseded)
        ->and($second->version->fresh()?->state)->toBe(VersionState::Published);
});
