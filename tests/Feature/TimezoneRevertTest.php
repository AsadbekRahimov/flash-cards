<?php

declare(strict_types=1);

/*
 * Task 2R.7 (docs/13 §9): taking back a mistaken time-zone change within 10 minutes —
 * LearnerSettingsService::revertTimezone(), POST /api/twa/me/settings/timezone/revert and the bot's `set1:tzundo`.
 */

use App\Domain\Billing\Services\NewWordQuota;
use App\Domain\Engagement\Services\StreakFreezeService;
use App\Domain\Learners\Exceptions\TimezoneChangeTooSoonException;
use App\Domain\Learners\Exceptions\TimezoneRevertUnavailableException;
use App\Domain\Learners\Services\LearnerClock;
use App\Domain\Learners\Services\LearnerSettingsService;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Domain\Twa\Services\JwtService;
use App\Models\Learner;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeTelegramClient;

uses(RefreshDatabase::class);

afterEach(fn () => CarbonImmutable::setTestNow());

function revLearner(array $attributes = []): Learner
{
    return Learner::factory()->onboarded()->create(['timezone' => 'Asia/Tashkent', 'reminder_time' => '19:00', ...$attributes]);
}

function revPost(object $test, Learner $learner): TestResponse
{
    return $test->withHeaders(['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']])
        ->postJson('/api/twa/me/settings/timezone/revert');
}

it('puts the zone, the change stamp and the carried day back within 10 minutes, with no 24 h penalty', function (): void {
    $learner = revLearner();
    $settings = app(LearnerSettingsService::class);
    $metaBefore = $learner->meta;

    CarbonImmutable::setTestNow('2026-10-19 10:00:00');
    $settings->update($learner, ['timezone' => 'Asia/Kolkata']);
    expect($learner->fresh()->meta)->toHaveKeys(['timezone_changed_at', 'local_day_carry', 'timezone_change_undo'])
        ->and($settings->timezoneUndoAvailableUntil($learner->fresh())?->toIso8601String())->toBe('2026-10-19T10:10:00+00:00');

    CarbonImmutable::setTestNow('2026-10-19 10:09:59');
    $settings->revertTimezone($learner);

    $fresh = $learner->fresh();
    expect($fresh->timezone)->toBe('Asia/Tashkent')
        ->and($fresh->meta)->toBe($metaBefore ?? [])
        ->and($settings->timezoneChangeAvailableAt($fresh))->toBeNull()
        ->and($settings->timezoneUndoAvailableUntil($fresh))->toBeNull()
        // 19:00 Tashkent = 14:00 UTC, still ahead of 10:09
        ->and($fresh->next_reminder_at?->utc()->toDateTimeString())->toBe('2026-10-19 14:00:00');

    // No wait: the next change goes straight through.
    $settings->update($learner, ['timezone' => 'Europe/Moscow']);
    expect($learner->fresh()->timezone)->toBe('Europe/Moscow');
});

it('restores the previous stamp and carried day exactly when there already were some', function (): void {
    $learner = revLearner();
    $settings = app(LearnerSettingsService::class);

    CarbonImmutable::setTestNow('2026-10-17 10:00:00');
    $settings->update($learner, ['timezone' => 'Asia/Kolkata']);
    CarbonImmutable::setTestNow('2026-10-19 10:00:00'); // 24 h+ later: the zone may change again; the carry is over
    $before = $learner->fresh()->meta;
    unset($before['timezone_change_undo']);

    $settings->update($learner, ['timezone' => 'Asia/Tokyo']);
    expect($learner->fresh()->meta['timezone_changed_at'])->not->toBe($before['timezone_changed_at']);

    CarbonImmutable::setTestNow('2026-10-19 10:05:00');
    $settings->revertTimezone($learner);

    $meta = $learner->fresh()->meta;
    expect($learner->fresh()->timezone)->toBe('Asia/Kolkata')
        ->and($meta)->toEqual($before);
});

