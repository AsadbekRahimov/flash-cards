<?php

declare(strict_types=1);

/*
 * The onboarding of the Mini App, O1-O8 (docs/40 §2.1, docs/04 A.24): GET /onboarding, PUT /onboarding/steps/{step},
 * POST /onboarding/complete, and the fields `display_name` / `onboarding` of /auth and /me.
 */

use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Content\Services\CoursePublisher;
use App\Domain\Learners\Exceptions\OnboardingException;
use App\Domain\Learners\Services\OnboardingService;
use App\Domain\Operations\Support\PureCache;
use App\Domain\Twa\Services\InitDataValidator;
use App\Domain\Twa\Services\JwtService;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\ProductEvent;
use App\Models\Stage;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Testing\TestResponse;
use Tests\Support\HotPathFixtures as Fx;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['billing.plans.free.limits.daily_goal_max' => 10, 'learners.daily_goal_options' => [10, 20, 40], 'learners.goals' => ['general']]);
});

/** @return array<string, string> */
function obHeaders(Learner $learner): array
{
    return ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];
}

/**
 * The general courses of five levels (A2 has none: it leads to the first in catalog order, the A1 course) and a course of phrases.
 *
 * @return array{a1: Course, b1: Course, a1_lessons: array<string, Lesson>}
 */
function obCatalog(): array
{
    $make = fn (string $slug, string $level, array $layout): array => Fx::course($layout, [
        'slug' => $slug, 'exam_tag' => 'general', 'level' => $level,
        'title' => ['ru' => 'Английский '.$level, 'uz' => 'Ingliz tili '.$level],
    ]);
    ['course' => $a1, 'lessons' => $lessons] = $make('english-a1', 'A1', [1 => [1 => 3, 2 => 3], 2 => [1 => 3]]);
    ['course' => $b1] = $make('english-b1', 'B1', [1 => [1 => 3]]);
    $make('english-b2', 'B2', [1 => [1 => 3]]);
    $make('english-c1', 'C1', [1 => [1 => 3]]);
    $make('english-c2', 'C2', [1 => [1 => 3]]);
    Fx::course([1 => [1 => 3]], ['slug' => 'phrases', 'exam_tag' => 'phrases', 'level' => 'A1']);

    return ['a1' => $a1, 'b1' => $b1, 'a1_lessons' => $lessons];
}

/** @param array<string, mixed> $body */
function obPut(object $test, Learner $learner, string $step, array $body): TestResponse
{
    return $test->withHeaders(obHeaders($learner))->putJson("/api/twa/onboarding/steps/{$step}", $body);
}

/** @return list<array{name: string, props: array<string, mixed>}> */
function obEvents(Learner $learner): array
{
    return ProductEvent::query()->where('learner_id', $learner->id)->orderBy('id')->get()
        ->map(fn (ProductEvent $e): array => ['name' => $e->name, 'props' => $e->props])->values()->all();
}

/** @return list<string> */
function obEventNames(Learner $learner, string $prefix = 'onboarding.'): array
{
    return array_values(array_filter(array_column(obEvents($learner), 'name'), fn (string $n): bool => str_starts_with($n, $prefix)));
}

const OB_STATE = [
    'completed', 'step',
    'values' => ['display_name', 'first_name', 'age_range', 'goals', 'level', 'course', 'daily_goal', 'reminder' => ['enabled', 'time']],
    'options' => [
        'age_ranges', 'goals', 'levels' => ['*' => ['level', 'levels', 'course_slug', 'title', 'level_label', 'stages_count']],
        'daily_goals', 'daily_goal_max', 'recommended_daily_goal', 'reminder_times', 'reminder_step_minutes', 'placement_available',
    ],
];

// --- GET /onboarding ------------------------------------------------------------------------------------------------------------

it('shows a new learner the welcome screen, the values from the row and the choices of every screen', function (): void {
    obCatalog();
    $learner = Learner::factory()->create(['first_name' => 'Aziz', 'interface_locale' => 'uz', 'daily_goal' => 10, 'reminder_time' => '19:00']);

    $response = $this->withHeaders(obHeaders($learner))->getJson('/api/twa/onboarding')->assertOk()->assertExactJsonStructure(OB_STATE);

    expect($response->json('completed'))->toBeFalse()
        ->and($response->json('step'))->toBeNull()
        ->and($response->json('values'))->toBe([
            'display_name' => null, 'first_name' => 'Aziz', 'age_range' => null, 'goals' => [], 'level' => null, 'course' => null,
            'daily_goal' => 10, 'reminder' => ['enabled' => true, 'time' => '19:00'],
        ])
        ->and($response->json('options.age_ranges'))->toBe(['lt14', '14_17', '18_24', '25_34', '35_44', '45p'])
        ->and($response->json('options.goals'))->toBe(['career', 'study', 'travel', 'relocation', 'communication', 'media'])
        ->and($response->json('options.daily_goals'))->toBe([10, 20, 40])
        ->and($response->json('options.daily_goal_max'))->toBe(10) // Free
        ->and($response->json('options.recommended_daily_goal'))->toBe(20)
        ->and($response->json('options.reminder_times'))->toBe(['08:00', '12:30', '18:00', '20:00', '21:30'])
        ->and($response->json('options.reminder_step_minutes'))->toBe(15)
        // A1 and A2 lead to one course: one row; titles in the learner's language; the course of phrases is never offered (В2).
        ->and($response->json('options.levels'))->toBe([
            ['level' => 'A1', 'levels' => ['A1', 'A2'], 'course_slug' => 'english-a1', 'title' => 'Ingliz tili A1', 'level_label' => 'A1–A2', 'stages_count' => 2],
            ['level' => 'B1', 'levels' => ['B1'], 'course_slug' => 'english-b1', 'title' => 'Ingliz tili B1', 'level_label' => 'B1', 'stages_count' => 1],
            ['level' => 'B2', 'levels' => ['B2'], 'course_slug' => 'english-b2', 'title' => 'Ingliz tili B2', 'level_label' => 'B2', 'stages_count' => 1],
            ['level' => 'C1', 'levels' => ['C1'], 'course_slug' => 'english-c1', 'title' => 'Ingliz tili C1', 'level_label' => 'C1', 'stages_count' => 1],
            ['level' => 'C2', 'levels' => ['C2'], 'course_slug' => 'english-c2', 'title' => 'Ingliz tili C2', 'level_label' => 'C2', 'stages_count' => 1],
        ]);
});

