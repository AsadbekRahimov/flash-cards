<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Learning\Services\ReviewReminderService;
use Illuminate\Console\Command;

final class NotifyDueRepetitions extends Command
{
    protected $signature = 'repetitions:notify {--threshold=5 : Minimum due cards required for a reminder}';

    protected $description = 'Queue Telegram reminders for students with due vocabulary reviews.';

    public function handle(ReviewReminderService $service): int
    {
        $threshold = max(1, (int) $this->option('threshold'));
        $queued = $service->queueDueReminders($threshold);

        $this->info("Queued {$queued} review reminder job(s).");

        return self::SUCCESS;
    }
}
