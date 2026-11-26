<?php

declare(strict_types=1);

use App\Domain\Cup\Enums\CupStatus;
use App\Domain\Cup\Services\CupPlatformAdmin;
use App\Filament\Resources\CupResource;
use App\Filament\Resources\CupResource\Pages\ListCups;
use App\Filament\Resources\CupResource\Pages\ViewCup;
use App\Filament\Resources\CupResource\RelationManagers\AttemptsRelationManager;
use App\Filament\Resources\CupResource\RelationManagers\ParticipantsRelationManager;
use App\Filament\Resources\TelegramGroupResource\Pages\EditTelegramGroup;
use App\Filament\Resources\TelegramGroupResource\Pages\ListTelegramGroups;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Cup;
use App\Models\CupDay;
use App\Models\CupParticipant;
use App\Models\CupRoundAttempt;
use App\Models\Learner;
use App\Models\TelegramGroup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use function Pest\Livewire\livewire;

use Tests\Support\Cup\CupKit;
use Tests\Support\FilamentQueryBudget;

uses(RefreshDatabase::class);

/*
 * docs/33 §8 (K9): the cups in /admin - an active platform admin only (a teacher of the panel gets 403 on the pages and on a direct
 * Livewire call of every action); the list with its filters and a budget that does not grow with the rows; the page of a cup with the
 * participants and the attempts of a day; "Отменить кубок" (reason required), "Сбросить имя", "Запретить кубки", "Кубок: пилот" - each
 * with its audit record.
 */

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-12-03 10:00:00');
    CupKit::open();
    $this->seed(RolePermissionSeeder::class);
    app()->setLocale('ru');
    $this->admin = cupAdminUser('admin');
    $this->actingAs($this->admin);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function cupAdminUser(string $role, bool $active = true): User
{
    $user = User::factory()->create([
        'is_active' => $active,
        'two_factor_secret' => 'stub',
        'two_factor_recovery_codes' => [],
        'two_factor_confirmed_at' => now(),
    ]);
    $user->assignRole($role);

    return $user;
}

/** A running cup of its own group with `$players` participants (rows only: the panel reads, it does not play). */
function cupAdminRunning(int $players = 2, ?TelegramGroup $group = null, ?Course $course = null): Cup
{
    $cup = Cup::factory()->create([
        'telegram_group_id' => ($group ?? CupKit::group())->id,
        'course_id' => ($course ?? Course::factory()->published()->create())->id,
        'status' => CupStatus::Running,
        'starts_at' => CarbonImmutable::parse('2026-11-30 19:00:00'),
        'ends_at' => CarbonImmutable::parse('2026-12-07 19:00:00'),
        'confirmed_at' => now()->subDays(3),
    ]);
    for ($i = 0; $i < $players; $i++) {
        CupParticipant::factory()->create(['cup_id' => $cup->id, 'best_total' => 10 * $i, 'days_played' => $i % 2]);
    }

    return $cup;
}

function cupAdminSession(object $test, User $user): object
{
    return $test->actingAs($user)->withSession(['2fa.web.passed_at' => now()->timestamp, '2fa.web.user_id' => $user->id]);
}

it('opens the pages for an active admin and for nobody else', function (): void {
    $cup = cupAdminRunning();
    $urls = ['/admin/cups', "/admin/cups/{$cup->id}"];

    foreach ($urls as $url) {
        cupAdminSession($this, $this->admin)->get($url)->assertOk();
    }
    $teacher = cupAdminUser('teacher');
    $teacher->telegramGroups()->attach($cup->telegram_group_id);
    foreach ([$teacher, cupAdminUser('admin', active: false)] as $user) {
        foreach ($urls as $url) {
            cupAdminSession($this, $user)->get($url)->assertForbidden();
        }
    }
});