it('lifts the lock of the daily goal for Pro, resumes on the saved step and shows the course of the level chosen in the bot', function (): void {
    obCatalog();
    $learner = Learner::factory()->withTwaOnboarding('daily_goal')->create(['level' => 'B1']);
    Subscription::factory()->create(['subscriber_id' => $learner->id]);
    app(EntitlementResolver::class)->forget($learner);

    $this->withHeaders(obHeaders($learner))->getJson('/api/twa/onboarding')->assertOk()
        ->assertJsonPath('options.daily_goal_max', null)
        ->assertJsonPath('step', 'daily_goal')
        ->assertJsonPath('values.course', ['slug' => 'english-b1', 'title' => 'Английский B1', 'level_label' => 'B1', 'stages_count' => 1]);
});

it('lists no level when the catalog has no general course, and answers 401 without a token', function (): void {
    Fx::course([1 => [1 => 3]], ['slug' => 'phrases', 'exam_tag' => 'phrases']);
    $learner = Learner::factory()->create();

    $this->withHeaders(obHeaders($learner))->getJson('/api/twa/onboarding')->assertOk()->assertJsonPath('options.levels', []);
    $this->flushHeaders();
    $this->getJson('/api/twa/onboarding')->assertUnauthorized();
    $this->putJson('/api/twa/onboarding/steps/age', ['age_range' => '18_24'])->assertUnauthorized();
    $this->postJson('/api/twa/onboarding/complete')->assertUnauthorized();
});

// --- PUT /onboarding/steps/{step} -----------------------------------------------------------------------------------------------

it('saves every step at once, moves the screen on and answers with the state', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-12-02 10:00:00', 'UTC')); // 15:00 in Tashkent
    obCatalog();
    $learner = Learner::factory()->create(['timezone' => 'Asia/Tashkent', 'daily_goal' => 10, 'reminder_time' => '19:00']);

    obPut($this, $learner, 'name', ['display_name' => '  Азиз  '])->assertOk()->assertExactJsonStructure(OB_STATE)
        ->assertJsonPath('step', 'age')->assertJsonPath('values.display_name', 'Азиз');
    obPut($this, $learner, 'age', ['age_range' => '18_24'])->assertOk()->assertJsonPath('step', 'goals')->assertJsonPath('values.age_range', '18_24');
    obPut($this, $learner, 'goals', ['goals' => ['career', 'travel']])->assertOk()->assertJsonPath('step', 'level')->assertJsonPath('values.goals', ['career', 'travel']);
    obPut($this, $learner, 'level', ['level' => 'A2', 'course_slug' => 'english-a1'])->assertOk()->assertJsonPath('step', 'daily_goal')
        ->assertJsonPath('values.level', 'A2')
        ->assertJsonPath('values.course', ['slug' => 'english-a1', 'title' => 'Английский A1', 'level_label' => 'A1–A2', 'stages_count' => 2]);
    obPut($this, $learner, 'daily_goal', ['daily_goal' => 10])->assertOk()->assertJsonPath('step', 'reminder')->assertJsonPath('values.daily_goal', 10);
    obPut($this, $learner, 'reminder', ['enabled' => true, 'time' => '20:00', 'write_access' => 'granted'])->assertOk()
        ->assertJsonPath('step', 'plan')->assertJsonPath('values.reminder', ['enabled' => true, 'time' => '20:00']);

    $fresh = $learner->fresh();
    expect($fresh?->display_name)->toBe('Азиз')
        ->and($fresh?->age_range)->toBe('18_24')
        ->and($fresh?->goals)->toBe(['career', 'travel'])
        ->and($fresh?->level)->toBe('A2')
        ->and($fresh?->reminder_time)->toStartWith('20:00')
        // 20:00 in Tashkent, today.
        ->and($fresh?->next_reminder_at?->utc()->toDateTimeString())->toBe('2026-12-02 15:00:00')
        // The bot's state machine is not touched, the course is chosen only at the end.
        ->and($fresh?->onboarding_state)->toBe('new')
        ->and($fresh?->active_course_id)->toBeNull()
        ->and($fresh?->meta['twa_onboarding'])->toMatchArray(['step' => 'plan', 'course_slug' => 'english-a1', 'answered' => ['name', 'age', 'goals', 'level', 'daily_goal', 'reminder']]);

    $steps = array_values(array_filter(obEvents($learner), fn (array $e): bool => $e['name'] === 'onboarding.step_completed'));
    expect(obEventNames($learner))->toBe(['onboarding.started', ...array_fill(0, 6, 'onboarding.step_completed')])
        ->and(obEvents($learner)[0]['props'])->toEqual(['channel' => 'mini_app', 'source_kind' => 'direct'])
        ->and(array_column(array_column($steps, 'props'), 'step'))->toBe(['name', 'age', 'goals', 'level', 'daily_goal', 'reminder'])
        ->and($steps[0]['props'])->toEqual(['step' => 'name', 'channel' => 'mini_app'])
        ->and($steps[5]['props'])->toEqual(['step' => 'reminder', 'channel' => 'mini_app', 'write_access' => 'granted']);
});

