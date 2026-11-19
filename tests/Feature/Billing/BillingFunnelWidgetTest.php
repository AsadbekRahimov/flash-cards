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
        // The three statements of the report, whatever the volume.
        ->and(QueryLog::matching($small, '/product_events|payment_orders/'))->toHaveCount(3)
        ->and(QueryLog::matching($warm, '/product_events|payment_orders/'))->toBe([]);
});

it('is for active admins only', function (): void {
    $teacher = User::factory()->create(['is_active' => true]);
    $teacher->assignRole('teacher');
    Auth::login($teacher);

    expect(BillingFunnelWidget::canView())->toBeFalse();
});
