<?php

declare(strict_types=1);

use App\Domain\Content\DTO\ImportIssue;
use App\Domain\Practice\Enums\AudioStatus;
use App\Domain\Practice\Enums\PracticeItemStatus;
use App\Domain\Practice\Enums\PracticeItemType;
use App\Domain\Practice\Enums\Verdict;
use App\Domain\Practice\Enums\VersionState;
use App\Domain\Practice\Exceptions\PracticeContentException;
use App\Domain\Practice\Exceptions\PracticeItemStateException;
use App\Domain\Practice\Services\Grading\SentenceGrader;
use App\Domain\Practice\Services\PracticeAccess;
use App\Domain\Practice\Services\PracticeImporter;
use App\Domain\Practice\Services\PracticeItemService;
use App\Domain\Practice\Services\PracticePilotService;
use App\Domain\Practice\Support\PracticeContentValidator;
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
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
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

/**
 * The content of the current version of a task in the form a save takes (texts, not indices).
 *
 * @return array<string, mixed>
 */
function pad_content(PracticeItem $item): array
{
    $version = PracticeItemVersion::query()->findOrFail($item->fresh()?->current_version_id);

    return app(PracticeContentValidator::class)->authorForm($item->type, $version->content);
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

it('shows the text a learner works on, not the prompt, and prints whatever an author wrote escaped', function (): void {
    $w = pad_world();
    $sentence = pad_make($w['course'], 'sentence', 'text-sentence');
    $dictation = pad_make($w['course'], 'dictation', 'text-dictation', content: ['text' => 'Tom & Jerry say "hello"']);

    livewire(ListPracticeItems::class)
        ->assertTableColumnStateSet('preview', 'My mother is a teacher.', $sentence)
        ->assertTableColumnStateSet('preview', 'Tom & Jerry say "hello"', $dictation)
        // `&` and quotes are text: the page prints them as entities, so nothing an author typed can become markup.
        ->assertSeeHtml('Tom &amp; Jerry say &quot;hello&quot;');
});

it('refuses a text with markup in the form and says so in Russian: nothing is written', function (): void {
    $w = pad_world();
    pad_sent();

    livewire(CreatePracticeItem::class)
        ->fillForm([
            'course_id' => $w['course']->id,
            'type' => 'dictation',
            'key' => 'with-markup',
            'dictation' => ['text' => 'Say <b>hello</b>'],
        ])
        ->call('create');

    expect(pad_sent())->toBe([['title' => 'Не получилось', 'body' => 'В поле «Текст» не должно быть знаков «<» и «>»: разметка не поддерживается, пишите обычный текст.']])
        ->and(PracticeItem::query()->count())->toBe(0);
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

it('shows the audio of the PUBLISHED version of a dictation, and notes a draft behind it whose audio is another', function (): void {
    $w = pad_world();
    $item = pad_publish(pad_make($w['course'], 'dictation', 'audio-served', [$w['words']['tomorrow']]), $this->admin);
    PracticeItemVersion::query()->where('item_id', $item->id)->update(['audio_status' => AudioStatus::Ready->value]);
    // A newer draft: its audio failed.
    $draft = app(PracticeItemService::class)->saveVersion($item->fresh() ?? $item, $w['course'], [
        'word_ids' => [$w['words']['tomorrow']->id], 'level' => 'A1',
        'content' => ['text' => 'See you soon', 'accepted' => [], 'translation' => ['ru' => 'Скоро увидимся', 'uz' => "Tez ko'rishamiz"]],
    ], $this->admin);
    $draft->forceFill(['audio_status' => AudioStatus::Failed, 'audio_error' => 'tts_service_failed'])->save();

    livewire(ListPracticeItems::class)
        ->assertTableColumnFormattedStateSet('audio', 'Готово', $item->fresh())
        ->assertTableColumnHasDescription('audio', 'Правка: ошибка', $item->fresh());

    // The draft with the same audio status as the published one: no note. A task that was never published shows its current version.
    $draft->forceFill(['audio_status' => AudioStatus::Ready, 'audio_error' => null])->save();
    $unpublished = pad_make($w['course'], 'dictation', 'audio-unpublished');
    PracticeItemVersion::query()->where('item_id', $unpublished->id)->update(['audio_status' => AudioStatus::Failed->value]);

    livewire(ListPracticeItems::class)
        ->assertTableColumnFormattedStateSet('audio', 'Готово', $item->fresh())
        ->assertTableColumnDoesNotHaveDescription('audio', 'Правка: готово', $item->fresh())
        ->assertTableColumnFormattedStateSet('audio', 'Ошибка', $unpublished)
        ->assertTableColumnDoesNotHaveDescription('audio', 'Правка: ошибка', $unpublished);
});

it('finds a dictation by the audio of the version learners get or of the draft behind it', function (): void {
    $w = pad_world();
    $served = pad_publish(pad_make($w['course'], 'dictation', 'audio-find-served', [$w['words']['tomorrow']]), $this->admin);
    PracticeItemVersion::query()->where('item_id', $served->id)->update(['audio_status' => AudioStatus::Failed->value]);
    $behind = pad_publish(pad_make($w['course'], 'dictation', 'audio-find-behind', [$w['words']['tomorrow']]), $this->admin);
    PracticeItemVersion::query()->where('item_id', $behind->id)->update(['audio_status' => AudioStatus::Ready->value]);
    $draft = app(PracticeItemService::class)->saveVersion($behind->fresh() ?? $behind, $w['course'], [
        'word_ids' => [$w['words']['tomorrow']->id], 'level' => 'A1',
        'content' => ['text' => 'See you soon', 'accepted' => [], 'translation' => ['ru' => 'Скоро увидимся', 'uz' => "Tez ko'rishamiz"]],
    ], $this->admin);
    $draft->forceFill(['audio_status' => AudioStatus::Failed])->save();
    $fine = pad_publish(pad_make($w['course'], 'dictation', 'audio-find-fine', [$w['words']['tomorrow']]), $this->admin);
    PracticeItemVersion::query()->where('item_id', $fine->id)->update(['audio_status' => AudioStatus::Ready->value]);

    livewire(ListPracticeItems::class)
        ->filterTable('audio', 'failed')
        ->assertCanSeeTableRecords([$served, $behind])
        ->assertCanNotSeeTableRecords([$fine])
        ->resetTableFilters()
        ->filterTable('audio', 'ready')
        ->assertCanSeeTableRecords([$behind, $fine])
        ->assertCanNotSeeTableRecords([$served]);
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

it('keeps the order of the fragments first when the field lists one other order, and shows that one alone when the task is opened again', function (): void {
    $w = pad_world();

    livewire(CreatePracticeItem::class)
        ->fillForm([
            'course_id' => $w['course']->id,
            'type' => 'sentence',
            'key' => 'form-other-order',
            'sentence' => [
                'prompt' => ['ru' => 'Я обычно пью чай.', 'uz' => 'Men odatda choy ichaman.'],
                'fragments' => "I\nusually\ndrink\ntea",
                'accepted' => 'usually | I | drink | tea',
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $item = PracticeItem::query()->where('key', 'form-other-order')->sole();
    $content = PracticeItemVersion::query()->findOrFail($item->current_version_id)->content;

    // [the order of the fragments, the other one]: the field never takes the place of the first.
    expect($content['accepted'])->toBe([[0, 1, 2, 3], [1, 0, 2, 3]]);

    // Both are right answers for the grader: the case that was graded wrong when the field replaced the order of the fragments.
    $grader = new SentenceGrader;
    $accepted = $grader->forms($content['fragments'], $content['accepted'])['accepted'];
    expect($grader->grade(['f1' => 'I', 'f2' => 'usually', 'f3' => 'drink', 'f4' => 'tea'], $accepted, ['order' => ['f1', 'f2', 'f3', 'f4']])->verdict)->toBe(Verdict::Correct)
        ->and($grader->grade(['f1' => 'I', 'f2' => 'usually', 'f3' => 'drink', 'f4' => 'tea'], $accepted, ['order' => ['f2', 'f1', 'f3', 'f4']])->verdict)->toBe(Verdict::Correct);

    livewire(EditPracticeItem::class, ['record' => $item->getKey()])
        ->assertFormSet(['sentence.fragments' => "I\nusually\ndrink\ntea", 'sentence.accepted' => 'usually | I | drink | tea']);
});

it('leaves out of the other orders a line that reads like the order of the fragments or like an earlier line', function (): void {
    $w = pad_world();

    livewire(CreatePracticeItem::class)
        ->fillForm([
            'course_id' => $w['course']->id,
            'type' => 'sentence',
            'key' => 'form-repeats',
            'sentence' => [
                'prompt' => ['ru' => 'Я обычно пью чай.', 'uz' => 'Men odatda choy ichaman.'],
                'fragments' => "I\nusually\ndrink\ntea",
                // the first line is the order of the fragments (in other case), the third is the second one with a full stop
                'accepted' => "i | Usually | drink | tea\nusually | I | drink | tea\nUsually | i | drink | tea.\n",
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $item = PracticeItem::query()->where('key', 'form-repeats')->sole();

    expect(PracticeItemVersion::query()->findOrFail($item->current_version_id)->content['accepted'])->toBe([[0, 1, 2, 3], [1, 0, 2, 3]]);

    // A field that is only the order of the fragments is the same as an empty one: no error about "one order written twice".
    livewire(CreatePracticeItem::class)
        ->fillForm([
            'course_id' => $w['course']->id,
            'type' => 'sentence',
            'key' => 'form-only-repeat',
            'sentence' => ['prompt' => ['ru' => 'Это кот.'], 'fragments' => "this\nis\na\ncat", 'accepted' => "this | is | a | cat\nThis | is | a | cat"],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(PracticeItemVersion::query()->findOrFail(PracticeItem::query()->where('key', 'form-only-repeat')->sole()->current_version_id)->content['accepted'])->toBe([[0, 1, 2, 3]]);
});

it('says in Russian that an order of the field is no permutation of the fragments', function (): void {
    $w = pad_world();
    pad_sent();

    livewire(CreatePracticeItem::class)
        ->fillForm([
            'course_id' => $w['course']->id,
            'type' => 'sentence',
            'key' => 'form-bad-order',
            'sentence' => ['prompt' => ['ru' => 'Это кот.'], 'fragments' => "this\nis\na\ncat", 'accepted' => 'this | is | a'],
        ])
        ->call('create');

    expect(pad_sent())->toBe([['title' => 'Не получилось', 'body' => 'Один из допустимых порядков — не перестановка фрагментов: каждый фрагмент должен встретиться в нём ровно столько раз, сколько в списке фрагментов.']])
        ->and(PracticeItem::query()->count())->toBe(0);
});

it('shows every order of a task saved before the rule, so that saving it again loses none, and the task can be published after that', function (): void {
    $w = pad_world();
    // What the old form stored: the field REPLACED the order of the fragments, so the first accepted order is `usually I drink tea`.
    $item = PracticeItem::factory()->sentence()->reviewed()->forCourse($w['course'])->targeting($w['words']['mother'])->withContent([
        'fragments' => ['I', 'usually', 'drink', 'tea'],
        'accepted' => [[1, 0, 2, 3]],
        'display' => 'Usually I drink tea.',
    ])->create(['key' => 'legacy-order']);
    pad_sent();

    // It cannot be published as it is: the order of the fragments must be the first one.
    livewire(ListPracticeItems::class)->callTableAction('publish', $item);
    expect(pad_sent())->toBe([['title' => 'Не получилось', 'body' => 'Первым допустимым порядком должен быть порядок фрагментов. Откройте задание и сохраните его заново: порядок фрагментов встанет первым сам.']]);

    // The form shows the order that is stored (it is not the order of the fragments, so it is not hidden)...
    livewire(EditPracticeItem::class, ['record' => $item->getKey()])
        ->assertFormSet(['sentence.accepted' => 'usually | I | drink | tea'])
        ->fillForm(['sentence' => ['display' => 'I usually drink tea.']])
        ->call('save')
        ->assertHasNoFormErrors();

    // ... and saving puts the order of the fragments first and keeps that one after it.
    $item->refresh();
    expect(PracticeItemVersion::query()->findOrFail($item->current_version_id)->content['accepted'])->toBe([[0, 1, 2, 3], [1, 0, 2, 3]]);
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

    // The word is named by its text (the admin knows it so), not by its number.
    expect(pad_sent()[0]['body'])->toContain('«ghost»')->not->toContain((string) $w['words']['ghost']->id.'»')->toContain('не найдено среди активных слов курса');
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
            // The order of the fragments is the first accepted order and is not shown again: the field lists the others.
            'sentence.accepted' => 'usually | I | drink | tea',
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

it('shows in the form the place and the target words of the CURRENT version, not the ones the published version has', function (): void {
    $w = pad_world();
    $item = pad_publish(pad_make($w['course'], 'sentence', 'form-version', [$w['words']['mother']], [], null), $this->admin);
    $service = app(PracticeItemService::class);
    // The published version: lesson none, level A1, word «mother». The draft: lesson 1.2, level B1, word «tomorrow».
    $service->saveVersion($item->fresh() ?? $item, $w['course'], [
        'lesson_id' => $w['l12']->id, 'level' => 'B1', 'word_ids' => [$w['words']['tomorrow']->id],
        'content' => pad_content($item),
    ], $this->admin);
    $item->refresh();

    // What the learners get is still the published version's ...
    expect($item->lesson_id)->toBeNull()
        ->and($item->level)->toBe('A1')
        ->and(PracticeItemWord::query()->where('item_id', $item->id)->pluck('word_id')->all())->toBe([$w['words']['mother']->id]);

    // ... and the form edits the draft.
    livewire(EditPracticeItem::class, ['record' => $item->getKey()])
        ->assertFormSet(['lesson_id' => $w['l12']->id, 'level' => 'B1', 'word_ids' => [$w['words']['tomorrow']->id]]);

    // Saved as it is shown: nothing new, nothing changed for the learners.
    livewire(EditPracticeItem::class, ['record' => $item->getKey()])->call('save')->assertHasNoFormErrors();
    expect(PracticeItemVersion::query()->where('item_id', $item->id)->count())->toBe(2);

    // A change of the lesson alone in the form is a new version, and the learners are not moved.
    livewire(EditPracticeItem::class, ['record' => $item->getKey()])
        ->fillForm(['lesson_id' => $w['l21']->id])
        ->call('save')
        ->assertHasNoFormErrors();

    $item->refresh();
    expect(PracticeItemVersion::query()->where('item_id', $item->id)->count())->toBe(3)
        ->and(PracticeItemVersion::query()->findOrFail($item->current_version_id)->lesson_id)->toBe($w['l21']->id)
        ->and($item->lesson_id)->toBeNull();
});

it('marks a published task as having an unpublished edit when only its lesson, level or target words differ in the draft', function (string $change): void {
    $w = pad_world();
    $item = pad_publish(pad_make($w['course'], 'sentence', "edit-{$change}", [$w['words']['mother']]), $this->admin);
    $plain = pad_publish(pad_make($w['course'], 'sentence', "plain-{$change}", [$w['words']['mother']]), $this->admin);
    $content = pad_content($item);
    $data = [
        'lesson_id' => $change === 'lesson' ? $w['l12']->id : null,
        'level' => $change === 'level' ? 'B2' : 'A1',
        'word_ids' => [$change === 'words' ? $w['words']['tomorrow']->id : $w['words']['mother']->id],
        'content' => $content,
    ];

    app(PracticeItemService::class)->saveVersion($item, $w['course'], $data, $this->admin);

    livewire(ListPracticeItems::class)
        ->assertTableColumnStateSet('has_unpublished_edit', true, $item->fresh())
        ->assertTableColumnStateSet('has_unpublished_edit', false, $plain->fresh())
        ->filterTable('unpublished_edit', true)
        ->assertCanSeeTableRecords([$item])
        ->assertCanNotSeeTableRecords([$plain]);
})->with(['lesson', 'level', 'words']);

it('says on the form that the lesson, the level and the target words take effect after publishing', function (): void {
    $w = pad_world();
    $item = pad_make($w['course'], 'sentence', 'help-line');

    livewire(CreatePracticeItem::class)->assertSee('Изменения урока, уровня и целевых слов вступают в силу после публикации.');
    livewire(EditPracticeItem::class, ['record' => $item->getKey()])->assertSee('Изменения урока, уровня и целевых слов вступают в силу после публикации.');
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

/** The id of the version a button was drawn with: what the browser sends when it is clicked (`mountAction('review', {"version": 5}, ...)`). */
function pad_button_version(string $html, string $action): ?int
{
    return preg_match('/mountAction\(\''.preg_quote($action, '/').'\', JSON\.parse\(\'\{\\\\u0022version\\\\u0022:(\d+)\}\'\)/', $html, $found) === 1 ? (int) $found[1] : null;
}

/** An edit of a task written by someone else (an import): a newer version, a draft. */
function pad_edit_elsewhere(PracticeItem $item, Course $course, Word $word, string $explanation = 'Другое пояснение.'): PracticeItemVersion
{
    return app(PracticeItemService::class)->saveVersion($item->fresh() ?? $item, $course, [
        'word_ids' => [$word->id],
        'content' => ['prompt' => ['ru' => 'Моя мама — учитель.', 'uz' => "Onam o'qituvchi."], 'fragments' => ['my', 'mother', 'is', 'a', 'teacher'], 'accepted' => [['my', 'mother', 'is', 'a', 'teacher']],
            'display' => 'My mother is a teacher.', 'explanation' => ['ru' => $explanation, 'uz' => 'Boshqa izoh.']],
    ], null);
}

it('draws «Проверено» and «Опубликовать» with the id of the version the page shows, in the list and on the edit page', function (): void {
    $w = pad_world();
    $draft = pad_make($w['course'], 'sentence', 'carry-draft', [$w['words']['mother']]);
    $reviewed = pad_make($w['course'], 'sentence', 'carry-reviewed', [$w['words']['mother']]);
    app(PracticeItemService::class)->markReviewed($reviewed, $this->admin);

    $list = livewire(ListPracticeItems::class)->html();
    $page = livewire(EditPracticeItem::class, ['record' => $draft->getKey()]);

    expect(pad_button_version($list, 'review'))->toBe($draft->fresh()?->current_version_id)
        ->and(pad_button_version($list, 'publish'))->toBe($reviewed->fresh()?->current_version_id)
        ->and(pad_button_version($page->html(), 'review'))->toBe($draft->fresh()?->current_version_id)
        ->and($page->instance()->shownVersionId)->toBe($draft->fresh()?->current_version_id);
});

it('refuses «Проверено» and «Опубликовать» from the list when the task has a newer version than the row showed', function (): void {
    $w = pad_world();
    $item = pad_make($w['course'], 'sentence', 'stale-row', [$w['words']['mother']]);
    $seen = $item->current_version_id;
    $list = livewire(ListPracticeItems::class);
    $drawn = pad_button_version($list->html(), 'review');
    pad_edit_elsewhere($item, $w['course'], $w['words']['mother']);
    pad_sent();

    // The row was drawn with the first version; the click carries it.
    $list->callTableAction('review', $item, arguments: ['version' => $drawn]);

    expect($drawn)->toBe($seen)
        ->and(pad_sent())->toBe([['title' => 'Не получилось', 'body' => 'Задание изменили, пока вы его смотрели. Откройте его заново.']])
        ->and(PracticeItemVersion::query()->where('item_id', $item->id)->where('state', 'reviewed')->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'practice_item.reviewed')->count())->toBe(0);

    // The same for «Опубликовать»: the newer version is reviewed, the row still has the old one.
    app(PracticeItemService::class)->markReviewed($item->fresh() ?? $item, $this->admin);
    livewire(ListPracticeItems::class)->callTableAction('publish', $item->fresh(), arguments: ['version' => $seen]);

    expect(pad_sent())->toBe([['title' => 'Не получилось', 'body' => 'Задание изменили, пока вы его смотрели. Откройте его заново.']])
        ->and($item->fresh()?->published_version_id)->toBeNull();

    // A row that is drawn again carries the newer version, and the move goes through.
    $fresh = livewire(ListPracticeItems::class);
    $fresh->callTableAction('publish', $item->fresh(), arguments: ['version' => pad_button_version($fresh->html(), 'publish')]);

    expect($item->fresh()?->status)->toBe(PracticeItemStatus::Published);
});

it('keeps the edit page to the version its form shows: a redraw does not move the buttons to a newer one', function (): void {
    $w = pad_world();
    $item = pad_make($w['course'], 'sentence', 'stale-page', [$w['words']['mother']]);
    $seen = $item->current_version_id;
    $page = livewire(EditPracticeItem::class, ['record' => $item->getKey()]);
    $newer = pad_edit_elsewhere($item, $w['course'], $w['words']['mother']);

    // Any request redraws the page and its buttons; the record is read again, the form is not.
    $page->call('$refresh');
    $drawn = pad_button_version($page->html(), 'review');
    pad_sent();
    $page->callAction('review', arguments: ['version' => $drawn]);

    expect($drawn)->toBe($seen)
        ->and($newer->id)->not->toBe($seen)
        ->and(pad_sent())->toBe([['title' => 'Не получилось', 'body' => 'Задание изменили, пока вы его смотрели. Откройте его заново.']])
        ->and($newer->fresh()?->state)->toBe(VersionState::Draft);

    // Opened again, the page shows the newer version and its buttons carry it.
    $reopened = livewire(EditPracticeItem::class, ['record' => $item->getKey()]);
    $reopened->callAction('review', arguments: ['version' => pad_button_version($reopened->html(), 'review')]);

    expect($newer->fresh()?->state)->toBe(VersionState::Reviewed);
});

it('moves the buttons of the edit page to the version it has just saved', function (): void {
    $w = pad_world();
    $item = pad_make($w['course'], 'sentence', 'saved-page', [$w['words']['mother']]);
    $page = livewire(EditPracticeItem::class, ['record' => $item->getKey()]);
    $first = $item->current_version_id;

    $page->fillForm(['sentence' => ['explanation' => ['ru' => 'Новое пояснение.', 'uz' => 'Yangi izoh.']]])->call('save')->assertHasNoFormErrors();

    $second = $item->fresh()?->current_version_id;
    expect($second)->not->toBe($first)
        ->and($page->instance()->shownVersionId)->toBe($second)
        ->and(pad_button_version($page->html(), 'review'))->toBe($second);

    $page->callAction('review', arguments: ['version' => $second]);
    expect(PracticeItemVersion::query()->findOrFail($second)->state)->toBe(VersionState::Reviewed);
});

it('takes anything but a whole number for the id of a version as no version: the move is not tied to one', function (mixed $argument): void {
    $w = pad_world();
    $item = pad_make($w['course'], 'sentence', 'odd-argument', [$w['words']['mother']]);

    livewire(ListPracticeItems::class)->callTableAction('review', $item, arguments: ['version' => $argument]);

    expect($item->fresh()?->status)->toBe(PracticeItemStatus::Reviewed);
})->with(['an array' => [[1]], 'text' => ['latest'], 'nothing' => [null], 'a fraction' => [1.5]]);

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

it('voices again from the button both the published version, whose audio failed, and the draft behind it', function (): void {
    $w = pad_world();
    $item = pad_publish(pad_make($w['course'], 'dictation', 'regen-both', [$w['words']['tomorrow']]), $this->admin);
    $published = PracticeItemVersion::query()->where('item_id', $item->id)->sole();
    $published->forceFill(['audio_status' => AudioStatus::Failed, 'audio_error' => 'tts_service_failed'])->save();
    $draft = app(PracticeItemService::class)->saveVersion($item->fresh() ?? $item, $w['course'], [
        'word_ids' => [$w['words']['tomorrow']->id], 'level' => 'A1',
        'content' => ['text' => 'See you soon', 'accepted' => [], 'translation' => ['ru' => 'Скоро увидимся', 'uz' => "Tez ko'rishamiz"]],
    ], $this->admin);
    Queue::fake();

    livewire(ListPracticeItems::class)->callTableAction('regenerateAudio', $item->fresh());

    Queue::assertPushed(GeneratePracticeAudioJob::class, 2);
    Queue::assertPushed(GeneratePracticeAudioJob::class, fn (GeneratePracticeAudioJob $job): bool => $job->versionId === $published->id && $job->force);
    Queue::assertPushed(GeneratePracticeAudioJob::class, fn (GeneratePracticeAudioJob $job): bool => $job->versionId === $draft->id && $job->force);
    expect($published->fresh()?->audio_status)->toBe(AudioStatus::Pending);
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

it('prints the text of a refused file in the notification as text, never as HTML: one escaped line per fault, told apart by <br>', function (string $text): void {
    $this->seed(DemoContentSeeder::class);
    $file = json_encode([
        'format' => 'lexiflow-practice/1', 'course' => 'default',
        'items' => [
            // the key of the file is in the sentence about the key; the word, in the one about the target word
            ['key' => $text, 'type' => 'dictation', 'target_words' => ['tomorrow'], 'text' => 'Hello'],
            ['key' => 'second', 'type' => 'dictation', 'target_words' => [$text], 'text' => 'Hello'],
            ['key' => 'third', 'type' => 'dictation', 'target_words' => ['tomorrow'], 'text' => 'Hello', 'level' => 'Z9'],
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    pad_sent();

    livewire(ImportPracticeItems::class)
        ->set('data.file', UploadedFile::fake()->createWithContent('practice.json', $file))
        ->call('submit');

    $body = (string) pad_sent()[0]['body'];
    $printed = (string) str($body)->sanitizeHtml();
    expect($body)->toContain(e($text))->not->toContain($text)
        // the lines are told apart by <br>, not run together by "\n"
        ->and(substr_count($body, '<br>'))->toBe(2)
        ->and($body)->not->toContain("\n")
        // what Filament prints of the body (its sanitizer rewrites the markup: `<br />`, `&#61;`) has no element of the file's, and has the line breaks
        ->and($printed)->not->toContain('<a ')->not->toContain('<img')->not->toContain('<b>')->toContain('&lt;')->toContain('<br />');
    expect(PracticeItem::query()->count())->toBe(0);
})->with([
    'a tag' => ['<b>x</b>'],
    'a link' => ['<a href="https://evil.example/">click</a>'],
    'an image' => ['<img src="https://evil.example/pixel.png">'],
]);

it('agrees the noun with the number in «В файле N заданий»', function (): void {
    $line = fn (int $count): string => AdminErrors::practiceImportIssue(new ImportIssue('items_too_many', 'English', ['count' => $count, 'max' => 300]));

    expect($line(301))->toBe('В файле 301 задание, а можно не больше 300.')
        ->and($line(302))->toBe('В файле 302 задания, а можно не больше 300.')
        ->and($line(305))->toBe('В файле 305 заданий, а можно не больше 300.')
        ->and($line(311))->toBe('В файле 311 заданий, а можно не больше 300.')
        ->and($line(321))->toBe('В файле 321 задание, а можно не больше 300.')
        ->and($line(1000))->toBe('В файле 1000 заданий, а можно не больше 300.');
});

it('takes the size limit of a file from the config on the page and on the command line, as the importer does', function (): void {
    $this->seed(DemoContentSeeder::class);
    config(['practice.import.max_bytes' => 3 * 1024 * 1024, 'practice.import.max_items' => 250]);

    // The help line and the limit of the upload follow the config ...
    livewire(ImportPracticeItems::class)
        ->assertSee('До 3 МБ, до 250 заданий.')
        ->assertFormFieldExists('file', fn (FileUpload $field): bool => $field->getMaxSize() === 3072);

    config(['practice.import.max_bytes' => 1536 * 1024]);
    livewire(ImportPracticeItems::class)->assertSee('До 1,5 МБ, до 250 заданий.');

    // ... and so does what is read of the file: a valid file longer than the old 2 MB is not cut into broken JSON.
    config(['practice.import.max_bytes' => 3 * 1024 * 1024]);
    $json = json_decode(pad_sample(), true, 512, JSON_THROW_ON_ERROR);
    $big = json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $big = substr($big, 0, -1).str_repeat(' ', 2 * 1024 * 1024 + 4096)."}\n";

    livewire(ImportPracticeItems::class)
        ->set('data.file', UploadedFile::fake()->createWithContent('practice.json', $big))
        ->call('submit')
        ->assertNotified('Импорт выполнен: новых — 3, обновлено — 0, пропущено — 0, опубликовано — 0.');

    PracticeItem::query()->delete();
    $path = sys_get_temp_dir().'/practice-big-'.bin2hex(random_bytes(6)).'.json';
    file_put_contents($path, $big);

    expect(Artisan::call('practice:import', ['file' => $path]))->toBe(0)
        ->and(PracticeItem::query()->count())->toBe(3);

    // Under the old limit the same file is refused as too large, whole.
    config(['practice.import.max_bytes' => 2 * 1024 * 1024]);
    PracticeItem::query()->delete();

    expect(Artisan::call('practice:import', ['file' => $path]))->toBe(1)
        ->and(Artisan::output())->toContain('file_too_large');
    unlink($path);
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
        ->assertActionVisible('practicePilotInclude')
        ->assertActionHidden('practicePilotExclude')
        ->assertActionHasLabel('practicePilotInclude', 'Включить в пилот практики')
        ->callAction('practicePilotInclude');

    $in = $learner->fresh()?->practice_pilot_at;
    expect($in)->not->toBeNull()
        ->and(pad_sent())->toBe([['title' => 'Ученик включён в пилот практики', 'body' => null]]);

    $audit = AuditLog::query()->where('action', 'learner.practice_pilot_changed')->sole();
    expect($audit->user_id)->toBe($this->admin->id)
        ->and($audit->entity_type)->toBe(Learner::class)
        ->and($audit->entity_id)->toBe($learner->id)
        ->and($audit->meta['from'])->toBeNull()
        ->and($audit->meta['to'])->toBe($in?->toIso8601String());

    $page->assertActionHidden('practicePilotInclude')
        ->assertActionHasLabel('practicePilotExclude', 'Исключить из пилота')
        ->callAction('practicePilotExclude');

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
        ->assertTableActionVisible('practicePilotInclude', $out)
        ->assertTableActionHidden('practicePilotExclude', $out)
        ->assertTableActionHidden('practicePilotInclude', $in)
        ->assertTableActionVisible('practicePilotExclude', $in)
        ->callTableAction('practicePilotInclude', $out);

    expect($out->fresh()?->practice_pilot_at)->not->toBeNull();

    livewire(ListLearners::class)->callTableAction('practicePilotExclude', $in);
    expect($in->fresh()?->practice_pilot_at)->toBeNull();
});

it('shows the pilot to an admin only: a teacher sees neither the column, the filter nor the button', function (): void {
    $group = TelegramGroup::factory()->create();
    $teacher = pad_user('teacher');
    $teacher->telegramGroups()->attach($group->id, ['is_primary' => true]);
    $learner = Learner::factory()->memberOf($group)->create();
    $this->actingAs($teacher);

    livewire(ViewLearner::class, ['record' => $learner->getKey()])->assertActionHidden('practicePilotInclude')->assertActionHidden('practicePilotExclude');
    livewire(ListLearners::class)
        ->assertTableActionHidden('practicePilotInclude', $learner)
        ->assertTableActionHidden('practicePilotExclude', $learner)
        ->assertTableColumnHidden('practice_pilot')
        ->assertTableFilterHidden('practice_pilot');
});

it('makes the practice open to the learner of the pilot and to no one else while the access is «pilot»', function (): void {
    config(['practice.access' => 'pilot', 'practice.plan' => 'all']);
    $learner = Learner::factory()->create();
    $access = app(PracticeAccess::class);

    expect($access->state($learner)->isAvailable())->toBeFalse();

    livewire(ViewLearner::class, ['record' => $learner->getKey()])->callAction('practicePilotInclude');
    expect($access->state($learner->fresh() ?? $learner)->isAvailable())->toBeTrue();

    livewire(ViewLearner::class, ['record' => $learner->getKey()])->callAction('practicePilotExclude');
    expect($access->state($learner->fresh() ?? $learner)->isAvailable())->toBeFalse();
});

it('does not turn the move round for a button of a page that is out of date: a confirmed «Включить» never takes a learner OUT of the pilot', function (): void {
    $learner = Learner::factory()->create();
    // The page was opened while the learner was not in the pilot: it shows «Включить в пилот практики» ...
    $page = livewire(ViewLearner::class, ['record' => $learner->getKey()])->assertActionVisible('practicePilotInclude');
    // ... and another admin put the learner there before the click.
    app(PracticePilotService::class)->include($learner, $this->admin);
    $in = $learner->fresh()?->practice_pilot_at;
    pad_sent();

    $page->callAction('practicePilotInclude');

    // The button of a direction the learner has already gone is not run at all: the page is drawn again with the right one.
    expect($learner->fresh()?->practice_pilot_at?->equalTo($in))->toBeTrue()
        ->and(pad_sent())->toBe([])
        ->and(AuditLog::query()->where('action', 'learner.practice_pilot_changed')->count())->toBe(1);

    // And the other way round: «Исключить» from an old page does not put back a learner who has been taken out.
    $out = Learner::factory()->create(['practice_pilot_at' => now()]);
    $stale = livewire(ViewLearner::class, ['record' => $out->getKey()])->assertActionVisible('practicePilotExclude');
    app(PracticePilotService::class)->exclude($out, $this->admin);

    $stale->callAction('practicePilotExclude');

    expect($out->fresh()?->practice_pilot_at)->toBeNull()
        ->and(pad_sent())->toBe([])
        ->and(AuditLog::query()->where('action', 'learner.practice_pilot_changed')->count())->toBe(2);

    // Both directions are the action's own: the buttons of a learner who is out of the pilot offer «Включить» only.
    livewire(ViewLearner::class, ['record' => $out->getKey()])->assertActionVisible('practicePilotInclude')->assertActionHidden('practicePilotExclude');
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
