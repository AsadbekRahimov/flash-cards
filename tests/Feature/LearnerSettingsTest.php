<?php

declare(strict_types=1);

/*
 * Learner settings (task 1.6, docs/12 §3.8): LearnerSettingsService, PATCH /api/twa/me/settings and the
 * `settings` block of GET /me. The bot's /settings goes through the same service (OnboardingFlowTest
 * keeps its behaviour pinned).
 */

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Learners\Exceptions\InvalidSettingException;
use App\Domain\Learners\Exceptions\OnboardingException;
use App\Domain\Learners\Services\DailyStatsService;
use App\Domain\Learners\Services\LearnerClock;
use App\Domain\Learners\Services\LearnerRegistrar;
use App\Domain\Learners\Services\LearnerSettingsService;
use App\Domain\Learners\Services\OnboardingService;
use App\Domain\Learners\Services\StreakService;
use App\Domain\Twa\Services\JwtService;
use App\Models\Course;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\Lesson;
use App\Models\ProductEvent;
use App\Models\Stage;
use App\Models\Word;
use App\Models\WordTranslation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

afterEach(fn () => CarbonImmutable::setTestNow());

/** @return array<string, string> */
function settingsHeaders(Learner $learner): array
{
    return ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];
}

/** @param  array<string, mixed>  $body */
function patchSettings(object $test, Learner $learner, array $body): TestResponse
{
    return $test->withHeaders(settingsHeaders($learner))->patchJson('/api/twa/me/settings', $body);
}

function settingsLearner(array $attributes = []): Learner
{
    return Learner::factory()->onboarded()->create([
        'timezone' => 'Asia/Tashkent',
        'reminder_time' => '19:00',
        'daily_goal' => 20,
        ...$attributes,
    ]);
}

function nextReminder(Learner $learner): ?string
{
    return $learner->fresh()?->next_reminder_at?->utc()->toDateTimeString();
}

// --- GET /me: the settings block --------------------------------------------

it('GET /me reports the current settings and the choices of each field', function (): void {
    $course = Course::factory()->published()->create(['slug' => 'a1', 'title' => ['ru' => 'Курс А1', 'uz' => 'A1 kursi']]);
    $learner = settingsLearner(['goal' => 'ielts', 'level' => 'B1', 'active_course_id' => $course->id]);

    $settings = $this->withHeaders(settingsHeaders($learner))->getJson('/api/twa/me')->assertOk()->json('settings');

    expect($settings)->toMatchArray([
        'interface_locale' => 'ru',
        'timezone' => 'Asia/Tashkent',
        'reminder_time' => '19:00',
        'daily_goal' => 20,
        'goal' => 'ielts',
        'level' => 'B1',
        'active_course' => ['slug' => 'a1', 'title' => 'Курс А1'],
    ])
        ->and($settings['options']['locales'])->toBe([
            ['id' => 'ru', 'label' => '🇷🇺 Русский'],
            ['id' => 'uz', 'label' => "🇺🇿 O'zbekcha"],
        ])
        ->and($settings['options']['reminder_times'])->toBe(['08:00', '13:00', '19:00', '21:00'])
        ->and($settings['options']['daily_goals'])->toBe([10, 20, 40])
        ->and($settings['options']['timezones'][0])->toBe(['id' => 'Asia/Tashkent', 'label' => 'Ташкент (UTC+5)'])
        ->and(array_column($settings['options']['timezones'], 'id'))->toBe(config('learners.timezone_options'));
});

it('GET /me has no active course for a learner without one, and "off" reminders as null', function (): void {
    $learner = settingsLearner(['reminder_time' => null]);

    $this->withHeaders(settingsHeaders($learner))->getJson('/api/twa/me')
        ->assertOk()
        ->assertJsonPath('settings.active_course', null)
        ->assertJsonPath('settings.reminder_time', null)
        ->assertJsonPath('settings.goal', null);
});

