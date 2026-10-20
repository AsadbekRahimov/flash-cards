<?php

declare(strict_types=1);

use App\Domain\Twa\Services\JwtService;
use App\Models\Learner;
use App\Models\PromoCode;
use App\Models\PromoRedemption;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function promoHeaders(Learner $learner): array
{
    return ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];
}

it('redeems a code and answers with the refreshed profile (Pro with its end)', function (): void {
    $learner = Learner::factory()->create();
    PromoCode::factory()->create(['code' => 'WELCOME30', 'duration_days' => 30]);

    $this->withHeaders(promoHeaders($learner))
        ->postJson('/api/twa/promo/redeem', ['code' => ' welcome-30 '])
        ->assertOk()
        ->assertExactJsonStructure([
            'learner' => ['id', 'first_name', 'username', 'locale', 'onboarding_completed'],
            'groups',
            'stats' => ['words_learned', 'words_due_today'],
            'entitlement' => ['plan', 'source', 'until', 'new_words_per_day', 'renewal', 'lapsed'],
            'paywall' => ['mode', 'contact'],
            'settings' => [
                'interface_locale', 'timezone', 'reminder_time', 'daily_goal', 'goal', 'level', 'leagues_opt_out', 'timezone_change_available_at', 'timezone_undo_available_until', 'active_course',
                'options' => [
                    'locales' => ['*' => ['id', 'label']],
                    'timezones' => ['*' => ['id', 'label']],
                    'reminder_times',
                    'daily_goals',
                ],
            ],
        ])
        ->assertJsonPath('entitlement.plan', 'pro')
        ->assertJsonPath('entitlement.source', 'subscription')
        ->assertJsonPath('entitlement.new_words_per_day', null);

    expect(Subscription::query()->where('subscriber_id', $learner->id)->where('provider', 'promo')->count())->toBe(1)
        ->and(PromoRedemption::query()->count())->toBe(1);
});

it('maps every PromoCodeException to its status and stable error code', function (string $code, int $status, string $errorCode, Closure $arrange): void {
    $learner = Learner::factory()->create();
    $arrange($learner);

    $this->withHeaders(promoHeaders($learner))
        ->postJson('/api/twa/promo/redeem', ['code' => $code])
        ->assertStatus($status)
        ->assertJsonPath('error.code', $errorCode)
        ->assertJsonStructure(['error' => ['code', 'message']]);
})->with([
    'unknown code' => ['NOPE', 404, 'promo_not_found', fn () => null],
    'deactivated code' => ['OFF', 404, 'promo_not_found', fn () => PromoCode::factory()->create(['code' => 'OFF', 'is_active' => false])],
    'expired' => ['OLD', 410, 'promo_expired', fn () => PromoCode::factory()->create(['code' => 'OLD', 'expires_at' => now()->subDay()])],
    'exhausted' => ['FULL', 409, 'promo_exhausted', fn () => PromoCode::factory()->create(['code' => 'FULL', 'max_redemptions' => 1, 'redemptions_count' => 1])],
    'already redeemed' => ['TWICE', 409, 'promo_already_redeemed', function (Learner $learner): void {
        $promo = PromoCode::factory()->create(['code' => 'TWICE']);
        PromoRedemption::factory()->create(['promo_code_id' => $promo->id, 'learner_id' => $learner->id]);
    }],
    'perpetual personal subscription' => ['MORE', 409, 'promo_not_applicable', function (Learner $learner): void {
        PromoCode::factory()->create(['code' => 'MORE']);
        Subscription::factory()->create(['subscriber_id' => $learner->id, 'current_period_ends_at' => null]);
    }],
]);

it('rejects a missing, non-string or oversized code with 422', function (array $body): void {
    $learner = Learner::factory()->create();

    $this->withHeaders(promoHeaders($learner))
        ->postJson('/api/twa/promo/redeem', $body)
        ->assertStatus(422)
        ->assertJsonValidationErrors('code');
})->with([
    'missing' => [[]],
    'empty' => [['code' => '']],
    'array' => [['code' => ['X']]],
    'too long' => [['code' => str_repeat('A', 65)]],
]);

it('requires a token', function (): void {
    $this->postJson('/api/twa/promo/redeem', ['code' => 'X'])->assertStatus(401);
});

it('throttles code guessing per learner: the 6th attempt in a minute is 429', function (): void {
    $learner = Learner::factory()->create();

    foreach (range(1, 5) as $_) {
        $this->withHeaders(promoHeaders($learner))
            ->postJson('/api/twa/promo/redeem', ['code' => 'GUESS'])
            ->assertStatus(404);
    }

    $this->withHeaders(promoHeaders($learner))
        ->postJson('/api/twa/promo/redeem', ['code' => 'GUESS'])
        ->assertStatus(429);

    // another learner behind the same IP is still under the per-IP cap
    $other = Learner::factory()->create();
    $this->withHeaders(promoHeaders($other))
        ->postJson('/api/twa/promo/redeem', ['code' => 'GUESS'])
        ->assertStatus(404);
});

it('caps one IP at 60 attempts a minute across learners (CGNAT: many learners share one address)', function (): void {
    foreach (range(1, 60) as $_) {
        $this->withHeaders(promoHeaders(Learner::factory()->create()))
            ->postJson('/api/twa/promo/redeem', ['code' => 'GUESS'])
            ->assertStatus(404);
    }

    $this->withHeaders(promoHeaders(Learner::factory()->create()))
        ->postJson('/api/twa/promo/redeem', ['code' => 'GUESS'])
        ->assertStatus(429);
});
