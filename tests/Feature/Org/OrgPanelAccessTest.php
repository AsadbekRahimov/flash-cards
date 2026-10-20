<?php

declare(strict_types=1);

use App\Domain\Organizations\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\TelegramGroup;
use App\Models\User;
use App\Policies\Org\OrgPolicyResolver;
use App\Policies\Org\OrgTelegramGroupPolicy;
use App\Policies\TelegramGroupPolicy;
use App\Policies\UserPolicy;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PragmaRX\Google2FA\Google2FA;

/*
 * Access to the `/org` cabinet (docs/13 §6.3, §10): own guard, tenant check, 2FA rules, and the
 * policy swap that only happens inside this panel. `/admin` is covered by its own tests, which must
 * stay green.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

/** @param array<string, mixed> $attrs */
function orgStaff(Organization $organization, OrganizationRole $role, bool $twoFactor, array $attrs = []): User
{
    $user = User::factory()->create(array_merge([
        'is_active' => true,
        'two_factor_secret' => $twoFactor ? app(Google2FA::class)->generateSecretKey() : null,
        'two_factor_recovery_codes' => $twoFactor ? [] : null,
        'two_factor_confirmed_at' => $twoFactor ? now() : null,
    ], $attrs));

    OrganizationUser::factory()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role->value]);

    return $user;
}

/** @return array<string, mixed> */
function orgPassed(User $user): array
{
    return ['2fa.passed_at' => now()->timestamp, '2fa.user_id' => $user->id];
}

it('redirects guests to the cabinet login', function (): void {
    $this->get('/org')->assertRedirect('/org/login');
    $this->get('/org/login')->assertOk();
});

it('forbids a user who is staff of no organization, a superadmin included', function (): void {
    $admin = User::factory()->create(['is_active' => true, 'two_factor_confirmed_at' => now(), 'two_factor_secret' => 'stub', 'two_factor_recovery_codes' => []]);
    $admin->assignRole('admin');

    $nobody = User::factory()->create(['is_active' => true]);
    $this->actingAs($nobody, 'org')->get('/org')->assertForbidden();

    $this->actingAs($admin, 'org')->withSession(orgPassed($admin))->get('/org')->assertForbidden();
});

it('does not treat a /admin (web guard) login as a cabinet login', function (): void {
    $organization = Organization::factory()->create();
    $owner = orgStaff($organization, OrganizationRole::Owner, twoFactor: true);

    $this->actingAs($owner, 'web')->withSession(orgPassed($owner))
        ->get('/org/'.$organization->slug)
        ->assertRedirect('/org/login');
});

it('sends an owner without 2FA to the setup page and remembers where to return', function (): void {
    $organization = Organization::factory()->create();
    $owner = orgStaff($organization, OrganizationRole::Owner, twoFactor: false);

    $this->actingAs($owner, 'org')
        ->get('/org/'.$organization->slug.'/members')
        ->assertRedirect(route('2fa.setup'))
        ->assertSessionHas('url.intended', url('/org/'.$organization->slug.'/members'));
});

it('challenges an owner with 2FA enabled until the mark of THIS user is in the session', function (): void {
    $organization = Organization::factory()->create();
    $owner = orgStaff($organization, OrganizationRole::Owner, twoFactor: true);
    $other = User::factory()->create();

    $this->actingAs($owner, 'org')->get('/org/'.$organization->slug)->assertRedirect(route('2fa.challenge'));

    // A mark left by another account (shared session, logged in through /admin) does not count.
    $this->actingAs($owner, 'org')->withSession(['2fa.passed_at' => now()->timestamp, '2fa.user_id' => $other->id])
        ->get('/org/'.$organization->slug)
        ->assertRedirect(route('2fa.challenge'));

    $this->actingAs($owner, 'org')->withSession(orgPassed($owner))
        ->get('/org/'.$organization->slug)
        ->assertOk();
});

it('lets the 2FA challenge return the owner to the cabinet URL', function (): void {
    $organization = Organization::factory()->create();
    $owner = orgStaff($organization, OrganizationRole::Owner, twoFactor: true);

    $this->actingAs($owner, 'org')->get('/org/'.$organization->slug.'/groups')->assertRedirect(route('2fa.challenge'));

    $code = app(Google2FA::class)->getCurrentOtp((string) $owner->two_factor_secret);

    $this->actingAs($owner, 'org')
        ->post(route('2fa.verify'), ['code' => $code])
        ->assertRedirect(url('/org/'.$organization->slug.'/groups'))
        ->assertSessionHas('2fa.user_id', $owner->id);

    $this->get('/org/'.$organization->slug.'/groups')->assertOk();
});

