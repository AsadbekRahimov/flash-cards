<?php

declare(strict_types=1);

/*
 * docs/35 O6, O8: the polls of `/admin`. Active admin only (not a teacher, not an inactive admin, not in `/org`); the form writes a
 * draft through PollEditor; after the launch the server refuses the question, the options, the picture, the answer rules and the
 * audience - also when a crafted Livewire request sets them; the test send, the launch (with every refusal worded), the stop and the
 * closing; the region of the audience («Другая страна» = `other`); the recipients counted on the button only; the list within its
 * query budget (docs/20 §1).
 */

use App\Domain\Polls\Enums\PollDeliveryStatus;
use App\Domain\Polls\Enums\PollStatus;
use App\Domain\Polls\Exceptions\PollEditException;
use App\Domain\Polls\Services\PollAudienceQuery;
use App\Domain\Polls\Services\PollEditor;
use App\Domain\Polls\Services\PollViewCache;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Filament\Resources\PollResource;
use App\Filament\Resources\PollResource\Pages\CreatePoll;
use App\Filament\Resources\PollResource\Pages\EditPoll;
use App\Filament\Resources\PollResource\Pages\ListPolls;
use App\Filament\Resources\Support\AdminErrors;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Poll;
use App\Models\PollDelivery;
use App\Models\PollOption;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\FakeTelegramClient;
use Tests\Support\FilamentQueryBudget;
use Tests\Support\Polls\PollFixtures;
use Tests\Support\QueryLog;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->setLocale('ru');
    $this->seed(RolePermissionSeeder::class);
    $this->admin = User::factory()->create([
        'is_active' => true,
        'telegram_user_id' => 555_000_111,
        'two_factor_secret' => 'stub',
        'two_factor_recovery_codes' => [],
        'two_factor_confirmed_at' => now(),
    ]);
    $this->admin->assignRole('admin');
    $this->actingAs($this->admin);
    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);
    Storage::fake('local');
});

/** @return array<string, mixed> a valid form */
function pollForm(array $overrides = []): array
{
    return [
        'title' => 'Удобное время',
        'question' => ['ru' => 'Когда вам удобнее заниматься?', 'uz' => 'Qachon shug\'ullanish qulay?'],
        'options' => [
            ['label' => ['ru' => 'Утром', 'uz' => 'Ertalab']],
            ['label' => ['ru' => 'Вечером', 'uz' => 'Kechqurun']],
        ],
        'allows_multiple' => false,
        'max_choices' => null,
        'allow_change' => true,
        'closes_at' => null,
        'audience' => ['plan' => 'all', 'locales' => [], 'course_ids' => [], 'region_codes' => [], 'onboarded_only' => true],
        ...$overrides,
    ];
}

/** Titles of the notifications sent since the last call. */
function pollNotices(): array
{
    $mailbox = app(NotificationMailbox::class);
    $mailbox->flush();

    return array_map(static fn (array $n): ?string => $n['title'] ?? null, $mailbox->pull());
}

// --- access --------------------------------------------------------------------------------------------------------------

it('closes the polls to teachers and inactive admins and keeps them out of /org', function (): void {
    $poll = PollFixtures::poll();
    $teacher = User::factory()->create(['is_active' => true]);
    $teacher->assignRole('teacher');
    $inactive = User::factory()->create(['is_active' => false]);
    $inactive->assignRole('admin');

    foreach ([$teacher, $inactive] as $user) {
        $this->actingAs($user)->get('/admin/polls')->assertForbidden();
        $this->actingAs($user)->get("/admin/polls/{$poll->id}/results")->assertForbidden();
        expect(PollResource::canViewAny())->toBeFalse()
            ->and($user->can('run', $poll))->toBeFalse()
            ->and($user->can('results', $poll))->toBeFalse();
    }

    $this->actingAs($teacher);
    Livewire::test(EditPoll::class, ['record' => $poll->id])->assertForbidden();

    expect(Filament::getPanel('org')->getResources())->not->toContain(PollResource::class)
        ->and(Filament::getPanel('admin')->getResources())->toContain(PollResource::class);
});