it('hides an active course that is no longer available', function (): void {
    $course = Course::factory()->create(['status' => 'draft']);
    $learner = settingsLearner(['active_course_id' => $course->id]);

    $this->withHeaders(settingsHeaders($learner))->getJson('/api/twa/me')->assertJsonPath('settings.active_course', null);
});

it('writes the reminder time as HH:MM even where the database returns HH:MM:SS', function (): void {
    $learner = settingsLearner();
    DB::table('learners')->where('id', $learner->id)->update(['reminder_time' => '19:00:00']);

    $this->withHeaders(settingsHeaders($learner))->getJson('/api/twa/me')->assertJsonPath('settings.reminder_time', '19:00');
});

it('adds a time zone and a reminder time the lists do not offer, so the picker can show them', function (): void {
    $learner = settingsLearner(['timezone' => 'Asia/Kolkata', 'reminder_time' => '07:45']);

    $options = $this->withHeaders(settingsHeaders($learner))->getJson('/api/twa/me')->json('settings.options');

    expect(collect($options['timezones'])->last())->toBe(['id' => 'Asia/Kolkata', 'label' => 'Asia/Kolkata (UTC+5:30)'])
        ->and($options['reminder_times'])->toBe(['07:45', '08:00', '13:00', '19:00', '21:00']);
});

it('labels the time zones in the interface language of the learner', function (): void {
    $learner = settingsLearner(['interface_locale' => 'uz']);

    $first = $this->withHeaders(settingsHeaders($learner))->getJson('/api/twa/me')->json('settings.options.timezones.0');

    expect($first['id'])->toBe('Asia/Tashkent')
        ->and($first['label'])->toBe(__('onboarding.options.timezone.tashkent', ['offset' => 'UTC+5'], 'uz'))
        ->and($first['label'])->not->toBe('Ташкент (UTC+5)');
});

// --- PATCH /me/settings ------------------------------------------------------

it('requires authentication', function (): void {
    $this->patchJson('/api/twa/me/settings', ['daily_goal' => 10])->assertStatus(401);
});

it('applies a partial update and answers with the refreshed profile', function (): void {
    $learner = settingsLearner();

    patchSettings($this, $learner, ['interface_locale' => 'uz', 'daily_goal' => 40, 'reminder_time' => '21:00'])
        ->assertOk()
        ->assertJsonPath('learner.locale', 'uz')
        ->assertJsonPath('settings.interface_locale', 'uz')
        ->assertJsonPath('settings.daily_goal', 40)
        ->assertJsonPath('settings.reminder_time', '21:00')
        ->assertJsonPath('settings.timezone', 'Asia/Tashkent')
        ->assertJsonStructure(['learner', 'groups', 'stats', 'entitlement', 'paywall', 'settings' => ['options']]);

    $fresh = $learner->fresh();
    expect($fresh->interface_locale)->toBe('uz')
        ->and($fresh->daily_goal)->toBe(40)
        ->and($fresh->reminder_time)->toStartWith('21:00')
        ->and($fresh->timezone)->toBe('Asia/Tashkent');
});

it('accepts the onboarding goal and level too', function (): void {
    $learner = settingsLearner();

    patchSettings($this, $learner, ['goal' => 'sat', 'level' => 'B2'])->assertOk()
        ->assertJsonPath('settings.goal', 'sat')
        ->assertJsonPath('settings.level', 'B2');
});

it('ignores fields that are not settings and treats an empty body as a no-op', function (): void {
    $learner = settingsLearner(['streak_current' => 3]);

    patchSettings($this, $learner, ['streak_current' => 99, 'blocked_at' => now()->toIso8601String(), 'onboarding_state' => 'new'])->assertOk();
    patchSettings($this, $learner, [])->assertOk();

    $fresh = $learner->fresh();
    expect($fresh->streak_current)->toBe(3)
        ->and($fresh->blocked_at)->toBeNull()
        ->and($fresh->onboarding_state)->toBe('done')
        ->and(ProductEvent::query()->where('name', ProductEventName::SettingsChanged->value)->count())->toBe(0);
});

