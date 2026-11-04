<?php

declare(strict_types=1);

use App\Domain\Game\Enums\ClassCaravanStatus;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Services\OrgTenantScope;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Filament\Org\Resources\ClassCaravanResource;
use App\Filament\Org\Resources\ClassCaravanResource\Pages\ListClassCaravans;
use App\Filament\Org\Resources\ClassCaravanResource\Pages\ViewClassCaravan;
use App\Models\ClassCaravan;
use App\Models\ClassCaravanMember;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Exceptions\ActionNotResolvableException;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Support\FakeTelegramClient;
use Tests\Support\FilamentQueryBudget;
use Tests\Support\Game\GameKit;

/*
 * The "Caravans" page of `/org` (docs/18 §12.10, §12.11, G2.9b): the owner sees the caravans of every group of the center, a teacher those of
 * their own groups, another center's are 404; start and cancel go through ClassCaravanService and tell the chat of the group what the bot's
 * `/start_caravan` and `/cancel_caravan` tell; an owner who does not teach a group looks and cannot run it; the page is closed with the game.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-05 10:00:00');
    GameKit::openAccess();
    config(['game.class.centers_only' => true, 'twa.base_url' => 'https://twa.test']);
    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);

    $this->a = Organization::factory()->create(['name' => 'Center A', 'slug' => 'center-a']);
    $this->b = Organization::factory()->create(['name' => 'Center B', 'slug' => 'center-b']);
    Subscription::factory()->forOrganization($this->a)->create();
    Subscription::factory()->forOrganization($this->b)->create();

    $this->ownerA = crvStaff($this->a, OrganizationRole::Owner);
    $this->ownerB = crvStaff($this->b, OrganizationRole::Owner);
    $this->teacherA = crvStaff($this->a, OrganizationRole::Teacher);
    $this->teacherA2 = crvStaff($this->a, OrganizationRole::Teacher);

    $this->course = Course::factory()->published()->create();
    $this->g1 = TelegramGroup::factory()->create(['chat_id' => -101, 'organization_id' => $this->a->id, 'course_id' => $this->course->id, 'title' => 'Mine', 'status' => 'active']);
    $this->g2 = TelegramGroup::factory()->create(['chat_id' => -102, 'organization_id' => $this->a->id, 'course_id' => $this->course->id, 'title' => 'Not mine', 'status' => 'active']);
    $this->gB = TelegramGroup::factory()->create(['chat_id' => -103, 'organization_id' => $this->b->id, 'course_id' => $this->course->id, 'title' => 'Other center', 'status' => 'active']);
    $this->teacherA->telegramGroups()->attach($this->g1->id, ['is_primary' => true]);
    $this->ownerB->telegramGroups()->attach($this->gB->id, ['is_primary' => true]);

    $this->c1 = crvCaravan($this->g1, $this->a);
    $this->c2 = crvCaravan($this->g2, $this->a);
    $this->cB = crvCaravan($this->gB, $this->b);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function crvStaff(Organization $organization, OrganizationRole $role): User
{
    $user = User::factory()->create([
        'is_active' => true,
        'two_factor_secret' => 'stub',
        'two_factor_recovery_codes' => [],
        'two_factor_confirmed_at' => now(),
    ]);
    OrganizationUser::factory()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role->value]);

    return $user;
}

/** @param array<string, mixed> $attributes */
function crvCaravan(TelegramGroup $group, Organization $organization, array $attributes = []): ClassCaravan
{
    return ClassCaravan::factory()->create([
        'telegram_group_id' => $group->id,
        'organization_id' => $organization->id,
        'course_id' => $group->course_id,
        'target_stars' => 18,
        ...$attributes,
    ]);
}

function crvEnter(User $user, Organization $tenant): void
{
    Filament::setCurrentPanel(Filament::getPanel('org'));
    Filament::bootCurrentPanel();
    test()->actingAs($user, 'org');
    Session::put('2fa.org.passed_at', now()->timestamp);
    Session::put('2fa.org.user_id', $user->id);
    Filament::setTenant($tenant, isQuiet: true);
}

function crvVisit(User $user, string $path): TestResponse
{
    return test()->actingAs($user, 'org')
        ->withSession(['2fa.org.passed_at' => now()->timestamp, '2fa.org.user_id' => $user->id])
        ->get($path);
}

// ---------------------------------------------------------------------- who sees what

