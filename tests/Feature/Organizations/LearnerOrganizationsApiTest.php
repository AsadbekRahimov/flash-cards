<?php

declare(strict_types=1);

use App\Domain\Twa\Services\JwtService;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\ProductEvent;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @return array<string, string> */
function myOrgHeaders(Learner $learner): array
{
    return ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];
}

function myOrgSponsor(string $name = 'Bright Center'): Organization
{
    $org = Organization::factory()->create(['name' => $name]);
    Subscription::factory()->forOrganization($org)->create(['current_period_ends_at' => null, 'tier' => 'tutor', 'seats_limit' => 30]);

    return $org;
}

it('requires the TWA token', function (): void {
    $this->getJson('/api/twa/me/organizations')->assertUnauthorized();
    $this->deleteJson('/api/twa/me/organizations/1')->assertUnauthorized();
});

it('lists only the active memberships of the learner, newest first', function (): void {
    $older = myOrgSponsor('Older Center');
    $newer = myOrgSponsor('Newer Center');
    $learner = Learner::factory()->seatedIn($older)->create();
    OrganizationMember::factory()->create([
        'organization_id' => $newer->id,
        'learner_id' => $learner->id,
        'seat_state' => 'waitlisted',
        'seated_at' => null,
        'waitlisted_at' => now(),
        'joined_via' => 'group',
        'joined_at' => now()->addMinute(),
    ]);
    $ended = myOrgSponsor('Ended Center');
    OrganizationMember::factory()->create(['organization_id' => $ended->id, 'learner_id' => $learner->id, 'status' => 'left', 'seat_state' => 'none']);
    Learner::factory()->seatedIn($older)->create();

    $this->withHeaders(myOrgHeaders($learner))
        ->getJson('/api/twa/me/organizations')
        ->assertOk()
        ->assertExactJsonStructure(['organizations' => ['*' => ['id', 'name', 'seat_state', 'joined_via', 'joined_at', 'can_leave']]])
        ->assertJsonCount(2, 'organizations')
        ->assertJsonPath('organizations.0.name', 'Newer Center')
        ->assertJsonPath('organizations.0.seat_state', 'waitlisted')
        ->assertJsonPath('organizations.0.joined_via', 'group')
        ->assertJsonPath('organizations.0.can_leave', false)
        ->assertJsonPath('organizations.1.name', 'Older Center')
        ->assertJsonPath('organizations.1.seat_state', 'seated')
        ->assertJsonPath('organizations.1.can_leave', true);
});

it('answers an empty list for a learner without centers', function (): void {
    $this->withHeaders(myOrgHeaders(Learner::factory()->create()))
        ->getJson('/api/twa/me/organizations')
        ->assertOk()
        ->assertExactJson(['organizations' => []]);
});

it('lets the learner leave an invite or admin membership and returns the refreshed list', function (string $via): void {
    $org = myOrgSponsor();
    $other = myOrgSponsor('Other Center');
    $learner = Learner::factory()->seatedIn($org, 'seated', $via)->seatedIn($other)->create();
    $member = OrganizationMember::query()->where('organization_id', $org->id)->where('learner_id', $learner->id)->sole();

    $this->withHeaders(myOrgHeaders($learner))
        ->deleteJson("/api/twa/me/organizations/{$member->id}")
        ->assertOk()
        ->assertJsonCount(1, 'organizations')
        ->assertJsonPath('organizations.0.name', 'Other Center');

    expect($member->refresh()->status)->toBe('left')
        ->and($member->seat_state)->toBe('none')
        ->and($member->left_at)->not->toBeNull()
        ->and(ProductEvent::query()->where('name', 'org.member_left')->count())->toBe(1);
})->with(['invite', 'admin']);

it('gives the freed seat to the first waitlisted member', function (): void {
    $org = Organization::factory()->create();
    Subscription::factory()->forOrganization($org)->create(['current_period_ends_at' => null, 'tier' => 'tutor', 'seats_limit' => 1]);
    $leaver = Learner::factory()->seatedIn($org)->create();
    $waiting = Learner::factory()->seatedIn($org, 'waitlisted')->create();
    $member = OrganizationMember::query()->where('learner_id', $leaver->id)->sole();

    $this->withHeaders(myOrgHeaders($leaver))->deleteJson("/api/twa/me/organizations/{$member->id}")->assertOk();

    expect(OrganizationMember::query()->where('learner_id', $waiting->id)->sole()->seat_state)->toBe('seated');
});

it('refuses to leave a group membership with a clear code and keeps it', function (): void {
    $org = myOrgSponsor();
    $learner = Learner::factory()->seatedIn($org, 'seated', 'group')->create();
    $member = OrganizationMember::query()->where('learner_id', $learner->id)->sole();

    $this->withHeaders(myOrgHeaders($learner))
        ->deleteJson("/api/twa/me/organizations/{$member->id}")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'membership_via_group');

    expect($member->refresh()->status)->toBe('active')
        ->and($member->seat_state)->toBe('seated');
});

it('answers 404 for somebody else\'s, an ended or a missing membership', function (): void {
    $org = myOrgSponsor();
    $owner = Learner::factory()->seatedIn($org)->create();
    $stranger = Learner::factory()->create();
    $member = OrganizationMember::query()->where('learner_id', $owner->id)->sole();
    $ended = OrganizationMember::factory()->create(['organization_id' => $org->id, 'learner_id' => $stranger->id, 'status' => 'left', 'seat_state' => 'none']);

    $this->withHeaders(myOrgHeaders($stranger))
        ->deleteJson("/api/twa/me/organizations/{$member->id}")
        ->assertNotFound()
        ->assertJsonPath('error.code', 'membership_not_found');
    $this->withHeaders(myOrgHeaders($stranger))->deleteJson("/api/twa/me/organizations/{$ended->id}")->assertNotFound();
    $this->withHeaders(myOrgHeaders($stranger))->deleteJson('/api/twa/me/organizations/999999')->assertNotFound();

    expect($member->refresh()->status)->toBe('active');
});

it('does not route a non-numeric id', function (): void {
    $this->withHeaders(myOrgHeaders(Learner::factory()->create()))
        ->deleteJson('/api/twa/me/organizations/abc')
        ->assertNotFound();
});

it('throttles leaving to 10 a minute per learner', function (): void {
    $learner = Learner::factory()->create();

    foreach (range(1, 10) as $_) {
        $this->withHeaders(myOrgHeaders($learner))->deleteJson('/api/twa/me/organizations/999999')->assertNotFound();
    }

    $this->withHeaders(myOrgHeaders($learner))->deleteJson('/api/twa/me/organizations/999999')->assertStatus(429);
});