it('leaves the quota window exactly as it would be without the change', function (): void {
    config()->set('billing.plans.free.limits.new_words_per_day', 3);
    $learner = revLearner();
    $settings = app(LearnerSettingsService::class);
    $quota = app(NewWordQuota::class);
    $clock = app(LearnerClock::class);

    // 00:30 in Tashkent on the 19th: the day is [18th 19:00, 19th 19:00) UTC.
    CarbonImmutable::setTestNow('2026-10-18 19:30:00');
    $window = $clock->limitWindowUtc($learner);

    $settings->update($learner, ['timezone' => 'Asia/Kolkata']);
    // Without the undo the carried window would run to the 20th 18:30 UTC.
    expect($clock->limitWindowUtc($learner->fresh())->endUtc->toDateTimeString())->toBe('2026-10-20 18:30:00');

    CarbonImmutable::setTestNow('2026-10-18 19:35:00');
    $settings->revertTimezone($learner);

    $after = $clock->limitWindowUtc($learner->fresh());
    expect($after->startUtc->toDateTimeString())->toBe($window->startUtc->toDateTimeString())
        ->and($after->endUtc->toDateTimeString())->toBe($window->endUtc->toDateTimeString())
        ->and($after->endUtc->toDateTimeString())->toBe('2026-10-19 19:00:00')
        ->and($clock->hasActiveDayCarry($learner->fresh()))->toBeFalse();

    // The new day starts at the Tashkent midnight, not at Kolkata's carried one.
    CarbonImmutable::setTestNow('2026-10-19 19:00:00');
    expect($quota->remaining($learner->fresh()))->toBe(3);
});

it('refuses after 10 minutes, without a recent change, and a second undo', function (): void {
    $learner = revLearner();
    $settings = app(LearnerSettingsService::class);

    expect(fn () => $settings->revertTimezone($learner))->toThrow(TimezoneRevertUnavailableException::class);

    CarbonImmutable::setTestNow('2026-10-19 10:00:00');
    $settings->update($learner, ['timezone' => 'Asia/Kolkata']);

    CarbonImmutable::setTestNow('2026-10-19 10:10:00');
    expect(fn () => $settings->revertTimezone($learner))->toThrow(TimezoneRevertUnavailableException::class)
        ->and($learner->fresh()->timezone)->toBe('Asia/Kolkata')
        ->and($settings->timezoneUndoAvailableUntil($learner->fresh()))->toBeNull();

    // Double undo: the snapshot is consumed.
    CarbonImmutable::setTestNow('2026-10-20 10:00:00');
    $settings->update($learner, ['timezone' => 'Asia/Tokyo']);
    CarbonImmutable::setTestNow('2026-10-20 10:01:00');
    $settings->revertTimezone($learner);
    expect(fn () => $settings->revertTimezone($learner))->toThrow(TimezoneRevertUnavailableException::class)
        ->and($learner->fresh()->timezone)->toBe('Asia/Kolkata');
});

it('lets a newer change overwrite the snapshot, so the undo goes back one change only', function (): void {
    $learner = revLearner();
    $settings = app(LearnerSettingsService::class);

    CarbonImmutable::setTestNow('2026-10-17 10:00:00');
    $settings->update($learner, ['timezone' => 'Asia/Kolkata']);
    CarbonImmutable::setTestNow('2026-10-18 10:00:00');
    $settings->update($learner, ['timezone' => 'Asia/Tokyo']);

    CarbonImmutable::setTestNow('2026-10-18 10:02:00');
    $settings->revertTimezone($learner);

    expect($learner->fresh()->timezone)->toBe('Asia/Kolkata');
    // ...and the first change's snapshot is gone with the 10 minutes.
    expect(fn () => $settings->revertTimezone($learner))->toThrow(TimezoneRevertUnavailableException::class);
});

it('keeps a refused change from touching the snapshot', function (): void {
    $learner = revLearner();
    $settings = app(LearnerSettingsService::class);

    CarbonImmutable::setTestNow('2026-10-19 10:00:00');
    $settings->update($learner, ['timezone' => 'Asia/Kolkata']);
    $undo = $learner->fresh()->meta['timezone_change_undo'];

    CarbonImmutable::setTestNow('2026-10-19 10:03:00');
    expect(fn () => $settings->update($learner, ['timezone' => 'Asia/Tokyo']))->toThrow(TimezoneChangeTooSoonException::class)
        ->and($learner->fresh()->meta['timezone_change_undo'])->toBe($undo);
});

it('ignores a malformed snapshot', function (): void {
    $learner = revLearner(['meta' => ['timezone_change_undo' => ['timezone' => 'Nowhere/Land', 'changed_at' => 'x']]]);

    expect(app(LearnerSettingsService::class)->timezoneUndoAvailableUntil($learner))->toBeNull();
    expect(fn () => app(LearnerSettingsService::class)->revertTimezone($learner))->toThrow(TimezoneRevertUnavailableException::class);
});