it('accepts any IANA time zone, not only the ones the picker offers', function (): void {
    $learner = settingsLearner();

    patchSettings($this, $learner, ['timezone' => 'America/New_York'])->assertOk()->assertJsonPath('settings.timezone', 'America/New_York');
    patchSettings($this, $learner, ['timezone' => 'UTC'])->assertOk()->assertJsonPath('settings.timezone', 'UTC');
});

it('rejects invalid values with 422 and changes nothing', function (string $field, mixed $value): void {
    $learner = settingsLearner(['goal' => 'general']);
    $before = $learner->fresh()->getAttributes();

    patchSettings($this, $learner, [$field => $value])->assertStatus(422)->assertJsonValidationErrors([$field]);

    expect($learner->fresh()->getAttributes())->toBe($before);
})->with([
    'timezone garbage' => ['timezone', 'Mars/Olympus'],
    'timezone traversal' => ['timezone', '../../etc/passwd'],
    'timezone lower case' => ['timezone', 'asia/tashkent'],
    'timezone empty' => ['timezone', ''],
    'timezone offset' => ['timezone', '+05:00'],
    'timezone number' => ['timezone', 5],
    'timezone null' => ['timezone', null],
    'timezone too long' => ['timezone', str_repeat('A', 65)],
    'locale unsupported' => ['interface_locale', 'en'],
    'locale null' => ['interface_locale', null],
    'locale array' => ['interface_locale', ['ru']],
    'reminder past the day' => ['reminder_time', '24:00'],
    'reminder hour 25' => ['reminder_time', '25:00'],
    'reminder not a quarter' => ['reminder_time', '19:07'],
    'reminder without zero' => ['reminder_time', '7:00'],
    'reminder with seconds' => ['reminder_time', '19:00:00'],
    'reminder number' => ['reminder_time', 1900],
    'reminder text' => ['reminder_time', 'off'],
    'goal off the list' => ['daily_goal', 15],
    'goal zero' => ['daily_goal', 0],
    'goal negative' => ['daily_goal', -10],
    'goal as string' => ['daily_goal', '20'],
    'goal float' => ['daily_goal', 20.5],
    'goal null' => ['daily_goal', null],
    'goal huge' => ['daily_goal', 1000000],
    'learning goal unknown' => ['goal', 'toefl'],
    'level beyond the self-assessment' => ['level', 'C2'],
    'level unknown' => ['level', 'unknown'],
]);

it('does not change anything when one of several fields is invalid', function (): void {
    $learner = settingsLearner();

    patchSettings($this, $learner, ['daily_goal' => 40, 'timezone' => 'Nowhere/Land'])->assertStatus(422)->assertJsonValidationErrors(['timezone']);

    expect($learner->fresh()->daily_goal)->toBe(20);
});

it('turns the reminders off with a null reminder_time', function (): void {
    $learner = settingsLearner(['next_reminder_at' => '2026-10-19 14:00:00']);

    patchSettings($this, $learner, ['reminder_time' => null])
        ->assertOk()
        ->assertJsonPath('settings.reminder_time', null);

    expect($learner->fresh()->reminder_time)->toBeNull()
        ->and($learner->fresh()->next_reminder_at)->toBeNull();
});

it('accepts a free reminder time on a quarter of an hour', function (string $time): void {
    $learner = settingsLearner();

    patchSettings($this, $learner, ['reminder_time' => $time])->assertOk()->assertJsonPath('settings.reminder_time', $time);
})->with(['00:00', '07:45', '12:15', '23:45']);

// --- next_reminder_at --------------------------------------------------------

it('recomputes next_reminder_at when the reminder time changes (Tashkent 19:00 = 14:00 UTC)', function (): void {
    CarbonImmutable::setTestNow('2026-10-19 10:00:00');
    $learner = settingsLearner(['reminder_time' => '08:00', 'next_reminder_at' => '2026-10-20 03:00:00']);

    patchSettings($this, $learner, ['reminder_time' => '19:00'])->assertOk();
    expect(nextReminder($learner))->toBe('2026-10-19 14:00:00');

    // 08:00 Tashkent (03:00 UTC) has passed today: tomorrow.
    patchSettings($this, $learner, ['reminder_time' => '08:00'])->assertOk();
    expect(nextReminder($learner))->toBe('2026-10-20 03:00:00');

    patchSettings($this, $learner, ['reminder_time' => '15:30'])->assertOk();
    expect(nextReminder($learner))->toBe('2026-10-19 10:30:00');
});

