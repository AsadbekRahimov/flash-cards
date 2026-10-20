<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Billing\Services\SubscriptionService;
use App\Domain\Learners\Services\GroupMembershipService;
use App\Domain\Organizations\Events\OrganizationMemberJoined;
use App\Domain\Organizations\Events\OrganizationMemberLeft;
use App\Domain\Organizations\Events\OrganizationMemberSeated;
use App\Domain\Organizations\Exceptions\OrganizationMembershipException;
use App\Domain\Organizations\Services\OrganizationMembershipService;
use App\Models\AuditLog;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\OrganizationInvite;
use App\Models\OrganizationMember;
use App\Models\ProductEvent;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-19 12:00:00', 'UTC'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/** An active organization sponsoring `pro` with the tier's seats (null tier = perpetual, unlimited). */
function omsOrganization(?string $tier = 'tutor', ?int $seats = 30): Organization
{
    $org = Organization::factory()->create();
    Subscription::factory()->forOrganization($org)->create([
        'current_period_ends_at' => null,
        'tier' => $tier,
        'seats_limit' => $seats,
    ]);

    return $org;
}

function omsInvite(Organization $org, array $attributes = []): OrganizationInvite
{
    return OrganizationInvite::factory()->create(['organization_id' => $org->id, ...$attributes]);
}

function omsService(): OrganizationMembershipService
{
    return app(OrganizationMembershipService::class);
}

function omsPlan(Learner $learner): Plan
{
    return app(EntitlementResolver::class)->for($learner)->plan;
}

// --- joinByInvite --------------------------------------------------------------------------

it('seats a learner who redeems an invite, counts the use, drops the cache and records the events', function (): void {
    Event::fake([OrganizationMemberJoined::class]);
    $org = omsOrganization();
    $invite = omsInvite($org, ['code' => 'ABCDEFGHJK']);
    $learner = Learner::factory()->create();
    expect(omsPlan($learner))->toBe(Plan::Free); // primes the cache

    $member = omsService()->joinByInvite($learner, ' abcdefghjk '); // case and blanks do not matter

    expect($member->status)->toBe('active')
        ->and($member->seat_state)->toBe('seated')
        ->and($member->joined_via)->toBe('invite')
        ->and($member->invite_id)->toBe($invite->id)
        ->and($member->seated_at?->toIso8601String())->toBe(CarbonImmutable::now()->toIso8601String())
        ->and($member->waitlisted_at)->toBeNull()
        ->and($invite->refresh()->uses_count)->toBe(1)
        ->and(Cache::has(EntitlementResolver::cacheKey($learner->id)))->toBeFalse()
        ->and(omsPlan($learner))->toBe(Plan::Pro)
        ->and(app(EntitlementResolver::class)->for($learner)->source)->toBe("sponsor:organization:{$org->id}");

    $event = ProductEvent::query()->where('name', 'org.member_joined')->sole();
    expect($event->learner_id)->toBe($learner->id)
        ->and($event->props['via'])->toBe('invite')
        ->and($event->props['seat_state'])->toBe('seated');

    Event::assertDispatched(OrganizationMemberJoined::class, fn (OrganizationMemberJoined $e): bool => $e->learnerId === $learner->id
        && $e->organizationId === $org->id && $e->via === 'invite' && $e->seatState === 'seated');
});

it('is idempotent for an active member: no second use, no event, nothing changes', function (): void {
    $org = omsOrganization();
    $invite = omsInvite($org);
    $learner = Learner::factory()->create();
    $first = omsService()->joinByInvite($learner, $invite->code);
    omsPlan($learner); // cache

    $second = omsService()->joinByInvite($learner, $invite->code);

    expect($second->id)->toBe($first->id)
        ->and($invite->refresh()->uses_count)->toBe(1)
        ->and(OrganizationMember::query()->count())->toBe(1)
        ->and(ProductEvent::query()->where('name', 'org.member_joined')->count())->toBe(1)
        ->and(Cache::has(EntitlementResolver::cacheKey($learner->id)))->toBeTrue();
});

