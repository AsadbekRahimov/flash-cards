<?php

declare(strict_types=1);

namespace App\Filament\Org\Pages;

use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Billing\Support\OrgTiers;
use App\Domain\Billing\Support\PriceFormatter;
use App\Domain\Organizations\DTO\OrgBillingPage;
use App\Domain\Organizations\Exceptions\OrgBillingException;
use App\Domain\Organizations\Services\OrgBillingService;
use App\Domain\Organizations\Services\OrgTenantScope;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentOrder;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * Billing of the center (docs/13 §6.5) — owner only: the subscription and the seats (with the
 * excess after a downgrade), the choice of tariff and period, Payme / Click buttons, "request an
 * invoice" and the history of orders and payments. Thin: everything is {@see OrgBillingService}.
 */
class Billing extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-credit-card';

    protected static ?int $navigationSort = 70;

    protected string $view = 'filament.org.pages.billing';

    public string $tier = '';

    public string $period = 'month';

    public string $comment = '';

    private const HISTORY_LIMIT = 20;

    public static function getNavigationLabel(): string
    {
        return __('org.nav.billing');
    }

    public function getTitle(): string
    {
        return __('org.nav.billing');
    }

    /** Owner of the current tenant only — also enforced in the Livewire `mount` by Filament (`canAccess`). */
    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();
        $tenant = Filament::getTenant();

        return $user instanceof User
            && $tenant instanceof Organization
            && app(OrgTenantScope::class)->isOwner($user, $tenant);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $page = $this->data();

        $purchasable = array_values(array_filter($page->tiers, static fn (array $t): bool => $t['purchasable']));
        $current = $page->tier;

        $keys = array_column($page->tiers, 'key');

        $this->tier = $current !== null && in_array($current, $keys, true)
            ? $current
            : (string) ($purchasable[0]['key'] ?? ($keys[0] ?? ''));
    }

    public function data(): OrgBillingPage
    {
        try {
            return app(OrgBillingService::class)->page($this->user(), $this->organization());
        } catch (OrgBillingException) {
            abort(403);
        }
    }

    /** @return array{orders: Collection<int, PaymentOrder>, payments: Collection<int, Payment>} */
    public function history(): array
    {
        try {
            return app(OrgBillingService::class)->history($this->user(), $this->organization(), self::HISTORY_LIMIT);
        } catch (OrgBillingException) {
            abort(403);
        }
    }

    /** Price of the selected tier / period in minor units, null when the tier is not sold for it. */
    public function selectedPrice(OrgBillingPage $page): ?int
    {
        foreach ($page->tiers as $tier) {
            if ($tier['key'] === $this->tier) {
                return $tier['prices'][$this->period] ?? null;
            }
        }

        return null;
    }

    /** What the selected year saves versus 12 months of the tier (minor units); null for a month or when nothing is saved. */
    public function selectedSavings(): ?int
    {
        if ($this->period !== 'year') {
            return null;
        }

        $savings = OrgTiers::yearSavings($this->tier, OrgBillingService::CURRENCY);

        return $savings > 0 ? $savings : null;
    }

    public function priceLabel(int $minorUnits, string $currency = 'UZS'): string
    {
        return PriceFormatter::format($minorUnits, $currency, app()->getLocale());
    }

    public function invoiceUrl(PaymentOrder $order): string
    {
        return route('filament.org.invoice', ['tenant' => $this->organization()->slug, 'order' => $order->id]);
    }

    /** "Pay with Payme / Click": opens an order and sends the owner to the provider's checkout page. */
    public function pay(string $provider): void
    {
        $key = SubscriptionProvider::tryFrom($provider);

        if ($key === null) {
            $this->fail('provider_not_supported');

            return;
        }

        try {
            $order = app(OrgBillingService::class)->checkout($this->user(), $this->organization(), $this->tier, $this->period, $key);
        } catch (BillingException|OrgBillingException $e) {
            $this->fail($e->errorCode);

            return;
        }

        $this->redirect((string) $order->provider_ref);
    }

    public function requestInvoice(): void
    {
        try {
            app(OrgBillingService::class)->requestInvoice($this->user(), $this->organization(), $this->tier, $this->period, $this->comment, request()->ip());
        } catch (BillingException|OrgBillingException $e) {
            $this->fail($e->errorCode);

            return;
        }

        $this->comment = '';
        Notification::make()->title(__('org.billing.notifications.invoice_requested'))->success()->send();
    }

    private function fail(string $code): void
    {
        $key = "org.billing.errors.{$code}";

        Notification::make()->title(__(trans()->has($key) ? $key : 'org.billing.errors.unknown'))->danger()->send();
    }

    private function user(): User
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function organization(): Organization
    {
        $tenant = Filament::getTenant();
        abort_unless($tenant instanceof Organization, 404);

        return $tenant;
    }
}