it('recomputes next_reminder_at when the time zone changes, and keeps null while the reminders are off', function (): void {
    CarbonImmutable::setTestNow('2026-10-19 10:00:00');
    $learner = settingsLearner(['next_reminder_at' => '2026-10-19 14:00:00']);

    patchSettings($this, $learner, ['timezone' => 'Europe/Moscow'])->assertOk();
    expect(nextReminder($learner))->toBe('2026-10-19 16:00:00');

    patchSettings($this, $learner, ['timezone' => 'America/New_York'])->assertOk();
    // 19:00 EDT (UTC-4) on 19 Oct.
    expect(nextReminder($learner))->toBe('2026-10-19 23:00:00');

    patchSettings($this, $learner, ['reminder_time' => null])->assertOk();
    patchSettings($this, $learner, ['timezone' => 'Asia/Almaty'])->assertOk();
    expect(nextReminder($learner))->toBeNull();

    // Switching the reminders back on schedules the next one in the CURRENT zone (Almaty UTC+5).
    patchSettings($this, $learner, ['reminder_time' => '19:00'])->assertOk();
    expect(nextReminder($learner))->toBe('2026-10-19 14:00:00');
});

it('follows daylight saving time of the zone when it recomputes', function (): void {
    // New York leaves DST on 1 Nov 2026: 19:00 is UTC-4 before it and UTC-5 after.
    CarbonImmutable::setTestNow('2026-10-31 12:00:00');
    $learner = settingsLearner();

    patchSettings($this, $learner, ['timezone' => 'America/New_York'])->assertOk();
    expect(nextReminder($learner))->toBe('2026-10-31 23:00:00');

    CarbonImmutable::setTestNow('2026-11-01 12:00:00');
    patchSettings($this, $learner, ['reminder_time' => '19:00', 'timezone' => 'America/Chicago'])->assertOk();
    expect(nextReminder($learner))->toBe('2026-11-02 01:00:00');
});

it('leaves next_reminder_at alone when only other settings change', function (): void {
    $learner = settingsLearner(['next_reminder_at' => '2026-10-19 14:00:00']);

    patchSettings($this, $learner, ['daily_goal' => 10, 'interface_locale' => 'uz', 'goal' => 'sat'])->assertOk();

    expect(nextReminder($learner))->toBe('2026-10-19 14:00:00');
});

// --- Locale and the card -------------------------------------------------------

it('switching the interface language changes the card translation right away', function (): void {
    $course = Course::factory()->published()->create();
    $lesson = Lesson::factory()->for(Stage::factory()->create(['course_id' => $course->id, 'number' => 1]))->create(['number' => 1]);
    foreach (Word::factory()->count(2)->for($lesson)->create() as $word) {
        WordTranslation::factory()->create(['word_id' => $word->id, 'locale' => 'ru', 'translation' => 'привет']);
        WordTranslation::factory()->uz()->create(['word_id' => $word->id, 'translation' => 'salom']);
    }
    $learner = settingsLearner(['active_course_id' => $course->id]);
    $headers = settingsHeaders($learner);

    $sessionId = $this->withHeaders($headers)
        ->postJson('/api/twa/training/sessions', ['mode' => 'lesson', 'lesson_id' => $lesson->id])
        ->assertOk()
        ->assertJsonPath('card.translation', 'привет')
        ->json('session_id');

    patchSettings($this, $learner, ['interface_locale' => 'uz'])->assertOk();

    $this->withHeaders($headers)->getJson("/api/twa/training/sessions/{$sessionId}/next")
        ->assertOk()
        ->assertJsonPath('card.translation', 'salom')
        ->assertJsonPath('card.translation_fallback', false);

    patchSettings($this, $learner, ['interface_locale' => 'ru'])->assertOk();

    $this->withHeaders($headers)->getJson("/api/twa/training/sessions/{$sessionId}/next")
        ->assertJsonPath('card.translation', 'привет');
});

