<?php

declare(strict_types=1);

use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Services\OrganizationMembershipService;
use App\Domain\Organizations\Services\OrgOverviewService;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Filament\Org\Pages\Reports;
use App\Filament\Org\Resources\MemberResource\Pages\ListMembers;
use App\Filament\Org\Widgets\MemberInvitationsWidget;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\OrganizationMemberInvitation;
use App\Models\OrganizationUser;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\Support\FakeTelegramClient;

/*
 * The cabinet is no oracle (review finding B1 of the audit 2026-11-10): after inviting a learner who exists and a handle nobody has,
 * everything the owner can open — the learners list, a member's page, the reports and their CSV, the counters, the invitations
 * block — reads the same. The learner's name, username and metrics appear only after they accepted; a decline looks like silence;
 * revoke + invite again sends no second DM.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-10 12:00:00', 'UTC'));
    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);

    $this->org = Organization::factory()->create(['name' => 'Center A', 'slug' => 'oracle-a', 'timezone' => 'UTC', 'locale' => 'ru']);
    Subscription::factory()->forOrganization($this->org)->create(['current_period_ends_at' => null, 'tier' => 'tutor', 'seats_limit' => 30]);
    $this->owner = oraStaff($this->org, OrganizationRole::Owner);
    $this->teacher = oraStaff($this->org, OrganizationRole::Teacher);
    $this->group = TelegramGroup::factory()->create(['organization_id' => $this->org->id, 'title' => 'Class', 'status' => 'active']);
    $this->teacher->telegramGroups()->attach($this->group->id, ['is_primary' => true]);

    // One ordinary member, so every screen has a row to show; and the learner the owner will invite — with a telling name.
    $this->member = Learner::factory()->memberOf($this->group)->seatedIn($this->org)->create(['first_name' => 'Ordinary', 'last_name' => 'Member', 'username' => 'ordinary_member']);
    $this->hidden = Learner::factory()->onboarded()->create([
        'telegram_user_id' => 8001,
        'first_name' => 'Zafarbek',
        'last_name' => 'Hiddenov',
        'username' => 'hidden_person',
        'streak_current' => 17,
        'last_seen_at' => now()->subMinutes(3),
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function oraStaff(Organization $organization, OrganizationRole $role): User
{
    $user = User::factory()->create([
        'is_active' => true,
        'two_factor_secret' => 'stub',
        'two_factor_recovery_codes' => [],
        'two_factor_confirmed_at' => now(),
        'telegram_user_id' => null,
    ]);
    OrganizationUser::factory()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role->value]);

    return $user;
}

function oraEnter(User $user, Organization $tenant): void
{
    Filament::setCurrentPanel(Filament::getPanel('org'));
    Filament::bootCurrentPanel();
    test()->actingAs($user, 'org');
    Session::put('2fa.org.passed_at', now()->timestamp);
    Session::put('2fa.org.user_id', $user->id);
    Filament::setTenant($tenant, isQuiet: true);
    app()->setLocale($tenant->locale);
}

function oraInvite(string $handle): void
{
    Livewire::test(ListMembers::class)->callTableAction('add', data: ['telegram' => $handle])->assertNotified();
}

function oraTap(int $tgId, string $data): void
{
    app(TelegramDispatcher::class)->dispatch(['callback_query' => [
        'id' => 'cb-'.md5($data),
        'from' => ['id' => $tgId, 'first_name' => 'Zafarbek', 'last_name' => 'Hiddenov', 'username' => 'hidden_person', 'language_code' => 'ru'],
        'message' => ['message_id' => 7, 'chat' => ['id' => $tgId, 'type' => 'private']],
        'data' => $data,
    ]]);
}

/** The words of a rendered component: tags, Livewire attributes and whitespace do not count, the text does. */
function oraText(string $html): string
{
    return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}

/**
 * Everything the owner can read about the center, as one array: the learners list and the invitations block (with the handles
 * of the invitations normalised away), the report rows, the CSV and the counters.
 */
