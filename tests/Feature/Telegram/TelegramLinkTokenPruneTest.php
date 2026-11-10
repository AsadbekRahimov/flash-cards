<?php

declare(strict_types=1);

use App\Models\TelegramLinkToken;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * One-time Telegram link tokens that were used or expired more than 30 days ago are deleted by the daily `model:prune`.
 */

uses(RefreshDatabase::class);

it('prunes tokens used or expired more than 30 days ago and keeps the live and the recent ones', function (): void {
    $usedOld = TelegramLinkToken::factory()->create(['used_at' => now()->subDays(31), 'expires_at' => now()->subDays(31)]);
    $expiredOld = TelegramLinkToken::factory()->create(['used_at' => null, 'expires_at' => now()->subDays(31)]);
    $usedRecent = TelegramLinkToken::factory()->create(['used_at' => now()->subDays(29), 'expires_at' => now()->subDays(29)]);
    $expiredRecent = TelegramLinkToken::factory()->create(['used_at' => null, 'expires_at' => now()->subDays(29)]);
    $live = TelegramLinkToken::factory()->create(['used_at' => null, 'expires_at' => now()->addMinutes(10)]);
    // Used long ago although it would have expired later: used counts.
    $usedLongAgoNotExpired = TelegramLinkToken::factory()->create(['used_at' => now()->subDays(40), 'expires_at' => now()->addDay()]);

    expect((new TelegramLinkToken)->prunable()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$usedOld, $expiredOld, $usedLongAgoNotExpired])->pluck('id')->sort()->values()->all());

    $this->artisan('model:prune', ['--model' => [TelegramLinkToken::class]])->assertSuccessful();

    expect(TelegramLinkToken::query()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$usedRecent, $expiredRecent, $live])->pluck('id')->sort()->values()->all());
});

it('prunes in the daily model:prune that already serves the logs', function (): void {
    $event = collect(app(Schedule::class)->events())->first(fn ($e): bool => str_contains((string) $e->command, 'model:prune'));

    expect($event)->not->toBeNull()->and((string) $event->command)->toContain(TelegramLinkToken::class);
});