it('rejects an unknown, deactivated, expired or exhausted invite with the documented codes', function (): void {
    $org = omsOrganization();
    $learner = Learner::factory()->create();
    omsInvite($org, ['code' => 'INACTIVEXX', 'is_active' => false]);
    omsInvite($org, ['code' => 'EXPIREDXXX', 'expires_at' => CarbonImmutable::now()->subMinute()]);
    omsInvite($org, ['code' => 'FULLXXXXXX', 'max_uses' => 1, 'uses_count' => 1]);
    $stillValid = omsInvite($org, ['code' => 'VALIDXXXXX', 'expires_at' => CarbonImmutable::now()->addMinute(), 'max_uses' => 2, 'uses_count' => 1]);

    foreach (['NOSUCHCODE' => 'invite_invalid', 'INACTIVEXX' => 'invite_invalid', 'EXPIREDXXX' => 'invite_expired', 'FULLXXXXXX' => 'invite_exhausted'] as $code => $expected) {
        try {
            omsService()->joinByInvite($learner, $code);
            $this->fail("{$code} was accepted");
        } catch (OrganizationMembershipException $e) {
            expect($e->errorCode)->toBe($expected, $code);
        }
    }

    expect(OrganizationMember::query()->count())->toBe(0)
        ->and(omsService()->joinByInvite($learner, 'VALIDXXXXX')->seat_state)->toBe('seated')
        ->and($stillValid->refresh()->uses_count)->toBe(2)
        ->and($stillValid->isExhausted())->toBeTrue();

    // The last use was consumed: the next learner is turned away.
    expect(fn () => omsService()->joinByInvite(Learner::factory()->create(), 'VALIDXXXXX'))
        ->toThrow(OrganizationMembershipException::class, 'no uses left');
});

it('maps the error codes to HTTP statuses', function (): void {
    expect(OrganizationMembershipException::inviteInvalid()->httpStatus())->toBe(404)
        ->and(OrganizationMembershipException::inviteExpired('X')->httpStatus())->toBe(410)
        ->and(OrganizationMembershipException::inviteExhausted('X')->httpStatus())->toBe(409)
        ->and(OrganizationMembershipException::organizationSuspended(1)->httpStatus())->toBe(409)
        ->and(OrganizationMembershipException::notAMember(1, 2)->httpStatus())->toBe(404);
});

it('refuses a suspended organization and keeps its seated members Free', function (): void {
    $org = omsOrganization();
    $invite = omsInvite($org);
    $seated = Learner::factory()->create();
    omsService()->joinByInvite($seated, $invite->code);
    expect(omsPlan($seated))->toBe(Plan::Pro);

    $org->update(['status' => 'suspended']);

    expect(omsPlan($seated))->toBe(Plan::Free)
        ->and(fn () => omsService()->joinByInvite(Learner::factory()->create(), $invite->code))
        ->toThrow(OrganizationMembershipException::class, 'suspended');
    expect($invite->refresh()->uses_count)->toBe(1);
});

// --- seats -----------------------------------------------------------------------------------

it('waitlists the 31st member of a Tutor organization: active member, no seat, plain Free', function (): void {
    $org = omsOrganization('tutor', 30);
    $invite = omsInvite($org);
    Learner::factory()->seatedIn($org)->count(30)->create();

    $late = Learner::factory()->create();
    $member = omsService()->joinByInvite($late, $invite->code);

    expect($member->status)->toBe('active')
        ->and($member->seat_state)->toBe('waitlisted')
        ->and($member->waitlisted_at)->not->toBeNull()
        ->and($member->seated_at)->toBeNull()
        ->and(omsPlan($late))->toBe(Plan::Free)
        ->and($invite->refresh()->uses_count)->toBe(1) // waitlisted still counts as a use
        ->and(ProductEvent::query()->where('name', 'org.member_joined')->sole()->props['seat_state'])->toBe('waitlisted');

    $summary = omsService()->seatsSummary($org);
    expect($summary->toArray())->toBe(['limit' => 30, 'seated' => 30, 'waitlisted' => 1, 'sponsoring' => true])
        ->and($summary->free())->toBe(0)
        ->and($summary->overLimit())->toBeFalse();
});

