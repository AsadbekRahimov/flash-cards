<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Administration\Services\DashboardOverview;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Computes the open speed of the Mini App for the dashboard of `/admin` («Время до первой кнопки», {@see DashboardOverview::warmOpenSpeed()})
 * off the request of the widget: pushed by the schedule every 30 minutes (routes/console.php) and by the widget when it finds no
 * entry. Unique, so an empty cache and many opens queue one job; a lost run is harmless — the next one, or the next open, writes it.
 */
final class WarmOpenSpeedJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function __construct()
    {
        $this->onQueue('low');
    }

    public function handle(DashboardOverview $overview): void
    {
        $overview->warmOpenSpeed();
    }
}
