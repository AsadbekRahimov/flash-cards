<?php

declare(strict_types=1);

use App\Domain\Content\DTO\ImportIssue;
use App\Domain\Practice\Enums\AudioStatus;
use App\Domain\Practice\Enums\PracticeItemStatus;
use App\Domain\Practice\Enums\PracticeItemType;
use App\Domain\Practice\Enums\VersionState;
use App\Domain\Practice\Exceptions\PracticeContentException;
use App\Domain\Practice\Exceptions\PracticeItemStateException;
use App\Domain\Practice\Services\PracticeAccess;
use App\Domain\Practice\Services\PracticeImporter;
use App\Domain\Practice\Services\PracticeItemService;
use App\Domain\Practice\Services\PracticePilotService;
use App\Filament\Pages\ImportPracticeItems;
use App\Filament\Resources\LearnerResource\Pages\ListLearners;
use App\Filament\Resources\LearnerResource\Pages\ViewLearner;
use App\Filament\Resources\PracticeItemResource;
use App\Filament\Resources\PracticeItemResource\Pages\CreatePracticeItem;
use App\Filament\Resources\PracticeItemResource\Pages\EditPracticeItem;
use App\Filament\Resources\PracticeItemResource\Pages\ListPracticeItems;
use App\Filament\Resources\Support\AdminErrors;
use App\Jobs\GeneratePracticeAudioJob;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\PracticeItem;
use App\Models\PracticeItemVersion;
use App\Models\PracticeItemWord;
use App\Models\Stage;
use App\Models\TelegramGroup;
use App\Models\User;
use App\Models\Word;
use Database\Seeders\DemoContentSeeder;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

/*
 * The admin screens of the practice (docs/16 §12, §15 task 18.7a): the list of the tasks with its filters, the forms by type, the
 * buttons "Проверено / Опубликовать / В архив / Вернуть / Сгенерировать аудио заново", the import page, the pilot of a learner.
 * Admin only: a teacher gets 403, the cabinet of a center has no such screen. Every text is Russian.
 */

beforeEach(function (): void {
    Queue::fake();
    $this->seed(RolePermissionSeeder::class);
    app()->setLocale('ru');
    $this->admin = pad_user('admin');
    $this->actingAs($this->admin);
});

function pad_user(string $role): User
{
    $user = User::factory()->create([
        'is_active' => true,
        'two_factor_secret' => 'stub',
        'two_factor_recovery_codes' => [],
        'two_factor_confirmed_at' => now(),
    ]);
    $user->assignRole($role);

    return $user;
}

function pad_get(User $user, string $url): TestResponse
{
    return test()->actingAs($user)
        ->withSession(['2fa.web.passed_at' => now()->timestamp, '2fa.web.user_id' => $user->id])
        ->get($url);
}

/** The notifications sent since the last call: title and body. @return list<array{title: mixed, body: mixed}> */
function pad_sent(): array
{
    $mailbox = app(NotificationMailbox::class);
    $mailbox->flush();

    return array_map(static fn (array $notification): array => ['title' => $notification['title'] ?? null, 'body' => $notification['body'] ?? null], $mailbox->pull());
}

/**
 * A published course `pad` with module 1 / lessons 1 and 2 and module 2 / lesson 1 and a few words in each.
 *
 * @return array{course: Course, l11: Lesson, l12: Lesson, l21: Lesson, words: array<string, Word>}
 */
function pad_world(string $slug = 'pad'): array
{
    $course = Course::factory()->published()->create(['slug' => $slug, 'title' => ['ru' => 'Тестовый курс', 'uz' => 'Test kursi']]);
    $one = Stage::factory()->create(['course_id' => $course->id, 'number' => 1]);
    $two = Stage::factory()->create(['course_id' => $course->id, 'number' => 2]);
    $l11 = Lesson::factory()->for($one)->create(['number' => 1]);
    $l12 = Lesson::factory()->for($one)->create(['number' => 2]);
    $l21 = Lesson::factory()->for($two)->create(['number' => 1]);

    $words = [];
    foreach ([['mother', 'мать', $l11], ['tomorrow', 'завтра', $l12], ['meeting', 'встреча', $l21]] as [$text, $translation, $lesson]) {
        $words[$text] = Word::factory()->for($lesson)->create(['word' => $text, 'translation' => $translation]);
    }
    $words['ghost'] = Word::factory()->for($l11)->create(['word' => 'ghost', 'translation' => 'призрак', 'is_active' => false]);

    return ['course' => $course, 'l11' => $l11, 'l12' => $l12, 'l21' => $l21, 'words' => $words];
}

/** A task through the service, with the words, as a draft. */
function pad_make(Course $course, string $type, string $key, array $words = [], array $content = [], ?User $by = null): PracticeItem
{
    $base = match ($type) {
        'sentence' => ['prompt' => ['ru' => 'Моя мама — учитель.', 'uz' => "Onam o'qituvchi."], 'fragments' => ['my', 'mother', 'is', 'a', 'teacher'], 'accepted' => [['my', 'mother', 'is', 'a', 'teacher']], 'display' => 'My mother is a teacher.', 'explanation' => ['ru' => 'Порядок слов.', 'uz' => "So'z tartibi."]],
        'collocation' => ['text' => "Let's ___ a meeting.", 'options' => ['have', 'make', 'do'], 'correct' => ['have'], 'translation' => ['ru' => 'Давайте проведём встречу.', 'uz' => "Uchrashuv o'tkazaylik."], 'explanation' => ['ru' => 'Сочетание.', 'uz' => 'Birikma.']],
        default => ['text' => 'See you tomorrow', 'accepted' => ['See you tomorrow'], 'translation' => ['ru' => 'До завтра', 'uz' => 'Ertagacha']],
    };

    return app(PracticeItemService::class)
        ->save(null, $course, ['type' => $type, 'key' => $key, 'level' => 'A1', 'word_ids' => array_map(static fn (Word $w): int => $w->id, $words), 'content' => [...$base, ...$content]], $by)
        ->item;
}

function pad_publish(PracticeItem $item, User $by): PracticeItem
{
    $service = app(PracticeItemService::class);
    $service->markReviewed($item, $by);

    return $service->publish($item, $by);
}

// --- access ----------------------------------------------------------------------------------------------------------

it('opens the list, the form and the import page to an active admin', function (): void {
    pad_get($this->admin, '/admin/practice-items')->assertOk()->assertSee('Задания практики');
    pad_get($this->admin, '/admin/practice-items/create')->assertOk()->assertSee('Новое задание практики');
    pad_get($this->admin, '/admin/import-practice-items')->assertOk()->assertSee('Импорт заданий практики');
});

it('closes every practice screen to a teacher, an inactive admin and a user without a role', function (): void {
    $world = pad_world();
    $item = pad_make($world['course'], 'sentence', 'closed');

    $inactive = User::factory()->create(['is_active' => false]);
    $inactive->assignRole('admin');
    $nobody = User::factory()->create(['is_active' => true]);

    foreach ([pad_user('teacher'), $inactive, $nobody] as $user) {
        foreach (['/admin/practice-items', '/admin/practice-items/create', "/admin/practice-items/{$item->id}/edit", '/admin/import-practice-items'] as $url) {
            pad_get($user, $url)->assertForbidden();
        }

        $this->actingAs($user);
        expect(PracticeItemResource::canViewAny())->toBeFalse()
            ->and(ImportPracticeItems::canAccess())->toBeFalse();
    }
});