it('shows the course title of the catalog in the new language right after the switch', function (): void {
    Course::factory()->published()->create(['slug' => 'a1', 'title' => ['ru' => 'Курс', 'uz' => 'Kurs']]);
    $learner = settingsLearner(['active_course_id' => Course::query()->where('slug', 'a1')->value('id')]);

    patchSettings($this, $learner, ['interface_locale' => 'uz'])->assertOk()->assertJsonPath('settings.active_course.title', 'Kurs');
});

// --- Lowering the daily goal ----------------------------------------------------

/** Grades `$count` cards at the current (frozen) instant, each in its own transaction, like the review endpoint. */
function gradeToday(Learner $learner, int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        DB::transaction(fn () => app(DailyStatsService::class)->recordReview($learner, 4, 400, false, CarbonImmutable::now()));
    }
}

function todayRow(Learner $learner): ?LearnerDailyStats
{
    return LearnerDailyStats::query()->where('learner_id', $learner->id)->first();
}

it('completes today\'s goal at once when the goal is lowered to what is already done, and credits the streak', function (): void {
    CarbonImmutable::setTestNow('2026-10-19 10:00:00');
    $learner = settingsLearner();
    gradeToday($learner, 12);
    expect(todayRow($learner)->goal_met_at)->toBeNull();

    patchSettings($this, $learner, ['daily_goal' => 10])->assertOk();

    expect(todayRow($learner)->goal_met_at?->toDateTimeString())->toBe('2026-10-19 10:00:00');
    $fresh = $learner->fresh();
    expect($fresh->streak_current)->toBe(1)
        ->and($fresh->streak_best)->toBe(1)
        ->and($fresh->streak_last_date?->toDateString())->toBe('2026-10-19')
        ->and(ProductEvent::query()->where('name', ProductEventName::DailyGoalMet->value)->count())->toBe(1);

    $this->withHeaders(settingsHeaders($learner))->getJson('/api/twa/today')
        ->assertJsonPath('goal', ['target' => 10, 'done' => 12, 'met' => true])
        ->assertJsonPath('streak.current', 1)
        ->assertJsonPath('streak.met_today', true);
});

it('continues the series of yesterday when the lowered goal completes today', function (): void {
    CarbonImmutable::setTestNow('2026-10-18 10:00:00');
    $learner = settingsLearner(['daily_goal' => 10]);
    gradeToday($learner, 10);
    expect($learner->fresh()->streak_current)->toBe(1);

    CarbonImmutable::setTestNow('2026-10-19 10:00:00');
    patchSettings($this, $learner, ['daily_goal' => 20])->assertOk();
    gradeToday($learner->fresh(), 12);
    expect($learner->fresh()->streak_current)->toBe(1);

    patchSettings($this, $learner, ['daily_goal' => 10])->assertOk();

    expect($learner->fresh()->streak_current)->toBe(2)
        ->and($learner->fresh()->streak_best)->toBe(2);
});

it('does not complete the goal when it is lowered but still above what is done', function (): void {
    CarbonImmutable::setTestNow('2026-10-19 10:00:00');
    $learner = settingsLearner(['daily_goal' => 40]);
    gradeToday($learner, 12);

    patchSettings($this, $learner, ['daily_goal' => 20])->assertOk();

    expect(todayRow($learner)->goal_met_at)->toBeNull()
        ->and($learner->fresh()->streak_current)->toBe(0);
});