it('lists the caravans of every group of the center for the owner and nothing of another center', function (): void {
    crvEnter($this->ownerA, $this->a);

    Livewire::test(ListClassCaravans::class)
        ->assertCanSeeTableRecords([$this->c1, $this->c2])
        ->assertCanNotSeeTableRecords([$this->cB]);
});

it('lists only the caravans of their own groups for a teacher, and nothing for a teacher of no group', function (): void {
    crvEnter($this->teacherA, $this->a);
    Livewire::test(ListClassCaravans::class)
        ->assertCanSeeTableRecords([$this->c1])
        ->assertCanNotSeeTableRecords([$this->c2, $this->cB]);

    crvEnter($this->teacherA2, $this->a);
    Livewire::test(ListClassCaravans::class)->assertCanNotSeeTableRecords([$this->c1, $this->c2, $this->cB]);
});

it('does not list the caravan of a group that has left the center', function (): void {
    $this->g2->forceFill(['organization_id' => $this->b->id])->save();
    crvEnter($this->ownerA, $this->a);

    Livewire::test(ListClassCaravans::class)->assertCanSeeTableRecords([$this->c1])->assertCanNotSeeTableRecords([$this->c2]);
});

it('answers 404 to a caravan of another center or of a group the teacher does not run', function (): void {
    crvVisit($this->ownerA, "/org/center-a/class-caravans/{$this->c1->id}")->assertOk();
    crvVisit($this->ownerA, "/org/center-a/class-caravans/{$this->c2->id}")->assertOk();
    crvVisit($this->ownerA, "/org/center-a/class-caravans/{$this->cB->id}")->assertNotFound();
    crvVisit($this->ownerB, "/org/center-a/class-caravans/{$this->c1->id}")->assertForbidden(); // not staff of the center: the panel's own check

    crvVisit($this->teacherA, "/org/center-a/class-caravans/{$this->c1->id}")->assertOk();
    crvVisit($this->teacherA, "/org/center-a/class-caravans/{$this->c2->id}")->assertNotFound();
    crvVisit($this->teacherA, '/org/center-a/class-caravans')->assertOk();
});

it('is closed while the game is off: no page, no menu item', function (): void {
    config(['game.access' => 'off']);

    crvVisit($this->ownerA, '/org/center-a/class-caravans')->assertForbidden();
    crvVisit($this->ownerA, "/org/center-a/class-caravans/{$this->c1->id}")->assertForbidden();

    crvEnter($this->ownerA, $this->a);
    expect(ClassCaravanResource::shouldRegisterNavigation())->toBeFalse();

    config(['game.access' => 'all']);
    expect(ClassCaravanResource::shouldRegisterNavigation())->toBeTrue();
});

it('is closed in the pilot mode too: the caravan is for the whole group, so no page, no menu item, no start', function (): void {
    config(['game.access' => 'pilot']);
    $this->c1->forceFill(['status' => ClassCaravanStatus::Finished, 'active_group_id' => null])->save();

    crvVisit($this->ownerA, '/org/center-a/class-caravans')->assertForbidden();
    crvVisit($this->teacherA, "/org/center-a/class-caravans/{$this->c1->id}")->assertForbidden();

    crvEnter($this->teacherA, $this->a);
    expect(ClassCaravanResource::shouldRegisterNavigation())->toBeFalse();

    ClassCaravanResource::start($this->g1->id);

    expect(ClassCaravan::query()->where('telegram_group_id', $this->g1->id)->where('status', 'active')->count())->toBe(0)
        ->and($this->tg->sent)->toBe([]);
});

// ---------------------------------------------------------------------- the list

it('shows the group, the course, the status, the stars against the target, the week and the boss in the list', function (): void {
    $this->c1->forceFill(['stars_total' => 7, 'boss_unlocked_at' => now()])->save();
    $this->c2->forceFill(['status' => ClassCaravanStatus::Finished, 'active_group_id' => null, 'stars_total' => 18, 'boss_unlocked_at' => now(), 'boss_defeated_at' => now()])->save();
    ClassCaravanMember::factory()->create(['class_caravan_id' => $this->c1->id, 'stars' => 7, 'runs' => 2]);
    crvEnter($this->ownerA, $this->a);

    Livewire::test(ListClassCaravans::class)
        ->assertTableColumnStateSet('group.title', 'Mine', $this->c1)
        ->assertTableColumnStateSet('status', 'active', $this->c1)
        ->assertTableColumnStateSet('stars_total', '7 / 18', $this->c1)
        ->assertTableColumnStateSet('boss', 'ready', $this->c1)
        ->assertTableColumnStateSet('members_count', 1, $this->c1)
        ->assertTableColumnStateSet('status', 'finished', $this->c2)
        ->assertTableColumnStateSet('boss', 'defeated', $this->c2)
        ->assertTableColumnStateSet('starts_at', '05.11.2026 15:00 — 12.11.2026 15:00', $this->c1); // the center is in Asia/Tashkent
});