it('gives a teacher none of the abilities of the policy', function (): void {
    $item = pad_make(pad_world()['course'], 'sentence', 'policy');
    $teacher = pad_user('teacher');

    foreach (['viewAny', 'view', 'create', 'update', 'review', 'publish', 'archive', 'restore', 'delete'] as $ability) {
        $arguments = in_array($ability, ['viewAny', 'create'], true) ? [PracticeItem::class] : [$item];

        expect(Gate::forUser($teacher)->denies($ability, $arguments))->toBeTrue("a teacher may {$ability}");
    }

    foreach (['viewAny', 'view', 'create', 'update', 'review', 'publish', 'archive', 'restore'] as $ability) {
        $arguments = in_array($ability, ['viewAny', 'create'], true) ? [PracticeItem::class] : [$item];

        expect(Gate::forUser($this->admin)->allows($ability, $arguments))->toBeTrue("an admin may {$ability}");
    }

    // Nothing is deleted: a task goes to the archive.
    expect(Gate::forUser($this->admin)->denies('delete', $item))->toBeTrue();
});

it('has no practice screen in the cabinet of a center', function (): void {
    $org = Filament::getPanel('org');

    expect($org->getResources())->not->toContain(PracticeItemResource::class)
        ->and($org->getPages())->not->toContain(ImportPracticeItems::class)
        ->and(Filament::getPanel('admin')->getResources())->toContain(PracticeItemResource::class)
        ->and(Filament::getPanel('admin')->getPages())->toContain(ImportPracticeItems::class);
});

it('puts the group «Практика» next to «Контент» in the menu', function (): void {
    $html = (string) pad_get($this->admin, '/admin/practice-items')->assertOk()->getContent();
    $text = preg_replace('/\s+/u', ' ', strip_tags($html)) ?? '';

    $content = mb_strpos($text, 'Контент');
    $practice = mb_strpos($text, 'Практика');
    $learning = mb_strpos($text, 'Обучение');

    expect($content)->not->toBeFalse()
        ->and($practice)->toBeGreaterThan($content)
        ->and($practice)->toBeLessThan($learning)
        ->and($text)->toContain('Задания практики')->toContain('Импорт заданий');
});

// --- the list -----------------------------------------------------------------------------------------------------------------

it('lists the tasks: course, «Модуль N · Урок M», type, key, text, status, audio, the unpublished edit', function (): void {
    $w = pad_world();
    $sentence = PracticeItem::factory()->sentence()->published()->forLesson($w['l21'])->targeting($w['words']['meeting'])->create(['key' => 'pad-sentence']);
    $dictation = PracticeItem::factory()->dictation()->audioPending()->forLesson($w['l12'])->targeting($w['words']['tomorrow'])->create(['key' => 'pad-dictation']);
    $collocation = PracticeItem::factory()->collocation()->reviewed()->forCourse($w['course'])->create(['key' => 'pad-collocation']);

    livewire(ListPracticeItems::class)
        ->assertCanSeeTableRecords([$sentence, $dictation, $collocation])
        ->assertTableColumnStateSet('course.slug', 'pad', $sentence)
        ->assertTableColumnStateSet('lesson_label', 'Модуль 2 · Урок 1', $sentence)
        ->assertTableColumnStateSet('lesson_label', 'Модуль 1 · Урок 2', $dictation)
        ->assertTableColumnStateSet('lesson_label', '—', $collocation)
        ->assertTableColumnFormattedStateSet('type', 'Собери предложение', $sentence)
        ->assertTableColumnFormattedStateSet('type', 'Слушай и пиши', $dictation)
        ->assertTableColumnFormattedStateSet('type', 'Подбери сочетание', $collocation)
        ->assertTableColumnStateSet('key', 'pad-sentence', $sentence)
        ->assertTableColumnFormattedStateSet('status', 'Опубликовано', $sentence)
        ->assertTableColumnFormattedStateSet('status', 'Черновик', $dictation)
        ->assertTableColumnFormattedStateSet('status', 'Проверено', $collocation)
        ->assertTableColumnFormattedStateSet('audio', 'В очереди', $dictation)
        ->assertTableColumnStateSet('audio', null, $sentence)
        ->assertTableColumnStateSet('has_unpublished_edit', false, $sentence);

    expect(PracticeItemResource::lessonLabel($sentence->load('lesson.stage')))->toBe('Модуль 2 · Урок 1');
});

it('shows the text a learner works on, not the prompt, and escapes whatever an author wrote', function (): void {
    $w = pad_world();
    $sentence = pad_make($w['course'], 'sentence', 'text-sentence');
    $dictation = pad_make($w['course'], 'dictation', 'text-dictation', content: ['text' => 'Say <b>hello</b>']);

    livewire(ListPracticeItems::class)
        ->assertTableColumnStateSet('preview', 'My mother is a teacher.', $sentence)
        // The tags were stripped on the way in: what is left is text, and the page prints it escaped.
        ->assertTableColumnStateSet('preview', 'Say hello', $dictation)
        ->assertDontSeeHtml('<b>hello</b>');
});

it('marks a published task that has a newer version waiting', function (): void {
    $w = pad_world();
    $item = pad_publish(pad_make($w['course'], 'sentence', 'waiting', [$w['words']['mother']]), $this->admin);
    $plain = pad_publish(pad_make($w['course'], 'sentence', 'plain', [$w['words']['mother']]), $this->admin);
    app(PracticeItemService::class)->saveVersion($item, $w['course'], ['word_ids' => [$w['words']['mother']->id], 'content' => [
        'prompt' => ['ru' => 'Моя мама — врач.', 'uz' => 'Onam shifokor.'], 'fragments' => ['my', 'mother', 'is', 'a', 'doctor'],
        'accepted' => [['my', 'mother', 'is', 'a', 'doctor']], 'explanation' => ['ru' => 'Другое.', 'uz' => 'Boshqa.'],
    ]], $this->admin);

    livewire(ListPracticeItems::class)
        ->assertTableColumnStateSet('has_unpublished_edit', true, $item->fresh())
        ->assertTableColumnStateSet('has_unpublished_edit', false, $plain)
        ->assertTableColumnFormattedStateSet('status', 'Опубликовано', $item->fresh())
        ->filterTable('unpublished_edit', true)
        ->assertCanSeeTableRecords([$item])
        ->assertCanNotSeeTableRecords([$plain])
        ->filterTable('unpublished_edit', false)
        ->assertCanSeeTableRecords([$plain])
        ->assertCanNotSeeTableRecords([$item]);

    expect(PracticeItemResource::hasUnpublishedEdit($item->fresh() ?? $item))->toBeTrue();
});

