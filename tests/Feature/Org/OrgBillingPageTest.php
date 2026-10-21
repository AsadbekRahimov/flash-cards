<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Services\OrgBillingService;
use App\Filament\Org\Pages\Billing;
use App\Filament\Org\Widgets\OrgSeatsWidget;
use App\Mail\OrgInvoiceRequestMail;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\PaymentOrder;
use App\Models\Subscription;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\Support\FakeBillingProvider;

/*
 * The Billing page and the invoice of the `/org` cabinet (docs/13 §6.5, DoD 2B.6): owner only,
 * the choice of tariff / period, Payme / Click buttons only for enabled providers, "request an
 * invoice", the history, the printable invoice; another center is 404, a teacher is 403.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->payme = FakeBillingProvider::register(SubscriptionProvider::Payme);
    $this->org = Organization::factory()->create(['name' => 'Bright Center', 'slug' => 'bright', 'locale' => 'ru']);
    $this->other = Organization::factory()->create(['slug' => 'other']);
    $this->owner = pbStaff($this->org, OrganizationRole::Owner);
    $this->teacher = pbStaff($this->org, OrganizationRole::Teacher);
    $this->otherOwner = pbStaff($this->other, OrganizationRole::Owner);
});

function pbStaff(Organization $organization, OrganizationRole $role): User
{
    $user = User::factory()->create([
        'is_active' => true,
        'two_factor_secret' => $role === OrganizationRole::Owner ? 'stub' : null,
        'two_factor_recovery_codes' => $role === OrganizationRole::Owner ? [] : null,
        'two_factor_confirmed_at' => $role === OrganizationRole::Owner ? now() : null,
    ]);
    OrganizationUser::factory()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role->value]);

    return $user;
}

/** An HTTP request as this staff user (guard `org`, 2FA passed). */
function pbAs(User $user): void
{
    test()->actingAs($user, 'org')->withSession(['2fa.passed_at' => now()->timestamp, '2fa.user_id' => $user->id]);
}

/** Puts a Livewire test into the cabinet of $tenant as $user, as the panel middleware would. */
function pbEnter(User $user, Organization $tenant): void
{
    Filament::setCurrentPanel(Filament::getPanel('org'));
    Filament::bootCurrentPanel();
    test()->actingAs($user, 'org');
    Session::put('2fa.passed_at', now()->timestamp);
    Session::put('2fa.user_id', $user->id);
    Filament::setTenant($tenant, isQuiet: true);
}

// --- access ---------------------------------------------------------------------------------------

it('shows the owner the billing page with the tariffs, prices and the pay buttons of the enabled providers', function (): void {
    pbAs($this->owner);

    $this->get('/org/bright/billing')->assertOk()
        ->assertSee('Тариф и оплата')
        ->assertSee('349 000 сум', false)
        ->assertSee('Оплатить через Payme')
        ->assertDontSee('Оплатить через Click')
        ->assertSee('по договорённости');
});

it('hides the pay buttons of a provider that is switched off', function (): void {
    config()->set('billing.providers.payme.enabled', false);
    pbAs($this->owner);

    $this->get('/org/bright/billing')->assertOk()
        ->assertDontSee('Оплатить через Payme')
        ->assertSee('Онлайн-оплата сейчас недоступна');
});

it('forbids a teacher and answers 404 for another center\'s billing', function (): void {
    pbAs($this->teacher);
    $this->get('/org/bright/billing')->assertForbidden();
    $this->get('/org/other/billing')->assertNotFound();

    pbAs($this->owner);
    $this->get('/org/other/billing')->assertNotFound();
});

it('does not even mount the Livewire page for a teacher', function (): void {
    pbEnter($this->teacher, $this->org);

    Livewire::test(Billing::class)->assertForbidden();
});

// --- the page -------------------------------------------------------------------------------------

it('creates an Payme order for the selected tariff and sends the owner to the checkout link', function (): void {
    pbEnter($this->owner, $this->org);

    $component = Livewire::test(Billing::class)
        ->set('tier', 'center_m')
        ->set('period', 'month')
        ->call('pay', 'payme');

    $order = PaymentOrder::query()->sole();
    $component->assertRedirect("https://pay.test/{$order->token}");
    expect($order->subscriber_type)->toBe('organization')
        ->and($order->subscriber_id)->toBe($this->org->id)
        ->and($order->tier)->toBe('center_m')
        ->and($order->amount)->toBe(84_900_000)
        ->and($order->created_via)->toBe('org_panel');
});