it('filters the list by status', function (): void {
    $this->c2->forceFill(['status' => ClassCaravanStatus::Canceled, 'active_group_id' => null])->save();
    crvEnter($this->ownerA, $this->a);

    Livewire::test(ListClassCaravans::class)
        ->filterTable('status', 'canceled')
        ->assertCanSeeTableRecords([$this->c2])
        ->assertCanNotSeeTableRecords([$this->c1]);
});

it('shows the contributions of the members on the view page', function (): void {
    ClassCaravanMember::factory()->create(['class_caravan_id' => $this->c1->id, 'learner_id' => Learner::factory()->create(['first_name' => 'Anna']), 'stars' => 5, 'runs' => 2, 'best_score' => 80]);
    crvEnter($this->teacherA, $this->a);

    Livewire::test(ViewClassCaravan::class, ['record' => $this->c1->id])->assertSee('Anna')->assertSee('80');
});

it('keeps a deleted account in the contributions without his name', function (): void {
    $gone = Learner::factory()->create(['first_name' => 'Zafar']);
    ClassCaravanMember::factory()->create(['class_caravan_id' => $this->c1->id, 'learner_id' => $gone->id, 'stars' => 9, 'runs' => 3, 'best_score' => 77]);
    ClassCaravanMember::factory()->create(['class_caravan_id' => $this->c1->id, 'learner_id' => Learner::factory()->create(['first_name' => 'Anna']), 'stars' => 2, 'runs' => 1, 'best_score' => 50]);
    $gone->delete();
    crvEnter($this->teacherA, $this->a);

    Livewire::test(ViewClassCaravan::class, ['record' => $this->c1->id])->assertSee('Anna')->assertSee('77')->assertDontSee('Zafar');
});

// ---------------------------------------------------------------------- start

it('starts a caravan in a group of the teacher and tells the chat of the group', function (): void {
    $this->c1->forceFill(['status' => ClassCaravanStatus::Finished, 'active_group_id' => null, 'finished_at' => now()])->save();
    crvEnter($this->teacherA, $this->a);

    Livewire::test(ListClassCaravans::class)
        ->callTableAction('start', data: ['telegram_group_id' => $this->g1->id])
        ->assertNotified(__('org.caravans.notifications.started'));

    $caravan = ClassCaravan::query()->where('telegram_group_id', $this->g1->id)->where('status', 'active')->sole();
    expect($caravan->started_by_user_id)->toBe($this->teacherA->id)
        ->and($caravan->organization_id)->toBe($this->a->id)
        ->and($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['chat_id'])->toBe(-101)
        ->and($this->tg->sent[0]['text'])->toContain('Караван класса отправился')
        ->and($this->tg->sent[0]['url'])->toBe("https://twa.test/twa/game/class/{$caravan->id}");
});

it('offers a teacher only their own groups to start in, and refuses a forged group', function (): void {
    $this->c1->forceFill(['status' => ClassCaravanStatus::Finished, 'active_group_id' => null])->save();
    $this->c2->forceFill(['status' => ClassCaravanStatus::Finished, 'active_group_id' => null])->save();
    crvEnter($this->teacherA, $this->a);

    expect(ClassCaravanResource::groupOptions())->toBe([$this->g1->id => 'Mine']);

    foreach ([$this->g2->id, $this->gB->id] as $forged) {
        // the form takes only what the select offers ...
        Livewire::test(ListClassCaravans::class)
            ->callTableAction('start', data: ['telegram_group_id' => $forged])
            ->assertHasTableActionErrors(['telegram_group_id']);
        // ... and the action itself does not trust the form
        ClassCaravanResource::start($forged);
    }

    expect(ClassCaravan::query()->withoutGlobalScopes()->where('status', 'active')->count())->toBe(1) // $this->cB, the tenant scope of Filament hides it
        ->and($this->tg->sent)->toBe([]);
});