it('waitlists everyone while the organization has no live subscription, and seats nobody from a lapsed one', function (): void {
    $org = Organization::factory()->create();
    Subscription::factory()->forOrganization($org)->expired()->create();
    $invite = omsInvite($org);

    $member = omsService()->joinByInvite(Learner::factory()->create(), $invite->code);

    expect($member->seat_state)->toBe('waitlisted')
        ->and(omsService()->seatsSummary($org)->toArray())->toBe(['limit' => null, 'seated' => 0, 'waitlisted' => 1, 'sponsoring' => false])
        ->and(omsService()->seatsSummary($org)->unlimited())->toBeFalse();
});

it('seats without limit when the live subscription has no seats_limit (a plain admin grant)', function (): void {
    $org = omsOrganization(null, null);
    $invite = omsInvite($org);

    foreach (range(1, 3) as $_) {
        expect(omsService()->joinByInvite(Learner::factory()->create(), $invite->code)->seat_state)->toBe('seated');
    }

    $summary = omsService()->seatsSummary($org);
    expect($summary->unlimited())->toBeTrue()
        ->and($summary->free())->toBe(PHP_INT_MAX)
        ->and($summary->limit)->toBeNull();
});

it('gives a freed seat to the earliest waitlisted member in the same transaction, drops both caches and raises both events', function (): void {
    Event::fake([OrganizationMemberLeft::class, OrganizationMemberSeated::class]);
    $org = omsOrganization('tutor', 2);
    $invite = omsInvite($org);
    $service = omsService();

    $a = Learner::factory()->create();
    $b = Learner::factory()->create();
    $service->joinByInvite($a, $invite->code);
    $service->joinByInvite($b, $invite->code);

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinute());
    $firstWaiting = Learner::factory()->create();
    $service->joinByInvite($firstWaiting, $invite->code);
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinute());
    $secondWaiting = Learner::factory()->create();
    $service->joinByInvite($secondWaiting, $invite->code);

    foreach ([$a, $b, $firstWaiting, $secondWaiting] as $l) {
        omsPlan($l); // prime every cache
    }
    expect(omsPlan($firstWaiting))->toBe(Plan::Free);

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinute());
    $service->leave($a, $org, 'left');

    $aRow = OrganizationMember::query()->where('learner_id', $a->id)->sole();
    $promoted = OrganizationMember::query()->where('learner_id', $firstWaiting->id)->sole();
    expect($aRow->status)->toBe('left')
        ->and($aRow->seat_state)->toBe('none')
        ->and($aRow->left_at?->toIso8601String())->toBe(CarbonImmutable::now()->toIso8601String())
        ->and($promoted->seat_state)->toBe('seated')
        ->and($promoted->seated_at?->toIso8601String())->toBe(CarbonImmutable::now()->toIso8601String())
        ->and($promoted->waitlisted_at)->toBeNull()
        ->and(OrganizationMember::query()->where('learner_id', $secondWaiting->id)->sole()->seat_state)->toBe('waitlisted')
        // Caches: the leaver and the promoted one are dropped, the untouched ones stay.
        ->and(Cache::has(EntitlementResolver::cacheKey($a->id)))->toBeFalse()
        ->and(Cache::has(EntitlementResolver::cacheKey($firstWaiting->id)))->toBeFalse()
        ->and(Cache::has(EntitlementResolver::cacheKey($b->id)))->toBeTrue()
        ->and(Cache::has(EntitlementResolver::cacheKey($secondWaiting->id)))->toBeTrue()
        ->and(omsPlan($a))->toBe(Plan::Free)
        ->and(omsPlan($firstWaiting))->toBe(Plan::Pro)
        ->and(omsService()->seatsSummary($org)->toArray())->toBe(['limit' => 2, 'seated' => 2, 'waitlisted' => 1, 'sponsoring' => true])
        ->and(ProductEvent::query()->where('name', 'org.member_left')->sole()->props['was_seated'])->toBeTrue()
        ->and(ProductEvent::query()->where('name', 'org.member_seated')->sole()->learner_id)->toBe($firstWaiting->id);

    Event::assertDispatched(OrganizationMemberLeft::class, fn (OrganizationMemberLeft $e): bool => $e->learnerId === $a->id && $e->wasSeated && $e->status === 'left' && $e->byUserId === null);
    Event::assertDispatched(OrganizationMemberSeated::class, fn (OrganizationMemberSeated $e): bool => $e->learnerId === $firstWaiting->id && $e->organizationId === $org->id);
});