it('lets a teacher in without 2FA, but challenges a teacher who enabled it', function (): void {
    $organization = Organization::factory()->create();

    $plain = orgStaff($organization, OrganizationRole::Teacher, twoFactor: false);
    $this->actingAs($plain, 'org')->get('/org/'.$organization->slug)->assertOk();

    $secured = orgStaff($organization, OrganizationRole::Teacher, twoFactor: true);
    $this->actingAs($secured, 'org')->get('/org/'.$organization->slug)->assertRedirect(route('2fa.challenge'));
    $this->actingAs($secured, 'org')->withSession(orgPassed($secured))->get('/org/'.$organization->slug)->assertOk();
});

it('opens the first organization of the user from the bare /org URL', function (): void {
    $organization = Organization::factory()->create();
    $owner = orgStaff($organization, OrganizationRole::Owner, twoFactor: true);

    $this->actingAs($owner, 'org')->withSession(orgPassed($owner))
        ->get('/org')
        ->assertRedirect('/org/'.$organization->slug);
});

it('answers 404 for every URL of an organization the user is not staff of', function (): void {
    $a = Organization::factory()->create();
    $b = Organization::factory()->create();
    $ownerA = orgStaff($a, OrganizationRole::Owner, twoFactor: true);
    orgStaff($b, OrganizationRole::Owner, twoFactor: true);
    $groupB = TelegramGroup::factory()->create(['organization_id' => $b->id]);

    $this->actingAs($ownerA, 'org')->withSession(orgPassed($ownerA));

    foreach (['', '/members', '/groups', '/invites', '/staff', '/billing', '/profile', '/groups/'.$groupB->id] as $path) {
        $this->get('/org/'.$b->slug.$path)->assertNotFound();
    }

    $this->get('/org/no-such-center')->assertNotFound();
    $this->get('/org/'.$a->slug)->assertOk();
});

it('hides billing, invites, staff and settings from a teacher', function (): void {
    $organization = Organization::factory()->create();
    $teacher = orgStaff($organization, OrganizationRole::Teacher, twoFactor: false);

    $this->actingAs($teacher, 'org');

    $this->get('/org/'.$organization->slug)->assertOk()
        ->assertDontSee('/org/'.$organization->slug.'/billing')
        ->assertDontSee('/org/'.$organization->slug.'/invites')
        ->assertDontSee('/org/'.$organization->slug.'/staff');
    $this->get('/org/'.$organization->slug.'/billing')->assertForbidden();
    $this->get('/org/'.$organization->slug.'/invites')->assertForbidden();
    $this->get('/org/'.$organization->slug.'/staff')->assertForbidden();
    $this->get('/org/'.$organization->slug.'/profile')->assertNotFound();
    $this->get('/org/'.$organization->slug.'/groups')->assertOk();
    $this->get('/org/'.$organization->slug.'/members')->assertOk();
    $this->get('/org/'.$organization->slug.'/assignments')->assertOk();
    $this->get('/org/'.$organization->slug.'/reports')->assertOk();
});

it('shows the owner every screen', function (): void {
    $organization = Organization::factory()->create();
    $owner = orgStaff($organization, OrganizationRole::Owner, twoFactor: true);

    $this->actingAs($owner, 'org')->withSession(orgPassed($owner));

    foreach (['', '/members', '/groups', '/invites', '/staff', '/assignments', '/reports', '/billing', '/profile'] as $path) {
        $this->get('/org/'.$organization->slug.$path)->assertOk();
    }
    $this->get('/org/profile')->assertOk();
});

it('resolves the tenant-aware policies only while the org panel is current', function (): void {
    expect(Gate::getPolicyFor(TelegramGroup::class))->toBeInstanceOf(TelegramGroupPolicy::class);
    expect(OrgPolicyResolver::inOrgPanel())->toBeFalse();

    Filament::setCurrentPanel(Filament::getPanel('org'));

    expect(Gate::getPolicyFor(TelegramGroup::class))->toBeInstanceOf(OrgTelegramGroupPolicy::class);
    expect(OrgPolicyResolver::candidates(TelegramGroup::class))->toBe([OrgTelegramGroupPolicy::class, TelegramGroupPolicy::class]);
    // A model without a cabinet policy keeps the platform one (which grants nothing to cabinet staff).
    expect(OrgPolicyResolver::candidates(User::class))->toBe([UserPolicy::class]);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    expect(Gate::getPolicyFor(TelegramGroup::class))->toBeInstanceOf(TelegramGroupPolicy::class);
});

it('maps every model of a cabinet resource to a tenant-aware policy', function (): void {
    foreach (Filament::getPanel('org')->getResources() as $resource) {
        expect(OrgPolicyResolver::MAP)->toHaveKey($resource::getModel());
    }
});