it('lets an owner who teaches a group start in it, and only in it', function (): void {
    $this->c2->forceFill(['status' => ClassCaravanStatus::Finished, 'active_group_id' => null])->save();
    $this->ownerA->telegramGroups()->attach($this->g2->id, ['is_primary' => false]);
    crvEnter($this->ownerA, $this->a);

    expect(ClassCaravanResource::groupOptions())->toBe([$this->g2->id => 'Not mine']);

    Livewire::test(ListClassCaravans::class)
        ->callTableAction('start', data: ['telegram_group_id' => $this->g2->id])
        ->assertNotified(__('org.caravans.notifications.started'));

    expect(ClassCaravan::query()->where('telegram_group_id', $this->g2->id)->where('status', 'active')->count())->toBe(1);
});

it('shows an owner who teaches no group the caravans and neither the start nor the cancel', function (): void {
    crvEnter($this->ownerA, $this->a);

    Livewire::test(ListClassCaravans::class)
        ->assertCanSeeTableRecords([$this->c1, $this->c2])
        ->assertTableActionHidden('cancel', $this->c1)
        ->assertTableActionHidden('start');
    expect(ClassCaravanResource::can('cancel', $this->c1))->toBeFalse()->and(ClassCaravanResource::can('create'))->toBeFalse();
});

it('answers that the caravan of the group is on its way, and starts no second one', function (): void {
    crvEnter($this->teacherA, $this->a);

    Livewire::test(ListClassCaravans::class)
        ->callTableAction('start', data: ['telegram_group_id' => $this->g1->id])
        ->assertNotified(__('org.caravans.errors.already_active'));

    expect(ClassCaravan::query()->where('telegram_group_id', $this->g1->id)->count())->toBe(1)->and($this->tg->sent)->toBe([]);
});

it('refuses a group of a center without a live subscription: the caravan is for centers', function (): void {
    $this->c1->forceFill(['status' => ClassCaravanStatus::Finished, 'active_group_id' => null])->save();
    Subscription::query()->where('subscriber_type', 'organization')->where('subscriber_id', $this->a->id)->update(['status' => 'canceled', 'current_period_ends_at' => now()->subDay()]);
    crvEnter($this->teacherA, $this->a);

    Livewire::test(ListClassCaravans::class)
        ->callTableAction('start', data: ['telegram_group_id' => $this->g1->id])
        ->assertNotified(__('org.caravans.errors.centers_only'));

    expect(ClassCaravan::query()->where('telegram_group_id', $this->g1->id)->where('status', 'active')->count())->toBe(0);
});

it('withdraws the caravan and says so when the chat did not get the message', function (): void {
    $this->c1->forceFill(['status' => ClassCaravanStatus::Finished, 'active_group_id' => null])->save();
    $this->tg->failSends = 1;
    crvEnter($this->teacherA, $this->a);

    Livewire::test(ListClassCaravans::class)
        ->callTableAction('start', data: ['telegram_group_id' => $this->g1->id])
        ->assertNotified(__('org.caravans.notifications.started_not_delivered'));

    expect(ClassCaravan::query()->where('telegram_group_id', $this->g1->id)->where('status', 'active')->count())->toBe(0);
});

// ---------------------------------------------------------------------- cancel

it('cancels a caravan of the teacher from the list and from the view page, and tells the chat', function (): void {
    crvEnter($this->teacherA, $this->a);

    Livewire::test(ListClassCaravans::class)->callTableAction('cancel', $this->c1)->assertNotified(__('org.caravans.notifications.canceled'));

    $canceled = $this->c1->fresh();
    expect($canceled->status)->toBe(ClassCaravanStatus::Canceled)
        ->and($canceled->active_group_id)->toBeNull()
        ->and($canceled->meta['canceled_by_user_id'])->toBe($this->teacherA->id)
        ->and($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['chat_id'])->toBe(-101)
        ->and($this->tg->sent[0]['text'])->toContain('Караван класса отменён');

    $second = crvCaravan($this->g1, $this->a);
    Livewire::test(ViewClassCaravan::class, ['record' => $second->id])->callAction('cancel');
    expect($second->fresh()->status)->toBe(ClassCaravanStatus::Canceled);
});

it('does not let a teacher cancel the caravan of another group, and hides the cancel of a finished one', function (): void {
    crvEnter($this->teacherA, $this->a);
    $over = crvCaravan($this->g1, $this->a, ['status' => ClassCaravanStatus::Finished, 'active_group_id' => null]);

    expect(fn () => Livewire::test(ListClassCaravans::class)->callTableAction('cancel', $this->c2))->toThrow(ActionNotResolvableException::class);
    Livewire::test(ListClassCaravans::class)->assertTableActionHidden('cancel', $over);

    expect($this->c2->fresh()->status)->toBe(ClassCaravanStatus::Active)->and($this->tg->sent)->toBe([]);
});

