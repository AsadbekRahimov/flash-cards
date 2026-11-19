<?php

declare(strict_types=1);

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Billing\Enums\OfferPlacement;
use App\Domain\Billing\Services\OfferViewTracker;
use App\Domain\Twa\Services\JwtService;
use App\Models\Learner;
use App\Models\ProductEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\QueryLog;

/*
 * POST /api/twa/billing/offer-viewed (docs/04 A.8, docs/13 §5.1): the first step of the Pro funnel. One `billing.offer_viewed`
 * per (learner, placement, local day of the learner), props `placement` and `locale` only; 204 always; nothing while nothing is
 * for sale; a repeat costs no SQL beyond the authentication; its own limiter.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Stars only (the owner's decision): the real provider is enough to sell, nothing is called by this endpoint.
    config()->set('billing.providers.telegram_stars.enabled', true);
    config()->set('billing.paywall.mode', 'purchase');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-19 10:00:00', 'UTC'));
    $this->learner = Learner::factory()->create(['interface_locale' => 'uz', 'timezone' => 'Asia/Tashkent', 'first_name' => 'Ada', 'username' => 'ada_l']);
    $this->headers = ['Authorization' => 'Bearer '.app(JwtService::class)->issue($this->learner->id)['token']];
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/** @return list<ProductEvent> */
function offerViews(): array
{
    return ProductEvent::query()->where('name', ProductEventName::BillingOfferViewed->value)->orderBy('id')->get()->all();
}

it('records one event with the placement and the locale snapshot, and answers 204 with no body', function (): void {
    $this->withHeaders($this->headers)->postJson('/api/twa/billing/offer-viewed', ['placement' => 'paywall_quota'])
        ->assertNoContent();

    $events = offerViews();
    expect($events)->toHaveCount(1)
        ->and($events[0]->learner_id)->toBe($this->learner->id)
        // Exactly these two props (any order: jsonb sorts keys): no name, no username, no Telegram id.
        ->and($events[0]->props)->toEqual(['placement' => 'paywall_quota', 'locale' => 'uz']);
});

it('counts a placement once per local day of the learner, every placement on its own', function (): void {
    $post = fn (string $placement) => $this->withHeaders($this->headers)->postJson('/api/twa/billing/offer-viewed', ['placement' => $placement])->assertNoContent();

    $post('paywall_quota');
    $post('paywall_quota');
    $post('finished_pro_tip');
    expect(offerViews())->toHaveCount(2);

    // 23:59 in Tashkent (UTC+5) is still the same local day...
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-19 18:59:00', 'UTC'));
    $post('paywall_quota');
    expect(offerViews())->toHaveCount(2);

    // ...00:01 is the next one, though the UTC date has not changed.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-19 19:01:00', 'UTC'));
    $post('paywall_quota');
    $post('finished_pro_tip');

    expect(array_map(static fn (ProductEvent $e): string => (string) $e->props['placement'], offerViews()))
        ->toBe(['paywall_quota', 'finished_pro_tip', 'paywall_quota', 'finished_pro_tip']);
});

it('keeps the day of one learner apart from another', function (): void {
    $other = Learner::factory()->create(['interface_locale' => 'ru']);
    $otherHeaders = ['Authorization' => 'Bearer '.app(JwtService::class)->issue($other->id)['token']];

    $this->withHeaders($this->headers)->postJson('/api/twa/billing/offer-viewed', ['placement' => 'billing_view'])->assertNoContent();
    $this->withHeaders($otherHeaders)->postJson('/api/twa/billing/offer-viewed', ['placement' => 'billing_view'])->assertNoContent();

    expect(array_map(static fn (ProductEvent $e): string => (string) $e->props['locale'], offerViews()))->toBe(['uz', 'ru']);
});

it('marks the day with a key of ids and a TTL on the non-evicting store', function (): void {
    $this->withHeaders($this->headers)->postJson('/api/twa/billing/offer-viewed', ['placement' => 'lapsed_banner'])->assertNoContent();

    // 15:00 in Tashkent on 2026-11-19.
    expect(Cache::has("billing:offer_viewed:v1:{$this->learner->id}:lapsed_banner:2026-11-19"))->toBeTrue()
        ->and(OfferViewTracker::markerKey($this->learner->id, OfferPlacement::LapsedBanner, '2026-11-19'))
        ->toBe("billing:offer_viewed:v1:{$this->learner->id}:lapsed_banner:2026-11-19");

    // The marker outlives the local day but not by much: 48 h.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-21 10:01:00', 'UTC'));
    expect(Cache::has("billing:offer_viewed:v1:{$this->learner->id}:lapsed_banner:2026-11-19"))->toBeFalse();
});