it('does not give a second streak freeze refill, nor touch the inventory, when the zone change is undone', function (): void {
    $learner = revLearner(['streak_freezes' => 0]);
    Subscription::factory()->create(['subscriber_id' => $learner->id]);
    $freezes = app(StreakFreezeService::class);
    $clock = app(LearnerClock::class);
    $settings = app(LearnerSettingsService::class);

    // Tashkent: Monday 07:00; Los Angeles: still Sunday.
    CarbonImmutable::setTestNow('2026-10-19 02:00:00');
    expect($freezes->refill($learner, $clock->today($learner)))->toBeTrue();

    $settings->update($learner, ['timezone' => 'America/Los_Angeles']);
    expect($freezes->refill($learner->fresh(), $clock->today($learner->fresh())))->toBeFalse();

    CarbonImmutable::setTestNow('2026-10-19 02:05:00');
    $settings->revertTimezone($learner);

    $fresh = $learner->fresh();
    expect($freezes->refill($fresh, $clock->today($fresh)))->toBeFalse()
        ->and($fresh->streak_freezes)->toBe(1)
        ->and($fresh->streak_freezes_refilled_on?->toDateString())->toBe('2026-10-19');
});

it('answers POST /me/settings/timezone/revert with the profile and the undo mark, 422 afterwards', function (): void {
    $learner = revLearner();
    $headers = ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];

    $this->postJson('/api/twa/me/settings/timezone/revert')->assertUnauthorized();

    CarbonImmutable::setTestNow('2026-10-19 10:00:00');
    revPost($this, $learner)->assertStatus(422)->assertJsonPath('error.code', 'timezone_revert_unavailable');

    $this->withHeaders($headers)->patchJson('/api/twa/me/settings', ['timezone' => 'Asia/Kolkata'])
        ->assertOk()
        ->assertJsonPath('settings.timezone_undo_available_until', '2026-10-19T10:10:00+00:00');

    CarbonImmutable::setTestNow('2026-10-19 10:04:00');
    revPost($this, $learner)->assertOk()
        ->assertJsonPath('settings.timezone', 'Asia/Tashkent')
        ->assertJsonPath('settings.timezone_undo_available_until', null)
        ->assertJsonPath('settings.timezone_change_available_at', null);

    revPost($this, $learner)->assertStatus(422)->assertJsonPath('error.code', 'timezone_revert_unavailable');
});

it('offers an undo button under the confirmation of a /settings zone change and takes the change back', function (): void {
    $tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $tg);
    $learner = revLearner(['telegram_user_id' => 7301, 'interface_locale' => 'ru']);
    $tap = fn (string $data, int $mid) => app(TelegramDispatcher::class)->dispatch(['callback_query' => [
        'id' => 'cb-'.$mid,
        'from' => ['id' => 7301, 'first_name' => 'Ali', 'language_code' => 'ru'],
        'message' => ['message_id' => $mid, 'chat' => ['id' => 7301, 'type' => 'private']],
        'data' => $data,
    ]]);

    CarbonImmutable::setTestNow('2026-10-19 10:00:00');
    $tap('set1:timezone:Europe/Moscow', 10);
    $sent = end($tg->sent);
    expect($sent['text'])->toBe('Сохранено.')
        ->and($sent['markup']['inline_keyboard'][0][0])->toBe(['text' => 'Отменить', 'callback_data' => 'set1:tzundo']);

    // A non-zone setting has no button.
    $tap('set1:goal:ielts', 11);
    expect(end($tg->sent)['markup'])->toBeNull();

    CarbonImmutable::setTestNow('2026-10-19 10:05:00');
    $tap('set1:tzundo', 12);
    expect(end($tg->sent)['text'])->toBe('Часовой пояс возвращён.')
        ->and($learner->fresh()->timezone)->toBe('Asia/Tashkent');

    // Second tap: nothing to take back.
    $tap('set1:tzundo', 12);
    expect(end($tg->sent)['text'])->toBe(__('onboarding.settings.timezone_revert_unavailable', [], 'ru'))
        ->and($learner->fresh()->timezone)->toBe('Asia/Tashkent');
});