it('keeps the cancellation when the chat does not take the message "canceled"', function (): void {
    crvEnter($this->teacherA, $this->a);
    $this->tg->failSends = 1;

    Livewire::test(ListClassCaravans::class)->callTableAction('cancel', $this->c1)->assertNotified(__('org.caravans.notifications.canceled'));

    expect($this->c1->fresh()->status)->toBe(ClassCaravanStatus::Canceled)->and($this->tg->sent)->toBe([]);
});

it('stays silent for the chat when the sweep closed the caravan under the page', function (): void {
    crvEnter($this->teacherA, $this->a);
    $stale = $this->c1->fresh();
    ClassCaravan::query()->whereKey($this->c1->id)->update(['status' => 'finished', 'active_group_id' => null, 'finished_at' => now()]);

    ClassCaravanResource::cancel($stale);

    expect($this->c1->fresh()->status)->toBe(ClassCaravanStatus::Finished)->and($this->tg->sent)->toBe([]);
});

it('words the refusals in the language of the cabinet, not of the chat of the group and not as the exception', function (): void {
    $this->g1->forceFill(['meta' => ['locale' => 'uz']])->save();
    $this->c1->forceFill(['status' => ClassCaravanStatus::Finished, 'active_group_id' => null])->save();
    Subscription::query()->where('subscriber_type', 'organization')->where('subscriber_id', $this->a->id)->update(['status' => 'canceled', 'current_period_ends_at' => now()->subDay()]);
    crvEnter($this->teacherA, $this->a);

    Livewire::test(ListClassCaravans::class)
        ->callTableAction('start', data: ['telegram_group_id' => $this->g1->id])
        ->assertNotified(__('org.caravans.errors.centers_only'));

    expect(__('org.caravans.errors.centers_only'))->toMatch('/[а-я]/u')->and(__('org.caravans.errors.failed'))->toMatch('/[а-я]/u');
});

// ---------------------------------------------------------------------- the scope

it('states the rule of the scope for a record without a query', function (): void {
    crvEnter($this->teacherA, $this->a);
    $scope = app(OrgTenantScope::class);

    expect($scope->canSeeClassCaravan($this->teacherA, $this->a, $this->c1))->toBeTrue()
        ->and($scope->canSeeClassCaravan($this->teacherA, $this->a, $this->c2))->toBeFalse()
        ->and($scope->canSeeClassCaravan($this->teacherA, $this->a, $this->cB))->toBeFalse()
        ->and($scope->caravanGroupIds($this->teacherA, $this->a))->toBe([$this->g1->id])
        ->and($scope->caravanGroupIds($this->ownerA, $this->a))->toBe([])
        ->and($scope->classCaravans($this->ownerA, $this->a)->pluck('class_caravans.id')->all())->toEqualCanonicalizing([$this->c1->id, $this->c2->id]);
});

// ---------------------------------------------------------------------- the budget

it('lists the caravans within a budget that does not grow with the number of rows (owner and teacher)', function (string $who): void {
    $user = $who === 'owner' ? $this->ownerA : $this->teacherA;
    crvEnter($user, $this->a);

    // Rows with what the columns read: a group (the teacher's, so that both see them), a course, a contributor each.
    $fill = function (int $rows) use ($who): void {
        $visible = ClassCaravan::query()->where('organization_id', test()->a->id);
        $have = ($who === 'owner' ? $visible : $visible->where('telegram_group_id', test()->g1->id))->count();
        for ($i = $have; $i < $rows; $i++) {
            $caravan = ClassCaravan::factory()->finished()->create([
                'telegram_group_id' => test()->g1->id,
                'organization_id' => test()->a->id,
                'course_id' => Course::factory()->published(),
                'boss_unlocked_at' => $i % 2 === 0 ? now() : null,
            ]);
            ClassCaravanMember::factory()->create(['class_caravan_id' => $caravan->id]);
        }
    };

    // 16 statements for 10 rows and for 50: two renders (the mount and the change of the page size) of 8 - the role and the groups of the
    // user (the memory of a request is gone with the request), the count, the caravans with the number of their contributors, their groups
    // and courses, the options of the group filter; no query per row.
    FilamentQueryBudget::assert(ListClassCaravans::class, $fill, ceiling: 16);
})->with(['owner', 'teacher']);
