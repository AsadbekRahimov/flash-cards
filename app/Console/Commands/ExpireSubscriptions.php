<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Billing\Services\SubscriptionService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Applies time-based subscription transitions (docs/12 §5.6):
 * period ended -> past_due (grace, auto-renewing providers) or expired;
 * grace over -> expired. Scheduled hourly with `withoutOverlapping()`;
 * safe to run concurrently anyway (each row re-checked under lockForUpdate).
 */
final class ExpireSubscriptions extends Command
{
    /** @var string */
    protected $signature = 'subscriptions:expire';

    /** @var string */
    protected $description = 'Move subscriptions whose period or grace ended to past_due / expired.';

    public function handle(SubscriptionService $subscriptions): int
    {
        $changed = $subscriptions->expireDue(CarbonImmutable::now());

        $this->info("Subscriptions transitioned: {$changed}");

        return self::SUCCESS;
    }
}
