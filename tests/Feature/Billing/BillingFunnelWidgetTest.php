<?php

declare(strict_types=1);

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Billing\Services\BillingFunnelService;
use App\Filament\Widgets\BillingFunnelWidget;
use App\Models\Learner;
use App\Models\PaymentOrder;
use App\Models\ProductEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\Support\QueryLog;

/*
 * "Воронка Pro — 30 дней" on the dashboard of /admin (docs/13 §5.1, docs/20 §1.11, §1.13): numerator and denominator of every step,
 * a constant number of queries whatever the volume, none at all on the second open (the pure cache), admins only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-19 12:00:00', 'UTC'));
    app(RolePermissionSeeder::class)->run();

    $this->admin = User::factory()->create(['is_active' => true, 'two_factor_secret' => 'stub', 'two_factor_recovery_codes' => [], 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('admin');
    Auth::login($this->admin);
    Session::put('2fa.web.passed_at', now()->timestamp);
    Session::put('2fa.web.user_id', $this->admin->id);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/** `$learners` learners who saw the offer; every second one started a Stars checkout, every fourth paid and studied the next day. */
function widgetFunnel(int $learners, string $locale = 'ru'): void
{
    $at = CarbonImmutable::now()->subDays(10);

    for ($i = 0; $i < $learners; $i++) {
        $learner = Learner::factory()->create(['interface_locale' => $locale]);
        $event = static fn (ProductEventName $name, int $minutes, array $props) => ProductEvent::query()->create([
            'name' => $name->value, 'learner_id' => $learner->id, 'props' => $props, 'occurred_at' => $at->addMinutes($minutes),
        ]);

        $event(ProductEventName::BillingOfferViewed, 0, ['placement' => 'paywall_quota', 'locale' => $locale]);
        if ($i % 2 === 0) {
            $event(ProductEventName::CheckoutStarted, 1, ['provider' => 'telegram_stars', 'locale' => $locale]);
            PaymentOrder::factory()->create(['subscriber_id' => $learner->id, 'status' => $i % 4 === 0 ? 'paid' : 'expired', 'meta' => ['learner_locale' => $locale], 'created_at' => $at]);
        }
        if ($i % 4 === 0) {
            $event(ProductEventName::PaymentSucceeded, 2, ['provider' => 'telegram_stars', 'locale' => $locale, 'kind' => 'charge']);
            $event(ProductEventName::TrainingSessionOpened, 1440, ['mode' => 'daily_review']);
        }
    }
}

it('shows each step with its numerator and denominator, the placements and the order outcomes', function (): void {
    widgetFunnel(8);
    widgetFunnel(2, 'uz');

    Livewire::test(BillingFunnelWidget::class)
        ->assertOk()
        ->assertSee('Воронка Pro — 30 дней')
        ->assertSeeInOrder(['Все провайдеры', 'Все языки', '10', '5 / 10 · 50 %', '3 / 5 · 60 %', '3 / 3 · 100 %', '5', '2'])
        ->assertSeeInOrder(['Русский', '8', '4 / 8 · 50 %', '2 / 4 · 50 %', '2 / 2 · 100 %'])
        ->assertSeeInOrder(['Узбекский', '2', '1 / 2 · 50 %', '1 / 1 · 100 %'])
        ->assertSee('Telegram Stars')
        ->assertSee('Лист Pro: кончились новые слова')
        ->assertSeeInOrder(['Заказы учеников за период', 'Telegram Stars', 'Русский', 'истёк', '—', '2', '2'])
        ->assertDontSeeHtml('wire:poll');
});