function oraSnapshot(): array
{
    OrgOverviewService::forget(test()->org->id);
    $rows = app(OrganizationMembershipService::class);
    $overview = app(OrgOverviewService::class)->summary(test()->owner, test()->org);
    $invitations = OrganizationMemberInvitation::query()->where('organization_id', test()->org->id)->orderBy('id')->get();
    $widget = Livewire::test(MemberInvitationsWidget::class)->html();
    $list = Livewire::test(ListMembers::class)->html();
    $reports = Livewire::test(Reports::class);
    $csv = (string) base64_decode((string) ($reports->callAction('csvLearners')->effects['download']['content'] ?? ''), true);

    // The handle is the one thing the rows are allowed to differ in; the ids never reach the text.
    $widget = str_replace($invitations->pluck('invited_handle')->all(), '[handle]', oraText($widget));

    return [
        'members' => OrganizationMember::query()->where('organization_id', test()->org->id)->count(),
        'seats' => $rows->seatsSummary(test()->org)->toArray(),
        'active_members' => $overview->activeMembers,
        'active_7d' => $overview->activeLearners7d,
        'states' => $invitations->map(fn (OrganizationMemberInvitation $i): string => $i->state()->value)->all(),
        'list' => oraText($list),
        'widget' => $widget,
        'reports' => oraText($reports->html()),
        'csv' => $csv,
    ];
}

it('shows the owner the same cabinet after inviting a learner who exists and a handle nobody has — and nothing of the learner', function (): void {
    oraEnter($this->owner, $this->org);
    $before = oraSnapshot();

    oraInvite('@hidden_person');
    $afterReal = oraSnapshot();
    expect(end($this->tg->sent)['chat_id'])->toBe(8001);

    // Invite the ghost and compare: the only differences are one more row that reads the same.
    oraInvite('@ghost_person');
    $afterGhost = oraSnapshot();

    expect($afterReal['states'])->toBe(['pending'])
        ->and($afterGhost['states'])->toBe(['pending', 'pending'])
        ->and($afterReal['members'])->toBe($before['members'])->toBe($afterGhost['members'])
        ->and($afterReal['seats'])->toBe($before['seats'])->toBe($afterGhost['seats'])
        ->and($afterReal['active_members'])->toBe($before['active_members'])->toBe($afterGhost['active_members'])
        ->and($afterReal['active_7d'])->toBe($before['active_7d'])->toBe($afterGhost['active_7d'])
        ->and($afterReal['list'])->toBe($before['list'])->toBe($afterGhost['list'])
        ->and($afterReal['reports'])->toBe($before['reports'])->toBe($afterGhost['reports'])
        ->and($afterReal['csv'])->toBe($before['csv'])->toBe($afterGhost['csv']);

    // The two invitation rows differ in nothing but the handle and the id (normalised away above): the ghost's widget is
    // the real learner's widget plus one identical row — so the real one's rendering is contained in the ghost's.
    expect($afterGhost['widget'])->toContain('[handle]')
        ->and(substr_count($afterGhost['widget'], 'Ожидает ответа'))->toBe(2)
        ->and(substr_count($afterReal['widget'], 'Ожидает ответа'))->toBe(1)
        ->and(strlen($afterGhost['widget']))->toBeGreaterThan(strlen($afterReal['widget']));

    foreach (['Zafarbek', 'Hiddenov', 'hidden_person', '8001', '17'] as $secret) {
        expect($afterGhost['list'])->not->toContain($secret, "list leaks {$secret}")
            ->and($afterGhost['reports'])->not->toContain($secret, "reports leak {$secret}")
            ->and($afterGhost['csv'])->not->toContain($secret, "csv leaks {$secret}");
    }
    expect($afterGhost['widget'])->not->toContain('Zafarbek')->not->toContain('Hiddenov')->not->toContain('8001')->not->toContain('17 ');
});

