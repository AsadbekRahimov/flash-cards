<?php

declare(strict_types=1);

/*
 * achievements:prune-cards (docs/36 §2б): the cards unused for a month, the PNG cards of template 1 and the leftovers of a killed
 * render go; fresh cards and a render in progress stay. Weekly by the schedule.
 */

use App\Domain\Engagement\Enums\AchievementKey;
use App\Domain\Engagement\Services\AchievementCardRenderer;
use App\Models\Achievement;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
    config(['achievements.illustrations' => sys_get_temp_dir().'/lf-no-card-art']);
});

function pruneAged(string $relative, int $daysOld, string $contents = 'x'): string
{
    $disk = Storage::disk('public');
    $disk->put($relative, $contents);
    touch($disk->path($relative), now()->subDays($daysOld)->subMinute()->getTimestamp());
    clearstatcache();

    return $relative;
}

it('deletes old cards, old-template PNGs and stale temporary files, and keeps fresh ones', function (): void {
    $fresh = basename(app(AchievementCardRenderer::class)->render(Achievement::factory()->key(AchievementKey::Streak7)->create(), 'ru'));
    $recent = pruneAged('share-cards/words_100-ru-0123456789abcdef0123.jpg', 29);
    $old = pruneAged('share-cards/words_100-uz-0123456789abcdef0123.jpg', 31);
    $staleTmp = pruneAged('share-cards/streak_7-ru-0123456789abcdef0123.jpg.a1b2c3.tmp', 2);
    $rendering = pruneAged('share-cards/streak_7-uz-0123456789abcdef0123.jpg.d4e5f6.tmp', 0);
    $legacyOld = pruneAged('achievements/streak_7-ru-0123456789ab.png', 2);
    $legacyJustNow = pruneAged('achievements/streak_7-uz-0123456789ab.png', 0);
    $elsewhere = pruneAged('audio/0123456789abcdef.mp3', 400);

    $this->artisan('achievements:prune-cards')
        ->expectsOutputToContain('deleted 3, kept 4, failed 0 (unused for more than 30 days)')
        ->assertSuccessful();

    $disk = Storage::disk('public');
    expect($disk->exists("share-cards/{$fresh}"))->toBeTrue()
        ->and($disk->exists($recent))->toBeTrue()
        ->and($disk->exists($rendering))->toBeTrue()
        ->and($disk->exists($legacyJustNow))->toBeTrue()
        ->and($disk->exists($elsewhere))->toBeTrue()
        ->and($disk->exists($old))->toBeFalse()
        ->and($disk->exists($staleTmp))->toBeFalse()
        ->and($disk->exists($legacyOld))->toBeFalse();
});

it('takes --days, refuses a bad one, and draws a pruned card again on demand', function (): void {
    $renderer = app(AchievementCardRenderer::class);
    $achievement = Achievement::factory()->key(AchievementKey::Streak7)->create();
    $path = $renderer->render($achievement, 'ru');
    touch($path, now()->subDays(10)->getTimestamp());
    clearstatcache();

    $this->artisan('achievements:prune-cards', ['--days' => 'x'])->assertExitCode(2);
    $this->artisan('achievements:prune-cards', ['--days' => '0'])->assertExitCode(2);
    $this->artisan('achievements:prune-cards', ['--days' => '30'])->expectsOutputToContain('deleted 0')->assertSuccessful();
    expect(is_file($path))->toBeTrue();

    $this->artisan('achievements:prune-cards', ['--days' => '7'])->expectsOutputToContain('deleted 1')->assertSuccessful();
    expect(is_file($path))->toBeFalse()
        ->and($renderer->render($achievement, 'ru'))->toBe($path)
        ->and(is_file($path))->toBeTrue();
});

it('runs on an empty disk', function (): void {
    $this->artisan('achievements:prune-cards')->expectsOutputToContain('deleted 0, kept 0, failed 0')->assertSuccessful();
});

it('is scheduled weekly without overlapping', function (): void {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'achievements:prune-cards'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('50 4 * * 1')
        ->and($event->withoutOverlapping)->toBeTrue();
});