it('accepts the steps in any order (back and change) and gives one event per step and per real change', function (): void {
    obCatalog();
    $learner = Learner::factory()->create();

    obPut($this, $learner, 'goals', ['goals' => ['media']])->assertOk()->assertJsonPath('step', 'level');
    obPut($this, $learner, 'goals', ['goals' => ['media']])->assertOk(); // the same request again: nothing new
    obPut($this, $learner, 'name', ['display_name' => 'Ali'])->assertOk()->assertJsonPath('step', 'age'); // back to O2
    obPut($this, $learner, 'name', ['display_name' => 'Ali'])->assertOk();
    obPut($this, $learner, 'goals', ['goals' => ['media', 'study']])->assertOk(); // a real change

    expect(obEventNames($learner))->toBe(['onboarding.started', 'onboarding.step_completed', 'onboarding.step_completed', 'onboarding.step_completed'])
        ->and($learner->fresh()?->goals)->toBe(['media', 'study']);
});

it('records onboarding.started only for a learner the bot has not started, and keeps the bot state of one in the middle of it', function (): void {
    $learner = Learner::factory()->create(['onboarding_state' => 'level']);

    obPut($this, $learner, 'age', ['age_range' => '45p'])->assertOk()->assertJsonPath('step', 'goals');

    expect(obEventNames($learner))->toBe(['onboarding.step_completed'])
        ->and($learner->fresh()?->onboarding_state)->toBe('level');
});

it('refuses a name the rules of names refuse, with the reason, and changes nothing', function (mixed $name, string $reason): void {
    $learner = Learner::factory()->create();

    obPut($this, $learner, 'name', ['display_name' => $name])->assertStatus(422)
        ->assertExactJson(['error' => ['code' => 'display_name_invalid', 'message' => $reason === 'empty' || $reason === 'too_long' ? 'The name must be 1 to 32 characters.' : ($reason === 'marks' ? 'The name has too many combining marks.' : 'The name must not look like a link, a @mention, a /command or a #tag.'), 'details' => ['reason' => $reason]]]);

    expect($learner->fresh()?->display_name)->toBeNull()
        ->and(obEventNames($learner))->toBe([]);
})->with([
    'a link' => ['see t.me/spam', 'link'],
    'a site' => ['https://x.uz', 'link'],
    'a mention' => ['@spam', 'mention'],
    'a command' => ['/start', 'command'],
    'a tag' => ['#promo', 'tag'],
    'zalgo' => ["Zq\u{0301}\u{0302}\u{0303}\u{0304}a", 'marks'],
    '33 graphemes' => [str_repeat('я', 33), 'too_long'],
    'blank' => ['   ', 'empty'],
    'empty' => ['', 'empty'],
    'null' => [null, 'empty'],
]);

it('takes a name of exactly 32 graphemes', function (): void {
    $learner = Learner::factory()->create();

    obPut($this, $learner, 'name', ['display_name' => str_repeat('я', 32)])->assertOk();

    expect($learner->fresh()?->display_name)->toBe(str_repeat('я', 32));
});

it('refuses an invalid value of every step with 422 and changes nothing', function (string $step, array $body, string $field): void {
    obCatalog();
    $learner = Learner::factory()->create(['daily_goal' => 10, 'reminder_time' => '19:00']);
    $before = $learner->fresh()?->getAttributes();

    obPut($this, $learner, $step, $body)->assertStatus(422)->assertJsonValidationErrors($field);

    expect($learner->fresh()?->getAttributes())->toBe($before)
        ->and(obEventNames($learner))->toBe([]);
})->with([
    'a name that is no string' => ['name', ['display_name' => 42], 'display_name'],
    'no name at all' => ['name', [], 'display_name'],
    'an unknown age' => ['age', ['age_range' => '14-17'], 'age_range'],
    'an age as a number' => ['age', ['age_range' => 18], 'age_range'],
    'no goals' => ['goals', ['goals' => []], 'goals'],
    'seven goals' => ['goals', ['goals' => ['career', 'study', 'travel', 'relocation', 'communication', 'media', 'career']], 'goals'],
    'a repeated goal' => ['goals', ['goals' => ['career', 'career']], 'goals.0'],
    'an unknown goal' => ['goals', ['goals' => ['money']], 'goals.0'],
    'goals as an object' => ['goals', ['goals' => ['a' => 'career']], 'goals'],
    'goals as a string' => ['goals', ['goals' => 'career'], 'goals'],
    'an unknown level' => ['level', ['level' => 'D1', 'course_slug' => 'english-a1'], 'level'],
    'the course of another level' => ['level', ['level' => 'B1', 'course_slug' => 'english-a1'], 'course_slug'],
    'an unknown course' => ['level', ['level' => 'B1', 'course_slug' => 'nope'], 'course_slug'],
    'the course of phrases' => ['level', ['level' => 'A1', 'course_slug' => 'phrases'], 'course_slug'],
    'no course' => ['level', ['level' => 'B1'], 'course_slug'],
    'a goal not offered' => ['daily_goal', ['daily_goal' => 30], 'daily_goal'],
    'a goal as a string' => ['daily_goal', ['daily_goal' => '20'], 'daily_goal'],
    'a time off the quarter' => ['reminder', ['enabled' => true, 'time' => '20:10', 'write_access' => 'granted'], 'time'],
    'a time out of the day' => ['reminder', ['enabled' => true, 'time' => '24:00', 'write_access' => 'granted'], 'time'],
    'on without a time' => ['reminder', ['enabled' => true, 'time' => null, 'write_access' => 'granted'], 'time'],
    'enabled as a string' => ['reminder', ['enabled' => 'true', 'time' => '20:00', 'write_access' => 'granted'], 'enabled'],
    'no write_access' => ['reminder', ['enabled' => true, 'time' => '20:00'], 'write_access'],
    'an unknown write_access' => ['reminder', ['enabled' => true, 'time' => '20:00', 'write_access' => 'maybe'], 'write_access'],
]);