it('refuses Stars, a tier without a price and a tampered provider on the page, creating no order', function (): void {
    FakeBillingProvider::register(SubscriptionProvider::TelegramStars);
    pbEnter($this->owner, $this->org);

    Livewire::test(Billing::class)
        ->set('tier', 'tutor')
        ->call('pay', 'telegram_stars')
        ->assertNotified(__('org.billing.errors.provider_not_supported'))
        ->call('pay', 'bitcoin')
        ->assertNotified(__('org.billing.errors.provider_not_supported'))
        ->set('tier', 'center_l')
        ->call('pay', 'payme')
        ->assertNotified(__('org.billing.errors.tier_not_purchasable'))
        ->set('tier', 'gold')
        ->call('pay', 'payme')
        ->assertNotified(__('org.billing.errors.invalid_tier'));

    expect(PaymentOrder::query()->count())->toBe(0);
});

it('does not offer a payment for center_l: only the invoice request', function (): void {
    pbEnter($this->owner, $this->org);

    Livewire::test(Billing::class)
        ->set('tier', 'center_l')
        ->assertSee('Этот тариф или срок нельзя оплатить онлайн')
        ->assertDontSee('Оплатить через Payme');
});

it('requests an invoice by e-mail from the page', function (): void {
    Mail::fake();
    config()->set('billing.invoice.request_email', 'sales@lexiflow.test');
    pbEnter($this->owner, $this->org);

    Livewire::test(Billing::class)
        ->set('tier', 'center_s')
        ->set('comment', 'Please send a signed copy')
        ->call('requestInvoice')
        ->assertNotified(__('org.billing.notifications.invoice_requested'))
        ->assertSet('comment', '');

    Mail::assertSent(OrgInvoiceRequestMail::class, fn (OrgInvoiceRequestMail $m): bool => $m->hasTo('sales@lexiflow.test') && $m->tier === 'center_s' && $m->comment === 'Please send a signed copy');

    // Sent again at once: throttled, no second mail.
    Livewire::test(Billing::class)
        ->set('tier', 'center_s')
        ->call('requestInvoice')
        ->assertNotified(__('org.billing.errors.invoice_request_throttled'));
    Mail::assertSentCount(1);
});

it('hides the invoice request when no address is configured', function (): void {
    config()->set('billing.invoice.request_email', '');
    config()->set('site.legal.email', '');
    pbEnter($this->owner, $this->org);

    Livewire::test(Billing::class)->assertDontSee('Запросить счёт');
});

it('shows the excess over the limit after a downgrade, and the history of the center only', function (): void {
    Subscription::factory()->forOrganization($this->org)->create(['provider' => 'payme', 'tier' => 'tutor', 'seats_limit' => 2, 'current_period_ends_at' => now()->addDays(10)]);
    Learner::factory()->count(5)->seatedIn($this->org)->create();
    $mine = PaymentOrder::factory()->forOrganization($this->org, 'center_s')->create();
    $foreign = PaymentOrder::factory()->forOrganization($this->other, 'center_m')->create();
    pbEnter($this->owner, $this->org);

    Livewire::test(Billing::class)
        ->assertSet('tier', 'tutor')
        ->assertSee('Превышение лимита: занято 5 из 2 мест')
        ->assertSee(sprintf('LF-%06d', $mine->id))
        ->assertDontSee(sprintf('LF-%06d', $foreign->id));

    // The dashboard widget shows the same excess.
    Livewire::test(OrgSeatsWidget::class)->assertSee('5 / 2')->assertSee(__('org.dashboard.seats_over_limit'));
});

// --- invoice --------------------------------------------------------------------------------------

it('renders the printable invoice with the seller details, the number, the date and the amount in sums', function (): void {
    config()->set('site.legal', ['name' => 'LexiFlow LLC', 'inn' => '305123456', 'address' => 'Tashkent, Amir Temur 1', 'bank' => 'Test Bank', 'bank_account' => '20208000900100200300', 'mfo' => '00014', 'phone' => '', 'email' => 'billing@lexiflow.test']);
    $order = PaymentOrder::factory()->forOrganization($this->org, 'center_s')->create(['amount' => 34_900_000, 'list_amount' => 34_900_000]);
    pbAs($this->owner);

    $this->get("/org/bright/invoice/{$order->id}")->assertOk()
        ->assertSee(sprintf('LF-%06d', $order->id))
        // The invoice date is in the organization's timezone (late UTC evening is already tomorrow in Tashkent).
        ->assertSee($order->created_at?->copy()->setTimezone($this->org->timezone)->format('d.m.Y') ?? '')
        ->assertSee('349 000 сум')
        ->assertSee('LexiFlow LLC')
        ->assertSee('305123456')
        ->assertSee('20208000900100200300')
        ->assertSee('Bright Center')
        ->assertSee('Центр S')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('X-Robots-Tag', 'noindex');
});

it('answers 404 for another center\'s order, a learner\'s order and a missing one on the invoice route', function (): void {
    $foreign = PaymentOrder::factory()->forOrganization($this->other, 'tutor')->create();
    $learnerOrder = PaymentOrder::factory()->create();
    pbAs($this->owner);

    foreach ([$foreign->id, $learnerOrder->id, 999_999] as $id) {
        $this->get("/org/bright/invoice/{$id}")->assertNotFound();
    }
});

