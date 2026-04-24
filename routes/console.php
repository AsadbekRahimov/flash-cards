<?php

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

// Daily DB + storage backup via spatie/laravel-backup.
Schedule::command('backup:clean')->daily()->at('01:00');
Schedule::command('backup:run')->daily()->at('01:30');
Schedule::command('backup:monitor')->daily()->at('02:00');
