<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Exceptions\PromoCodeException;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Billing\Services\PromoCodeService;
use App\Models\GroupMember;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\ProductEvent;
use App\Models\PromoCode;
use App\Models\PromoRedemption;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-18 12:00:00', 'UTC'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function promoFailure(callable $attempt): PromoCodeException
{
    try {
        $attempt();
    } catch (PromoCodeException $e) {
        return $e;
    }

    throw new LogicException('Expected PromoCodeException was not thrown.');
}

it('redeems a code into a promo subscription, records the redemption and invalidates the cache', function (): void {
    $learner = Learner::factory()->create();
    $promo = PromoCode::factory()->create(['code' => 'WELCOME30', 'duration_days' => 30, 'max_redemptions' => 100]);
    $resolver = app(EntitlementResolver::class);

    expect($resolver->for($learner)->plan)->toBe(Plan::Free);

    $sub = app(PromoCodeService::class)->redeem($learner, 'WELCOME30');

    expect($sub->provider)->toBe('promo')
        ->and($sub->provider_ref)->toBe((string) $promo->id)
        ->and($sub->status)->toBe('active')
        ->and($sub->plan)->toBe('pro')
        ->and($sub->subscriber_id)->toBe($learner->id)
        ->and($sub->current_period_ends_at?->toIso8601String())->toBe(CarbonImmutable::now()->addDays(30)->toIso8601String());

    $redemption = PromoRedemption::query()->sole();
    expect($redemption->promo_code_id)->toBe($promo->id)
        ->and($redemption->learner_id)->toBe($learner->id)
        ->and($redemption->subscription_id)->toBe($sub->id)
        ->and($redemption->redeemed_at->toIso8601String())->toBe(CarbonImmutable::now()->toIso8601String())
        ->and($promo->refresh()->redemptions_count)->toBe(1);

    expect(ProductEvent::query()->where('name', 'promo.redeemed')->sole()->props['promo_code_id'])->toBe($promo->id)
        ->and(ProductEvent::query()->where('name', 'subscription.granted')->sole()->props['provider'])->toBe('promo');

    expect(Cache::has(EntitlementResolver::cacheKey($learner->id)))->toBeFalse();
    $e = $resolver->for($learner);
    expect($e->plan)->toBe(Plan::Pro)->and($e->subscriptionId)->toBe($sub->id);
});

it('normalizes what the user typed: case, whitespace, dashes', function (): void {
    PromoCode::factory()->create(['code' => 'ABC123XYZ0']);

    expect(PromoCodeService::normalize("  abc-123 xyz0\n"))->toBe('ABC123XYZ0');

    $sub = app(PromoCodeService::class)->redeem(Learner::factory()->create(), ' abc-123 xyz0 ');
    expect($sub->status)->toBe('active');
});

it('reports unknown, deactivated and blank codes as promo_not_found (404)', function (): void {
    $learner = Learner::factory()->create();
    PromoCode::factory()->create(['code' => 'DISABLED01', 'is_active' => false]);
    $service = app(PromoCodeService::class);

    foreach (['NOPE', 'DISABLED01', '', '   '] as $code) {
        $e = promoFailure(fn () => $service->redeem($learner, $code));
        expect($e->errorCode)->toBe(PromoCodeException::NOT_FOUND)->and($e->httpStatus())->toBe(404);
    }

    expect(Subscription::query()->count())->toBe(0)->and(PromoRedemption::query()->count())->toBe(0);
});

it('rejects an expired code (410) and leaves no trace', function (): void {
    $promo = PromoCode::factory()->create(['code' => 'OLD', 'expires_at' => CarbonImmutable::now()]);

    $e = promoFailure(fn () => app(PromoCodeService::class)->redeem(Learner::factory()->create(), 'OLD'));

    expect($e->errorCode)->toBe(PromoCodeException::EXPIRED)
        ->and($e->httpStatus())->toBe(410)
        ->and($promo->refresh()->redemptions_count)->toBe(0)
        ->and(Subscription::query()->count())->toBe(0);

    // One second earlier it is still valid.
    $promo->update(['expires_at' => CarbonImmutable::now()->addSecond()]);
    expect(app(PromoCodeService::class)->redeem(Learner::factory()->create(), 'OLD')->status)->toBe('active');
});

it('enforces max_redemptions (409 promo_exhausted) and counts each learner once', function (): void {
    $promo = PromoCode::factory()->create(['code' => 'LIMIT2', 'max_redemptions' => 2]);
    $service = app(PromoCodeService::class);

    $service->redeem(Learner::factory()->create(), 'LIMIT2');
    $service->redeem(Learner::factory()->create(), 'LIMIT2');

    $e = promoFailure(fn () => $service->redeem(Learner::factory()->create(), 'LIMIT2'));

    expect($e->errorCode)->toBe(PromoCodeException::EXHAUSTED)
        ->and($e->httpStatus())->toBe(409)
        ->and($promo->refresh()->redemptions_count)->toBe(2)
        ->and(PromoRedemption::query()->count())->toBe(2)
        ->and(Subscription::query()->count())->toBe(2);

    // Unlimited codes keep going.
    $promo->update(['max_redemptions' => null]);
    expect($service->redeem(Learner::factory()->create(), 'LIMIT2')->status)->toBe('active')
        ->and($promo->refresh()->redemptions_count)->toBe(3);
});