it('answers 404 for a step that is not one', function (string $step): void {
    $learner = Learner::factory()->create();

    obPut($this, $learner, $step, [])->assertNotFound();
})->with(['plan', 'locale', 'region', 'NAME', 'complete']);

it('turns the reminders off with "Do not remind" and recomputes next_reminder_at', function (): void {
    $learner = Learner::factory()->create(['reminder_time' => '19:00', 'next_reminder_at' => now()->addHour()]);

    obPut($this, $learner, 'reminder', ['enabled' => false, 'time' => null, 'write_access' => 'not_asked'])->assertOk()
        ->assertJsonPath('values.reminder', ['enabled' => false, 'time' => null]);

    expect($learner->fresh()?->reminder_time)->toBeNull()
        ->and($learner->fresh()?->next_reminder_at)->toBeNull()
        ->and(collect(obEvents($learner))->firstWhere('name', 'onboarding.step_completed')['props'] ?? null)
        ->toEqual(['step' => 'reminder', 'channel' => 'mini_app', 'write_access' => 'not_asked'])
        // A first answer of the onboarding, not a learner turning reminders off: the guardrail of the reminder experiment counts
        // every `reminders.disabled` whatever its `via`, so none is written here (nor `settings.changed`).
        ->and(array_column(obEvents($learner), 'name'))->toBe(['onboarding.started', 'onboarding.step_completed']);
});

it('records only the events of the onboarding for the steps written through the settings: no settings.changed, no reminders.disabled', function (): void {
    obCatalog();
    $learner = Learner::factory()->create(['daily_goal' => 20, 'reminder_time' => '19:00', 'level' => null]);
    Subscription::factory()->create(['subscriber_id' => $learner->id]);
    app(EntitlementResolver::class)->forget($learner);

    obPut($this, $learner, 'level', ['level' => 'B1', 'course_slug' => 'english-b1'])->assertOk();
    obPut($this, $learner, 'daily_goal', ['daily_goal' => 40])->assertOk();
    obPut($this, $learner, 'reminder', ['enabled' => true, 'time' => '08:00', 'write_access' => 'granted'])->assertOk();
    obPut($this, $learner, 'reminder', ['enabled' => false, 'time' => null, 'write_access' => 'denied'])->assertOk();

    expect($learner->fresh()?->reminder_time)->toBeNull()
        ->and(array_column(obEvents($learner), 'name'))->toBe(['onboarding.started', ...array_fill(0, 4, 'onboarding.step_completed')]);

    // The same columns changed in the settings afterwards still record both.
    $learner->update(['onboarding_state' => 'done']);
    $this->withHeaders(obHeaders($learner))->patchJson('/api/twa/me/settings', ['reminder_time' => '20:00'])->assertOk();
    $this->withHeaders(obHeaders($learner))->patchJson('/api/twa/me/settings', ['reminder_time' => null])->assertOk();
    $settings = array_values(array_filter(obEvents($learner), fn (array $e): bool => ! str_starts_with($e['name'], 'onboarding.')));
    expect(array_column($settings, 'name'))->toBe(['settings.changed', 'settings.changed', 'reminders.disabled'])
        ->and($settings[2]['props'])->toEqual(['via' => 'settings']);
});

it('refuses a daily goal above the Free limit with 403 daily_goal_pro_required {max} and lets Pro take 40', function (): void {
    $free = Learner::factory()->create(['daily_goal' => 10]);

    obPut($this, $free, 'daily_goal', ['daily_goal' => 20])->assertForbidden()
        ->assertJsonPath('error.code', 'daily_goal_pro_required')->assertJsonPath('error.details', ['max' => 10]);
    expect($free->fresh()?->daily_goal)->toBe(10)
        ->and($free->fresh()?->meta['twa_onboarding'] ?? null)->toBeNull();

    $pro = Learner::factory()->create(['daily_goal' => 10]);
    Subscription::factory()->create(['subscriber_id' => $pro->id]);
    app(EntitlementResolver::class)->forget($pro);

    obPut($this, $pro, 'daily_goal', ['daily_goal' => 40])->assertOk()->assertJsonPath('values.daily_goal', 40);
    expect($pro->fresh()?->daily_goal)->toBe(40);
});

