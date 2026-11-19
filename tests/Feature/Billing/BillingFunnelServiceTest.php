<?php

declare(strict_types=1);

use App\Domain\Administration\Services\WidgetCache;
use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Billing\Services\BillingFunnelService;
use App\Domain\Operations\Support\PureCache;
use App\Models\Learner;
use App\Models\PaymentOrder;
use App\Models\ProductEvent;
use Carbon\CarbonImmutable;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\QueryLog;

/*
 * The Pro funnel (docs/13 §5.1): offer viewed → checkout started → payment succeeded → studied within 7 days, by provider and locale,
 * with the boundaries — a payment with no recorded view, a start before the view, a session on day 8, a renewal, two providers of one
 * learner, two locales, an unknown locale, the window. Runs on sqlite and on PostgreSQL (`DB_CONNECTION=pgsql`).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->now = CarbonImmutable::parse('2026-11-19 12:00:00', 'UTC');
    CarbonImmutable::setTestNow($this->now);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/** @param  array<string, mixed>  $props */
function funnelEventAt(Learner $learner, ProductEventName $name, CarbonImmutable $at, array $props = []): void
{
    ProductEvent::query()->create(['name' => $name->value, 'learner_id' => $learner->id, 'props' => $props, 'occurred_at' => $at]);
}

/** A learner through the steps of the funnel, each step at its moment (null = not done). */
function funnelLearner(
    ?string $viewLocale,
    ?CarbonImmutable $viewed,
    array $starts = [],
    array $payments = [],
    array $sessions = [],
    ?string $placement = 'paywall_quota',
): Learner {
    $learner = Learner::factory()->create(['interface_locale' => $viewLocale ?? 'ru']);

    if ($viewed !== null) {
        funnelEventAt($learner, ProductEventName::BillingOfferViewed, $viewed, array_filter(['placement' => $placement, 'locale' => $viewLocale], static fn ($v): bool => $v !== null));
    }
    foreach ($starts as [$provider, $at, $locale]) {
        funnelEventAt($learner, ProductEventName::CheckoutStarted, $at, ['provider' => $provider, 'locale' => $locale, 'via' => 'twa', 'placement' => $placement]);
    }
    foreach ($payments as [$provider, $at, $locale, $kind]) {
        funnelEventAt($learner, ProductEventName::PaymentSucceeded, $at, ['provider' => $provider, 'locale' => $locale, 'kind' => $kind]);
    }
    foreach ($sessions as $at) {
        funnelEventAt($learner, ProductEventName::TrainingSessionOpened, $at, ['mode' => 'daily_review', 'source' => 'self']);
    }

    return $learner;
}

/** The fixture of the whole file: every boundary of the definition (see each line). */
function funnelScenario(CarbonImmutable $now): void
{
    $d = static fn (float $days): CarbonImmutable => $now->subMinutes((int) round($days * 1440));

    // A (ru): the whole chain with Stars, a second view on another day, a later Stars renewal (not a decision to buy).
    $a = funnelLearner('ru', $d(10), [['telegram_stars', $d(9.9), 'ru']], [['telegram_stars', $d(9.8), 'ru', 'charge'], ['telegram_stars', $d(1), 'ru', 'renewal']], [$d(7)]);
    funnelEventAt($a, ProductEventName::BillingOfferViewed, $d(8), ['placement' => 'paywall_quota', 'locale' => 'ru']);
    // B (uz): paid with Stars, but the session after the payment is on day 8 (+7 d 1 h); the one before the payment does not count either.
    $paidB = $d(20);
    funnelLearner('uz', $d(20.1), [['telegram_stars', $d(20.05), 'uz']], [['telegram_stars', $paidB, 'uz', 'charge']], [$d(21), $paidB->addDays(7)->addHour()], 'billing_view');
    // C (ru): started with Payme, never paid - lost during the payment.
    funnelLearner('ru', $d(3), [['payme', $d(2.9), 'ru']], placement: 'paywall_info');
    // D (uz): only saw the offer - lost before the checkout.
    funnelLearner('uz', $d(2), placement: 'finished_pro_tip');
    // E (ru): started and paid with no recorded view (the bot, an old client): outside the chain.
    funnelLearner('ru', null, [['telegram_stars', $d(4), 'ru']], [['telegram_stars', $d(3.9), 'ru', 'charge']]);
    // F (ru): paid yesterday and studied an hour later: studied, but its 7 days are still running (maturing).
    funnelLearner('ru', $d(1.2), [['telegram_stars', $d(1.1), 'ru']], [['telegram_stars', $d(1), 'ru', 'charge']], [$d(1)->addHour()]);
    // G (ru): the start came BEFORE the first view: not a step of the chain, an outside start.
    funnelLearner('ru', $d(4), [['telegram_stars', $d(6), 'ru']]);
    // H (ru): two providers - started both, paid with Payme; counted once in "all providers".
    funnelLearner('ru', $d(3), [['telegram_stars', $d(2.9), 'ru'], ['payme', $d(2.8), 'ru']], [['payme', $d(2.7), 'ru', 'charge']], placement: 'lapsed_banner');
    // I (uz): the view is older than the window, the start is inside: outside.
    funnelLearner('uz', $d(31), [['telegram_stars', $d(5), 'uz']]);
    // J: a view without placement and locale (an old client): the unknown locale and placement.
    funnelLearner(null, $d(6), placement: null);
    // A center's event and a learner-less one are not the learners' funnel.
    ProductEvent::query()->create(['name' => ProductEventName::CheckoutStarted->value, 'learner_id' => null, 'props' => ['provider' => 'payme'], 'occurred_at' => $d(1)]);
}

