<?php

declare(strict_types=1);

use App\Domain\Twa\Services\JwtService;
use App\Models\Learner;
use App\Models\Referral;
use App\Models\ReferralMilestone;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * GET /api/twa/referrals (docs/04 A.12; docs/13 §7.1 "2C.9"): the learner's own overview — the code is generated on
 * the first call and stays; the milestones with their state and the next one; of invited learners only a first name
 * and the state are exposed.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'telegram.bot_username' => 'LexiFlowBot',
        'referrals.milestones' => [3, 6],
        'referrals.milestone_days' => 7,
        'referrals.activation' => ['days' => 1, 'reviews_per_day' => 10, 'window_days' => 14],
    ]);
});

function referralsHeaders(Learner $learner): array
{
    return ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];
}

it('answers with the code, the link, the rule, the counts, the milestones and the invited first names', function (): void {
    $learner = Learner::factory()->create();
    $friend = Learner::factory()->create(['first_name' => 'Dilnoza', 'last_name' => 'K', 'username' => 'dilnoza']);
    Referral::factory()->activated()->create(['referrer_learner_id' => $learner->id, 'referee_learner_id' => $friend->id]);
    Referral::factory()->create(['referrer_learner_id' => $learner->id, 'status' => 'rejected', 'reject_reason' => 'existing_learner']);
    Referral::factory()->create(['referee_learner_id' => $learner->id]); // as the invited one: not mine

    $response = $this->withHeaders(referralsHeaders($learner))->getJson('/api/twa/referrals')->assertOk();
    $code = $learner->fresh()?->referral_code;

    expect($code)->not->toBeNull()
        ->and($response->json('code'))->toBe($code)
        ->and($response->json('link'))->toBe("https://t.me/LexiFlowBot?start=ref_{$code}")
        ->and($response->json('activation'))->toBe(['days' => 1, 'reviews_per_day' => 10, 'window_days' => 14])
        ->and($response->json('milestone_days'))->toBe(7)
        ->and($response->json('counts'))->toBe(['invited' => 1, 'activated' => 1, 'counted' => 0])
        ->and($response->json('milestones'))->toBe([
            ['friends' => 3, 'days' => 7, 'reached_at' => null, 'rewarded' => false],
            ['friends' => 6, 'days' => 7, 'reached_at' => null, 'rewarded' => false],
        ])
        ->and($response->json('next'))->toBe(['friends' => 3, 'remaining' => 3])
        ->and($response->json('referrals'))->toHaveCount(1)
        ->and($response->json('referrals.0.first_name'))->toBe('Dilnoza')
        ->and($response->json('referrals.0.status'))->toBe('activated')
        ->and(array_keys($response->json('referrals.0')))->toBe(['first_name', 'status', 'linked_at']);

    // Stable on the second call.
    expect($this->withHeaders(referralsHeaders($learner))->getJson('/api/twa/referrals')->json('code'))->toBe($code);
});

it('shows the progress: the reached milestone with its date, the next one with what is left, and none after the last', function (): void {
    $learner = Learner::factory()->create();
    Referral::factory()->count(4)->rewarded()->create(['referrer_learner_id' => $learner->id]);
    $reached = ReferralMilestone::factory()->granted()->create(['referrer_learner_id' => $learner->id, 'milestone' => 3, 'reached_at' => now()->subDay()]);

    $response = $this->withHeaders(referralsHeaders($learner))->getJson('/api/twa/referrals')->assertOk();

    expect($response->json('counts'))->toBe(['invited' => 4, 'activated' => 4, 'counted' => 4])
        ->and($response->json('milestones.0'))->toBe(['friends' => 3, 'days' => 7, 'reached_at' => $reached->reached_at?->toIso8601String(), 'rewarded' => true])
        ->and($response->json('milestones.1.reached_at'))->toBeNull()
        ->and($response->json('next'))->toBe(['friends' => 6, 'remaining' => 2]);

    // A reached milestone still owed (the grant is in flight): reached, not rewarded.
    ReferralMilestone::factory()->create(['referrer_learner_id' => $learner->id, 'milestone' => 6]);
    $response = $this->withHeaders(referralsHeaders($learner))->getJson('/api/twa/referrals')->assertOk();
    expect($response->json('milestones.1.rewarded'))->toBeFalse()
        ->and($response->json('milestones.1.reached_at'))->not->toBeNull()
        ->and($response->json('next'))->toBeNull();
});

it('requires a learner token', function (): void {
    $this->getJson('/api/twa/referrals')->assertUnauthorized();
});
