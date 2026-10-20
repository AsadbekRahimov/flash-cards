<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Billing\Services\CheckoutService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Pending payment orders past `expires_at` become `expired` (docs/13 §4.4). Scheduled every 15
 * minutes; a single UPDATE, so a repeated or overlapping run is harmless.
 */
final class ExpireOrders extends Command
{
    /** @var string */
    protected $signature = 'billing:expire-orders';

    /** @var string */
    protected $description = 'Mark pending payment orders whose TTL ended as expired.';

    public function handle(CheckoutService $checkout): int
    {
        $expired = $checkout->expirePending(CarbonImmutable::now());

        $this->info("Orders expired: {$expired}");

        return self::SUCCESS;
    }
}
