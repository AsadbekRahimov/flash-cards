<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Close exams whose `ends_at` passed more than a minute ago and post the
// group leaderboard. See CloseExpiredExams for idempotency guarantees.
Schedule::command('exams:close-expired')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