it('shows the share studied over the payers whose 7 days are over, with the maturing ones beside it', function (): void {
    widgetFunnel(4); // i = 0 paid ten days ago and studied: matured
    // Paid yesterday and studied already: maturing, not in the share yet.
    $young = Learner::factory()->create(['interface_locale' => 'ru']);
    $at = CarbonImmutable::now()->subDay();
    foreach ([
        [ProductEventName::BillingOfferViewed, 0, ['placement' => 'paywall_quota', 'locale' => 'ru']],
        [ProductEventName::CheckoutStarted, 1, ['provider' => 'telegram_stars', 'locale' => 'ru']],
        [ProductEventName::PaymentSucceeded, 2, ['provider' => 'telegram_stars', 'locale' => 'ru', 'kind' => 'charge']],
        [ProductEventName::TrainingSessionOpened, 60, ['mode' => 'daily_review']],
    ] as [$name, $minutes, $props]) {
        ProductEvent::query()->create(['name' => $name->value, 'learner_id' => $young->id, 'props' => $props, 'occurred_at' => $at->addMinutes($minutes)]);
    }

    // All providers: viewed 5, started 3, paid 2 (one maturing), studied 1 of the 1 matured; lost before the checkout 2, during 1.
    Livewire::test(BillingFunnelWidget::class)
        ->assertOk()
        ->assertSeeInOrder(['Все провайдеры', 'Все языки', '5', '3 / 5 · 60 %', '2 / 3 · 66,7 %', '1 / 1 · 100 %', 'ещё идут 7 дней: 1', '2', '1'])
        ->assertDontSee('1 / 2 · 50 %');
});

it('shows the loss before the checkout on the rows over all providers only', function (): void {
    widgetFunnel(4);

    $html = Livewire::test(BillingFunnelWidget::class)->assertOk()->html();

    // A provider row: viewed, started, paid, studied, a dash for "lost before", then "lost during".
    expect($html)->toMatch('/data-row="telegram_stars:all".*?<td class="lm-num">\s*—\s*<\/td>\s*<td class="lm-num">1<\/td>/s')
        ->and($html)->toMatch('/data-row="all:all".*?<td class="lm-num">2<\/td>\s*<td class="lm-num">1<\/td>/s');
});

it('shows the offers after the trial per step, with the payments after each and the opt-outs', function (): void {
    $at = CarbonImmutable::now()->subDays(5);
    foreach ([0, 0, 3] as $i => $step) {
        $learner = Learner::factory()->create();
        ProductEvent::query()->create(['name' => 'trial_offer.sent', 'learner_id' => $learner->id, 'props' => ['step' => $step], 'occurred_at' => $at]);
        if ($i === 0) {
            ProductEvent::query()->create(['name' => 'payment.succeeded', 'learner_id' => $learner->id, 'props' => ['provider' => 'telegram_stars', 'kind' => 'charge'], 'occurred_at' => $at->addHour()]);
        }
        if ($i === 2) {
            ProductEvent::query()->create(['name' => 'trial_offer.opted_out', 'learner_id' => $learner->id, 'props' => [], 'occurred_at' => $at->addHour()]);
        }
    }

    Livewire::test(BillingFunnelWidget::class)
        ->assertOk()
        ->assertSee('Предложения после пробного Pro')
        ->assertSee('Отказались от предложений: 1.')
        ->assertSeeInOrder(['в день окончания', '2', '1 / 2 · 50 %', 'через 3 дня', '1', '0 / 1 · 0 %']);
});

it('says so when there is nothing yet', function (): void {
    Livewire::test(BillingFunnelWidget::class)
        ->assertOk()
        ->assertSee('За период нет ни показов предложения, ни заказов.');
});

it('costs the same number of queries for 4 and for 40 learners, and none on the second open', function (): void {
    widgetFunnel(4);
    // A first open loads what the guard remembers for the request (the admin's roles); the report itself is computed anew below.
    Livewire::test(BillingFunnelWidget::class)->assertOk();
    app(BillingFunnelService::class)->forget();
    $small = QueryLog::during(fn () => Livewire::test(BillingFunnelWidget::class)->assertOk());
    $warm = QueryLog::during(fn () => Livewire::test(BillingFunnelWidget::class)->assertOk());

    app(BillingFunnelService::class)->forget();
    widgetFunnel(36);
    $large = QueryLog::during(fn () => Livewire::test(BillingFunnelWidget::class)->assertOk());

    expect(count($large))->toBe(count($small))
        // The five statements of the report, whatever the volume.
        ->and(QueryLog::matching($small, '/product_events|payment_orders/'))->toHaveCount(5)
        ->and(QueryLog::matching($warm, '/product_events|payment_orders/'))->toBe([]);
});

it('is for active admins only', function (): void {
    $teacher = User::factory()->create(['is_active' => true]);
    $teacher->assignRole('teacher');
    Auth::login($teacher);

    expect(BillingFunnelWidget::canView())->toBeFalse();
});