it('does not promote anyone when a waitlisted member leaves, and reports wasSeated = false', function (): void {
    Event::fake([OrganizationMemberLeft::class, OrganizationMemberSeated::class]);
    $org = omsOrganization('tutor', 1);
    $invite = omsInvite($org);
    omsService()->joinByInvite(Learner::factory()->create(), $invite->code);
    $waiting = Learner::factory()->create();
    omsService()->joinByInvite($waiting, $invite->code);
    $alsoWaiting = Learner::factory()->create();
    omsService()->joinByInvite($alsoWaiting, $invite->code);

    omsService()->leave($waiting, $org, 'left');

    expect(OrganizationMember::query()->where('learner_id', $alsoWaiting->id)->sole()->seat_state)->toBe('waitlisted');
    Event::assertDispatched(OrganizationMemberLeft::class, fn (OrganizationMemberLeft $e): bool => $e->learnerId === $waiting->id && ! $e->wasSeated);
    Event::assertNotDispatched(OrganizationMemberSeated::class);
});

it('re-activates a member who comes back after leaving and seats them by the current capacity', function (): void {
    $org = omsOrganization('tutor', 1);
    $invite = omsInvite($org);
    $learner = Learner::factory()->create();
    $row = omsService()->joinByInvite($learner, $invite->code);
    omsService()->leave($learner, $org, 'removed');
    Learner::factory()->seatedIn($org)->create(); // the seat is taken meanwhile

    $back = omsService()->joinByInvite($learner, $invite->code);

    expect($back->id)->toBe($row->id)
        ->and($back->status)->toBe('active')
        ->and($back->seat_state)->toBe('waitlisted')
        ->and($back->left_at)->toBeNull()
        ->and($invite->refresh()->uses_count)->toBe(2)
        ->and(OrganizationMember::query()->count())->toBe(2);
});

it('refuses to end a membership that is not active, and an unknown end status', function (): void {
    $org = omsOrganization();
    $learner = Learner::factory()->create();

    expect(fn () => omsService()->leave($learner, $org, 'left'))
        ->toThrow(OrganizationMembershipException::class, 'not an active member');

    omsService()->joinByInvite($learner, omsInvite($org)->code);
    expect(fn () => omsService()->leave($learner, $org, 'banned'))->toThrow(InvalidArgumentException::class);
});

it('audits a removal by staff and an admin-side join', function (): void {
    $org = omsOrganization();
    $admin = User::factory()->create();
    $learner = Learner::factory()->create();

    $member = omsService()->joinByAdmin($learner, $org, $admin, '10.0.0.1');
    expect($member->joined_via)->toBe('admin')
        ->and($member->seat_state)->toBe('seated')
        ->and(AuditLog::query()->where('action', 'organization.member_added')->sole()->meta['learner_id'])->toBe($learner->id);

    // Idempotent: no second audit row.
    omsService()->joinByAdmin($learner, $org, $admin);
    expect(AuditLog::query()->where('action', 'organization.member_added')->count())->toBe(1);

    omsService()->leave($learner, $org, 'removed', $admin, '10.0.0.1');
    $audit = AuditLog::query()->where('action', 'organization.member_removed')->sole();
    expect($audit->user_id)->toBe($admin->id)
        ->and($audit->ip)->toBe('10.0.0.1')
        ->and($audit->meta['status'])->toBe('removed')
        ->and($audit->meta['was_seated'])->toBeTrue()
        ->and(ProductEvent::query()->where('name', 'org.member_left')->sole()->user_id)->toBe($admin->id);
});