it('rejects a second redemption by the same learner (409 promo_already_redeemed) without extending anything', function (): void {
    $learner = Learner::factory()->create();
    $promo = PromoCode::factory()->create(['code' => 'ONCE', 'duration_days' => 30]);
    $service = app(PromoCodeService::class);

    $sub = $service->redeem($learner, 'ONCE');

    $e = promoFailure(fn () => $service->redeem($learner, 'once'));

    expect($e->errorCode)->toBe(PromoCodeException::ALREADY_REDEEMED)
        ->and($e->httpStatus())->toBe(409)
        ->and($promo->refresh()->redemptions_count)->toBe(1)
        ->and(PromoRedemption::query()->count())->toBe(1)
        ->and($sub->refresh()->current_period_ends_at?->toIso8601String())->toBe(CarbonImmutable::now()->addDays(30)->toIso8601String());
});

it('extends a live personal subscription from its end, re-activating one in grace', function (): void {
    $learner = Learner::factory()->create();
    PromoCode::factory()->create(['code' => 'PLUS30', 'duration_days' => 30]);
    PromoCode::factory()->create(['code' => 'PLUS7', 'duration_days' => 7]);
    $service = app(PromoCodeService::class);

    $existing = Subscription::factory()->create([
        'subscriber_id' => $learner->id,
        'provider' => 'manual',
        'current_period_ends_at' => CarbonImmutable::now()->addDays(10),
    ]);

    $sub = $service->redeem($learner, 'PLUS30');

    expect($sub->id)->toBe($existing->id)
        ->and($sub->provider)->toBe('manual') // the original grant keeps its provider; the extension is in meta.history
        ->and($sub->current_period_ends_at?->toIso8601String())->toBe(CarbonImmutable::now()->addDays(40)->toIso8601String())
        ->and($sub->meta['history'][0]['action'])->toBe('promo_extension')
        ->and($sub->meta['history'][0]['days'])->toBe(30)
        ->and(Subscription::query()->count())->toBe(1)
        ->and(PromoRedemption::query()->sole()->subscription_id)->toBe($existing->id);

    // In grace with the period already over: the new period starts now.
    $existing->update(['status' => 'past_due', 'provider' => 'telegram_stars', 'current_period_ends_at' => CarbonImmutable::now()->subDay(), 'grace_until' => CarbonImmutable::now()->addDay()]);

    $sub = $service->redeem($learner, 'PLUS7');

    expect($sub->id)->toBe($existing->id)
        ->and($sub->status)->toBe('active')
        ->and($sub->grace_until)->toBeNull()
        ->and($sub->current_period_ends_at?->toIso8601String())->toBe(CarbonImmutable::now()->addDays(7)->toIso8601String());
});

it('refuses to redeem onto a perpetual personal subscription (409 promo_not_applicable) without consuming the code', function (): void {
    $learner = Learner::factory()->create();
    $promo = PromoCode::factory()->create(['code' => 'USELESS', 'max_redemptions' => 1]);
    Subscription::factory()->create(['subscriber_id' => $learner->id, 'current_period_ends_at' => null]);

    $e = promoFailure(fn () => app(PromoCodeService::class)->redeem($learner, 'USELESS'));

    expect($e->errorCode)->toBe(PromoCodeException::NOT_APPLICABLE)
        ->and($e->httpStatus())->toBe(409)
        ->and($promo->refresh()->redemptions_count)->toBe(0)
        ->and(PromoRedemption::query()->count())->toBe(0)
        ->and(ProductEvent::query()->count())->toBe(0);
});

it('creates a personal subscription for a learner whose Pro is only sponsored', function (): void {
    $learner = Learner::factory()->create();
    $org = Organization::factory()->create();
    $group = TelegramGroup::factory()->create(['status' => 'active', 'organization_id' => $org->id]);
    GroupMember::factory()->create(['telegram_group_id' => $group->id, 'learner_id' => $learner->id]);
    Subscription::factory()->forOrganization($org)->create(['current_period_ends_at' => CarbonImmutable::now()->addDays(5)]);
    PromoCode::factory()->create(['code' => 'OWN', 'duration_days' => 30]);
    $resolver = app(EntitlementResolver::class);

    expect($resolver->for($learner)->isSponsored())->toBeTrue();

    $sub = app(PromoCodeService::class)->redeem($learner, 'OWN');

    expect($sub->subscriber_type)->toBe('learner')
        ->and(Subscription::query()->where('subscriber_type', 'learner')->count())->toBe(1);

    // The personal subscription now ends later than the sponsor's -> it is the effective one.
    $e = $resolver->for($learner);
    expect($e->source)->toBe('subscription')->and($e->subscriptionId)->toBe($sub->id);
});

it('generates unique upper-case codes of the documented length', function (): void {
    PromoCode::factory()->create(['code' => 'AAAAAAAAAA']);
    $service = app(PromoCodeService::class);

    $codes = collect(range(1, 20))->map(fn (): string => $service->generateCode());

    expect($codes->unique())->toHaveCount(20);
    foreach ($codes as $code) {
        expect($code)->toMatch('/^[A-Z0-9]{10}$/')->and(PromoCodeService::normalize($code))->toBe($code);
    }
});