it('answers 409 onboarding_completed to any step of a learner who is done, and changes nothing', function (): void {
    obCatalog();
    $learner = Learner::factory()->onboarded()->create(['level' => 'B1', 'daily_goal' => 10]);

    foreach ([
        ['name', ['display_name' => 'Ali']],
        ['age', ['age_range' => '18_24']],
        ['goals', ['goals' => ['career']]],
        ['level', ['level' => 'A1', 'course_slug' => 'english-a1']],
        ['daily_goal', ['daily_goal' => 40]],
        ['reminder', ['enabled' => false, 'time' => null, 'write_access' => 'denied']],
    ] as [$step, $body]) {
        obPut($this, $learner, $step, $body)->assertStatus(409)->assertExactJson(['error' => ['code' => 'onboarding_completed', 'message' => 'The onboarding is already completed.']]);
    }

    expect($learner->fresh()?->display_name)->toBeNull()
        ->and($learner->fresh()?->level)->toBe('B1')
        ->and(obEvents($learner))->toBe([]);
});

it('answers 409 to a step when the bot finished the onboarding after the Mini App loaded it', function (): void {
    obCatalog();
    $learner = Learner::factory()->create(['onboarding_state' => 'reminder', 'level' => 'B1']);
    $this->withHeaders(obHeaders($learner))->getJson('/api/twa/onboarding')->assertOk()->assertJsonPath('completed', false);

    app(OnboardingService::class)->apply($learner, 'reminder', '2100');

    obPut($this, $learner, 'goals', ['goals' => ['career']])->assertStatus(409)->assertJsonPath('error.code', 'onboarding_completed');
    $this->withHeaders(obHeaders($learner))->getJson('/api/twa/onboarding')->assertOk()->assertJsonPath('completed', true)->assertJsonPath('step', null);
});

it('caps the steps at 30 a minute per learner', function (): void {
    $learner = Learner::factory()->create();

    for ($i = 0; $i < 30; $i++) {
        obPut($this, $learner, 'age', ['age_range' => '18_24'])->assertOk();
    }
    obPut($this, $learner, 'age', ['age_range' => '18_24'])->assertStatus(429);
    $this->withHeaders(obHeaders($learner))->postJson('/api/twa/onboarding/complete')->assertStatus(429);

    // Another learner has a bucket of their own.
    obPut($this, Learner::factory()->create(), 'age', ['age_range' => '18_24'])->assertOk();
});

// --- POST /onboarding/complete --------------------------------------------------------------------------------------------------

it('refuses to complete before the level is chosen', function (): void {
    $learner = Learner::factory()->create();

    $this->withHeaders(obHeaders($learner))->postJson('/api/twa/onboarding/complete')->assertStatus(422)
        ->assertExactJson(['error' => ['code' => 'onboarding_incomplete', 'message' => 'The onboarding is not finished yet.', 'details' => ['missing' => ['level']]]]);

    expect($learner->fresh()?->onboarding_state)->toBe('new');
});

it('completes the onboarding where the bot does: done, the course of the level, the reminder, the events; a repeat changes nothing', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-12-02 10:00:00', 'UTC'));
    ['a1' => $a1, 'a1_lessons' => $lessons] = obCatalog();
    $learner = Learner::factory()->create(['timezone' => 'Asia/Tashkent', 'goal' => null, 'interface_locale' => 'ru']);
    obPut($this, $learner, 'level', ['level' => 'A2', 'course_slug' => 'english-a1'])->assertOk();
    obPut($this, $learner, 'reminder', ['enabled' => true, 'time' => '21:30', 'write_access' => 'denied'])->assertOk();

    $this->withHeaders(obHeaders($learner))->postJson('/api/twa/onboarding/complete')->assertOk()
        ->assertExactJson(['completed' => true, 'course' => ['slug' => 'english-a1', 'title' => 'Английский A1'], 'next_lesson_id' => $lessons['1.1']->id]);

    $fresh = $learner->fresh();
    expect($fresh?->onboarding_state)->toBe('done')
        ->and($fresh?->onboarding_completed_at?->toDateTimeString())->toBe('2026-12-02 10:00:00')
        ->and($fresh?->active_course_id)->toBe($a1->id)
        ->and($fresh?->goal)->toBe('general') // the skipped goal step of the bot
        ->and($fresh?->next_reminder_at?->utc()->toDateTimeString())->toBe('2026-12-02 16:30:00');

    $completed = collect(obEvents($learner))->firstWhere('name', 'onboarding.completed');
    expect($completed['props'] ?? null)->toEqual(['goal' => 'general', 'level' => 'A2', 'locale' => 'ru', 'channel' => 'mini_app'])
        ->and(collect(obEvents($learner))->firstWhere('name', 'course.selected')['props'] ?? null)->toEqual(['course_id' => $a1->id, 'via' => 'onboarding']);

    // The second press (a double tap, a retry): the same answer, nothing written, no second event.
    $events = count(obEvents($learner));
    $this->withHeaders(obHeaders($learner))->postJson('/api/twa/onboarding/complete')->assertOk()
        ->assertExactJson(['completed' => true, 'course' => ['slug' => 'english-a1', 'title' => 'Английский A1'], 'next_lesson_id' => $lessons['1.1']->id]);
    expect(obEvents($learner))->toHaveCount($events)
        ->and($learner->fresh()?->onboarding_completed_at?->toDateTimeString())->toBe('2026-12-02 10:00:00');

    // /auth-style fields of /me follow; the steps are closed now.
    $this->withHeaders(obHeaders($learner))->getJson('/api/twa/me')->assertOk()
        ->assertJsonPath('learner.onboarding', ['completed' => true, 'step' => null])->assertJsonPath('learner.onboarding_completed', true);
    obPut($this, $learner, 'name', ['display_name' => 'Ali'])->assertStatus(409);
});

