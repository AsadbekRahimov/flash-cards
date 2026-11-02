<?php

declare(strict_types=1);

namespace App\Observers;

use App\Domain\Administration\Services\WidgetCache;
use App\Models\Payment;
use App\Models\ProviderTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The money widgets of the admin dashboard (revenue, webhook errors) are cached for minutes (docs/19 K1), but money must not
 * lag: a payment, a renewal, a refund or a provider's cancel drops them after the commit that wrote it, so the next open
 * of the dashboard recomputes and shows the figure that includes it. Whatever path writes the ledger (the applier, the refund
 * service, a webhook) goes through Eloquent, so the model events are the one place to hook; a query-builder update would
 * bypass them. Registered in AppServiceProvider for {@see Payment} and {@see ProviderTransaction}.
 */
final class MoneyWidgetsObserver
{
    public function __construct(private readonly WidgetCache $widgets) {}

    public function saved(Model $model): void
    {
        $this->forgetAfterCommit();
    }

    public function deleted(Model $model): void
    {
        $this->forgetAfterCommit();
    }

    /**
     * After the commit, and never a reason to fail it: the money is already written, a cache that cannot be reached is
     * reported and the widget goes stale for at most its freshness window.
     */
    private function forgetAfterCommit(): void
    {
        DB::afterCommit(fn () => rescue(fn () => $this->widgets->forgetMoney()));
    }
}
