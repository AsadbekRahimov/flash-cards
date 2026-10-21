<?php

declare(strict_types=1);

/*
 * Regression for the review finding of 1.4b: a Free learner multiplied the daily new-word quota by moving
 * `learners.timezone` to whatever zone had just passed midnight (480 cards in 24 h through PATCH /me/settings).
 *
 * Two rules close it (docs/12 §5.3): the zone may change once per 24 h, and a change carries the running
 * local day into the new zone — the quota window keeps its start and ends at the new zone's first midnight
 * at or after the old day's end, so consecutive quota windows are never shorter than a local day.
 * The probe is kept here as the first test; the others attack the carry rule itself, with the rate limit
 * respected (an adversary who waits 24 h between changes).
 */

use App\Domain\Billing\Services\NewWordQuota;
use App\Domain\Learners\Exceptions\TimezoneChangeTooSoonException;
use App\Domain\Learners\Services\LearnerClock;
use App\Domain\Learners\Services\LearnerSettingsService;
use App\Domain\Learning\Services\CardPicker;
use App\Domain\Learning\Support\TrainingScope;
use App\Domain\Twa\Services\JwtService;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\Word;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(fn () => Cache::flush());

afterEach(fn () => CarbonImmutable::setTestNow());

/** A learner in `$zone` studying a one-lesson course of `$words` words. */
function tzqLearner(int $words, string $zone = 'Asia/Tashkent'): array
{
    $course = Course::factory()->published()->create();
    $stage = Stage::factory()->create(['course_id' => $course->id, 'number' => 1]);
    $lesson = Lesson::factory()->create(['stage_id' => $stage->id, 'number' => 1]);
    foreach (range(1, $words) as $i) {
        Word::query()->create(['lesson_id' => $lesson->id, 'word' => 'probe'.$i, 'translation' => 't'.$i, 'meta' => []]);
    }
    $learner = Learner::factory()->onboarded()->create(['active_course_id' => $course->id, 'timezone' => $zone]);

    return [$learner, $lesson];
}

/** An IANA zone where the wall clock has just passed midnight at `$now` (the probe's heuristic). */
function tzqZoneAtMidnight(CarbonImmutable $now): string
{
    foreach (DateTimeZone::listIdentifiers() as $zone) {
        if ($now->setTimezone($zone)->hour === 0) {
            return $zone;
        }
    }

    throw new RuntimeException('no zone at midnight');
}

/** Introduces new cards through CardPicker + NewWordQuota until the quota stops it; returns how many. */
function tzqSpend(Learner $learner, Lesson $lesson, CarbonImmutable $now): int
{
    $served = 0;
    for ($i = 0; $i < 50; $i++) {
        $pick = app(CardPicker::class)->pickNext((int) $learner->id, TrainingScope::lesson((int) $lesson->id), app(NewWordQuota::class)->budgetFor($learner, $now), $now);
        if (! $pick->hasCard()) {
            break;
        }
        // Grade it like a tab would: never due again inside the test.
        $pick->repetition?->update(['next_review_at' => $now->addYear()]);
        $served++;
    }

    return $served;
}

/** @param list<CarbonImmutable> $times */
function tzqMaxInRolling24h(array $times): int
{
    $max = 0;
    foreach ($times as $start) {
        $max = max($max, count(array_filter($times, fn (CarbonImmutable $t): bool => ! $t->isBefore($start) && $t->isBefore($start->addHours(24)))));
    }

    return $max;
}