it('falls back like the bot: the course of a c_<slug> link first, the learner\'s own course kept, the default course with words', function (): void {
    obCatalog();
    $linked = Fx::course([1 => [1 => 2]], ['slug' => 'linked', 'title' => ['ru' => 'По ссылке']])['course'];

    $byLink = Learner::factory()->create(['level' => 'B1', 'meta' => ['pending_course' => 'linked']]);
    $this->withHeaders(obHeaders($byLink))->postJson('/api/twa/onboarding/complete')->assertOk()->assertJsonPath('course.slug', 'linked');
    expect($byLink->fresh()?->active_course_id)->toBe($linked->id)
        ->and(collect(obEvents($byLink))->firstWhere('name', 'course.selected')['props']['via'] ?? null)->toBe('deeplink');

    $own = Learner::factory()->create(['level' => 'B1', 'active_course_id' => $linked->id]);
    $this->withHeaders(obHeaders($own))->postJson('/api/twa/onboarding/complete')->assertOk()->assertJsonPath('course.slug', 'linked');
    expect($own->fresh()?->active_course_id)->toBe($linked->id)
        ->and(collect(obEvents($own))->firstWhere('name', 'course.selected'))->toBeNull();
});

it('takes the default course when the catalog has no general course, and none when it has no words', function (): void {
    $learner = Learner::factory()->create(['level' => 'B1']);
    $this->withHeaders(obHeaders($learner))->postJson('/api/twa/onboarding/complete')->assertOk()
        ->assertExactJson(['completed' => true, 'course' => null, 'next_lesson_id' => null]);
    expect($learner->fresh()?->onboarding_state)->toBe('done')->and($learner->fresh()?->active_course_id)->toBeNull();

    // The default course the first completion created (empty, so not taken) gets words now.
    $default = Course::query()->where('slug', (string) config('content.default_course_slug'))->firstOrFail();
    Fx::words(Lesson::factory()->create(['stage_id' => Stage::factory()->create(['course_id' => $default->id])->id]), 2);
    app(CoursePublisher::class)->recount($default);
    $second = Learner::factory()->create(['level' => 'B1']);
    $this->withHeaders(obHeaders($second))->postJson('/api/twa/onboarding/complete')->assertOk()->assertJsonPath('course.slug', $default->slug);
});

it('lets a learner in the middle of the bot onboarding finish in the Mini App; the old bot button is stale then', function (): void {
    obCatalog();
    $learner = Learner::factory()->create(['onboarding_state' => 'level', 'interface_locale' => 'uz']);

    obPut($this, $learner, 'level', ['level' => 'B1', 'course_slug' => 'english-b1'])->assertOk();
    $this->withHeaders(obHeaders($learner))->postJson('/api/twa/onboarding/complete')->assertOk()->assertJsonPath('course.slug', 'english-b1');

    expect($learner->fresh()?->onboarding_state)->toBe('done');
    expect(fn () => app(OnboardingService::class)->apply($learner, 'level', 'A1'))
        ->toThrow(OnboardingException::class, 'Onboarding step [level] is not the current one.');
    expect($learner->fresh()?->level)->toBe('B1');
});

it('takes a passive learner of a group (created without /start) through the Mini App onboarding', function (): void {
    obCatalog();
    $learner = Learner::factory()->memberOf(TelegramGroup::factory()->create(['status' => 'active']))->create(['last_seen_at' => null, 'acquisition_source' => 'group:1']);

    obPut($this, $learner, 'level', ['level' => 'C1', 'course_slug' => 'english-c1'])->assertOk();
    $this->withHeaders(obHeaders($learner))->postJson('/api/twa/onboarding/complete')->assertOk()->assertJsonPath('course.slug', 'english-c1');

    expect(collect(obEvents($learner))->firstWhere('name', 'onboarding.started'))->toEqual(['name' => 'onboarding.started', 'props' => ['channel' => 'mini_app', 'source_kind' => 'group']]);
});

it('does not run the onboarding again for a learner restored after deletion', function (): void {
    $learner = Learner::factory()->onboarded()->create(['level' => 'A1']);
    $learner->delete();
    $learner->restore();

    $this->withHeaders(obHeaders($learner))->getJson('/api/twa/onboarding')->assertOk()->assertJsonPath('completed', true);
    obPut($this, $learner, 'age', ['age_range' => '25_34'])->assertStatus(409);
    $this->withHeaders(obHeaders($learner))->postJson('/api/twa/onboarding/complete')->assertOk()->assertJsonPath('completed', true);
    expect(obEvents($learner))->toBe([]);
});

// --- /auth and /me ----------------------------------------------------------------------------------------------------------------

