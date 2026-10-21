<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Services\OfferService;
use App\Models\Learner;
use App\Models\Offer;
use App\Models\Organization;
use App\Models\PaymentOrder;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-19 12:00:00', 'UTC'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('creates the post-organization discount once, from config, and not for a learner who already pays', function (): void {
    config()->set('billing.offers.post_org_discount', ['pct' => 25, 'valid_days' => 10, 'period' => 'month']);
    $service = app(OfferService::class);
    $learner = Learner::factory()->create();
    $org = Organization::factory()->create(['slug' => 'acme']);
    $now = CarbonImmutable::now();

    $offer = $service->createPostOrgDiscount($learner, $org, $now);

    expect($offer)->not->toBeNull()
        ->and($offer?->kind)->toBe('post_org_discount')
        ->and($offer?->plan)->toBe('pro')
        ->and($offer?->period)->toBe('month')
        ->and($offer?->discount_pct)->toBe(25)
        ->and($offer?->expires_at->toIso8601String())->toBe($now->addDays(10)->toIso8601String())
        ->and($offer?->source_organization_id)->toBe($org->id)
        ->and($offer?->meta['organization_slug'])->toBe('acme')
        ->and($offer?->isOpenAt($now))->toBeTrue()
        ->and($offer?->covers('pro', 'month'))->toBeTrue()
        ->and($offer?->covers('pro', 'year'))->toBeFalse();

    // Already has an open one -> nothing; after it expires unused, a new one replaces it (the
    // partial unique index counts every unredeemed offer, so the lapsed one is dropped).
    expect($service->createPostOrgDiscount($learner, $org, $now))->toBeNull()
        ->and($service->openFor($learner, 'post_org_discount', $now)?->id)->toBe($offer?->id)
        ->and($service->createPostOrgDiscount($learner, $org, $now->addDays(11)))->not->toBeNull()
        ->and(Offer::query()->where('learner_id', $learner->id)->count())->toBe(1)
        ->and(Offer::query()->whereKey($offer?->id)->exists())->toBeFalse();

    // A learner with a live personal subscription has nothing to buy.
    $paying = Learner::factory()->create();
    Subscription::factory()->create(['subscriber_id' => $paying->id]);
    expect($service->createPostOrgDiscount($paying, $org, $now))->toBeNull();

    // An expired or canceled-but-running subscription: canceled is live -> no offer; expired -> offer.
    $former = Learner::factory()->create();
    Subscription::factory()->expired()->create(['subscriber_id' => $former->id]);
    expect($service->createPostOrgDiscount($former, $org, $now))->not->toBeNull();
    $canceled = Learner::factory()->create();
    Subscription::factory()->create(['subscriber_id' => $canceled->id, 'status' => 'canceled', 'provider' => 'telegram_stars']);
    expect($service->createPostOrgDiscount($canceled, $org, $now))->toBeNull();
});

it('gives the post-organization discount once in a lifetime: a used one is never re-issued, a lapsed unused one is', function (): void {
    $service = app(OfferService::class);
    $org = Organization::factory()->create();
    $learner = Learner::factory()->create();
    $now = CarbonImmutable::now();

    $first = $service->createPostOrgDiscount($learner, $org, $now);
    expect($first)->not->toBeNull();

    // The discount was paid with: joining and leaving a center again (every month, if one likes) gives nothing.
    $first?->forceFill(['redeemed_at' => $now->addDay(), 'payment_order_id' => PaymentOrder::factory()->create(['subscriber_id' => $learner->id])->id])->save();
    expect($service->createPostOrgDiscount($learner, $org, $now->addDays(2)))->toBeNull()
        ->and($service->createPostOrgDiscount($learner, Organization::factory()->create(), $now->addDays(40)))->toBeNull()
        ->and(Offer::query()->where('learner_id', $learner->id)->count())->toBe(1);

    // A learner who let the discount lapse unused may get one again (that is no abuse); it is still one per lifetime once used.
    $other = Learner::factory()->create();
    $lapsed = $service->createPostOrgDiscount($other, $org, $now);
    $again = $service->createPostOrgDiscount($other, $org, $now->addDays(20));
    expect($lapsed)->not->toBeNull()
        ->and($again)->not->toBeNull()
        ->and($again?->id)->not->toBe($lapsed?->id)
        ->and(Offer::query()->where('learner_id', $other->id)->count())->toBe(1);
});