it('lets an active admin delete only a draft', function (): void {
    expect($this->admin->can('delete', PollFixtures::poll()))->toBeTrue()
        ->and($this->admin->can('delete', PollFixtures::poll(['status' => PollStatus::Sent])))->toBeFalse()
        ->and($this->admin->can('deleteAny', Poll::class))->toBeFalse();
});

// --- the form --------------------------------------------------------------------------------------------------------------

it('creates a draft with its options, the region «Другая страна» and the creator', function (): void {
    $course = Course::factory()->create();

    Livewire::test(CreatePoll::class)
        ->fillForm(pollForm([
            'allows_multiple' => true,
            'max_choices' => 2,
            'options' => [
                ['label' => ['ru' => 'Утром', 'uz' => 'Ertalab']],
                ['label' => ['ru' => 'Днём', 'uz' => 'Kunduzi']],
                ['label' => ['ru' => 'Вечером', 'uz' => 'Kechqurun']],
            ],
            'audience' => ['plan' => 'pro', 'locales' => ['uz'], 'course_ids' => [$course->id], 'region_codes' => ['UZ-SA', 'other'], 'onboarded_only' => true],
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    $poll = Poll::query()->sole();
    expect($poll->status)->toBe(PollStatus::Draft)
        ->and($poll->created_by_user_id)->toBe($this->admin->id)
        ->and($poll->allows_multiple)->toBeTrue()
        ->and($poll->max_choices)->toBe(2)
        ->and($poll->audience)->toBe(['plan' => 'pro', 'locales' => ['uz'], 'course_ids' => [$course->id], 'region_codes' => ['UZ-SA', 'other'], 'onboarded_only' => true])
        ->and($poll->options()->pluck('label')->map(fn (array $l): string => $l['ru'])->all())->toBe(['Утром', 'Днём', 'Вечером']);
});

it('refuses on the form an option over 40 characters, one option, and a question over 700', function (): void {
    Livewire::test(CreatePoll::class)
        ->fillForm(pollForm(['options' => [['label' => ['ru' => str_repeat('я', 41), 'uz' => 'a']]]]))
        ->call('create')
        ->assertHasFormErrors();

    Livewire::test(CreatePoll::class)
        ->fillForm(pollForm(['question' => ['ru' => str_repeat('я', 701), 'uz' => 'a']]))
        ->call('create')
        ->assertHasFormErrors(['question.ru']);

    expect(Poll::query()->count())->toBe(0);
});

it('refuses in the service what the form would let through', function (array $data, string $reason): void {
    expect(fn () => app(PollEditor::class)->create(pollForm($data), $this->admin))
        ->toThrow(PollEditException::class, 'The poll cannot be saved: '.$reason.'.');
})->with([
    'one option' => [['options' => [['label' => ['ru' => 'Да', 'uz' => 'Ha']]]], PollEditException::OPTIONS],
    'eleven options' => [['options' => array_fill(0, 11, ['label' => ['ru' => 'Да', 'uz' => 'Ha']])], PollEditException::OPTIONS],
    'an empty Uzbek label' => [['options' => [['label' => ['ru' => 'Да', 'uz' => ' ']], ['label' => ['ru' => 'Нет', 'uz' => 'Yo\'q']]]], PollEditException::LABEL],
    'no Uzbek question' => [['question' => ['ru' => 'Вопрос', 'uz' => '']], PollEditException::QUESTION],
    'a limit over the options' => [['allows_multiple' => true, 'max_choices' => 3], PollEditException::MAX_CHOICES],
    'an unknown region' => [['audience' => ['plan' => 'all', 'region_codes' => ['UZ-XX']]], PollEditException::AUDIENCE],
    'an unknown language' => [['audience' => ['plan' => 'all', 'locales' => ['en']]], PollEditException::AUDIENCE],
    'an unknown course' => [['audience' => ['plan' => 'all', 'course_ids' => [999_999]]], PollEditException::AUDIENCE],
    'an unknown plan' => [['audience' => ['plan' => 'vip']], PollEditException::AUDIENCE],
    'a deadline in the past' => [['closes_at' => now()->subHour()->toDateTimeString()], PollEditException::CLOSES_AT],
    'a path that is not an upload' => [['image_path' => '../../.env'], PollEditException::IMAGE],
    'a stored file that is not a picture' => [['image_path' => 'polls/'.str_repeat('a', 40).'.png'], PollEditException::IMAGE],
]);

it('stores the picture privately under a random name and drops the Telegram file id when it changes', function (): void {
    Livewire::test(CreatePoll::class)
        ->fillForm(pollForm(['image_path' => UploadedFile::fake()->image('../My Photo.png', 600, 400)]))
        ->call('create')
        ->assertHasNoFormErrors();

    $poll = Poll::query()->sole();
    expect($poll->image_path)->toMatch('~\Apolls/[A-Za-z0-9]{40}\.png\z~');
    Storage::disk('local')->assertExists($poll->image_path);

    $old = $poll->image_path;
    $poll->forceFill(['image_file_id' => 'AgAD-old'])->save();

    // A new picture of the draft (the form stored it under a random name): the old file and Telegram's id of it go.
    $new = 'polls/'.str_repeat('N', 40).'.jpg';
    $file = UploadedFile::fake()->image('new.jpg', 300, 300);
    Storage::disk('local')->put($new, (string) file_get_contents($file->getPathname()));
    app(PollEditor::class)->update($poll, pollForm(['image_path' => $new]));

    $poll->refresh();
    expect($poll->image_path)->toMatch('~\Apolls/[A-Za-z0-9]{40}\.jpg\z~')
        ->and($poll->image_file_id)->toBeNull();
    Storage::disk('local')->assertMissing($old);
});

it('refuses a picture of another type or over the size limit', function (): void {
    config(['polls.image_max_kb' => 50]);

    Livewire::test(CreatePoll::class)
        ->fillForm(pollForm(['image_path' => UploadedFile::fake()->create('note.gif', 10, 'image/gif')]))
        ->call('create')
        ->assertHasFormErrors(['image_path']);

    Livewire::test(CreatePoll::class)
        ->fillForm(pollForm(['image_path' => UploadedFile::fake()->image('big.png')->size(200)]))
        ->call('create')
        ->assertHasFormErrors(['image_path']);

    expect(Poll::query()->count())->toBe(0);
});

it('edits a draft in place: labels updated, positions kept, the view cache forgotten', function (): void {
    $poll = PollFixtures::poll();
    $ids = $poll->options()->pluck('id')->all();
    $views = app(PollViewCache::class);
    $views->get($poll->id);

    $page = Livewire::test(EditPoll::class, ['record' => $poll->id]);
    expect($page->get('data.options'))->toHaveCount(3);

    $page->fillForm(pollForm(['question' => ['ru' => 'Новый вопрос', 'uz' => 'Yangi savol'], 'options' => [
        ['label' => ['ru' => 'А', 'uz' => 'A']],
        ['label' => ['ru' => 'Б', 'uz' => 'B']],
    ]]))
        ->call('save')
        ->assertHasNoFormErrors();

    expect($poll->options()->pluck('id')->all())->toBe(array_slice($ids, 0, 2))
        ->and($views->get($poll->id)['question']['ru'])->toBe('Новый вопрос');
});

// --- after the launch: the server refuses ----------------------------------------------------------------------------------

it('keeps the question, options, picture, rules and audience of a launched poll even from a crafted Livewire request', function (): void {
    $poll = PollFixtures::poll(['status' => PollStatus::Sending, 'launched_at' => now()]);

    Livewire::test(EditPoll::class, ['record' => $poll->id])
        ->assertFormFieldIsDisabled('question.ru')
        ->assertFormFieldIsDisabled('options')
        ->assertFormFieldIsEnabled('title')
        ->assertFormFieldIsEnabled('closes_at')
        ->set('data.question.ru', 'Взломанный вопрос')
        ->set('data.audience.plan', 'pro')
        ->set('data.allows_multiple', true)
        ->set('data.title', 'Новое название')
        ->call('save');

    $poll->refresh();
    expect($poll->question['ru'])->toBe('Какой формат занятий вам удобнее?')
        ->and($poll->audience['plan'])->toBe('all')
        ->and($poll->allows_multiple)->toBeFalse()
        ->and($poll->title)->toBe('Новое название');
});

it('refuses in the service any locked field of a launched poll that would change, and the deadline of a finished one', function (string $field, mixed $value): void {
    $poll = PollFixtures::poll(['status' => PollStatus::Sent, 'launched_at' => now()]);

    expect(fn () => app(PollEditor::class)->update($poll, ['title' => $poll->title, $field => $value]))
        ->toThrow(PollEditException::class, 'The poll cannot be saved: locked.');
})->with([
    'question' => ['question', ['ru' => 'Другой', 'uz' => 'Boshqa']],
    'options' => ['options', [['label' => ['ru' => 'А', 'uz' => 'A']], ['label' => ['ru' => 'Б', 'uz' => 'B']]]],
    'picture' => ['image_path', 'polls/'.str_repeat('b', 40).'.png'],
    'several answers' => ['allows_multiple', true],
    'limit' => ['max_choices', 2],
    'change of an answer' => ['allow_change', false],
    'audience' => ['audience', ['plan' => 'pro']],
]);

it('lets a launched poll change its title and deadline, and a finished one only its title', function (): void {
    $editor = app(PollEditor::class);
    $open = PollFixtures::poll(['status' => PollStatus::Sending, 'launched_at' => now()]);
    $same = ['question' => $open->question, 'allow_change' => true, 'audience' => $open->audience];

    $editor->update($open, ['title' => 'Другое', 'closes_at' => now()->addDay()->startOfMinute()->toDateTimeString(), ...$same]);
    expect($open->fresh()->title)->toBe('Другое')->and($open->fresh()->closes_at)->not->toBeNull();

    $closed = PollFixtures::poll(['status' => PollStatus::Closed, 'launched_at' => now()->subDay(), 'closed_at' => now()]);
    expect(fn () => $editor->update($closed, ['title' => 'x', 'closes_at' => now()->addDay()->toDateTimeString()]))
        ->toThrow(PollEditException::class, 'finished');

    $editor->update($closed, ['title' => 'Итоги']);
    expect($closed->fresh()->title)->toBe('Итоги');
});

it('keeps the locked fields when a form opened on a draft is saved after the launch', function (): void {
    $poll = PollFixtures::poll();
    $page = Livewire::test(EditPoll::class, ['record' => $poll->id]);
    Poll::query()->whereKey($poll->id)->update(['status' => PollStatus::Sending->value]);

    $page->fillForm(pollForm(['question' => ['ru' => 'Поздно', 'uz' => 'Kech']]))->call('save');

    // The save request reads the poll again: its locked fields are not taken from the form any more, only the title is.
    expect($poll->fresh()->question['ru'])->toBe('Какой формат занятий вам удобнее?')
        ->and($poll->fresh()->title)->toBe('Удобное время');
});

// --- recipients, test send, launch, stop, close ------------------------------------------------------------------------------

it('counts the recipients on the button only, with the region filter and «Другая страна»', function (): void {
    PollFixtures::learners(2, ['region_code' => 'UZ-SA']);
    PollFixtures::learners(1, ['region_code' => 'other']);
    PollFixtures::learners(3, ['region_code' => null]);

    $page = Livewire::test(CreatePoll::class)->fillForm(pollForm(['audience' => ['plan' => 'all', 'region_codes' => ['UZ-SA', 'other'], 'onboarded_only' => true]]));
    $render = QueryLog::during(fn () => $page->set('data.title', 'Ещё'));
    expect(QueryLog::matching($render, '/from "learners"/'))->toBe([]);

    $page->callAction(TestAction::make('countRecipients')->schemaComponent('audienceSection', 'form'));

    expect(pollNotices())->toContain('Получателей сейчас: 3')
        ->and(app(PollAudienceQuery::class)->count(['region_codes' => ['other']]))->toBe(1)
        ->and(app(PollAudienceQuery::class)->count([]))->toBe(6);
})->skip(fn (): bool => ! class_exists(TestAction::class), 'TestAction is not available');

it('sends the poll to the admin\'s own Telegram and reports each outcome', function (): void {
    $poll = PollFixtures::poll();

    Livewire::test(EditPoll::class, ['record' => $poll->id])->callAction('testSend', ['locale' => 'uz']);

    $test = PollDelivery::query()->where('poll_id', $poll->id)->sole();
    expect($test->is_test)->toBeTrue()
        ->and($test->status)->toBe(PollDeliveryStatus::Sent)
        ->and($test->locale)->toBe('uz')
        ->and($this->tg->textMessages[0]['chat_id'])->toBe(555_000_111)
        ->and(pollNotices())->toBe(['Опрос отправлен вам в Telegram']);

    $this->tg->failSends = 1;
    $this->tg->failSendsForbidden = 'blocked';
    Livewire::test(EditPoll::class, ['record' => $poll->id])->callAction('testSend', ['locale' => 'ru']);
    expect(pollNotices()[0])->toStartWith('Telegram не доставил сообщение');
});

it('disables the test send for an admin without Telegram and words the refusals of the test send', function (): void {
    $this->admin->forceFill(['telegram_user_id' => null])->save();
    $poll = PollFixtures::poll();

    Livewire::test(EditPoll::class, ['record' => $poll->id])->assertActionDisabled('testSend');

    $this->admin->forceFill(['telegram_user_id' => 555_000_111])->save();
    $short = PollFixtures::poll([], options: 1);
    Livewire::test(EditPoll::class, ['record' => $short->id])->callAction('testSend', ['locale' => 'ru']);

    expect(pollNotices())->toBe(['Нужно от 2 до 10 вариантов ответа.']);
});

it('refuses the launch without a test send and launches after one, once', function (): void {
    Queue::fake();
    PollFixtures::learners(3);
    $poll = PollFixtures::poll();

    Livewire::test(EditPoll::class, ['record' => $poll->id])
        ->mountAction('launch')
        ->assertMountedActionModalSee('Опрос уйдёт примерно 3 ученикам')
        ->callMountedAction();

    expect($poll->fresh()->status)->toBe(PollStatus::Draft)
        ->and(pollNotices())->toBe(['Сначала отправьте опрос себе и убедитесь, что он дошёл: без тестовой отправки запуск невозможен.']);

    PollFixtures::tested($poll);
    Livewire::test(EditPoll::class, ['record' => $poll->id])->callAction('launch');

    $poll->refresh();
    expect($poll->status)->toBe(PollStatus::Sending)
        ->and($poll->recipient_count)->toBe(3)
        ->and($poll->launched_by_user_id)->toBe($this->admin->id)
        ->and(pollNotices())->toBe(['Опрос запущен: 3 получателей'])
        ->and(AuditLog::query()->where('action', 'poll.launched')->count())->toBe(1);

    Livewire::test(EditPoll::class, ['record' => $poll->id])->assertActionHidden('launch');
});

it('words every refusal of the launch', function (string $reason, string $text): void {
    expect(AdminErrors::text('polls', $reason))->toBe($text);
})->with([
    ['untested', 'Сначала отправьте опрос себе и убедитесь, что он дошёл: без тестовой отправки запуск невозможен.'],
    ['options', 'Нужно от 2 до 10 вариантов ответа.'],
    ['too_long', 'Текст не помещается в сообщение Telegram на одном из языков: сократите вопрос или варианты (с картинкой — до 1 024 символов вместе со списком вариантов).'],
    ['no_telegram', 'К вашему профилю не привязан Telegram: тестовую отправку некуда отправить.'],
    ['finished', 'Опрос закрыт или остановлен: менять в нём можно только название.'],
]);

it('refuses the launch of a text that does not fit Telegram', function (): void {
    Queue::fake();
    $poll = PollFixtures::poll(['question' => ['ru' => str_repeat('я', 700), 'uz' => str_repeat('a', 700)], 'image_path' => 'polls/x.png'], options: 10);
    PollOption::query()->where('poll_id', $poll->id)->update(['label' => json_encode(['ru' => str_repeat('д', 40), 'uz' => str_repeat('u', 40)])]);
    PollFixtures::tested($poll);

    Livewire::test(EditPoll::class, ['record' => $poll->id])->callAction('launch');

    expect($poll->fresh()->status)->toBe(PollStatus::Draft)
        ->and(pollNotices()[0])->toStartWith('Текст не помещается в сообщение Telegram');
});

it('stops a broadcast and closes a poll from the page, each once', function (): void {
    Queue::fake();
    $poll = PollFixtures::launched(2);

    Livewire::test(EditPoll::class, ['record' => $poll->id])
        ->assertActionVisible('cancel')
        ->assertActionVisible('close')
        ->callAction('cancel');

    expect($poll->fresh()->status)->toBe(PollStatus::Cancelled)
        ->and(PollDelivery::query()->where('poll_id', $poll->id)->whereNotNull('learner_id')->where('status', 'pending')->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'poll.cancelled')->where('user_id', $this->admin->id)->count())->toBe(1);

    $open = PollFixtures::launched(1);
    Livewire::test(EditPoll::class, ['record' => $open->id])->callAction('close');

    expect($open->fresh()->status)->toBe(PollStatus::Closed)
        ->and($open->fresh()->results_snapshot)->toBeArray()
        ->and(AuditLog::query()->where('action', 'poll.closed')->where('user_id', $this->admin->id)->count())->toBe(1);

    Livewire::test(EditPoll::class, ['record' => $open->id])
        ->assertActionHidden('close')
        ->assertActionHidden('cancel')
        ->assertActionHidden('testSend')
        ->assertActionHidden('launch');
});

it('deletes a draft with its picture', function (): void {
    Storage::disk('local')->put('polls/'.str_repeat('c', 40).'.png', 'x');
    $poll = PollFixtures::poll(['image_path' => 'polls/'.str_repeat('c', 40).'.png']);

    Livewire::test(EditPoll::class, ['record' => $poll->id])->callAction('delete');

    expect(Poll::query()->find($poll->id))->toBeNull();
    Storage::disk('local')->assertMissing('polls/'.str_repeat('c', 40).'.png');
});

// --- the list ----------------------------------------------------------------------------------------------------------------

it('lists the polls within the query budget: no statement per row', function (): void {
    $fill = function (int $rows): void {
        for ($i = Poll::query()->count(); $i < $rows; $i++) {
            Poll::factory()->create(['status' => [PollStatus::Draft, PollStatus::Sending, PollStatus::Closed][$i % 3], 'recipient_count' => $i]);
        }
    };

    $queries = FilamentQueryBudget::assert(ListPolls::class, $fill, ceiling: 6);

    expect(count($queries[FilamentQueryBudget::SMALL]))->toBe(count($queries[FilamentQueryBudget::LARGE]))
        ->and(QueryLog::matching($queries[FilamentQueryBudget::LARGE], '/"question"|results_snapshot|select \* from "polls"/'))->toBe([]);
});

it('opens the list, the form of a draft and of a launched poll', function (): void {
    $draft = PollFixtures::poll();
    $sent = PollFixtures::poll(['status' => PollStatus::Sent, 'launched_at' => now(), 'title' => 'Разосланный']);

    Livewire::test(ListPolls::class)->assertCanSeeTableRecords([$draft, $sent])->assertSee('Разосланный')->assertSee('Черновик');
    Livewire::test(EditPoll::class, ['record' => $draft->id])->assertOk()->assertSee('Какой формат занятий вам удобнее?');
    Livewire::test(EditPoll::class, ['record' => $sent->id])->assertOk()->assertSee('Опрос уже запущен');
    $this->withSession(['2fa.web.passed_at' => now()->timestamp, '2fa.web.user_id' => $this->admin->id])->get('/admin/polls/create')->assertOk();
});

it('does not count the audience of a draft when the form renders', function (): void {
    Learner::factory()->onboarded()->count(3)->create();
    $poll = PollFixtures::poll();

    $queries = QueryLog::during(fn () => Livewire::test(EditPoll::class, ['record' => $poll->id]));

    expect(QueryLog::matching($queries, '/count\(\*\).*from "learners"|from "learners".*count/'))->toBe([]);
});