it('filters the list by course, type, status and the audio of a dictation', function (): void {
    $w = pad_world();
    $other = pad_world('pad-other');
    $sentence = pad_make($w['course'], 'sentence', 'f-sentence', [$w['words']['mother']]);
    $dictationPending = pad_make($w['course'], 'dictation', 'f-dictation-pending');
    $dictationReady = pad_make($w['course'], 'dictation', 'f-dictation-ready');
    PracticeItemVersion::query()->where('item_id', $dictationReady->id)->update(['audio_status' => AudioStatus::Ready->value]);
    $dictationFailed = pad_make($w['course'], 'dictation', 'f-dictation-failed');
    PracticeItemVersion::query()->where('item_id', $dictationFailed->id)->update(['audio_status' => AudioStatus::Failed->value]);
    $foreign = pad_make($other['course'], 'collocation', 'f-foreign');
    pad_publish($sentence, $this->admin);
    app(PracticeItemService::class)->archive($foreign, $this->admin);

    $list = livewire(ListPracticeItems::class);

    $list->filterTable('course_id', $other['course']->id)->assertCanSeeTableRecords([$foreign])->assertCanNotSeeTableRecords([$sentence, $dictationReady])
        ->resetTableFilters()
        ->filterTable('type', 'dictation')->assertCanSeeTableRecords([$dictationPending, $dictationReady, $dictationFailed])->assertCanNotSeeTableRecords([$sentence, $foreign])
        ->resetTableFilters()
        ->filterTable('status', 'published')->assertCanSeeTableRecords([$sentence])->assertCanNotSeeTableRecords([$dictationPending, $foreign])
        ->resetTableFilters()
        ->filterTable('status', 'archived')->assertCanSeeTableRecords([$foreign])->assertCanNotSeeTableRecords([$sentence])
        ->resetTableFilters()
        ->filterTable('audio', 'pending')->assertCanSeeTableRecords([$dictationPending])->assertCanNotSeeTableRecords([$dictationReady, $dictationFailed, $sentence])
        ->resetTableFilters()
        ->filterTable('audio', 'ready')->assertCanSeeTableRecords([$dictationReady])->assertCanNotSeeTableRecords([$dictationPending])
        ->resetTableFilters()
        ->filterTable('audio', 'failed')->assertCanSeeTableRecords([$dictationFailed])->assertCanNotSeeTableRecords([$dictationPending])
        ->resetTableFilters()
        // a value the client made up counts as no filter
        ->filterTable('audio', "ready' OR '1'='1")->assertCanSeeTableRecords([$sentence, $dictationReady, $dictationPending, $foreign]);
});

it('finds a task by its key', function (): void {
    $w = pad_world();
    $a = pad_make($w['course'], 'sentence', 'alpha-one');
    $b = pad_make($w['course'], 'sentence', 'beta-two');

    livewire(ListPracticeItems::class)->searchTable('alpha')->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b]);
});

it('names the audio of the current version of a dictation, ready or failed', function (): void {
    $w = pad_world();
    $ready = pad_make($w['course'], 'dictation', 'a-ready');
    $failed = pad_make($w['course'], 'dictation', 'a-failed');
    PracticeItemVersion::query()->where('item_id', $ready->id)->update(['audio_status' => 'ready']);
    PracticeItemVersion::query()->where('item_id', $failed->id)->update(['audio_status' => 'failed']);

    livewire(ListPracticeItems::class)
        ->assertTableColumnFormattedStateSet('audio', 'Готово', $ready)
        ->assertTableColumnFormattedStateSet('audio', 'Ошибка', $failed);
});

// --- the forms by type ----------------------------------------------------------------------------------------------------------

it('shows the fields of the chosen type only', function (): void {
    $w = pad_world();

    livewire(CreatePracticeItem::class)
        ->fillForm(['course_id' => $w['course']->id, 'type' => 'sentence'])
        ->assertFormFieldIsVisible('sentence.fragments')
        ->assertFormFieldIsVisible('sentence.accepted')
        ->assertFormFieldIsHidden('collocation.text')
        ->assertFormFieldIsHidden('dictation.text')
        ->fillForm(['type' => 'collocation'])
        ->assertFormFieldIsVisible('collocation.text')
        ->assertFormFieldIsVisible('collocation.options')
        ->assertFormFieldIsHidden('sentence.fragments')
        ->assertFormFieldIsHidden('dictation.text')
        ->fillForm(['type' => 'dictation'])
        ->assertFormFieldIsVisible('dictation.text')
        ->assertFormFieldIsVisible('dictation.accepted')
        ->assertFormFieldIsHidden('collocation.text')
        ->assertFormFieldIsHidden('sentence.fragments');
});

it('offers the active words of the chosen course as target words, found by the word or its translation', function (): void {
    $w = pad_world();
    $other = pad_world('pad-other');
    Word::factory()->for($other['l11'])->create(['word' => 'stranger', 'translation' => 'чужак']);

    $mother = $w['words']['mother']->id;
    $tomorrow = $w['words']['tomorrow']->id;

    livewire(CreatePracticeItem::class)
        ->fillForm(['course_id' => $w['course']->id])
        ->assertFormFieldExists('word_ids', function (Select $field) use ($mother, $tomorrow, $w): bool {
            $options = $field->getOptions();

            // the active words of THIS course in the study order; no inactive word, no word of another course
            return array_keys($options) === [$mother, $tomorrow, $w['words']['meeting']->id]
                && $options[$mother] === 'mother — мать'
                && $field->getSearchResults('встреч') === [$w['words']['meeting']->id => 'meeting — встреча']
                && $field->getSearchResults('ghost') === []
                && $field->getSearchResults('stranger') === [];
        })
        ->assertFormFieldExists('lesson_id', fn (Select $field): bool => array_values($field->getOptions()) === ['Модуль 1 · Урок 1', 'Модуль 1 · Урок 2', 'Модуль 2 · Урок 1']);
});