it('counts the local day of the new time zone when the goal is lowered together with it', function (): void {
    // 23:30 UTC on the 19th is already the 20th in Tashkent (UTC+5): the reviews live on the 20th.
    CarbonImmutable::setTestNow('2026-10-19 23:30:00');
    $learner = settingsLearner();
    gradeToday($learner, 12);

    patchSettings($this, $learner, ['daily_goal' => 10])->assertOk();

    expect(todayRow($learner)->date->toDateString())->toBe('2026-10-20')
        ->and(todayRow($learner)->goal_met_at)->not->toBeNull()
        ->and($learner->fresh()->streak_last_date?->toDateString())->toBe('2026-10-20');
});

it('leaves a goal that was already met alone, also after raising the goal again', function (): void {
    CarbonImmutable::setTestNow('2026-10-19 10:00:00');
    $learner = settingsLearner(['daily_goal' => 10]);
    gradeToday($learner, 10);
    $metAt = todayRow($learner)->goal_met_at?->toDateTimeString();

    CarbonImmutable::setTestNow('2026-10-19 11:00:00');
    patchSettings($this, $learner, ['daily_goal' => 40])->assertOk();
    patchSettings($this, $learner, ['daily_goal' => 10])->assertOk();

    expect(todayRow($learner)->goal_met_at?->toDateTimeString())->toBe($metAt)
        ->and($learner->fresh()->streak_current)->toBe(1)
        ->and(ProductEvent::query()->where('name', ProductEventName::DailyGoalMet->value)->count())->toBe(1);
});

it('does nothing about the goal when nothing was graded today', function (): void {
    $learner = settingsLearner();

    patchSettings($this, $learner, ['daily_goal' => 10])->assertOk();

    expect(todayRow($learner))->toBeNull();
});

// --- Event ------------------------------------------------------------------------

it('records settings.changed with the fields that really changed', function (): void {
    $learner = settingsLearner();

    patchSettings($this, $learner, ['daily_goal' => 20, 'interface_locale' => 'uz', 'reminder_time' => '21:00'])->assertOk();

    $events = ProductEvent::query()->where('name', ProductEventName::SettingsChanged->value)->get();
    expect($events)->toHaveCount(1)
        ->and($events[0]->learner_id)->toBe($learner->id)
        ->and($events[0]->props)->toBe(['fields' => 'interface_locale,reminder_time']);

    // The same values again: no change, no event.
    patchSettings($this, $learner, ['interface_locale' => 'uz', 'reminder_time' => '21:00'])->assertOk();
    expect(ProductEvent::query()->where('name', ProductEventName::SettingsChanged->value)->count())->toBe(1);
});

// --- Service ----------------------------------------------------------------------

it('refuses unknown fields and values outside the rules, with the field named', function (array $changes, string $field): void {
    $learner = settingsLearner();

    try {
        app(LearnerSettingsService::class)->update($learner, $changes);
        $this->fail('The service accepted '.json_encode($changes));
    } catch (InvalidSettingException $e) {
        expect($e->field)->toBe($field);
    }

    expect($learner->fresh()->daily_goal)->toBe(20);
})->with([
    'unknown field' => [['streak_current' => 5], 'streak_current'],
    'column that is not a setting' => [['telegram_user_id' => 1], 'telegram_user_id'],
    'goal as numeric string' => [['daily_goal' => '10'], 'daily_goal'],
    'goal off the list' => [['daily_goal' => 11], 'daily_goal'],
    'time zone' => [['timezone' => 'Moon/Base'], 'timezone'],
    'time zone not a string' => [['timezone' => 3], 'timezone'],
    'reminder' => [['reminder_time' => '19:10'], 'reminder_time'],
    'locale' => [['interface_locale' => 'de'], 'interface_locale'],
    'level' => [['level' => 'unknown'], 'level'],
]);

it('writes nothing and no event for an empty change', function (): void {
    $learner = settingsLearner();

    expect(app(LearnerSettingsService::class)->update($learner, []))->toBe($learner)
        ->and(ProductEvent::query()->count())->toBe(0);
});