it('keeps the invoice from a teacher', function (): void {
    $order = PaymentOrder::factory()->forOrganization($this->org, 'tutor')->create();
    pbAs($this->teacher);

    $this->get("/org/bright/invoice/{$order->id}")->assertForbidden();
});

it('answers 404 on the invoice to the owner of ANOTHER center: neither the slug nor the order is theirs', function (): void {
    $order = PaymentOrder::factory()->forOrganization($this->org, 'tutor')->create();
    pbAs($this->otherOwner);

    $this->get("/org/bright/invoice/{$order->id}")->assertNotFound();
});

it('sends a guest from the invoice to the cabinet login', function (): void {
    $order = PaymentOrder::factory()->forOrganization($this->org, 'tutor')->create();

    $this->get("/org/bright/invoice/{$order->id}")->assertRedirect('/org/login');
});

// --- tier change is manual while the subscription is live (2B.8) -----------------------------------

it('disables the pay buttons for another tier while a subscription is live, keeps the current tier renewable', function (): void {
    Subscription::factory()->forOrganization($this->org)->create(['provider' => 'payme', 'tier' => 'tutor', 'seats_limit' => 2, 'current_period_ends_at' => now()->addDays(10)]);
    pbEnter($this->owner, $this->org);

    $tiers = collect(app(OrgBillingService::class)->page($this->owner, $this->org)->tiers)->keyBy('key');
    expect($tiers['tutor']['online_allowed'])->toBeTrue()
        ->and($tiers['center_m']['online_allowed'])->toBeFalse()
        ->and($tiers['center_l']['online_allowed'])->toBeFalse();

    Livewire::test(Billing::class)
        ->assertSet('tier', 'tutor')
        ->assertDontSeeHtml('data-tier-change-requires-support')
        ->assertDontSeeHtml('disabled="disabled"')
        ->set('tier', 'center_m')
        ->assertSeeHtml('data-tier-change-requires-support')
        ->assertSee(__('org.billing.errors.tier_change_requires_support'))
        ->assertSeeHtml('disabled')
        ->call('pay', 'payme')
        ->assertNotified(__('org.billing.errors.tier_change_requires_support'));

    expect(PaymentOrder::query()->count())->toBe(0);
});

it('offers every priced tier when there is no live subscription', function (): void {
    pbEnter($this->owner, $this->org);

    $tiers = collect(app(OrgBillingService::class)->page($this->owner, $this->org)->tiers)->keyBy('key');
    expect($tiers['tutor']['online_allowed'])->toBeTrue()
        ->and($tiers['center_m']['online_allowed'])->toBeTrue()
        ->and($tiers['center_l']['online_allowed'])->toBeFalse();

    Livewire::test(Billing::class)->set('tier', 'center_m')->assertDontSeeHtml('data-tier-change-requires-support');
});

// --- year (docs/13 "Реализация 2B.9") ---------------------------------------------------------------

it('offers the year next to the month with its price in the tariff list', function (): void {
    config()->set('billing.org_year_discount_percent', 20);
    pbAs($this->owner);

    $this->get('/org/bright/billing')->assertOk()
        ->assertSee('1 год')
        ->assertSee('3 350 400 сум / 1 год', false);
});

it('shows the price of the year and the saving versus twelve months once the year is selected', function (): void {
    config()->set('billing.org_year_discount_percent', 20);
    pbEnter($this->owner, $this->org);

    Livewire::test(Billing::class)
        ->set('tier', 'center_s')
        ->set('period', 'month')
        ->assertDontSee('Экономия по сравнению')
        ->set('period', 'year')
        ->assertSee('К оплате: 3 350 400 сум')
        ->assertSee('Экономия по сравнению с 12 месяцами: 837 600 сум');
});

it('shows no saving line while the year has no discount', function (): void {
    pbEnter($this->owner, $this->org);

    Livewire::test(Billing::class)->set('tier', 'center_s')->set('period', 'year')
        ->assertSee('К оплате: 4 188 000 сум')
        ->assertDontSee('Экономия по сравнению');
});

it('opens a year order through Payme from the page', function (): void {
    config()->set('billing.org_year_discount_percent', 20);
    pbEnter($this->owner, $this->org);

    $component = Livewire::test(Billing::class)->set('tier', 'center_s')->set('period', 'year')->call('pay', 'payme');

    $order = PaymentOrder::query()->sole();
    $component->assertRedirect("https://pay.test/{$order->token}");
    expect($order->period)->toBe('year')->and($order->amount)->toBe(335_040_000);
});

it('prints the period of the order on the invoice', function (): void {
    $order = PaymentOrder::factory()->forOrganization($this->org, 'center_s')->create(['period' => 'year', 'amount' => 418_800_000, 'list_amount' => 418_800_000]);
    pbAs($this->owner);

    $this->get("/org/bright/invoice/{$order->id}")->assertOk()->assertSee('срок: 1 год (365 дн.)');
});