it('REGRESSION (review 1.4b): hourly zone hopping through PATCH /me/settings yields one quota, not one per hour', function (): void {
    config()->set('billing.plans.free.limits.new_words_per_day', 5);
    [$learner] = tzqLearner(200);
    $headers = fn () => ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];
    $statuses = [];
    $perHour = [];

    for ($h = 0; $h < 24; $h++) {
        $now = CarbonImmutable::parse('2026-10-19 00:05:00', 'UTC')->addHours($h);
        CarbonImmutable::setTestNow($now);
        // Carbon moved by an hour, but a persistent cache store (CI: Redis) keeps the per-minute throttle
        // counters by wall-clock TTL: end the elapsed hour for them (the quota itself lives in the DB).
        Cache::flush();

        $statuses[] = $this->withHeaders($headers())->patchJson('/api/twa/me/settings', ['timezone' => tzqZoneAtMidnight($now)])->status();

        $before = WordRepetition::query()->where('learner_id', $learner->id)->count();
        $open = $this->withHeaders($headers())->postJson('/api/twa/training/sessions', ['mode' => 'daily_review'])->assertOk();
        $sid = (int) $open->json('session_id');
        $step = $open;
        $guard = 0;
        while ($step->json('card') !== null && $guard++ < 50) {
            $step = $this->withHeaders($headers())->postJson("/api/twa/training/sessions/{$sid}/review", [
                'word_id' => $step->json('card.word_id'), 'quality' => 5, 'time_spent_ms' => 300,
            ])->assertOk();
        }
        $this->withHeaders($headers())->postJson("/api/twa/training/sessions/{$sid}/finish");
        $perHour[] = WordRepetition::query()->where('learner_id', $learner->id)->count() - $before;
    }

    // Only the first change goes through; every other hour is refused with the dedicated code.
    expect($statuses)->toBe([200, ...array_fill(0, 23, 422)])
        ->and($perHour)->toBe([5, ...array_fill(0, 23, 0)])
        ->and(WordRepetition::query()->where('learner_id', $learner->id)->count())->toBe(5);

    CarbonImmutable::setTestNow('2026-10-19 12:00:00');
    $this->withHeaders($headers())->patchJson('/api/twa/me/settings', ['timezone' => 'Asia/Tokyo'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', TimezoneChangeTooSoonException::ERROR_CODE);
});

it('gives an adversary who waits 24 h between changes no more than one quota per local day over a week', function (): void {
    // Every 24 h (as soon as the rate limit allows) the attacker moves to the zone whose midnight has just struck,
    // and spends whatever the quota allows every hour. Baseline without changes: a quota per Tashkent midnight.
    config()->set('billing.plans.free.limits.new_words_per_day', 3);
    [$learner, $lesson] = tzqLearner(100);
    $settings = app(LearnerSettingsService::class);
    $spent = [];
    $changes = 0;

    $start = CarbonImmutable::parse('2026-10-19 18:30:00', 'UTC'); // 23:30 in Tashkent
    for ($h = 0; $h < 7 * 24; $h++) {
        $now = $start->addHours($h);
        CarbonImmutable::setTestNow($now);

        if ($settings->timezoneChangeAvailableAt($learner->fresh(), $now) === null) {
            $zone = tzqZoneAtMidnight($now);
            if ($zone !== $learner->fresh()->timezone) {
                $settings->update($learner, ['timezone' => $zone]);
                $changes++;
            }
        }

        $n = tzqSpend($learner->fresh(), $lesson, $now);
        for ($i = 0; $i < $n; $i++) {
            $spent[] = $now;
        }
    }

    // 7 days hold at most 8 local days (the first and the last partial) — exactly what an honest learner gets.
    expect($changes)->toBeGreaterThanOrEqual(6)
        ->and(count($spent))->toBeLessThanOrEqual(8 * 3)
        ->and(tzqMaxInRolling24h($spent))->toBeLessThanOrEqual(2 * 3);
});

it('keeps the window start through a second change made during the carried day', function (): void {
    config()->set('billing.plans.free.limits.new_words_per_day', 3);
    [$learner, $lesson] = tzqLearner(20);
    $clock = app(LearnerClock::class);
    $settings = app(LearnerSettingsService::class);
    $quota = app(NewWordQuota::class);

    // 00:30 in Tashkent on the 19th: the day is [18th 19:00, 19th 19:00) UTC. Kolkata (UTC+5:30) has its
    // midnight at 18:30 UTC, so its first midnight at or after the Tashkent day's end is the 20th 18:30 UTC.
    CarbonImmutable::setTestNow('2026-10-18 19:30:00');
    expect(tzqSpend($learner, $lesson, CarbonImmutable::now()))->toBe(3);
    $settings->update($learner, ['timezone' => 'Asia/Kolkata']);
    $carry = $learner->fresh()->meta[LearnerClock::DAY_CARRY_META];
    expect($clock->limitWindowUtc($learner->fresh())->endUtc->toDateTimeString())->toBe('2026-10-20 18:30:00');

    // 24 h later Kolkata has had its own midnight (19th 18:30 UTC) but the carry still holds: a further move to
    // Tokyo keeps the carried record, so the window start stays and the end is recomputed for Tokyo (UTC+9).
    CarbonImmutable::setTestNow('2026-10-19 19:30:00');
    expect($quota->remaining($learner->fresh()))->toBe(0);
    $settings->update($learner, ['timezone' => 'Asia/Tokyo']);
    $window = $clock->limitWindowUtc($learner->fresh());

    expect($learner->fresh()->meta[LearnerClock::DAY_CARRY_META])->toBe($carry)
        ->and($window->startUtc->toDateTimeString())->toBe('2026-10-18 19:00:00')
        ->and($window->endUtc->toDateTimeString())->toBe('2026-10-20 15:00:00')
        ->and($quota->remaining($learner->fresh()))->toBe(0);

    CarbonImmutable::setTestNow('2026-10-20 14:59:59');
    expect($quota->remaining($learner->fresh()))->toBe(0);
    CarbonImmutable::setTestNow('2026-10-20 15:00:00');
    expect($quota->remaining($learner->fresh()))->toBe(3);
});

it('leaves a change made in the same zone-offset family harmless: the carried day ends exactly with the old one', function (): void {
    config()->set('billing.plans.free.limits.new_words_per_day', 3);
    [$learner, $lesson] = tzqLearner(20);

    CarbonImmutable::setTestNow('2026-10-19 10:00:00');
    tzqSpend($learner, $lesson, CarbonImmutable::now());
    app(LearnerSettingsService::class)->update($learner, ['timezone' => 'Asia/Karachi']); // also UTC+5

    $window = app(LearnerClock::class)->limitWindowUtc($learner->fresh());
    expect($window->endUtc->toDateTimeString())->toBe('2026-10-19 19:00:00');

    CarbonImmutable::setTestNow('2026-10-19 19:00:00');
    expect(app(NewWordQuota::class)->remaining($learner->fresh()))->toBe(3);
});

it('does not start a carry for a learner whose zone is written outside the settings service (onboarding, admin)', function (): void {
    [$learner] = tzqLearner(5);

    $learner->update(['timezone' => 'Europe/Moscow']);

    expect(app(LearnerClock::class)->dayCarry($learner->fresh()))->toBeNull()
        ->and(app(LearnerSettingsService::class)->timezoneChangeAvailableAt($learner->fresh()))->toBeNull();
});