it('keeps the passed model current after the update', function (): void {
    $learner = settingsLearner();

    $returned = app(LearnerSettingsService::class)->update($learner, ['daily_goal' => 10, 'reminder_time' => null]);

    expect($returned)->toBe($learner)
        ->and($learner->daily_goal)->toBe(10)
        ->and($learner->reminder_time)->toBeNull()
        ->and($learner->next_reminder_at)->toBeNull();
});

// --- Bot /settings goes through the service ------------------------------------------

it('applies the bot /settings answers through the service: event, next_reminder_at, whitelists, finished onboarding', function (): void {
    CarbonImmutable::setTestNow('2026-10-19 10:00:00');
    $learner = settingsLearner(['next_reminder_at' => '2026-10-19 14:00:00']);
    $onboarding = app(OnboardingService::class);

    $onboarding->applySetting($learner, 'timezone', 'Europe/Moscow');

    expect(nextReminder($learner))->toBe('2026-10-19 16:00:00')
        ->and(ProductEvent::query()->where('name', ProductEventName::SettingsChanged->value)->value('props'))->toBe(['fields' => 'timezone']);

    // The bot keeps its narrower whitelists: a valid IANA zone that is not offered is refused.
    expect(fn () => $onboarding->applySetting($learner, 'timezone', 'America/New_York'))->toThrow(OnboardingException::class)
        ->and(fn () => $onboarding->applySetting($learner, 'reminder', '0730'))->toThrow(OnboardingException::class)
        ->and($learner->fresh()->timezone)->toBe('Europe/Moscow');

    $unfinished = Learner::factory()->create(['onboarding_state' => 'goal']);
    expect(fn () => $onboarding->applySetting($unfinished, 'level', 'B1'))->toThrow(OnboardingException::class);
});

// --- Review 1.6 fixes ---------------------------------------------------------------

it('keeps the language chosen in the settings before the onboarding: /auth and passive events no longer overwrite it', function (string $telegramLanguage): void {
    $learner = Learner::factory()->create(['telegram_user_id' => 7001, 'language_code' => 'ru', 'interface_locale' => 'ru']);

    patchSettings($this, $learner, ['interface_locale' => 'uz'])->assertOk();
    expect($learner->fresh()->onboarding_state)->toBe('new');

    $registrar = app(LearnerRegistrar::class);
    $registrar->fromTelegramUser(['id' => 7001, 'first_name' => 'A', 'language_code' => $telegramLanguage]);
    expect($learner->fresh()->interface_locale)->toBe('uz');

    $registrar->observeTelegramUser(['id' => 7001, 'first_name' => 'A', 'language_code' => $telegramLanguage]);
    expect($learner->fresh()->interface_locale)->toBe('uz')
        // The Telegram language itself is still refreshed.
        ->and($learner->fresh()->language_code)->toBe($telegramLanguage);
})->with(['en', 'ru']);

it('marks an explicit choice even when it equals the current language', function (): void {
    $learner = Learner::factory()->create(['telegram_user_id' => 7002, 'interface_locale' => 'ru']);

    patchSettings($this, $learner, ['interface_locale' => 'ru'])->assertOk();
    app(LearnerRegistrar::class)->fromTelegramUser(['id' => 7002, 'first_name' => 'A', 'language_code' => 'uz']);

    expect($learner->fresh()->interface_locale)->toBe('ru')
        ->and($learner->fresh()->meta)->toMatchArray(['locale_chosen' => true]);
});

it('still derives the language from Telegram for a learner who has not chosen one', function (): void {
    $learner = Learner::factory()->create(['telegram_user_id' => 7003, 'interface_locale' => 'ru']);

    patchSettings($this, $learner, ['daily_goal' => 40])->assertOk();
    app(LearnerRegistrar::class)->fromTelegramUser(['id' => 7003, 'first_name' => 'A', 'language_code' => 'uz']);

    expect($learner->fresh()->interface_locale)->toBe('uz')
        ->and($learner->fresh()->meta)->not->toHaveKey('locale_chosen');
});

