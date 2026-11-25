<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Billing\Services\TrialOfferSender;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * `billing:trial-offers` (docs/34 §2д): queues the offers after the welcome trial whose step is due and whose learner's clock is in the
 * day window. Hourly, `withoutOverlapping`; a repeated run is harmless - the marker of each step is written by the sender.
 */
final class QueueTrialOffers extends Command
{
    /** @var string */
    protected $signature = 'billing:trial-offers';

    /** @var string */
    protected $description = 'Queue the bot offers of Pro on the day the welcome trial ended and a few days after it.';

    public function handle(TrialOfferSender $offers): int
    {
        $queued = $offers->queueDue(CarbonImmutable::now());

        $this->info("Trial offers queued: {$queued}");

        return self::SUCCESS;
    }
}