it('refuses a teacher every action, even called on the component directly', function (): void {
    $cup = cupAdminRunning();
    $participant = CupParticipant::query()->where('cup_id', $cup->id)->firstOrFail();
    $teacher = cupAdminUser('teacher');
    $teacher->telegramGroups()->attach($cup->telegram_group_id);
    $this->actingAs($teacher);

    livewire(ListCups::class)->assertForbidden();
    livewire(ParticipantsRelationManager::class, ['ownerRecord' => $cup, 'pageClass' => ViewCup::class])->assertForbidden();
    livewire(AttemptsRelationManager::class, ['ownerRecord' => $cup, 'pageClass' => ViewCup::class])->assertForbidden();
    // The list of groups is the teacher's too: the cup buttons are hidden and not authorized (a mount of a hidden action is refused).
    livewire(ListTelegramGroups::class)
        ->assertTableActionHidden('cups_block', $cup->telegramGroup)
        ->assertTableActionHidden('cup_pilot', $cup->telegramGroup);
    expect(CupResource::can('manage'))->toBeFalse();

    // ... and the service behind the buttons says no on its own.
    expect(fn () => app(CupPlatformAdmin::class)->cancel($cup, $teacher, 'spam'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(CupPlatformAdmin::class)->resetName($participant, $teacher))->toThrow(AuthorizationException::class)
        ->and(fn () => app(CupPlatformAdmin::class)->setCupsBlocked($cup->telegramGroup, true, $teacher))->toThrow(AuthorizationException::class)
        ->and($cup->fresh()?->status)->toBe(CupStatus::Running)
        ->and(TelegramGroup::query()->findOrFail($cup->telegram_group_id)->meta)->not->toHaveKey('cups_blocked')
        ->and(AuditLog::query()->count())->toBe(0);
});

it('lists the cups with their group, status, pause, day and counts, and filters them', function (): void {
    $running = cupAdminRunning(3);
    $blockedGroup = CupKit::group(['cups_blocked' => true]);
    $paused = cupAdminRunning(2, $blockedGroup);
    $pilotGroup = CupKit::group(['cup_pilot' => true]);
    $finished = Cup::factory()->create(['telegram_group_id' => $pilotGroup->id, 'status' => CupStatus::Finished, 'starts_at' => now()->subDays(20), 'ends_at' => now()->subDays(13), 'finished_at' => now()->subDays(13)]);

    livewire(ListCups::class)
        ->assertCanSeeTableRecords([$running, $paused, $finished])
        ->assertTableColumnStateSet('participants_count', 3, $running)
        ->assertTableColumnStateSet('played_count', 1, $running)
        ->assertTableColumnStateSet('day', 'День 3 из 7', $running)
        ->assertTableColumnStateSet('paused', 'blocked', $paused)
        ->assertTableColumnStateSet('paused', 'none', $running)
        ->filterTable('status', 'finished')
        ->assertCanSeeTableRecords([$finished])
        ->assertCanNotSeeTableRecords([$running, $paused]);

    livewire(ListCups::class)->filterTable('paused')->assertCanSeeTableRecords([$paused])->assertCanNotSeeTableRecords([$running, $finished]);
    livewire(ListCups::class)->filterTable('pilot')->assertCanSeeTableRecords([$finished])->assertCanNotSeeTableRecords([$running, $paused]);
    livewire(ListCups::class)->searchTable(mb_substr($blockedGroup->title, 0, 12))->assertCanSeeTableRecords([$paused]);
});

it('cancels an open cup with a required reason and an audit record, once', function (): void {
    $cup = cupAdminRunning();

    livewire(ListCups::class)->callTableAction('cancel', $cup, ['reason' => ''])->assertHasTableActionErrors(['reason' => 'required']);
    expect($cup->fresh()?->status)->toBe(CupStatus::Running);

    livewire(ListCups::class)
        ->callTableAction('cancel', $cup, ['reason' => 'Оскорбления в чате'])
        ->assertHasNoTableActionErrors()
        ->assertNotified(__('admin.cups.notifications.canceled'));

    $fresh = Cup::query()->findOrFail($cup->id);
    expect($fresh->status)->toBe(CupStatus::Canceled)
        ->and($fresh->cancel_reason?->value)->toBe('platform_admin')
        ->and(AuditLog::query()->where('action', 'cup.canceled')->sole()->only(['user_id', 'entity_type', 'entity_id', 'meta']))->toEqual([
            'user_id' => $this->admin->id,
            'entity_type' => Cup::class,
            'entity_id' => $cup->id,
            'meta' => ['reason' => 'Оскорбления в чате', 'from' => 'running', 'telegram_group_id' => $cup->telegram_group_id],
        ]);

    livewire(ListCups::class)->assertTableActionHidden('cancel', $fresh);
    CupResource::cancel($fresh, 'again');
    expect(AuditLog::query()->where('action', 'cup.canceled')->count())->toBe(1);

    $other = cupAdminRunning();
    livewire(ViewCup::class, ['record' => $other->id])->callAction('cancel', ['reason' => 'Пилот закрыт'])->assertNotified(__('admin.cups.notifications.canceled'));
    expect($other->fresh()?->status)->toBe(CupStatus::Canceled);
});

it('resets an offensive name to the Telegram first name with an audit record, without spending a rename', function (): void {
    $cup = cupAdminRunning(0);
    $learner = Learner::factory()->create(['first_name' => 'Aziz']);
    $participant = CupParticipant::factory()->create(['cup_id' => $cup->id, 'learner_id' => $learner->id, 'display_name' => 'Bad Word', 'rename_count' => 2]);

    livewire(ParticipantsRelationManager::class, ['ownerRecord' => $cup, 'pageClass' => ViewCup::class])
        ->assertCanSeeTableRecords([$participant])
        ->callTableAction('reset_name', $participant)
        ->assertNotified(__('admin.cups.participants.notifications.reset'));

    expect($participant->fresh()?->display_name)->toBe('Aziz')
        ->and($participant->fresh()?->rename_count)->toBe(2)
        ->and(AuditLog::query()->where('action', 'cup.participant_renamed')->sole()->meta)->toEqual(['cup_id' => $cup->id, 'from' => 'Bad Word', 'to' => 'Aziz']);

    livewire(ParticipantsRelationManager::class, ['ownerRecord' => $cup, 'pageClass' => ViewCup::class])->callTableAction('reset_name', $participant->fresh());
    expect(AuditLog::query()->where('action', 'cup.participant_renamed')->count())->toBe(1);
});

it('forbids cups in a group and puts it into the pilot from the list of groups, each change audited', function (): void {
    $cup = cupAdminRunning();
    $group = TelegramGroup::query()->findOrFail($cup->telegram_group_id);
    $group->forceFill(['meta' => ['locale' => 'uz']])->save();

    livewire(ListTelegramGroups::class)->callTableAction('cups_block', $group)->assertNotified(__('admin.cups.notifications.saved'));
    livewire(ListTelegramGroups::class)->callTableAction('cup_pilot', $group);

    $meta = TelegramGroup::query()->findOrFail($group->id)->meta;
    expect($meta)->toEqual(['locale' => 'uz', 'cups_blocked' => true, 'cup_pilot' => true])
        ->and(AuditLog::query()->orderBy('id')->pluck('action')->all())->toBe(['cup.group_blocked', 'cup.group_pilot_on']);

    // The open cup of the group is paused: no new round (docs/33 §3.1).
    livewire(ListCups::class)->assertTableColumnStateSet('paused', 'blocked', $cup);

    livewire(ViewCup::class, ['record' => $cup->id])->callAction('cups_block');
    livewire(ListTelegramGroups::class)->callTableAction('cup_pilot', $group);
    expect(TelegramGroup::query()->findOrFail($group->id)->meta)->toBe(['locale' => 'uz'])
        ->and(AuditLog::query()->orderBy('id')->pluck('action')->all())->toBe(['cup.group_blocked', 'cup.group_pilot_on', 'cup.group_unblocked', 'cup.group_pilot_off']);
});

it('shows the attempts of one chosen day only', function (): void {
    $cup = cupAdminRunning(0);
    $days = [];
    foreach ([1, 2, 3] as $n) {
        $days[$n] = CupDay::factory()->create(['cup_id' => $cup->id, 'day' => $n, 'starts_at' => CarbonImmutable::parse('2026-11-30 19:00:00')->addDays($n - 1), 'ends_at' => CarbonImmutable::parse('2026-11-30 19:00:00')->addDays($n)]);
    }
    $p = CupParticipant::factory()->create(['cup_id' => $cup->id, 'display_name' => 'Malika']);
    $attempts = [];
    foreach ([1, 3] as $n) {
        $attempts[$n] = cupAdminAttempt($cup, $days[$n], $p, 6 * $n);
    }

    // Today is day 3 of the cup: the default.
    livewire(AttemptsRelationManager::class, ['ownerRecord' => $cup, 'pageClass' => ViewCup::class])
        ->assertCanSeeTableRecords([$attempts[3]])
        ->assertCanNotSeeTableRecords([$attempts[1]])
        ->filterTable('day', '1')
        ->assertCanSeeTableRecords([$attempts[1]])
        ->assertCanNotSeeTableRecords([$attempts[3]])
        ->filterTable('day', '2')
        ->assertCountTableRecords(0);
});

function cupAdminAttempt(Cup $cup, CupDay $day, CupParticipant $participant, int $points): CupRoundAttempt
{
    $id = DB::table('cup_round_attempts')->insertGetId([
        'cup_id' => $cup->id, 'cup_day_id' => $day->id, 'participant_id' => $participant->id, 'learner_id' => $participant->learner_id,
        'order' => '[]', 'state' => '{}', 'answered' => 10, 'correct' => intdiv($points, 2), 'points' => $points, 'status' => 'finished',
        'finish_reason' => 'completed', 'locale' => 'ru', 'started_at' => $day->starts_at, 'expires_at' => $day->starts_at->copy()->addMinutes(10), 'finished_at' => $day->starts_at->copy()->addMinutes(5),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return CupRoundAttempt::query()->findOrFail($id);
}

it('lists the cups within a budget that does not grow with the number of rows', function (): void {
    $course = Course::factory()->published()->create();
    $groups = [CupKit::group(), CupKit::group(['cups_blocked' => true])];

    FilamentQueryBudget::assert(ListCups::class, function (int $rows) use ($course, $groups): void {
        for ($i = Cup::query()->count(); $i < $rows; $i++) {
            $cup = Cup::factory()->create([
                'telegram_group_id' => $groups[$i % 2]->id,
                'course_id' => $course->id,
                'status' => CupStatus::Finished,
                'starts_at' => now()->subDays(20),
                'ends_at' => now()->subDays(13),
                'finished_at' => now()->subDays(13),
            ]);
            CupParticipant::factory()->count(2)->create(['cup_id' => $cup->id, 'days_played' => 1]);
        }
    }, ceiling: 10); // two renders of 5: the count, the page, the groups, the courses, the participants of the page (one grouped statement)
});

it('tells the admin a participant who left the cup himself from one who left the chat, from the row of the page', function (): void {
    $cup = cupAdminRunning(0);
    $active = CupParticipant::factory()->create(['cup_id' => $cup->id, 'status' => 'active']);
    $chat = CupParticipant::factory()->create(['cup_id' => $cup->id, 'status' => 'left', 'left_at' => now()]);
    $withdrawn = CupParticipant::factory()->create(['cup_id' => $cup->id, 'status' => 'left', 'left_at' => now(), 'withdrawn_at' => now()]);

    livewire(ParticipantsRelationManager::class, ['ownerRecord' => $cup, 'pageClass' => ViewCup::class])
        ->assertTableColumnStateSet('status', 'active', $active)
        ->assertTableColumnStateSet('status', 'left', $chat)
        ->assertTableColumnStateSet('status', 'withdrawn', $withdrawn)
        ->assertTableColumnFormattedStateSet('status', 'Вышел(а) из чата', $chat)
        ->assertTableColumnFormattedStateSet('status', 'Вышел(а) из кубка сам(а)', $withdrawn);
});

it('reads the participants and the attempts of a day within a budget that does not grow with the rows', function (): void {
    $cup = cupAdminRunning(0);
    $day = CupDay::factory()->create(['cup_id' => $cup->id, 'day' => 3, 'starts_at' => CarbonImmutable::parse('2026-12-02 19:00:00'), 'ends_at' => CarbonImmutable::parse('2026-12-03 19:00:00')]);

    FilamentQueryBudget::assert(ParticipantsRelationManager::class, function (int $rows) use ($cup): void {
        for ($i = CupParticipant::query()->count(); $i < $rows; $i++) {
            CupParticipant::factory()->create(['cup_id' => $cup->id, 'best_total' => $i]);
        }
    }, ceiling: 5, parameters: ['ownerRecord' => $cup, 'pageClass' => ViewCup::class]); // the count and the page twice, the owner once

    FilamentQueryBudget::assert(AttemptsRelationManager::class, function (int $rows) use ($cup, $day): void {
        foreach (CupParticipant::query()->where('cup_id', $cup->id)->orderBy('id')->get() as $i => $participant) {
            if ($i < $rows && ! CupRoundAttempt::query()->where('participant_id', $participant->id)->exists()) {
                cupAdminAttempt($cup, $day, $participant, 4);
            }
        }
    }, ceiling: 5, parameters: ['ownerRecord' => $cup, 'pageClass' => ViewCup::class]); // the page (simple: no count) and the names twice, the owner once
});

it('keeps the flags of the cups when the form of the group is saved after an action set one, and never takes them from the form', function (): void {
    $cup = cupAdminRunning();
    $group = TelegramGroup::query()->findOrFail($cup->telegram_group_id);
    $group->forceFill(['meta' => ['locale' => 'uz', 'cup_pilot' => true, 'type' => 'supergroup']])->save();

    // The form is opened: the flags are not in its key/value editor (they would come back as strings).
    $form = livewire(EditTelegramGroup::class, ['record' => $group->getKey()]);
    expect(array_values($form->get('data.meta')))->toBe([['key' => 'type', 'value' => 'supergroup']]);

    // Meanwhile another administrator presses «Запретить кубки» (audited, under FOR UPDATE of the row).
    app(CupPlatformAdmin::class)->setCupsBlocked(TelegramGroup::query()->findOrFail($group->id), true, $this->admin);

    // The form is saved with a key typed by hand that tries to switch the pilot off as a string.
    $form->fillForm(['title' => 'Renamed', 'meta' => ['type' => 'supergroup', 'cup_pilot' => '0', 'cups_blocked' => '0']])->call('save')->assertHasNoFormErrors();

    $meta = TelegramGroup::query()->findOrFail($group->id)->meta;
    expect($meta['cups_blocked'] ?? null)->toBeTrue()   // the action is not undone, and it is a bool
        ->and($meta['cup_pilot'] ?? null)->toBeTrue()    // the hand-typed key changed nothing
        ->and($meta['locale'] ?? null)->toBe('uz')
        ->and($meta['type'] ?? null)->toBe('supergroup')
        ->and(TelegramGroup::query()->findOrFail($group->id)->title)->toBe('Renamed');
});