/** @return array<string, array{viewed: int, started: int, paid: int, studied: int, maturing: int}> keyed "provider:locale" */
function funnelCells(array $report): array
{
    $out = [];
    foreach ($report['funnel'] as $row) {
        $out[$row['provider'].':'.$row['locale']] = array_intersect_key($row, array_flip(['viewed', 'started', 'paid', 'studied', 'maturing']));
    }

    return $out;
}

it('counts each step among the learners of the step before, by provider and locale of the first view', function (): void {
    funnelScenario($this->now);

    $report = app(BillingFunnelService::class)->compute($this->now);
    $cell = static fn (int $viewed, int $started, int $paid, int $studied, int $maturing): array => compact('viewed', 'started', 'paid', 'studied', 'maturing');

    expect($report['days'])->toBe(30)
        ->and(funnelCells($report))->toBe([
            // Every provider: viewed A, C, F, G, H (ru), B, D (uz), J (unknown); started A, C, F, H / B; paid A, F, H / B.
            // Studied among the matured payers only (A, B): A; F studied too, but its 7 days are still running.
            'all:all' => $cell(8, 5, 4, 1, 2),
            'all:ru' => $cell(5, 4, 3, 1, 2),
            'all:unknown' => $cell(1, 0, 0, 0, 0),
            'all:uz' => $cell(2, 1, 1, 0, 0),
            // Payme: C and H started, H paid three days ago (maturing, no session).
            'payme:all' => $cell(8, 2, 1, 0, 1),
            'payme:ru' => $cell(5, 2, 1, 0, 1),
            'payme:unknown' => $cell(1, 0, 0, 0, 0),
            'payme:uz' => $cell(2, 0, 0, 0, 0),
            // Stars: A, F, H started (ru), B (uz); A and F paid and studied, F still maturing (not counted yet); B studied on day 8 - not counted.
            'telegram_stars:all' => $cell(8, 4, 3, 1, 1),
            'telegram_stars:ru' => $cell(5, 3, 2, 1, 1),
            'telegram_stars:unknown' => $cell(1, 0, 0, 0, 0),
            'telegram_stars:uz' => $cell(2, 1, 1, 0, 0),
        ])
        // Outside the chain: E started and paid without a view, G started before its view (ru); I's view is older than the window (uz).
        ->and($report['outside'])->toBe([
            ['provider' => 'telegram_stars', 'locale' => 'ru', 'started' => 2, 'paid' => 1],
            ['provider' => 'telegram_stars', 'locale' => 'uz', 'started' => 1, 'paid' => 0],
        ])
        ->and($report['placements'])->toBe([
            ['placement' => 'paywall_quota', 'learners' => 3, 'views' => 4],
            ['placement' => 'billing_view', 'learners' => 1, 'views' => 1],
            ['placement' => 'finished_pro_tip', 'learners' => 1, 'views' => 1],
            ['placement' => 'lapsed_banner', 'learners' => 1, 'views' => 1],
            ['placement' => 'paywall_info', 'learners' => 1, 'views' => 1],
            ['placement' => 'unknown', 'learners' => 1, 'views' => 1],
        ]);
});

