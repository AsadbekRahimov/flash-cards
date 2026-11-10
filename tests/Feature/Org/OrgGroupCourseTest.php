<?php

declare(strict_types=1);

use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Exceptions\OrgGroupLinkException;
use App\Domain\Organizations\Services\OrgGroupLinkService;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Filament\Org\Resources\AssignmentResource\Pages\CreateAssignment;
use App\Filament\Org\Resources\GroupResource\Pages\ListGroups;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use App\Models\TelegramLinkToken;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\Support\FakeTelegramClient;

/*
 * The group's course in the /org cabinet (assignments need `telegram_groups.course_id`): the owner picks it in
 * Groups → «Курс группы», through OrgGroupLinkService::setCourse (own group, available course); a teacher cannot.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);

    $this->a = Organization::factory()->create(['name' => 'Center A', 'slug' => 'center-a', 'locale' => 'ru']);
    $this->b = Organization::factory()->create(['name' => 'Center B', 'slug' => 'center-b']);
    Subscription::factory()->forOrganization($this->a)->create(['current_period_ends_at' => null, 'tier' => 'tutor', 'seats_limit' => 30]);

    $this->ownerA = grpStaff($this->a, OrganizationRole::Owner);
    $this->teacherA = grpStaff($this->a, OrganizationRole::Teacher, twoFactor: false);

    $this->course = Course::factory()->published()->create(['title' => ['ru' => 'Английский A1', 'uz' => 'Ingliz tili A1']]);
    $this->group = TelegramGroup::factory()->create(['organization_id' => $this->a->id, 'course_id' => null, 'title' => 'Group 1', 'status' => 'active']);
    $this->foreign = TelegramGroup::factory()->create(['organization_id' => $this->b->id, 'course_id' => null, 'title' => 'Foreign', 'status' => 'active']);
    $this->teacherA->telegramGroups()->attach($this->group->id, ['is_primary' => true]);
});

function grpStaff(Organization $organization, OrganizationRole $role, bool $twoFactor = true): User
{
    $user = User::factory()->create([
        'is_active' => true,
        'two_factor_secret' => $twoFactor ? 'stub' : null,
        'two_factor_recovery_codes' => $twoFactor ? [] : null,
        'two_factor_confirmed_at' => $twoFactor ? now() : null,
        'telegram_user_id' => random_int(100000, 999999),
    ]);
    OrganizationUser::factory()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role->value]);

    return $user;
}

function grpEnter(User $user, Organization $tenant): void
{
    Filament::setCurrentPanel(Filament::getPanel('org'));
    Filament::bootCurrentPanel();
    test()->actingAs($user, 'org');
    Session::put('2fa.org.passed_at', now()->timestamp);
    Session::put('2fa.org.user_id', $user->id);
    Filament::setTenant($tenant, isQuiet: true);
}

it('lets the owner pick the course of their group: saved, audited, offered in the center language', function (): void {
    grpEnter($this->ownerA, $this->a);

    Livewire::test(ListGroups::class)
        ->callTableAction('course', $this->group, data: ['course_id' => $this->course->id])
        ->assertHasNoTableActionErrors()
        ->assertNotified();

    expect($this->group->refresh()->course_id)->toBe($this->course->id)
        ->and(AuditLog::query()->where('action', 'organization.group_course_changed')->sole()->user_id)->toBe($this->ownerA->id)
        ->and(app(OrgGroupLinkService::class)->courseOptions($this->a))->toBe([$this->course->id => 'Английский A1']);
});

it('does not touch the course of a group of another center', function (): void {
    grpEnter($this->ownerA, $this->a);

    expect(fn () => app(OrgGroupLinkService::class)->setCourse($this->a, $this->foreign, $this->course->id, $this->ownerA))
        ->toThrow(OrgGroupLinkException::class, 'not linked');
    expect($this->foreign->refresh()->course_id)->toBeNull();

    // The list does not even show it, so the action cannot be mounted on it.
    try {
        Livewire::test(ListGroups::class)->callTableAction('course', $this->foreign, data: ['course_id' => $this->course->id]);
    } catch (Throwable) {
        // a record outside the tenant may fail loudly — what matters is the data
    }

    expect($this->foreign->refresh()->course_id)->toBeNull();
});

it('does not let a teacher change the course, even of their own group', function (): void {
    grpEnter($this->teacherA, $this->a);

    Livewire::test(ListGroups::class)->assertTableActionHidden('course', $this->group);

    try {
        Livewire::test(ListGroups::class)->callTableAction('course', $this->group, data: ['course_id' => $this->course->id]);
    } catch (Throwable) {
        // an unauthorized action may fail loudly — what matters is the data
    }

    expect($this->group->refresh()->course_id)->toBeNull();
});

it('refuses a draft, a private or a missing course', function (): void {
    $draft = Course::factory()->create(['status' => 'draft']);
    $private = Course::factory()->published()->create(['visibility' => 'private']);

    foreach ([$draft->id, $private->id, 999999] as $id) {
        expect(fn () => app(OrgGroupLinkService::class)->setCourse($this->a, $this->group, $id, $this->ownerA))
            ->toThrow(OrgGroupLinkException::class, 'not available');
    }

    expect($this->group->refresh()->course_id)->toBeNull()
        ->and(AuditLog::query()->count())->toBe(0);
});

it('does not write an audit record when the course is the same', function (): void {
    $service = app(OrgGroupLinkService::class);

    $service->setCourse($this->a, $this->group, $this->course->id, $this->ownerA);
    $service->setCourse($this->a, $this->group, $this->course->id, $this->ownerA);

    expect(AuditLog::query()->where('action', 'organization.group_course_changed')->count())->toBe(1);
});

it('tells the owner what to do when no group of the center has a course', function (): void {
    grpEnter($this->ownerA, $this->a);

    Livewire::test(CreateAssignment::class)->assertSee('Сначала выберите курс для группы: Группы → «Курс группы».');

    $this->group->update(['course_id' => $this->course->id]);

    Livewire::test(CreateAssignment::class)->assertDontSee('Сначала выберите курс для группы');
});

it('issues a one-time group link to a verified owner from «Привязать группу», and refuses an owner whose Telegram is not verified', function (): void {
    $this->ownerA->forceFill(['telegram_verified_at' => now()])->save();
    grpEnter($this->ownerA->fresh() ?? $this->ownerA, $this->a);

    $component = Livewire::test(ListGroups::class)
        ->mountTableAction('link')
        ->assertTableActionMounted('link')
        ->assertNotNotified(); // no toast: the link is on screen
    $data = $component->instance()->mountedActions[0]['data'] ?? [];
    expect((string) ($data['url'] ?? ''))->toStartWith('https://t.me/')->toContain('?startgroup=grp_')
        ->and((string) ($data['command'] ?? ''))->toStartWith('/link ');

    $token = TelegramLinkToken::query()->sole();
    expect($token->kind)->toBe('group')
        ->and($token->organization_id)->toBe($this->a->id)
        ->and($token->user_id)->toBe($this->ownerA->id);

    // A hand-typed id (the fixture's) proves nothing: the modal is refused with the reason.
    $this->ownerA->forceFill(['telegram_verified_at' => null])->save();
    grpEnter($this->ownerA->fresh() ?? $this->ownerA, $this->a);

    Livewire::test(ListGroups::class)
        ->mountTableAction('link')
        ->assertTableActionNotMounted('link')
        ->assertNotified('Сначала подключите свой Telegram через бота: Профиль → «Подключить Telegram». Без этого нельзя доказать, что группа ваша.');

    expect(TelegramLinkToken::query()->count())->toBe(1);
});