it('gives /auth and /me the chosen name and where the onboarding resumes, and keeps first_name the Telegram one', function (): void {
    Config::set('telegram.bot_token', 'test-bot-token-1234567890');
    $learner = Learner::factory()->withDisplayName('Азиз')->withTwaOnboarding('goals')->create(['telegram_user_id' => 4242, 'first_name' => 'Old']);
    $initData = InitDataValidator::sign('test-bot-token-1234567890', ['user' => json_encode(['id' => 4242, 'first_name' => 'Aziz']), 'auth_date' => (string) time()]);

    $this->postJson('/api/twa/auth', ['init_data' => $initData])->assertOk()
        ->assertJsonPath('learner.first_name', 'Aziz') // the snapshot of Telegram, refreshed
        ->assertJsonPath('learner.display_name', 'Азиз') // the learner's own choice, untouched
        ->assertJsonPath('learner.onboarding', ['completed' => false, 'step' => 'goals']);

    $this->withHeaders(obHeaders($learner))->getJson('/api/twa/me')->assertOk()
        ->assertJsonPath('learner.display_name', 'Азиз')
        ->assertJsonPath('learner.onboarding', ['completed' => false, 'step' => 'goals']);

    // A step the client does not know (a broken meta) is no step: the welcome screen.
    $learner->update(['meta' => ['twa_onboarding' => ['step' => 'nonsense']]]);
    $this->withHeaders(obHeaders($learner))->getJson('/api/twa/me')->assertOk()->assertJsonPath('learner.onboarding.step', null);
});

it('shows neither the age nor the goals in /me', function (): void {
    $learner = Learner::factory()->create(['age_range' => 'lt14', 'goals' => ['media']]);

    $body = (string) $this->withHeaders(obHeaders($learner))->getJson('/api/twa/me')->assertOk()->getContent();

    expect($body)->not->toContain('age_range')->not->toContain('lt14')->not->toContain('"media"');
});

// --- the catalog: stages_count and the version of its cache ---------------------------------------------------------------------

it('gives GET /courses the number of modules from the catalog cache, under the v2 key (an entry of v1 has no such field)', function (): void {
    obCatalog();
    // What a running v1 process left behind: the old shape, never read by v2.
    PureCache::store()->put('catalog:courses:published:v1:ru', [['id' => 1, 'slug' => 'stale', 'title' => 'x', 'description' => null, 'level' => null, 'exam_tag' => null, 'words_count' => 1, 'is_featured' => false]], 600);
    $learner = Learner::factory()->create(['interface_locale' => 'ru']);

    $courses = collect($this->withHeaders(obHeaders($learner))->getJson('/api/twa/courses')->assertOk()->json('courses'))->keyBy('slug');

    expect($courses->keys()->all())->not->toContain('stale')
        ->and($courses['english-a1']['stages_count'] ?? null)->toBe(2)
        ->and($courses['english-b1']['stages_count'] ?? null)->toBe(1)
        ->and(PureCache::store()->get('catalog:courses:published:v2:ru'))->toBeArray()
        ->and(PureCache::store()->get('catalog:courses:published:v2:ru')[0])->toHaveKey('stages_count');
});

// --- The course of the summary is the course completion sets (review of 27.11.2026) ---------------------------------------------

it('makes the course of the level screen the active one even when the bot wrote another goal, and falls back when it is gone', function (): void {
    ['b1' => $b1] = obCatalog();
    $ielts = Fx::course([1 => [1 => 3]], ['slug' => 'ielts-b1', 'exam_tag' => 'ielts', 'level' => 'B1', 'title' => ['ru' => 'IELTS B1']])['course'];

    // The bot asked the goal (IELTS) and stopped on the level; the Mini App chose the level and its course.
    $learner = Learner::factory()->create(['onboarding_state' => 'level', 'goal' => 'ielts', 'interface_locale' => 'ru']);
    obPut($this, $learner, 'level', ['level' => 'B1', 'course_slug' => 'english-b1'])->assertOk()
        ->assertJsonPath('values.course.slug', 'english-b1');

    $this->withHeaders(obHeaders($learner))->postJson('/api/twa/onboarding/complete')->assertOk()
        ->assertJsonPath('course', ['slug' => 'english-b1', 'title' => 'Английский B1']);
    expect($learner->fresh()?->active_course_id)->toBe($b1->id)
        ->and($learner->fresh()?->goal)->toBe('ielts')
        ->and(collect(obEvents($learner))->firstWhere('name', 'course.selected')['props'] ?? null)->toEqual(['course_id' => $b1->id, 'via' => 'onboarding']);
    $this->withHeaders(obHeaders($learner))->getJson('/api/twa/onboarding')->assertOk()->assertJsonPath('values.course.slug', 'english-b1');

    // The chosen course left the catalog between the level screen and the summary: the bot's own choice, recommend(goal, level).
    $late = Learner::factory()->create(['onboarding_state' => 'level', 'goal' => 'ielts']);
    obPut($this, $late, 'level', ['level' => 'B1', 'course_slug' => 'english-b1'])->assertOk();
    $b1->update(['status' => 'archived']);
    $this->withHeaders(obHeaders($late))->postJson('/api/twa/onboarding/complete')->assertOk()->assertJsonPath('course.slug', 'ielts-b1');
    expect($late->fresh()?->active_course_id)->toBe($ielts->id);
});