it('counts a session on day 7 after the payment, not one on day 8', function (int $minutes, int $studied): void {
    $paid = $this->now->subDays(10);
    funnelLearner('ru', $paid->subHour(), [['telegram_stars', $paid->subMinutes(30), 'ru']], [['telegram_stars', $paid, 'ru', 'charge']], [$paid->addMinutes($minutes)]);

    $cells = funnelCells(app(BillingFunnelService::class)->compute($this->now));

    expect($cells['telegram_stars:ru']['paid'])->toBe(1)
        ->and($cells['telegram_stars:ru']['studied'])->toBe($studied)
        ->and($cells['telegram_stars:ru']['maturing'])->toBe(0);
})->with([
    'a minute after the payment' => [1, 1],
    'exactly 7 days after' => [7 * 1440, 1],
    'a minute past 7 days' => [7 * 1440 + 1, 0],
    'on day 8' => [8 * 1440, 0],
]);

it('counts as studied only a payer whose 7 days are over: a young payment is maturing, studied or not', function (int $daysAgo, int $studied, int $maturing): void {
    $paid = $this->now->subDays($daysAgo);
    funnelLearner('ru', $paid->subHour(), [['telegram_stars', $paid->subMinutes(30), 'ru']], [['telegram_stars', $paid, 'ru', 'charge']], [$paid->addHour()]);

    $cells = funnelCells(app(BillingFunnelService::class)->compute($this->now));

    expect($cells['telegram_stars:ru'])->toMatchArray(['paid' => 1, 'studied' => $studied, 'maturing' => $maturing])
        ->and($cells['all:all'])->toMatchArray(['paid' => 1, 'studied' => $studied, 'maturing' => $maturing]);
})->with([
    'paid 6 days ago' => [6, 0, 1],
    'paid exactly 7 days ago' => [7, 1, 0],
    'paid 8 days ago' => [8, 1, 0],
]);

it('reads what became of the learner orders of the window, with the reasons', function (): void {
    $learner = Learner::factory()->create();
    $order = static fn (array $state, string $locale, float $daysAgo) => PaymentOrder::factory()->create([
        'subscriber_id' => $learner->id, 'meta' => ['learner_locale' => $locale] + ($state['meta'] ?? []), 'created_at' => CarbonImmutable::now()->subDays((int) $daysAgo),
    ] + array_diff_key($state, ['meta' => true]));

    $order(['status' => 'paid'], 'ru', 2);
    $order(['status' => 'paid'], 'ru', 3);
    $order(['status' => 'paid'], 'uz', 20);
    $order(['status' => 'canceled', 'provider' => 'payme', 'currency' => 'UZS', 'meta' => ['canceled_reason' => 'offer_moved']], 'ru', 4);
    $order(['status' => 'failed', 'provider' => 'payme', 'currency' => 'UZS', 'meta' => ['checkout_error' => 'RuntimeException']], 'ru', 4);
    $order(['status' => 'expired'], 'ru', 5);
    $order(['status' => 'pending'], 'uz', 1);
    $order(['status' => 'canceled'], 'uz', 1);
    // Outside: older than the window; a center's order; an order without a locale is "unknown".
    $order(['status' => 'paid'], 'ru', 31);
    PaymentOrder::factory()->forOrganization()->create(['status' => 'failed']);
    PaymentOrder::factory()->create(['subscriber_id' => $learner->id, 'status' => 'expired', 'meta' => [], 'created_at' => CarbonImmutable::now()->subMinute()]);

    expect(app(BillingFunnelService::class)->compute($this->now)['orders'])->toBe([
        ['provider' => 'payme', 'locale' => 'ru', 'status' => 'canceled', 'reason' => 'offer_moved', 'orders' => 1, 'learners' => 1],
        ['provider' => 'payme', 'locale' => 'ru', 'status' => 'failed', 'reason' => 'checkout_error', 'orders' => 1, 'learners' => 1],
        ['provider' => 'telegram_stars', 'locale' => 'ru', 'status' => 'expired', 'reason' => null, 'orders' => 1, 'learners' => 1],
        ['provider' => 'telegram_stars', 'locale' => 'ru', 'status' => 'paid', 'reason' => null, 'orders' => 2, 'learners' => 1],
        ['provider' => 'telegram_stars', 'locale' => 'unknown', 'status' => 'expired', 'reason' => null, 'orders' => 1, 'learners' => 1],
        ['provider' => 'telegram_stars', 'locale' => 'uz', 'status' => 'canceled', 'reason' => 'unknown', 'orders' => 1, 'learners' => 1],
        ['provider' => 'telegram_stars', 'locale' => 'uz', 'status' => 'paid', 'reason' => null, 'orders' => 1, 'learners' => 1],
        ['provider' => 'telegram_stars', 'locale' => 'uz', 'status' => 'pending', 'reason' => null, 'orders' => 1, 'learners' => 1],
    ]);
});

