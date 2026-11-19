<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Billing\Services\BillingFunnelService;
use App\Filament\Concerns\RequiresActiveAdmin;
use Filament\Widgets\Widget;

/**
 * "Воронка Pro — 30 дней" (docs/13 §5.1, audit 2026-11-18 §4), next to the money widgets of the dashboard: offer shown → checkout
 * started → payment succeeded → studied within 7 days, by provider and locale, with the numerator and the denominator of every step;
 * the starts and payments outside the chain; the views by placement; the outcomes of the orders. Admin only: platform-wide numbers.
 *
 * The figures are the cached array of {@see BillingFunnelService} (`admin:widget:billing_funnel:v2:all:d30`, fresh 10 minutes, then
 * served stale while it is recomputed): an open dashboard costs no query of the report; lazy, no polling (docs/20 §1.11).
 *
 * @phpstan-import-type FunnelReport from BillingFunnelService
 */
class BillingFunnelWidget extends Widget
{
    use RequiresActiveAdmin;

    protected static ?int $sort = 10;

    protected string $view = 'filament.widgets.billing-funnel';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return self::isActiveAdmin();
    }

    /** @return array{report: FunnelReport} */
    protected function getViewData(): array
    {
        return ['report' => app(BillingFunnelService::class)->report()];
    }
}