it('applies a discount with rounding and a floor of one Star / one sum', function (): void {
    $service = app(OfferService::class);
    $thirty = Offer::factory()->make(['discount_pct' => 30]);
    $ninety = Offer::factory()->make(['discount_pct' => 90]);

    expect($service->apply($thirty, 150, 'XTR'))->toBe(105)
        ->and($service->apply($thirty, 2_900_000, 'UZS'))->toBe(2_030_000)
        ->and($service->apply($thirty, 149, 'XTR'))->toBe(104) // 104.3 -> 104
        ->and($service->apply($thirty, 145, 'XTR'))->toBe(102) // 101.5 -> 102
        ->and($service->apply($ninety, 5, 'XTR'))->toBe(1)      // 0.5 -> floor 1
        ->and($service->apply($ninety, 500, 'UZS'))->toBe(100)  // 50 tiyin -> floor 100
        ->and($service->apply(Offer::factory()->make(['discount_pct' => 0]), 150, 'XTR'))->toBe(150)
        // Integer half-up at the boundaries (the amounts of PostOrgDiscountTest), no float involved.
        ->and($service->apply($thirty, 155, 'XTR'))->toBe(109)                  // 108.5 -> 109
        ->and($service->apply($thirty, 2_900_001, 'UZS'))->toBe(2_030_001)      // 2 030 000.7 -> 2 030 001
        ->and($service->apply($thirty, 1, 'XTR'))->toBe(1)                       // 0.7 -> 1
        ->and($service->apply($thirty, 2, 'XTR'))->toBe(1)                       // 1.4 -> 1
        ->and($service->apply($thirty, 3, 'XTR'))->toBe(2)                       // 2.1 -> 2
        ->and($service->apply($thirty, 5, 'XTR'))->toBe(4)                       // 3.5 -> 4
        ->and($service->apply(Offer::factory()->make(['discount_pct' => 100]), 150, 'XTR'))->toBe(1)
        ->and($service->apply($thirty, 9_000_000_000_001, 'UZS'))->toBe(6_300_000_000_001); // 6 300 000 000 000.7
});

it('picks the applicable offer: open, covering plan and period, largest discount first', function (): void {
    $service = app(OfferService::class);
    $learner = Learner::factory()->create();
    $now = CarbonImmutable::now();

    Offer::factory()->create(['learner_id' => $learner->id, 'kind' => 'campaign', 'discount_pct' => 10, 'plan' => null, 'period' => null]);
    $month = Offer::factory()->create(['learner_id' => $learner->id, 'kind' => 'post_org_discount', 'discount_pct' => 30, 'period' => 'month']);
    Offer::factory()->redeemed()->create(['learner_id' => $learner->id, 'kind' => 'winback', 'discount_pct' => 50]);
    Offer::factory()->expired()->create(['learner_id' => $learner->id, 'kind' => 'winback', 'discount_pct' => 60]);
    Offer::factory()->create(['kind' => 'campaign', 'discount_pct' => 80]); // another learner

    expect($service->applicableFor($learner, Plan::Pro, 'month', $now)?->id)->toBe($month->id)
        ->and($service->applicableFor($learner, Plan::Pro, 'year', $now)?->discount_pct)->toBe(10)
        ->and($service->applicableFor($learner, Plan::Pro, 'month', $now->addDays(15)))->toBeNull()
        ->and($service->openFor($learner, 'winback', $now))->toBeNull()
        ->and($service->openFor($learner, 'campaign', $now)?->discount_pct)->toBe(10);
});