// --- reseat / tariffs ------------------------------------------------------------------------

it('seats the waitlist FIFO after a tariff upgrade (grantManual with a bigger tier reseats after commit)', function (): void {
    Event::fake([OrganizationMemberSeated::class]);
    $org = omsOrganization('tutor', 2);
    $invite = omsInvite($org);
    $service = omsService();
    $learners = Learner::factory()->count(5)->create();

    foreach ($learners as $i => $learner) {
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinute());
        $service->joinByInvite($learner, $invite->code);
        omsPlan($learner);
    }
    expect(OrganizationMember::query()->where('seat_state', 'waitlisted')->count())->toBe(3);

    // Raise the limit to 4: exactly the two earliest waitlisted get seats, the fifth keeps waiting.
    config()->set('billing.org_tiers.center_s.seats', 4);
    app(SubscriptionService::class)->grantManual($org, Plan::Pro, null, User::factory()->create(), 'upgrade', tier: 'center_s');

    $states = OrganizationMember::query()->orderBy('waitlisted_at')->orderBy('id')->get()->keyBy('learner_id');
    expect($states[$learners[0]->id]->seat_state)->toBe('seated')
        ->and($states[$learners[1]->id]->seat_state)->toBe('seated')
        ->and($states[$learners[2]->id]->seat_state)->toBe('seated')
        ->and($states[$learners[3]->id]->seat_state)->toBe('seated')
        ->and($states[$learners[4]->id]->seat_state)->toBe('waitlisted')
        ->and(Cache::has(EntitlementResolver::cacheKey($learners[2]->id)))->toBeFalse()
        ->and(omsPlan($learners[3]))->toBe(Plan::Pro)
        ->and(omsPlan($learners[4]))->toBe(Plan::Free);

    Event::assertDispatchedTimes(OrganizationMemberSeated::class, 2);

    // A second reseat with no capacity does nothing.
    expect($service->reseat($org))->toBe(0);
});

it('keeps everybody seated after a tariff downgrade, shows the excess and seats no newcomer', function (): void {
    $org = omsOrganization('center_s', 100);
    $invite = omsInvite($org);
    $members = Learner::factory()->count(3)->create();
    foreach ($members as $m) {
        omsService()->joinByInvite($m, $invite->code);
    }

    Subscription::query()->where('subscriber_id', $org->id)->update(['tier' => 'tutor', 'seats_limit' => 2]);
    omsService()->reseat($org);

    $summary = omsService()->seatsSummary($org);
    expect($summary->seated)->toBe(3)
        ->and($summary->limit)->toBe(2)
        ->and($summary->overLimit())->toBeTrue()
        ->and($summary->free())->toBe(0)
        ->and(omsPlan($members[2]))->toBe(Plan::Pro)
        ->and(omsService()->joinByInvite(Learner::factory()->create(), $invite->code)->seat_state)->toBe('waitlisted');

    // Someone leaves: 2 seated of 2, still nobody is promoted (the limit is reached exactly).
    omsService()->leave($members[0], $org, 'left');
    expect(omsService()->seatsSummary($org)->toArray())->toBe(['limit' => 2, 'seated' => 2, 'waitlisted' => 1, 'sponsoring' => true]);
});

it('reseat is a no-op for a suspended or missing organization', function (): void {
    $org = omsOrganization();
    Learner::factory()->seatedIn($org, 'waitlisted')->create();
    $org->update(['status' => 'suspended']);

    expect(omsService()->reseat($org))->toBe(0)
        ->and(omsService()->reseat(999_999))->toBe(0);
});

// --- groups ----------------------------------------------------------------------------------