it('keeps an active course on completion and shows it, not the course of the level screen, on the summary and in the answer', function (): void {
    obCatalog();
    $public = Fx::course([1 => [1 => 3]], ['slug' => 'business', 'level' => 'B2', 'title' => ['ru' => 'Деловой английский']])['course'];
    $unlisted = Fx::course([1 => [1 => 3]], ['slug' => 'by-link', 'visibility' => 'unlisted', 'title' => ['ru' => 'По ссылке']])['course'];

    foreach ([[$public, ['slug' => 'business', 'title' => 'Деловой английский', 'level_label' => 'B2', 'stages_count' => 1]],
        [$unlisted, ['slug' => 'by-link', 'title' => 'По ссылке', 'level_label' => (string) $unlisted->level, 'stages_count' => null]]] as [$course, $shown]) {
        $learner = Learner::factory()->create(['active_course_id' => $course->id, 'interface_locale' => 'ru']);

        obPut($this, $learner, 'level', ['level' => 'A1', 'course_slug' => 'english-a1'])->assertOk()->assertJsonPath('values.course', $shown);
        $this->withHeaders(obHeaders($learner))->getJson('/api/twa/onboarding')->assertOk()->assertJsonPath('values.course', $shown);

        $this->withHeaders(obHeaders($learner))->postJson('/api/twa/onboarding/complete')->assertOk()
            ->assertJsonPath('course', ['slug' => $shown['slug'], 'title' => $shown['title']]);
        expect($learner->fresh()?->active_course_id)->toBe($course->id);
        $this->withHeaders(obHeaders($learner))->getJson('/api/twa/onboarding')->assertOk()
            ->assertJsonPath('completed', true)->assertJsonPath('values.course', $shown);
    }
});

it('drops the draft of the placement test and the lists of the steps from meta on completion, in the same UPDATE', function (): void {
    obCatalog();
    $learner = Learner::factory()->create(['level' => 'B1', 'meta' => [
        'locale_chosen' => true,
        'twa_onboarding' => [
            'step' => 'plan', 'started_at' => '2026-11-27T10:00:00+00:00', 'answered' => ['name', 'level'], 'course_slug' => 'english-b1',
            'placement' => ['set_id' => str_repeat('a', 32), 'items' => [['id' => 1, 'word_id' => 1, 'level' => 'A1', 'correct' => 2, 'n' => 4]], 'issued_at' => '2026-11-27T10:01:00+00:00', 'expires_at' => '2026-11-27T10:21:00+00:00'],
        ],
    ]]);

    $this->withHeaders(obHeaders($learner))->postJson('/api/twa/onboarding/complete')->assertOk()->assertJsonPath('course.slug', 'english-b1');

    expect($learner->fresh()?->meta)->toBe([
        'locale_chosen' => true,
        'twa_onboarding' => ['started_at' => '2026-11-27T10:00:00+00:00', 'course_slug' => 'english-b1'],
    ]);

    // A repeated completion, the state and the placement routes of a learner who is done keep working.
    $this->withHeaders(obHeaders($learner))->postJson('/api/twa/onboarding/complete')->assertOk()->assertJsonPath('course.slug', 'english-b1');
    $this->withHeaders(obHeaders($learner))->getJson('/api/twa/onboarding')->assertOk()
        ->assertJsonPath('completed', true)->assertJsonPath('step', null)->assertJsonPath('values.course.slug', 'english-b1');
    $this->withHeaders(obHeaders($learner))->postJson('/api/twa/onboarding/placement/answers', ['set_id' => str_repeat('a', 32), 'answers' => [2]])
        ->assertStatus(409);

    // A learner who never opened the onboarding of the Mini App gets no key at all.
    $plain = Learner::factory()->create(['level' => 'B1', 'meta' => ['locale_chosen' => true]]);
    $this->withHeaders(obHeaders($plain))->postJson('/api/twa/onboarding/complete')->assertOk();
    expect($plain->fresh()?->meta)->toBe(['locale_chosen' => true]);
});

it('offers only the levels with a course of their own when the catalog is incomplete (and the next level in the row of a lower one)', function (): void {
    $make = fn (string $level): Course => Fx::course([1 => [1 => 3]], [
        'slug' => 'english-'.strtolower($level), 'exam_tag' => 'general', 'level' => $level, 'title' => ['ru' => 'Английский '.$level],
    ])['course'];
    $make('A1');
    $make('C1');
    $learner = Learner::factory()->create(['interface_locale' => 'ru']);

    // Without B1, B2 and C2 every one of them falls back to the first course of the catalog (A1): only A2, the next level, joins its row.
    $this->withHeaders(obHeaders($learner))->getJson('/api/twa/onboarding')->assertOk()
        ->assertJsonPath('options.levels', [
            ['level' => 'A1', 'levels' => ['A1', 'A2'], 'course_slug' => 'english-a1', 'title' => 'Английский A1', 'level_label' => 'A1–A2', 'stages_count' => 1],
            ['level' => 'C1', 'levels' => ['C1'], 'course_slug' => 'english-c1', 'title' => 'Английский C1', 'level_label' => 'C1', 'stages_count' => 1],
        ]);

    // A level left out cannot be saved with any course.
    obPut($this, $learner, 'level', ['level' => 'B2', 'course_slug' => 'english-a1'])->assertStatus(422)->assertJsonValidationErrors('course_slug');
    obPut($this, $learner, 'level', ['level' => 'C2', 'course_slug' => 'english-a1'])->assertStatus(422)->assertJsonValidationErrors('course_slug');
    obPut($this, $learner, 'level', ['level' => 'A2', 'course_slug' => 'english-a1'])->assertOk()->assertJsonPath('values.course.level_label', 'A1–A2');
});
