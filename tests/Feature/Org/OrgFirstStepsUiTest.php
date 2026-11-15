<?php

declare(strict_types=1);

use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Telegram\Services\TelegramAccountLinkService;
use App\Filament\Org\Pages\Dashboard;
use App\Filament\Org\Pages\OrgProfile;
use App\Filament\Org\Resources\GroupResource\Pages\ListGroups;
use App\Filament\Org\Resources\StaffResource\Pages\ListStaff;
use App\Filament\Org\Widgets\OrgChecklistWidget;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\OrganizationUser;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use App\Models\TrainingSession;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\Support\FilamentQueryBudget;
use Tests\Support\QueryLog;

/*
 * The "First steps" widget of the /org dashboard, the Telegram modals and status of the cabinet (audit 2026-11-10 §4 P1, H1) and the
 * Telegram column of the staff list.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->org = Organization::factory()->create(['slug' => 'first-steps', 'locale' => 'ru']);
    Subscription::factory()->forOrganization($this->org)->create();
    $this->owner = fsStaff($this->org, OrganizationRole::Owner);
    $this->teacher = fsStaff($this->org, OrganizationRole::Teacher);
});

function fsStaff(Organization $organization, OrganizationRole $role, array $attributes = []): User
{
    $user = User::factory()->create($attributes + [
        'is_active' => true,
        'two_factor_secret' => 'stub',
        'two_factor_recovery_codes' => [],
        'two_factor_confirmed_at' => now(),
        'telegram_user_id' => null,
        'telegram_verified_at' => null,
    ]);
    OrganizationUser::factory()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role->value]);

    return $user;
}

function fsEnter(User $user, Organization $tenant): void
{
    Filament::setCurrentPanel(Filament::getPanel('org'));
    Filament::bootCurrentPanel();
    test()->actingAs($user, 'org');
    Session::put('2fa.org.passed_at', now()->timestamp);
    Session::put('2fa.org.user_id', $user->id);
    Filament::setTenant($tenant, isQuiet: true);
}

// --- the checklist ----------------------------------------------------------------------------------

it('shows what is still to do, ticks what is done, and links to the sections', function (): void {
    fsEnter($this->owner, $this->org);

    Livewire::test(OrgChecklistWidget::class)
        ->assertSee('Первые шаги')
        ->assertSee('Сделано 0 из 5')
        ->assertSee('Подключите свой Telegram')
        ->assertSee('Профиль → «Подключить Telegram»')
        ->assertSee('Привяжите группу')
        ->assertSee('Выберите курс группы')
        ->assertSee('Пригласите учеников')
        ->assertSee('В группе отправьте /start_training')
        ->assertSeeHtml('/org/first-steps/groups')
        ->assertSeeHtml('/org/profile');
});

it('ticks the steps that are done and keeps the hint only for the ones that are not', function (): void {
    $this->owner->forceFill(['telegram_user_id' => 77, 'telegram_verified_at' => now()])->save();
    TelegramGroup::factory()->create(['organization_id' => $this->org->id, 'course_id' => null]);
    fsEnter($this->owner->fresh() ?? $this->owner, $this->org);

    Livewire::test(OrgChecklistWidget::class)
        ->assertSee('Сделано 2 из 5')
        ->assertDontSee('Профиль → «Подключить Telegram»')
        ->assertDontSee('Группы → «Привязать группу»')
        ->assertSee('Группы → «Курс группы»')
        ->assertSee('Ученики → «Пригласить ученика»', false);
});

it('hides the widget once every step is done', function (): void {
    $this->owner->forceFill(['telegram_user_id' => 77, 'telegram_verified_at' => now()])->save();
    $group = TelegramGroup::factory()->create(['organization_id' => $this->org->id, 'course_id' => Course::factory()->published()->create()->id]);
    OrganizationMember::factory()->create(['organization_id' => $this->org->id, 'learner_id' => Learner::factory()->create()->id, 'status' => 'active', 'seat_state' => 'seated']);
    TrainingSession::factory()->create(['telegram_group_id' => $group->id, 'mode' => 'group_lesson', 'started_by_user_id' => $this->owner->id]);
    fsEnter($this->owner->fresh() ?? $this->owner, $this->org);

    Livewire::test(OrgChecklistWidget::class)
        ->assertDontSee('Первые шаги')
        ->assertDontSee('Сделано');
});

it('shows a teacher the steps but links only the sections a teacher may open', function (): void {
    fsEnter($this->teacher, $this->org);

    $html = Livewire::test(OrgChecklistWidget::class)
        ->assertSee('Привяжите группу')
        ->assertSeeHtml('/org/profile')
        ->html();

    // Linking a group is the owner's; the groups list itself is open to a teacher, so the course step keeps its link.
    expect(substr_count($html, '/org/first-steps/groups'))->toBe(1);

    fsEnter($this->owner, $this->org);
    expect(substr_count(Livewire::test(OrgChecklistWidget::class)->html(), '/org/first-steps/groups'))->toBe(2);
});

it('renders the checklist on the dashboard and runs three queries for it', function (): void {
    fsEnter($this->owner, $this->org);
    Livewire::test(Dashboard::class); // warm-up: per-process work is not the checklist's

    $queries = QueryLog::during(function (): void {
        Livewire::test(Dashboard::class)->assertSee('Первые шаги');
    });

    $checklist = QueryLog::matching($queries, '/from ["`](telegram_groups|organization_members|training_sessions)["`]/');
    expect($checklist)->toHaveCount(3)
        ->and(count($queries))->toBeLessThanOrEqual(25);
});

// --- the profile ------------------------------------------------------------------------------------

it('polls the status block of the profile every 10 seconds only while a live link waits for the bot, never forever', function (): void {
    $user = fsStaff($this->org, OrganizationRole::Owner, ['telegram_user_id' => 99, 'telegram_verified_at' => null]);
    fsEnter($user, $this->org);

    // No link issued: nothing to wait for, no polling.
    Livewire::test(OrgProfile::class)->assertDontSeeHtml('wire:poll')->assertSee('не подтверждён');

    app(TelegramAccountLinkService::class)->issueLink($user);
    $page = Livewire::test(OrgProfile::class)->assertSeeHtml('wire:poll.10s');

    // The bot confirms while the page is open: the next poll shows it and the poll is gone.
    User::query()->whereKey($user->id)->update(['telegram_verified_at' => now(), 'telegram_username' => 'sardor']);
    $page->call('$refresh')->assertSee('@sardor')->assertDontSeeHtml('wire:poll');

    // A link that expired unspent: the poll stops too.
    fsEnter($this->owner, $this->org);
    app(TelegramAccountLinkService::class)->issueLink($this->owner);
    $page = Livewire::test(OrgProfile::class)->assertSeeHtml('wire:poll.10s')->assertSee('Telegram не подключён');
    $this->travel(16)->minutes();
    $page->call('$refresh')->assertDontSeeHtml('wire:poll');
});

it('tells a user whose hand-typed id was confirmed by somebody else why Telegram is disconnected', function (): void {
    $user = fsStaff($this->org, OrganizationRole::Owner, ['telegram_user_id' => null, 'telegram_superseded_at' => now()]);
    fsEnter($user, $this->org);

    Livewire::test(OrgProfile::class)
        ->assertSee('Telegram отключён: этот аккаунт Telegram подтвердил другой пользователь.')
        ->assertDontSeeHtml('wire:poll');
});

// Filament draws the body of a modal in the browser, so a component test checks the mounted action and its fields, and the
// button's own view is rendered on its own.
$copyable = fn ($field): bool => $field->isReadOnly() && $field->isCopyable();

it('offers the connection link as a read-only value to copy, next to a button that opens Telegram in a new tab', function () use ($copyable): void {
    fsEnter($this->owner, $this->org);

    $component = Livewire::test(OrgProfile::class)
        ->mountAction(TestAction::make('connectTelegram')->schemaComponent('telegramActions', 'form'))
        ->assertSchemaComponentExists('url', checkComponentUsing: $copyable);

    $data = $component->instance()->mountedActions[0]['data'] ?? [];
    expect((string) ($data['url'] ?? ''))->toStartWith('https://t.me/')->toContain('?start=link_');

    $button = view('filament.org.partials.open-telegram', ['url' => $data['url'], 'label' => 'Открыть в Telegram'])->render();
    expect($button)->toContain('href="'.$data['url'].'"')->toContain('target="_blank"')->toContain('rel="noopener"')->toContain('Открыть в Telegram');
});

it('offers the group link and the fallback command as values to copy', function () use ($copyable): void {
    $this->owner->forceFill(['telegram_user_id' => 77, 'telegram_verified_at' => now()])->save();
    fsEnter($this->owner->fresh() ?? $this->owner, $this->org);

    $component = Livewire::test(ListGroups::class)
        ->mountTableAction('link')
        ->assertSchemaComponentExists('url', checkComponentUsing: $copyable)
        ->assertSchemaComponentExists('command', checkComponentUsing: $copyable);

    $data = $component->instance()->mountedActions[0]['data'] ?? [];
    expect((string) $data['url'])->toContain('?startgroup=grp_')
        ->and((string) $data['command'])->toStartWith('/link ')
        ->and($data['expires'])->not->toBe('');
});

// --- the staff list ---------------------------------------------------------------------------------

it('tells in the staff list whether a Telegram account is verified, typed by hand or missing, within budget', function (): void {
    fsEnter($this->owner, $this->org);
    $this->owner->forceFill(['telegram_user_id' => 1, 'telegram_verified_at' => now()])->save();
    $this->teacher->forceFill(['telegram_user_id' => 2, 'telegram_verified_at' => null])->save();
    fsStaff($this->org, OrganizationRole::Teacher); // none

    Livewire::test(ListStaff::class)
        ->assertSee('подтверждён')
        ->assertSee('не подтверждён')
        ->assertSee('не подключён');

    $fill = function (int $rows): void {
        $have = OrganizationUser::query()->where('organization_id', $this->org->id)->count();

        for ($i = $have; $i < $rows; $i++) {
            fsStaff($this->org, OrganizationRole::Teacher, $i % 2 === 0 ? ['telegram_user_id' => 1000 + $i, 'telegram_verified_at' => now()] : []);
        }
    };

    FilamentQueryBudget::assert(ListStaff::class, $fill, ceiling: 10);
});