it('makes a learner who joins a group of the organization a member via group, with a seat', function (): void {
    $org = omsOrganization();
    $group = TelegramGroup::factory()->create(['status' => 'active', 'organization_id' => $org->id]);
    $learner = Learner::factory()->create();
    expect(omsPlan($learner))->toBe(Plan::Free);

    app(GroupMembershipService::class)->join($learner, $group, 'group');

    $member = OrganizationMember::query()->sole();
    expect($member->learner_id)->toBe($learner->id)
        ->and($member->joined_via)->toBe('group')
        ->and($member->seat_state)->toBe('seated')
        ->and($member->invite_id)->toBeNull()
        ->and(omsPlan($learner))->toBe(Plan::Pro);

    // Re-joining the group (duplicate update) changes nothing.
    app(GroupMembershipService::class)->join($learner, $group, 'chat_member');
    expect(OrganizationMember::query()->count())->toBe(1)
        ->and(ProductEvent::query()->where('name', 'org.member_joined')->count())->toBe(1);
});

it('does nothing for a group without an organization, and returns null for a suspended one', function (): void {
    $plain = TelegramGroup::factory()->create(['status' => 'active', 'organization_id' => null]);
    $suspended = Organization::factory()->suspended()->create();
    $suspendedGroup = TelegramGroup::factory()->create(['status' => 'active', 'organization_id' => $suspended->id]);
    $learner = Learner::factory()->create();

    app(GroupMembershipService::class)->join($learner, $plain, 'group');
    expect(omsService()->joinFromGroup($learner, $plain))->toBeNull()
        ->and(omsService()->joinFromGroup($learner, $suspendedGroup))->toBeNull()
        ->and(OrganizationMember::query()->count())->toBe(0);
});

it('ends a group membership only when the learner leaves the last group of the organization; an invite membership stays', function (): void {
    $org = omsOrganization();
    $groupA = TelegramGroup::factory()->create(['status' => 'active', 'organization_id' => $org->id]);
    $groupB = TelegramGroup::factory()->create(['status' => 'active', 'organization_id' => $org->id]);
    $groups = app(GroupMembershipService::class);

    $viaGroup = Learner::factory()->create();
    $groups->join($viaGroup, $groupA, 'group');
    $groups->join($viaGroup, $groupB, 'group');

    $viaInvite = Learner::factory()->create();
    omsService()->joinByInvite($viaInvite, omsInvite($org)->code);
    $groups->join($viaInvite, $groupA, 'group'); // already a member: the group adds nothing

    expect(OrganizationMember::query()->count())->toBe(2)
        ->and(OrganizationMember::query()->where('learner_id', $viaInvite->id)->sole()->joined_via)->toBe('invite');

    $groups->leave($viaGroup, $groupA);
    expect(OrganizationMember::query()->where('learner_id', $viaGroup->id)->sole()->status)->toBe('active') // still in B
        ->and(omsPlan($viaGroup))->toBe(Plan::Pro);

    $groups->leave($viaGroup, $groupB, 'removed');
    $row = OrganizationMember::query()->where('learner_id', $viaGroup->id)->sole();
    expect($row->status)->toBe('left')
        ->and($row->seat_state)->toBe('none')
        ->and(omsPlan($viaGroup))->toBe(Plan::Free);

    $groups->leave($viaInvite, $groupA);
    expect(OrganizationMember::query()->where('learner_id', $viaInvite->id)->sole()->status)->toBe('active')
        ->and(omsPlan($viaInvite))->toBe(Plan::Pro);
});

it('turns a group membership into an invite membership when the learner redeems a code, so it survives leaving the groups', function (): void {
    $org = omsOrganization();
    $group = TelegramGroup::factory()->create(['status' => 'active', 'organization_id' => $org->id]);
    $invite = omsInvite($org);
    $learner = Learner::factory()->create();
    app(GroupMembershipService::class)->join($learner, $group, 'group');

    $member = omsService()->joinByInvite($learner, $invite->code);

    expect($member->joined_via)->toBe('invite')
        ->and($member->invite_id)->toBe($invite->id)
        ->and($invite->refresh()->uses_count)->toBe(1)
        ->and(OrganizationMember::query()->count())->toBe(1);

    app(GroupMembershipService::class)->leave($learner, $group);
    expect($member->fresh()?->status)->toBe('active');
});