it('costs only the authentication when the view was already counted today, and one insert when it was not', function (): void {
    $post = fn () => $this->withHeaders($this->headers)->postJson('/api/twa/billing/offer-viewed', ['placement' => 'paywall_game'])->assertNoContent();

    $first = QueryLog::during($post);
    $repeat = QueryLog::during($post);

    expect(QueryLog::matching($first, '/^insert into ["`]product_events["`]/'))->toHaveCount(1)
        ->and(count($first))->toBe(2)
        ->and($repeat)->toHaveCount(1)
        ->and($repeat[0])->toMatch('/from ["`]learners["`]/');
});

it('records nothing while nothing is for sale, and still answers 204', function (string $case): void {
    if ($case === 'coming_soon') {
        config()->set('billing.paywall.mode', 'coming_soon');
    } else {
        // The mode says purchase, but no provider can sell: `/me` lowers it to coming_soon, so does this endpoint.
        config()->set('billing.providers.telegram_stars.enabled', false);
        config()->set('billing.providers.payme.enabled', false);
        config()->set('billing.providers.click.enabled', false);
    }

    $queries = QueryLog::during(fn () => $this->withHeaders($this->headers)
        ->postJson('/api/twa/billing/offer-viewed', ['placement' => 'paywall_info'])
        ->assertNoContent());

    expect(offerViews())->toBe([])
        ->and($queries)->toHaveCount(1)
        // No marker either: a view after billing opens the same day still counts.
        ->and(Cache::has("billing:offer_viewed:v1:{$this->learner->id}:paywall_info:2026-11-19"))->toBeFalse();
})->with(['coming_soon', 'no_provider']);

it('refuses a missing or unknown placement with 422 and records nothing', function (array $body): void {
    $this->withHeaders($this->headers)->postJson('/api/twa/billing/offer-viewed', $body)
        ->assertStatus(422)
        ->assertJsonValidationErrors('placement');

    expect(offerViews())->toBe([]);
})->with([
    'missing' => [[]],
    'unknown' => [['placement' => 'today_banner']],
    'not a string' => [['placement' => ['paywall_quota']]],
    'case' => [['placement' => 'PAYWALL_QUOTA']],
]);

it('needs the TWA token', function (): void {
    $this->postJson('/api/twa/billing/offer-viewed', ['placement' => 'paywall_quota'])->assertUnauthorized();

    expect(offerViews())->toBe([]);
});

it('has a limiter of its own: the 21st call in a minute is 429, for that learner only', function (): void {
    $placements = array_map(static fn (OfferPlacement $p): string => $p->value, OfferPlacement::cases());

    for ($i = 0; $i < 20; $i++) {
        $this->withHeaders($this->headers)->postJson('/api/twa/billing/offer-viewed', ['placement' => $placements[$i % count($placements)]])->assertNoContent();
    }

    $this->withHeaders($this->headers)->postJson('/api/twa/billing/offer-viewed', ['placement' => 'paywall_quota'])
        ->assertStatus(429)
        ->assertHeader('Retry-After');

    $other = Learner::factory()->create();
    $this->withHeaders(['Authorization' => 'Bearer '.app(JwtService::class)->issue($other->id)['token']])
        ->postJson('/api/twa/billing/offer-viewed', ['placement' => 'paywall_quota'])
        ->assertNoContent();

    // Every placement was counted once: the twelve of the closed list, the repeats were not.
    expect(offerViews())->toHaveCount(count($placements) + 1);
});

it('lists the places of the Mini App, the same as the client', function (): void {
    $client = (string) file_get_contents(resource_path('twa/src/composables/offerViewed.ts'));
    preg_match('/OFFER_PLACEMENTS = \[(.*?)\] as const/s', $client, $m);
    preg_match_all("/'([a-z_]+)'/", $m[1] ?? '', $values);

    expect($values[1])->toBe(array_map(static fn (OfferPlacement $p): string => $p->value, OfferPlacement::cases()));
});
