<?php

declare(strict_types=1);

use App\Domain\Twa\Services\JwtService;
use App\Models\Learner;
use App\Models\Referral;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * GET /api/twa/referrals (docs/04 A.12): the learner's own overview — the code is generated on the first
 * call and stays; of invited learners only a first name and the state are exposed.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['telegram.bot_username' => 'LexiFlowBot', 'referrals.reward_days' => 7]);
});

function referralsHeaders(Learner $learner): array
{
    return ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];
}

it('answers with the code, the link, the counts and the invited first names', function (): void {
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
        ->and($response->json('reward_days'))->toBe(7)
        ->and($response->json('activation'))->toBe(['days' => 3, 'reviews_per_day' => 10, 'window_days' => 14])
        ->and($response->json('counts'))->toBe(['invited' => 1, 'activated' => 1, 'rewarded' => 0])
        ->and($response->json('referrals'))->toHaveCount(1)
        ->and($response->json('referrals.0.first_name'))->toBe('Dilnoza')
        ->and($response->json('referrals.0.status'))->toBe('activated')
        ->and(array_keys($response->json('referrals.0')))->toBe(['first_name', 'status', 'linked_at']);

    // Stable on the second call.
    expect($this->withHeaders(referralsHeaders($learner))->getJson('/api/twa/referrals')->json('code'))->toBe($code);
});

it('requires a learner token', function (): void {
    $this->getJson('/api/twa/referrals')->assertUnauthorized();
});