it('syncGroup creates the missing group memberships only, skipping soft-deleted learners and non-active group rows', function (): void {
    $org = omsOrganization('tutor', 2);
    $group = TelegramGroup::factory()->create(['status' => 'active', 'organization_id' => $org->id]);
    $already = Learner::factory()->memberOf($group)->seatedIn($org, 'seated', 'group')->create();
    $missing = Learner::factory()->memberOf($group)->create();
    $missingToo = Learner::factory()->memberOf($group)->create();
    Learner::factory()->memberOf($group, 'left')->create();
    Learner::factory()->memberOf($group)->create(['deleted_at' => now()]);

    expect(omsService()->syncGroup($group))->toBe(2);

    $rows = OrganizationMember::query()->orderBy('id')->get();
    expect($rows)->toHaveCount(3)
        ->and($rows->firstWhere('learner_id', $missing->id)?->seat_state)->toBe('seated')      // 2nd seat
        ->and($rows->firstWhere('learner_id', $missingToo->id)?->seat_state)->toBe('waitlisted')
        ->and($rows->firstWhere('learner_id', $already->id)?->seat_state)->toBe('seated')
        ->and(omsService()->syncGroup($group))->toBe(0); // idempotent
});

it('the organizations:sync-members command syncs every group of every active organization, or one by slug', function (): void {
    $org = omsOrganization();
    $other = omsOrganization();
    $suspended = Organization::factory()->suspended()->create();
    $g1 = TelegramGroup::factory()->create(['status' => 'active', 'organization_id' => $org->id]);
    $g2 = TelegramGroup::factory()->create(['status' => 'disabled', 'organization_id' => $org->id]); // group status is irrelevant
    $g3 = TelegramGroup::factory()->create(['status' => 'active', 'organization_id' => $other->id]);
    $g4 = TelegramGroup::factory()->create(['status' => 'active', 'organization_id' => $suspended->id]);
    Learner::factory()->memberOf($g1)->count(2)->create();
    Learner::factory()->memberOf($g2)->create();
    Learner::factory()->memberOf($g3)->create();
    Learner::factory()->memberOf($g4)->create();

    $this->artisan('organizations:sync-members', ['--organization' => $org->slug])
        ->expectsOutputToContain("{$org->slug}: 3 membership(s) created")
        ->assertSuccessful();
    expect(OrganizationMember::query()->count())->toBe(3);

    $this->artisan('organizations:sync-members')
        ->expectsOutputToContain('Organization memberships synced: 1')
        ->assertSuccessful();
    expect(OrganizationMember::query()->count())->toBe(4)
        ->and(OrganizationMember::query()->where('organization_id', $suspended->id)->count())->toBe(0);

    $this->artisan('organizations:sync-members', ['--organization' => 'nope'])->assertFailed();
});

// --- misc --------------------------------------------------------------------------------------

it('logs a warning when one invite code brings more joins in a day than the configured burst', function (): void {
    config()->set('organizations.invite_burst_per_day', 2);
    Log::spy();
    $org = omsOrganization(null, null);
    $invite = omsInvite($org);

    foreach (range(1, 3) as $_) {
        omsService()->joinByInvite(Learner::factory()->create(), $invite->code);
    }

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $message === 'organizations.join_burst' && $context['joins_24h'] === 3 && $context['invite_id'] === $invite->id);
});

it('exposes the active membership of a learner', function (): void {
    $org = omsOrganization();
    $learner = Learner::factory()->create();

    expect(omsService()->activeMembership($learner, $org))->toBeNull();
    $member = omsService()->joinByInvite($learner, omsInvite($org)->code);
    expect(omsService()->activeMembership($learner, $org)?->id)->toBe($member->id);
    omsService()->leave($learner, $org);
    expect(omsService()->activeMembership($learner, $org))->toBeNull();
});