it('shows a decline as silence: "waiting" until the TTL, then "expired", exactly like the ghost; the learner appears only after "Join"', function (): void {
    oraEnter($this->owner, $this->org);
    oraInvite('@hidden_person');
    oraInvite('@ghost_person');
    $real = OrganizationMemberInvitation::query()->where('invited_handle', '@hidden_person')->sole();

    oraTap(8001, 'org1:decline:'.$real->id);
    $declined = oraSnapshot();
    expect($declined['states'])->toBe(['pending', 'pending'])
        ->and($declined['widget'])->not->toContain('Отклон')->not->toContain('Zafarbek')
        ->and($declined['list'])->not->toContain('Zafarbek');

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-17 12:00:00', 'UTC'));
    $expired = oraSnapshot();
    expect($expired['states'])->toBe(['expired', 'expired'])
        ->and(substr_count($expired['widget'], 'Истекло'))->toBe(2)
        ->and($expired['list'])->not->toContain('Zafarbek');

    // A fresh invitation after the window, accepted this time: the learner is an ordinary member now — name, username, metrics.
    oraInvite('@hidden_person');
    $fresh = OrganizationMemberInvitation::query()->where('invited_handle', '@hidden_person')->orderByDesc('id')->first();
    oraTap(8001, 'org1:accept:'.$fresh?->id);

    $joined = oraSnapshot();
    expect($joined['members'])->toBe(2)
        ->and($joined['states'])->toBe(['expired', 'expired', 'accepted'])
        ->and($joined['list'])->toContain('Zafarbek Hiddenov')->toContain('@hidden_person')
        ->and($joined['widget'])->toContain('Принято');
});

it('revoke and invite again sends no second DM, and the owner sees two rows that read the same', function (): void {
    oraEnter($this->owner, $this->org);
    oraInvite('@hidden_person');
    $first = OrganizationMemberInvitation::query()->sole();
    $sent = count($this->tg->sent);

    Livewire::test(MemberInvitationsWidget::class)->callTableAction('revoke', $first)->assertNotified('Приглашение отозвано.');
    oraInvite('@hidden_person');

    expect(count($this->tg->sent))->toBe($sent)
        ->and(OrganizationMemberInvitation::query()->count())->toBe(2)
        ->and(oraSnapshot()['states'])->toBe(['revoked', 'pending']);
});

it('hides the invitations block from a teacher and never lists an invitation among the teacher\'s learners', function (): void {
    oraEnter($this->owner, $this->org);
    oraInvite('@hidden_person');

    oraEnter($this->teacher, $this->org);
    expect(MemberInvitationsWidget::canView())->toBeFalse();
    Livewire::test(ListMembers::class)->assertSee('Ordinary Member')->assertDontSee('Zafarbek')->assertDontSee('hidden_person');
    Livewire::test(Reports::class)->assertDontSee('Zafarbek');

    // An invitation is no membership row at all: there is no member page to open for the invited learner.
    expect(OrganizationMember::query()->where('learner_id', $this->hidden->id)->exists())->toBeFalse();
});

it('shows the activity of active members only: a former member keeps the name, loses the metrics', function (): void {
    oraEnter($this->owner, $this->org);
    $this->member->forceFill(['streak_current' => 9, 'last_seen_at' => now()->subMinutes(2)])->save();
    $member = OrganizationMember::query()->where('learner_id', $this->member->id)->sole();

    Livewire::test(ListMembers::class)->assertSee('Ordinary Member')->assertSee('2 минуты назад');

    app(OrganizationMembershipService::class)->leave($this->member, $this->org, 'removed', $this->owner);
    Livewire::test(ListMembers::class)->filterTable('status', ['removed'])->assertSee('Ordinary Member')->assertDontSee('2 минуты назад');
    $this->get('/org/oracle-a/members/'.$member->id)->assertOk()->assertSee('Ordinary Member')->assertDontSee('2 минуты назад')->assertDontSee('>9<', false);
});