it('creates a sentence from the form: a draft with the first version, the lesson and the target words', function (): void {
    $w = pad_world();

    livewire(CreatePracticeItem::class)
        ->fillForm([
            'course_id' => $w['course']->id,
            'type' => 'sentence',
            'key' => 'form-sentence',
            'level' => 'A2',
            'lesson_id' => $w['l11']->id,
            'word_ids' => [$w['words']['mother']->id],
            'sentence' => [
                'prompt' => ['ru' => 'Я обычно пью чай.', 'uz' => 'Men odatda choy ichaman.'],
                'fragments' => "I\nusually\ndrink\ntea\n",
                'accepted' => "I | usually | drink | tea\n\nusually | I | drink | tea",
                'display' => 'I usually drink tea.',
                'explanation' => ['ru' => 'Наречие стоит перед глаголом.', 'uz' => "Ravish fe'ldan oldin keladi."],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $item = PracticeItem::query()->where('key', 'form-sentence')->sole();
    $version = PracticeItemVersion::query()->findOrFail($item->current_version_id);

    expect($item->status)->toBe(PracticeItemStatus::Draft)
        ->and($item->type)->toBe(PracticeItemType::Sentence)
        ->and($item->level)->toBe('A2')
        ->and($item->lesson_id)->toBe($w['l11']->id)
        ->and($item->created_by_user_id)->toBe($this->admin->id)
        ->and($version->number)->toBe(1)
        ->and($version->state)->toBe(VersionState::Draft)
        ->and($version->content['fragments'])->toBe(['I', 'usually', 'drink', 'tea'])
        ->and($version->content['accepted'])->toBe([[0, 1, 2, 3], [1, 0, 2, 3]])
        ->and($version->content['prompt'])->toBe(['ru' => 'Я обычно пью чай.', 'uz' => 'Men odatda choy ichaman.'])
        ->and(PracticeItemWord::query()->where('item_id', $item->id)->pluck('word_id')->all())->toBe([$w['words']['mother']->id]);
});

it('takes the order of the fragments as the only accepted one when the field of the orders is empty', function (): void {
    $w = pad_world();

    livewire(CreatePracticeItem::class)
        ->fillForm([
            'course_id' => $w['course']->id,
            'type' => 'sentence',
            'key' => 'form-simple',
            'sentence' => [
                'prompt' => ['ru' => 'Это кот.', 'uz' => 'Bu mushuk.'],
                'fragments' => "this\nis\na\ncat",
                'accepted' => '',
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $version = PracticeItemVersion::query()->findOrFail(PracticeItem::query()->where('key', 'form-simple')->sole()->current_version_id);
    expect($version->content['accepted'])->toBe([[0, 1, 2, 3]]);
});

it('creates a collocation from the form: three options, the correct ones marked', function (): void {
    $w = pad_world();

    livewire(CreatePracticeItem::class)
        ->fillForm([
            'course_id' => $w['course']->id,
            'type' => 'collocation',
            'key' => 'form-collocation',
            'word_ids' => [$w['words']['meeting']->id],
            'collocation' => [
                'text' => "Let's ___ a meeting on Monday.",
                'options' => [
                    ['text' => 'have', 'correct' => true],
                    ['text' => 'make', 'correct' => false],
                    ['text' => 'hold', 'correct' => true],
                ],
                'translation' => ['ru' => 'Давайте проведём встречу.', 'uz' => "Uchrashuv o'tkazaylik."],
                'explanation' => ['ru' => 'have / hold a meeting.', 'uz' => 'have / hold a meeting.'],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $version = PracticeItemVersion::query()->findOrFail(PracticeItem::query()->where('key', 'form-collocation')->sole()->current_version_id);
    expect($version->content['options'])->toBe(['have', 'make', 'hold'])
        ->and($version->content['correct'])->toBe([0, 2])
        ->and($version->content['text'])->toBe("Let's ___ a meeting on Monday.");
});

it('creates a dictation from the form and queues its voicing after the commit', function (): void {
    $w = pad_world();

    livewire(CreatePracticeItem::class)
        ->fillForm([
            'course_id' => $w['course']->id,
            'type' => 'dictation',
            'key' => 'form-dictation',
            'word_ids' => [$w['words']['tomorrow']->id],
            'dictation' => [
                'text' => 'See you tomorrow',
                'accepted' => "See you tomorrow!\nSee ya tomorrow",
                'translation' => ['ru' => 'До завтра', 'uz' => 'Ertagacha'],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $item = PracticeItem::query()->where('key', 'form-dictation')->sole();
    $version = PracticeItemVersion::query()->findOrFail($item->current_version_id);
    expect($version->content['accepted'])->toBe(['See you tomorrow', 'See ya tomorrow'])
        ->and($version->content['explanation'])->toBeNull()
        ->and($version->audio_status)->toBe(AudioStatus::Pending);
    Queue::assertPushed(GeneratePracticeAudioJob::class, fn (GeneratePracticeAudioJob $job): bool => $job->versionId === $version->id && $job->afterCommit === true);
});

it('checks the key in the form: its shape, and that the course has no such key yet', function (): void {
    $w = pad_world();
    pad_make($w['course'], 'sentence', 'taken');
    $valid = ['course_id' => $w['course']->id, 'type' => 'dictation', 'dictation' => ['text' => 'Hello there']];

    livewire(CreatePracticeItem::class)
        ->fillForm([...$valid, 'key' => 'Not Valid'])
        ->call('create')
        ->assertHasFormErrors(['key' => 'regex']);

    livewire(CreatePracticeItem::class)
        ->fillForm([...$valid, 'key' => 'taken'])
        ->call('create')
        ->assertHasFormErrors(['key' => 'unique']);

    // The same key in ANOTHER course is fine.
    $other = pad_world('pad-other');
    livewire(CreatePracticeItem::class)
        ->fillForm([...$valid, 'course_id' => $other['course']->id, 'key' => 'taken'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(PracticeItem::query()->where('key', 'taken')->count())->toBe(2);
});

it('requires the main text of the type in the form', function (): void {
    $w = pad_world();

    livewire(CreatePracticeItem::class)
        ->fillForm(['course_id' => $w['course']->id, 'type' => 'sentence', 'key' => 'no-fragments', 'sentence' => ['fragments' => '']])
        ->call('create')
        ->assertHasFormErrors(['sentence.fragments' => 'required']);

    livewire(CreatePracticeItem::class)
        ->fillForm(['course_id' => $w['course']->id, 'type' => 'collocation', 'key' => 'no-text', 'collocation' => ['text' => '']])
        ->call('create')
        ->assertHasFormErrors(['collocation.text' => 'required']);

    livewire(CreatePracticeItem::class)
        ->fillForm(['course_id' => $w['course']->id, 'type' => 'dictation', 'key' => 'no-phrase', 'dictation' => ['text' => '']])
        ->call('create')
        ->assertHasFormErrors(['dictation.text' => 'required']);

    expect(PracticeItem::query()->count())->toBe(0);
});

it('says in Russian why the text is not accepted and keeps the form: nothing is written', function (): void {
    $w = pad_world();
    pad_sent();

    livewire(CreatePracticeItem::class)
        ->fillForm([
            'course_id' => $w['course']->id,
            'type' => 'collocation',
            'key' => 'bad-gap',
            'collocation' => [
                'text' => 'Let us have a meeting.',
                'options' => [['text' => 'have', 'correct' => true], ['text' => 'make', 'correct' => false], ['text' => 'do', 'correct' => false]],
            ],
        ])
        ->call('create');

    expect(pad_sent())->toBe([['title' => 'Не получилось', 'body' => 'В тексте должен быть ровно один пропуск из трёх подчёркиваний (___).']])
        ->and(PracticeItem::query()->count())->toBe(0);

    livewire(CreatePracticeItem::class)
        ->fillForm([
            'course_id' => $w['course']->id,
            'type' => 'collocation',
            'key' => 'no-correct',
            'collocation' => [
                'text' => "Let's ___ a meeting.",
                'options' => [['text' => 'have', 'correct' => false], ['text' => 'make', 'correct' => false], ['text' => 'do', 'correct' => false]],
            ],
        ])
        ->call('create');

    expect(pad_sent()[0]['body'])->toBe('Отметьте верным один или два варианта из трёх.');

    livewire(CreatePracticeItem::class)
        ->fillForm([
            'course_id' => $w['course']->id,
            'type' => 'sentence',
            'key' => 'bad-order',
            'word_ids' => [$w['words']['ghost']->id],
            'sentence' => ['prompt' => ['ru' => 'Это кот.'], 'fragments' => "this\nis\na\ncat"],
        ])
        ->call('create');

    expect(pad_sent()[0]['body'])->toContain('«'.$w['words']['ghost']->id.'»')->toContain('не найдено среди активных слов курса');
});

it('fills the edit form with the text of the current version in the way it is typed', function (): void {
    $w = pad_world();
    $item = pad_make($w['course'], 'sentence', 'edit-sentence', [$w['words']['mother']], [
        'fragments' => ['I', 'usually', 'drink', 'tea'],
        'accepted' => [['I', 'usually', 'drink', 'tea'], ['usually', 'I', 'drink', 'tea']],
        'display' => 'I usually drink tea.',
    ]);
    $collocation = pad_make($w['course'], 'collocation', 'edit-collocation', [], ['correct' => ['make', 'do']]);
    $dictation = pad_make($w['course'], 'dictation', 'edit-dictation', [], ['accepted' => ['See you tomorrow', 'See ya tomorrow']]);

    livewire(EditPracticeItem::class, ['record' => $item->getKey()])
        ->assertFormSet([
            'course_id' => $w['course']->id,
            'type' => 'sentence',
            'key' => 'edit-sentence',
            'level' => 'A1',
            'word_ids' => [$w['words']['mother']->id],
            'sentence.fragments' => "I\nusually\ndrink\ntea",
            'sentence.accepted' => "I | usually | drink | tea\nusually | I | drink | tea",
            'sentence.display' => 'I usually drink tea.',
            'sentence.prompt.ru' => 'Моя мама — учитель.',
        ]);

    $options = livewire(EditPracticeItem::class, ['record' => $collocation->getKey()])->instance()->data['collocation']['options'];
    expect(array_values($options))->toBe([
        ['text' => 'have', 'correct' => false],
        ['text' => 'make', 'correct' => true],
        ['text' => 'do', 'correct' => true],
    ]);

    livewire(EditPracticeItem::class, ['record' => $dictation->getKey()])
        ->assertFormSet(['dictation.text' => 'See you tomorrow', 'dictation.accepted' => 'See ya tomorrow']);
});

it('saves an edit as a new version, keeps the course, the type and the key whatever the form says, and writes nothing for no change', function (): void {
    $w = pad_world();
    $other = pad_world('pad-other');
    $item = pad_make($w['course'], 'sentence', 'edit-me', [$w['words']['mother']]);
    $first = $item->current_version_id;

    // No change: no new version.
    livewire(EditPracticeItem::class, ['record' => $item->getKey()])->call('save')->assertHasNoFormErrors();
    expect(PracticeItemVersion::query()->where('item_id', $item->id)->count())->toBe(1);

    livewire(EditPracticeItem::class, ['record' => $item->getKey()])
        ->fillForm([
            'course_id' => $other['course']->id,
            'key' => 'tampered',
            'sentence' => ['prompt' => ['ru' => 'Моя мама — врач.', 'uz' => 'Onam shifokor.'], 'fragments' => "my\nmother\nis\na\ndoctor", 'accepted' => '', 'display' => ''],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $item->refresh();
    $second = PracticeItemVersion::query()->findOrFail($item->current_version_id);
    expect($item->key)->toBe('edit-me')
        ->and($item->type)->toBe(PracticeItemType::Sentence)
        ->and($item->course_id)->toBe($w['course']->id)
        ->and($item->current_version_id)->not->toBe($first)
        ->and($second->number)->toBe(2)
        ->and($second->content['fragments'])->toBe(['my', 'mother', 'is', 'a', 'doctor'])
        ->and(PracticeItemVersion::query()->findOrFail($first)->content['fragments'])->toBe(['my', 'mother', 'is', 'a', 'teacher'])
        ->and(PracticeItem::query()->where('key', 'tampered')->exists())->toBeFalse();
});

it('keeps a published task published with the old version handed out while its edit is a draft', function (): void {
    $w = pad_world();
    $item = pad_publish(pad_make($w['course'], 'sentence', 'live', [$w['words']['mother']]), $this->admin);
    $published = $item->published_version_id;

    livewire(EditPracticeItem::class, ['record' => $item->getKey()])
        ->fillForm(['sentence' => ['explanation' => ['ru' => 'Новое пояснение.', 'uz' => 'Yangi izoh.']]])
        ->call('save')
        ->assertHasNoFormErrors();

    $item->refresh();
    expect($item->status)->toBe(PracticeItemStatus::Published)
        ->and($item->published_version_id)->toBe($published)
        ->and($item->current_version_id)->not->toBe($published)
        ->and(PracticeItemVersion::query()->findOrFail($item->current_version_id)->state)->toBe(VersionState::Draft);
});

it('refuses to save an archived task and shows the reason', function (): void {
    $w = pad_world();
    $item = pad_make($w['course'], 'sentence', 'in-archive');
    app(PracticeItemService::class)->archive($item, $this->admin);
    pad_sent();

    livewire(EditPracticeItem::class, ['record' => $item->getKey()])
        ->fillForm(['sentence' => ['explanation' => ['ru' => 'Поздно.', 'uz' => 'Kech.']]])
        ->call('save');

    expect(pad_sent())->toBe([['title' => 'Не получилось', 'body' => 'Задание в архиве: сначала верните его из архива.']])
        ->and(PracticeItemVersion::query()->where('item_id', $item->id)->count())->toBe(1);
});

// --- the buttons ---------------------------------------------------------------------------------------------------------------------

it('marks a draft as reviewed from the list, then publishes it, with a notification and an audit record each', function (): void {
    $w = pad_world();
    $item = pad_make($w['course'], 'sentence', 'walk', [$w['words']['mother']]);
    pad_sent();

    livewire(ListPracticeItems::class)
        ->assertTableActionVisible('review', $item)
        ->assertTableActionHidden('publish', $item)
        ->assertTableActionHidden('restore', $item)
        ->callTableAction('review', $item);

    expect(pad_sent())->toBe([['title' => 'Версия отмечена проверенной', 'body' => null]])
        ->and($item->fresh()?->status)->toBe(PracticeItemStatus::Reviewed)
        ->and(AuditLog::query()->where('action', 'practice_item.reviewed')->sole()->user_id)->toBe($this->admin->id);

    livewire(ListPracticeItems::class)
        ->assertTableActionHidden('review', $item->fresh())
        ->assertTableActionVisible('publish', $item->fresh())
        ->callTableAction('publish', $item->fresh());

    expect(pad_sent())->toBe([['title' => 'Задание опубликовано', 'body' => null]])
        ->and($item->fresh()?->status)->toBe(PracticeItemStatus::Published)
        ->and($item->fresh()?->published_version_id)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'practice_item.published')->count())->toBe(1);
});

it('says in Russian why a task cannot be published', function (): void {
    $w = pad_world();
    $noWords = pad_make($w['course'], 'sentence', 'no-words');
    $oneLanguage = pad_make($w['course'], 'collocation', 'one-language', [$w['words']['meeting']], ['translation' => ['ru' => 'Только по-русски']]);
    app(PracticeItemService::class)->markReviewed($noWords, $this->admin);
    app(PracticeItemService::class)->markReviewed($oneLanguage, $this->admin);
    pad_sent();

    livewire(ListPracticeItems::class)->callTableAction('publish', $noWords);
    expect(pad_sent())->toBe([['title' => 'Не получилось', 'body' => 'Для публикации нужно хотя бы одно целевое слово.']]);

    livewire(ListPracticeItems::class)->callTableAction('publish', $oneLanguage);
    expect(pad_sent())->toBe([['title' => 'Не получилось', 'body' => 'Для публикации нужен текст поля «Перевод» на языке UZ.']])
        ->and($noWords->fresh()?->status)->toBe(PracticeItemStatus::Reviewed)
        ->and($oneLanguage->fresh()?->status)->toBe(PracticeItemStatus::Reviewed);
});

it('archives and restores a task from the list', function (): void {
    $w = pad_world();
    $item = pad_publish(pad_make($w['course'], 'sentence', 'arch', [$w['words']['mother']]), $this->admin);
    pad_sent();

    livewire(ListPracticeItems::class)
        ->assertTableActionVisible('archive', $item)
        ->callTableAction('archive', $item);

    expect(pad_sent())->toBe([['title' => 'Задание в архиве', 'body' => null]])
        ->and($item->fresh()?->status)->toBe(PracticeItemStatus::Archived);

    livewire(ListPracticeItems::class)
        ->assertTableActionHidden('archive', $item->fresh())
        ->assertTableActionHidden('review', $item->fresh())
        ->assertTableActionHidden('publish', $item->fresh())
        ->assertTableActionVisible('restore', $item->fresh())
        ->callTableAction('restore', $item->fresh());

    expect(pad_sent())->toBe([['title' => 'Задание возвращено из архива', 'body' => null]])
        ->and($item->fresh()?->status)->toBe(PracticeItemStatus::Published)
        ->and(AuditLog::query()->whereIn('action', ['practice_item.archived', 'practice_item.restored'])->count())->toBe(2);
});

it('offers the same buttons on the edit page and moves the task from there', function (): void {
    $w = pad_world();
    $item = pad_make($w['course'], 'sentence', 'header', [$w['words']['mother']]);

    $page = livewire(EditPracticeItem::class, ['record' => $item->getKey()])
        ->assertActionVisible('review')
        ->assertActionHidden('publish')
        ->assertActionHidden('restore')
        ->assertActionVisible('archive')
        ->callAction('review');

    expect($item->fresh()?->status)->toBe(PracticeItemStatus::Reviewed);

    $page->assertActionHidden('review')->assertActionVisible('publish')->callAction('publish');
    expect($item->fresh()?->status)->toBe(PracticeItemStatus::Published);

    $page->callAction('archive');
    expect($item->fresh()?->status)->toBe(PracticeItemStatus::Archived);
    $page->assertActionVisible('restore')->assertActionHidden('archive')->callAction('restore');
    expect($item->fresh()?->status)->toBe(PracticeItemStatus::Published);
});

it('voices a dictation again from the list and from the edit page, and offers it for no other type', function (): void {
    $w = pad_world();
    $dictation = pad_make($w['course'], 'dictation', 'voice');
    $sentence = pad_make($w['course'], 'sentence', 'mute');
    PracticeItemVersion::query()->where('item_id', $dictation->id)->update(['audio_status' => AudioStatus::Ready->value]);
    Queue::fake();
    pad_sent();

    livewire(ListPracticeItems::class)
        ->assertTableActionVisible('regenerateAudio', $dictation)
        ->assertTableActionHidden('regenerateAudio', $sentence)
        ->callTableAction('regenerateAudio', $dictation);

    $version = PracticeItemVersion::query()->where('item_id', $dictation->id)->sole();
    expect(pad_sent())->toBe([['title' => 'Озвучка поставлена в очередь', 'body' => null]])
        ->and($version->audio_status)->toBe(AudioStatus::Pending);
    Queue::assertPushed(GeneratePracticeAudioJob::class, fn (GeneratePracticeAudioJob $job): bool => $job->versionId === $version->id && $job->force && $job->afterCommit === true);

    livewire(EditPracticeItem::class, ['record' => $dictation->getKey()])->assertActionVisible('regenerateAudio')->callAction('regenerateAudio');
    Queue::assertPushed(GeneratePracticeAudioJob::class, 2);
    livewire(EditPracticeItem::class, ['record' => $sentence->getKey()])->assertActionHidden('regenerateAudio');
});

// --- the import page ---------------------------------------------------------------------------------------------------------------------

/** The sample of the page imported into the demo content (its words are the ones of the sample lesson). */
function pad_sample(): string
{
    return (string) file_get_contents(storage_path('app/private/samples/sample-practice.json'));
}

it('renders the import page and offers the sample file', function (): void {
    livewire(ImportPracticeItems::class)
        ->assertOk()
        ->assertSee('Файл заданий (JSON)')
        ->assertSee('Опубликовать сразу (только проверенный контент)')
        ->assertSee('Скачать пример')
        ->assertSee('Импортировать')
        ->assertDontSee('Download sample');

    expect(ImportPracticeItems::getNavigationLabel())->toBe('Импорт заданий')
        ->and(ImportPracticeItems::getNavigationGroup())->toBe('Практика');

    livewire(ImportPracticeItems::class)->callAction('downloadSample')->assertOk();
});

it('imports the sample of the page: it is a valid file for the demo content', function (): void {
    $this->seed(DemoContentSeeder::class);

    $report = app(PracticeImporter::class)->importAsSystem(pad_sample(), false);

    expect($report->errors)->toBe([])
        ->and($report->added)->toBe(3);
});

it('imports a file through the page as drafts and reports what was done', function (): void {
    $this->seed(DemoContentSeeder::class);
    pad_sent();

    livewire(ImportPracticeItems::class)
        ->set('data.file', UploadedFile::fake()->createWithContent('practice.json', pad_sample()))
        ->call('submit')
        ->assertHasNoErrors()
        ->assertNotified('Импорт выполнен: новых — 3, обновлено — 0, пропущено — 0, опубликовано — 0.')
        ->assertSee('Отчёт о последнем импорте')
        ->assertSee('Добавлено')
        ->assertSee('Опубликовано')
        ->assertSee('Прервано');

    expect(PracticeItem::query()->count())->toBe(3)
        ->and(PracticeItem::query()->where('status', 'draft')->count())->toBe(3)
        ->and(AuditLog::query()->where('action', 'practice.imported')->sole()->user_id)->toBe($this->admin->id);
});

it('publishes the tasks of the file at once only with the box ticked', function (): void {
    $this->seed(DemoContentSeeder::class);

    livewire(ImportPracticeItems::class)
        ->set('data.file', UploadedFile::fake()->createWithContent('practice.json', pad_sample()))
        ->set('data.publish', true)
        ->call('submit')
        ->assertNotified('Импорт выполнен: новых — 3, обновлено — 0, пропущено — 0, опубликовано — 3.');

    expect(PracticeItem::query()->where('status', 'published')->count())->toBe(3);

    // The same file again: nothing changes.
    livewire(ImportPracticeItems::class)
        ->set('data.file', UploadedFile::fake()->createWithContent('practice.json', pad_sample()))
        ->call('submit')
        ->assertNotified('Импорт выполнен: новых — 0, обновлено — 0, пропущено — 3, опубликовано — 0.');
});

it('takes the course from the file: a file for another course goes there', function (): void {
    $this->seed(DemoContentSeeder::class);
    $w = pad_world();
    $file = json_encode([
        'format' => 'lexiflow-practice/1', 'course' => 'pad',
        'items' => [['key' => 'other-course', 'type' => 'dictation', 'lesson' => ['stage' => 2, 'number' => 1], 'target_words' => ['meeting'], 'text' => 'The meeting is at ten', 'accepted' => [], 'translation' => ['ru' => 'Встреча в десять', 'uz' => "Yig'ilish o'nda"]]],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

    livewire(ImportPracticeItems::class)
        ->set('data.file', UploadedFile::fake()->createWithContent('practice.json', $file))
        ->call('submit')
        ->assertNotified('Импорт выполнен: новых — 1, обновлено — 0, пропущено — 0, опубликовано — 0.');

    $item = PracticeItem::query()->sole();
    expect($item->course_id)->toBe($w['course']->id)
        ->and($item->lesson_id)->toBe($w['l21']->id);
});

it('lists every refusal of a file in Russian with the number of the task and its key, and writes nothing', function (): void {
    $this->seed(DemoContentSeeder::class);
    $file = json_encode([
        'format' => 'lexiflow-practice/1', 'course' => 'default',
        'items' => [
            ['key' => 'good', 'type' => 'dictation', 'target_words' => ['tomorrow'], 'text' => 'See you tomorrow', 'translation' => ['ru' => 'До завтра', 'uz' => 'Ertagacha']],
            ['key' => 'no-gap', 'type' => 'collocation', 'target_words' => ['tomorrow'], 'text' => 'Let us meet tomorrow.', 'options' => ['have', 'make', 'do'], 'correct' => ['have']],
            ['key' => 'ghost-word', 'type' => 'dictation', 'target_words' => ['zebra'], 'text' => 'Hello'],
            ['key' => 'typo', 'type' => 'dictation', 'texts' => 'Hello'],
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    pad_sent();

    $page = livewire(ImportPracticeItems::class)
        ->set('data.file', UploadedFile::fake()->createWithContent('practice.json', $file))
        ->call('submit');

    // The first lines of the refusal are in the notification, all of them on the page.
    $sent = pad_sent();
    expect($sent)->toHaveCount(1)
        ->and($sent[0]['title'])->toBe('Импорт не выполнен — ничего не записано.')
        ->and($sent[0]['body'])->toContain('Задание №2 («no-gap»)');

    $page
        ->assertSee('Задание №2 («no-gap»): в "text" должен быть ровно один пропуск из трёх подчёркиваний (___).')
        ->assertSee('Задание №3 («ghost-word»): целевое слово «zebra» не найдено среди активных слов курса.')
        ->assertSee('Задание №4 («typo»): неизвестное поле «texts» — проверьте написание, иначе текст потеряется.')
        ->assertDontSee('Task 2')
        ->assertDontSee('admin.pages');

    expect(PracticeItem::query()->count())->toBe(0);
});

it('words the refusals of the file itself in Russian', function (string $json, string $text): void {
    $this->seed(DemoContentSeeder::class);

    livewire(ImportPracticeItems::class)
        ->set('data.file', UploadedFile::fake()->createWithContent('practice.json', $json))
        ->call('submit')
        ->assertSee($text);
})->with([
    'not JSON' => ['not-json', 'Файл не удалось прочитать как JSON: ошибка в синтаксисе. Проверьте запятые, кавычки и скобки.'],
    'a list' => ['[1]', 'В начале файла должен стоять объект JSON ({ ... }), а не список или отдельное значение.'],
    'another format' => ['{"format": "x", "course": "default", "items": [{}]}', 'Формат файла не поддерживается («x»): в поле "format" должно стоять "lexiflow-practice/1".'],
    'a course that does not exist' => ['{"format": "lexiflow-practice/1", "course": "nope", "items": [{}]}', 'Курс «nope» не найден: в поле "course" укажите код существующего курса (раздел «Курсы»).'],
    'no items' => ['{"format": "lexiflow-practice/1", "course": "default"}', 'В файле нужен непустой список "items" — от 1 до 300 заданий.'],
    'a field of the file that is not one' => ['{"format": "lexiflow-practice/1", "course": "default", "publish": true, "items": [{}]}', 'В файле неизвестное поле «publish». Допустимы только "format", "course" и "items": публикация задаётся флажком на странице, а не в файле.'],
]);

it('lets no teacher and no outsider import through the page', function (): void {
    $this->seed(DemoContentSeeder::class);
    $this->actingAs(pad_user('teacher'));

    // The page itself is closed (403 above); the service behind it refuses a person who may not import, too.
    $report = app(PracticeImporter::class)->import(pad_sample(), pad_user('teacher'), false);

    expect($report->aborted)->toBeTrue()
        ->and($report->issues[0]->code)->toBe('not_allowed')
        ->and(AdminErrors::practiceImportIssue($report->issues[0]))->toBe('Импортировать задания практики может только администратор платформы.')
        ->and(PracticeItem::query()->count())->toBe(0);
});

// --- Russian texts of every error --------------------------------------------------------------------------------------------------

it('has a Russian sentence in the editor for every code of the text and every refusal of a move', function (): void {
    $codes = [];
    foreach ((new ReflectionClass(PracticeContentException::class))->getConstants() as $name => $value) {
        // `audio_not_ready` of §4.5 is no refusal: the audio does not hold publishing back.
        if (is_string($value) && ! in_array($name, [], true)) {
            $codes[] = $value;
        }
    }
    foreach ((new ReflectionClass(PracticeItemStateException::class))->getConstants() as $value) {
        $codes[] = $value;
    }

    expect(count($codes))->toBeGreaterThanOrEqual(30);
    foreach ($codes as $code) {
        $text = AdminErrors::text('practice_items', $code, ['field' => 'Текст', 'max' => 5, 'min' => 3, 'count' => 3, 'index' => 1, 'word' => 'x', 'option' => 'y', 'locale' => 'UZ', 'key' => 'k']);

        expect($text)->not->toBe(__('admin.common.errors.unknown'), "no sentence for {$code}")
            ->and(preg_match('/\p{Cyrillic}/u', $text))->toBe(1, "{$code} is not Russian: {$text}")
            ->and($text)->not->toContain('admin.')->not->toMatch('/:[a-z_]+/');
    }
});

it('has a Russian sentence on the import page for every code an import can raise', function (): void {
    $codes = [
        ImportIssue::FILE_TOO_LARGE, ImportIssue::INVALID_JSON, ImportIssue::ROOT_NOT_OBJECT, ImportIssue::COURSE_NOT_FOUND, ImportIssue::NOT_ALLOWED,
        PracticeImporter::FORMAT_UNSUPPORTED, PracticeImporter::ITEMS_REQUIRED, PracticeImporter::ITEMS_TOO_MANY, PracticeImporter::ITEM_INVALID,
        PracticeImporter::FIELD_UNKNOWN, PracticeImporter::FIELD_INVALID, PracticeImporter::KEY_DUPLICATE_IN_FILE, PracticeImporter::KEY_TYPE_MISMATCH,
        PracticeImporter::ITEM_ARCHIVED,
    ];
    foreach ((new ReflectionClass(PracticeContentException::class))->getConstants() as $value) {
        $codes[] = $value;
    }

    $context = ['position' => 2, 'key' => 'k-2', 'field' => 'fragments', 'max' => 5, 'min' => 3, 'count' => 3, 'index' => 1, 'word' => 'zebra', 'option' => 'y', 'locale' => 'uz',
        'slug' => 's', 'format' => 'f', 'expected' => 'lexiflow-practice/1', 'megabytes' => 2, 'first_position' => 1, 'existing_type' => 'sentence', 'stage' => 1, 'number' => 2];

    foreach (array_unique($codes) as $code) {
        $text = AdminErrors::practiceImportIssue(new ImportIssue($code, 'English', $context));

        expect($text)->not->toBe(__('admin.common.errors.unknown'), "no sentence for {$code}")
            ->and(preg_match('/\p{Cyrillic}/u', $text))->toBe(1, "{$code} is not Russian: {$text}")
            ->and($text)->not->toContain('English')->not->toContain('admin.')->not->toMatch('/:[a-z_]+\b/');
    }
});

it('picks the sentence of the cause of a dictation fault and of a bad JSON file', function (): void {
    $dictation = fn (string $cause): string => AdminErrors::practiceImportIssue(new ImportIssue('dictation_accepted_invalid', 'English', ['position' => 1, 'key' => 'k', 'cause' => $cause, 'max' => 5]));

    expect($dictation('count'))->toContain('не больше 5')
        ->and($dictation('length'))->toContain('длиннее 5')
        ->and($dictation('empty'))->toContain('пуста')
        ->and($dictation('shape'))->toContain('списком строк')
        ->and(AdminErrors::practiceImportIssue(new ImportIssue('field_unknown', 'English', ['field' => 'publish', 'cause' => 'file'])))->toContain('В файле неизвестное поле «publish»')
        ->and(AdminErrors::practiceImportIssue(new ImportIssue('brand_new_code', 'English')))->toBe(__('admin.common.errors.unknown'));
});

it('names the field of a refusal in the editor by its caption', function (): void {
    $text = AdminErrors::for('practice_items', new PracticeContentException(PracticeContentException::TEXT_TOO_LONG, 'explanation', 'English', ['max' => 300, 'locale' => 'ru']));

    expect($text)->toBe('Поле «Пояснение» длиннее 300 символов.')
        ->and(AdminErrors::for('practice_items', PracticeItemStateException::notReviewed()))->toContain('сначала нажмите «Проверено»');
});

// --- the pilot of a learner --------------------------------------------------------------------------------------------------------------------

it('puts a learner into the pilot and out of it from the page of the learner, with the audit trail', function (): void {
    $learner = Learner::factory()->create();
    pad_sent();

    $page = livewire(ViewLearner::class, ['record' => $learner->getKey()])
        ->assertActionVisible('practicePilot')
        ->assertActionHasLabel('practicePilot', 'Включить в пилот практики')
        ->callAction('practicePilot');

    $in = $learner->fresh()?->practice_pilot_at;
    expect($in)->not->toBeNull()
        ->and(pad_sent())->toBe([['title' => 'Ученик включён в пилот практики', 'body' => null]]);

    $audit = AuditLog::query()->where('action', 'learner.practice_pilot_changed')->sole();
    expect($audit->user_id)->toBe($this->admin->id)
        ->and($audit->entity_type)->toBe(Learner::class)
        ->and($audit->entity_id)->toBe($learner->id)
        ->and($audit->meta['from'])->toBeNull()
        ->and($audit->meta['to'])->toBe($in?->toIso8601String());

    $page->assertActionHasLabel('practicePilot', 'Исключить из пилота')->callAction('practicePilot');

    $out = AuditLog::query()->where('action', 'learner.practice_pilot_changed')->latest('id')->firstOrFail();
    expect($learner->fresh()?->practice_pilot_at)->toBeNull()
        ->and(pad_sent())->toBe([['title' => 'Ученик исключён из пилота практики', 'body' => null]])
        ->and($out->meta['from'])->toBe($in?->toIso8601String())
        ->and($out->meta['to'])->toBeNull()
        ->and(AuditLog::query()->where('action', 'learner.practice_pilot_changed')->count())->toBe(2);
});

it('changes the pilot from the list too, shows who is in it and filters by it', function (): void {
    $in = Learner::factory()->create(['practice_pilot_at' => now()]);
    $out = Learner::factory()->create();

    livewire(ListLearners::class)
        ->assertTableColumnStateSet('practice_pilot', true, $in)
        ->assertTableColumnStateSet('practice_pilot', false, $out)
        ->filterTable('practice_pilot', true)
        ->assertCanSeeTableRecords([$in])
        ->assertCanNotSeeTableRecords([$out])
        ->filterTable('practice_pilot', false)
        ->assertCanSeeTableRecords([$out])
        ->assertCanNotSeeTableRecords([$in])
        ->resetTableFilters()
        ->callTableAction('practicePilot', $out);

    expect($out->fresh()?->practice_pilot_at)->not->toBeNull();

    livewire(ListLearners::class)->callTableAction('practicePilot', $in);
    expect($in->fresh()?->practice_pilot_at)->toBeNull();
});

it('shows the pilot to an admin only: a teacher sees neither the column, the filter nor the button', function (): void {
    $group = TelegramGroup::factory()->create();
    $teacher = pad_user('teacher');
    $teacher->telegramGroups()->attach($group->id, ['is_primary' => true]);
    $learner = Learner::factory()->memberOf($group)->create();
    $this->actingAs($teacher);

    livewire(ViewLearner::class, ['record' => $learner->getKey()])->assertActionHidden('practicePilot');
    livewire(ListLearners::class)
        ->assertTableActionHidden('practicePilot', $learner)
        ->assertTableColumnHidden('practice_pilot')
        ->assertTableFilterHidden('practice_pilot');
});

it('makes the practice open to the learner of the pilot and to no one else while the access is «pilot»', function (): void {
    config(['practice.access' => 'pilot', 'practice.plan' => 'all']);
    $learner = Learner::factory()->create();
    $access = app(PracticeAccess::class);

    expect($access->state($learner)->isAvailable())->toBeFalse();

    livewire(ViewLearner::class, ['record' => $learner->getKey()])->callAction('practicePilot');
    expect($access->state($learner->fresh() ?? $learner)->isAvailable())->toBeTrue();

    livewire(ViewLearner::class, ['record' => $learner->getKey()])->callAction('practicePilot');
    expect($access->state($learner->fresh() ?? $learner)->isAvailable())->toBeFalse();
});

it('does not change the pilot twice: a repeated move writes no second audit record', function (): void {
    $learner = Learner::factory()->create();
    $service = app(PracticePilotService::class);

    expect($service->include($learner, $this->admin))->toBeTrue()
        ->and($service->include($learner, $this->admin))->toBeFalse()
        ->and($service->exclude($learner, $this->admin))->toBeTrue()
        ->and($service->exclude($learner, $this->admin))->toBeFalse()
        ->and(AuditLog::query()->where('action', 'learner.practice_pilot_changed')->count())->toBe(2);
});