it('takes the window from its argument: a shorter one leaves the older steps out', function (): void {
    funnelScenario($this->now);

    $cells = funnelCells(app(BillingFunnelService::class)->compute($this->now, 7));

    // In 7 days: viewed C, F, G, H (ru), D (uz), J; A's views are 10 and 8 days old, B's 20. Both payers (F, H) are maturing: nobody
    // is counted as studied yet, though F has.
    expect($cells['all:all'])->toBe(['viewed' => 6, 'started' => 3, 'paid' => 2, 'studied' => 0, 'maturing' => 2]);
});

it('answers an empty platform with one row of zeros', function (): void {
    $report = app(BillingFunnelService::class)->compute($this->now);

    expect($report['funnel'])->toBe([['provider' => 'all', 'locale' => 'all', 'viewed' => 0, 'started' => 0, 'paid' => 0, 'studied' => 0, 'maturing' => 0]])
        ->and($report['outside'])->toBe([])
        ->and($report['placements'])->toBe([])
        ->and($report['orders'])->toBe([]);
});

it('runs three statements whatever the volume', function (): void {
    funnelScenario($this->now);
    $small = QueryLog::during(fn () => app(BillingFunnelService::class)->compute($this->now));

    for ($i = 0; $i < 15; $i++) {
        funnelScenario($this->now);
    }
    $large = QueryLog::during(fn () => app(BillingFunnelService::class)->compute($this->now));

    expect($small)->toHaveCount(3)
        ->and($large)->toHaveCount(3)
        // The values of the filters are bindings: no event name, provider or date is in the SQL text.
        ->and(implode("\n", $large))->not->toContain('billing.offer_viewed', 'checkout.started', 'telegram_stars', '2026-');
});

it('keeps the report in the pure cache as an array of scalars, under a versioned key, until it is forgotten', function (): void {
    funnelScenario($this->now);
    $service = app(BillingFunnelService::class);

    $first = $service->report();
    $warm = QueryLog::during(fn () => $service->report());
    $key = WidgetCache::key(BillingFunnelService::CACHE_WIDGET, 'all', 'd30');

    expect($warm)->toBe([])
        ->and($key)->toBe('admin:widget:billing_funnel:v2:all:d30')
        ->and(PureCache::store()->get($key))->toBe($first);

    // A new learner pays: the cached figures stay until the TTL or a forget; then the next read sees it.
    funnelLearner('uz', $this->now->subHours(3), [['telegram_stars', $this->now->subHours(2), 'uz']], [['telegram_stars', $this->now->subHour(), 'uz', 'charge']]);
    expect(funnelCells($service->report())['all:uz']['paid'])->toBe(1);

    $service->forget();
    expect(funnelCells($service->report())['all:uz']['paid'])->toBe(2);
});

it('reads numbers that come back from the cache as strings, and recomputes what is not a report', function (): void {
    $key = WidgetCache::key(BillingFunnelService::CACHE_WIDGET, 'all', 'd30');
    PureCache::store()->put($key, [
        'generated_at' => '2026-11-19T12:00:00+00:00', 'days' => '30',
        'funnel' => [['provider' => 'all', 'locale' => 'all', 'viewed' => '4', 'started' => '2', 'paid' => '1', 'studied' => '1', 'maturing' => '0']],
        'outside' => [], 'placements' => [['placement' => 'paywall_quota', 'learners' => '3', 'views' => '5']], 'orders' => [],
    ], 600);
    // `Cache::flexible` keeps the moment of the write beside the value; without it the entry would be recomputed at once.
    PureCache::store()->put(Repository::FLEXIBLE_CREATED_KEY_PREFIX.$key, CarbonImmutable::now()->getTimestamp(), 600);

    $report = app(BillingFunnelService::class)->report();
    expect($report['days'])->toBe(30)
        ->and($report['funnel'][0]['viewed'])->toBe(4)
        ->and($report['placements'][0]['views'])->toBe(5);

    PureCache::store()->put($key, ['something' => 'else'], 600);
    PureCache::store()->put(Repository::FLEXIBLE_CREATED_KEY_PREFIX.$key, CarbonImmutable::now()->getTimestamp(), 600);
    expect(app(BillingFunnelService::class)->report()['funnel'][0]['viewed'])->toBe(0);
});