it('the onboarding "locale" step marks the language as chosen too', function (): void {
    $learner = Learner::factory()->create(['telegram_user_id' => 7004, 'language_code' => 'ru', 'interface_locale' => 'ru', 'onboarding_state' => 'locale', 'meta' => ['pending_course' => 'x']]);

    app(OnboardingService::class)->apply($learner, 'locale', 'uz');

    expect($learner->fresh()->interface_locale)->toBe('uz')
        ->and($learner->fresh()->meta)->toBe(['pending_course' => 'x', 'locale_chosen' => true]);

    app(LearnerRegistrar::class)->fromTelegramUser(['id' => 7004, 'first_name' => 'A', 'language_code' => 'ru']);
    expect($learner->fresh()->interface_locale)->toBe('uz');
});

it('describe() takes the course title without computing the progress of the course', function (): void {
    $course = Course::factory()->published()->create(['slug' => 'big', 'title' => ['ru' => 'Большой', 'uz' => 'Katta']]);
    $lesson = Lesson::factory()->for(Stage::factory()->create(['course_id' => $course->id, 'number' => 1]))->create(['number' => 1]);
    Word::factory()->count(5)->for($lesson)->create();
    $learner = settingsLearner(['active_course_id' => $course->id]);

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $described = app(LearnerSettingsService::class)->describe($learner);

    expect($described['active_course'])->toBe(['slug' => 'big', 'title' => 'Большой'])
        ->and(array_filter($queries, fn (string $sql): bool => str_contains($sql, '"words"') || str_contains($sql, 'word_repetitions') || str_contains($sql, '"stages"')))->toBe([]);
});

it('GET /me runs the same number of queries for a small course and a big one', function (): void {
    $count = function (int $words): int {
        $course = Course::factory()->published()->create();
        $lesson = Lesson::factory()->for(Stage::factory()->create(['course_id' => $course->id, 'number' => 1]))->create(['number' => 1]);
        Word::factory()->count($words)->for($lesson)->create();
        $learner = settingsLearner(['active_course_id' => $course->id]);
        $headers = settingsHeaders($learner);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->withHeaders($headers)->getJson('/api/twa/me')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    expect($count(30))->toBe($count(2));
});

it('does not turn a failed goal reconcile into a 500: the settings are already saved', function (): void {
    Exceptions::fake();
    $this->app->instance(DailyStatsService::class, new class(app(LearnerClock::class), app(StreakService::class)) extends DailyStatsService
    {
        public function reconcileGoal(Learner $learner, ?CarbonImmutable $now = null): bool
        {
            throw new RuntimeException('deadlock');
        }
    });
    $learner = settingsLearner();

    patchSettings($this, $learner, ['daily_goal' => 10])->assertOk()->assertJsonPath('settings.daily_goal', 10);

    expect($learner->fresh()->daily_goal)->toBe(10);
    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'deadlock');
});

it('answers 401 learner_blocked to PATCH /me/settings from a blocked learner and changes nothing', function (): void {
    $learner = settingsLearner();
    $headers = settingsHeaders($learner);
    $learner->update(['blocked_at' => now()]);

    $this->withHeaders($headers)->patchJson('/api/twa/me/settings', ['daily_goal' => 10])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'learner_blocked');

    expect($learner->fresh()->daily_goal)->toBe(20);
});

it('accepts the legacy aliases PHP lists (DateTimeZone::ALL_WITH_BC, e.g. Asia/Calcutta) as they are', function (): void {
    // Which aliases exist depends on the tz database of the PHP build; take the ones this runtime has.
    $aliases = array_values(array_filter(
        array_diff(DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), DateTimeZone::listIdentifiers()),
        fn (string $id): bool => str_contains($id, '/'),
    ));
    expect($aliases)->not->toBeEmpty();
    $learner = settingsLearner();

    foreach (array_slice($aliases, 0, 3) as $alias) {
        patchSettings($this, $learner, ['timezone' => $alias])->assertOk()->assertJsonPath('settings.timezone', $alias);
    }
    expect($learner->fresh()->timezone)->toBe($alias);
    patchSettings($this, $learner, ['timezone' => 'Mars/Olympus'])->assertStatus(422);
});
