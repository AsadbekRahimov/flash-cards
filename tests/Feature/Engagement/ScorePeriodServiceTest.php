<?php

declare(strict_types=1);

/*
 * Fixing the category and the rules version of a learner's week and month (docs/28 «Смена тарифа», docs/32 §2.3, S3): once, at the
 * first credit of the period; a plan change inside the period moves only the periods that start later; the week and the month are
 * independent. The parallel race is in ScorePeriodConcurrencyTest (PostgreSQL).
 */

use App\Domain\Billing\Enums\Plan;
use App\Domain\Engagement\Enums\ScoreCategory;
use App\Domain\Engagement\Enums\ScorePeriod;
use App\Domain\Engagement\Services\Scoring\ScorePeriodService;
use App\Models\Learner;
use App\Models\LearnerScorePeriod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\QueryLog;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('leagues.timezone', 'Asia/Tashkent');
    config()->set('leagues.scoring.mode', 'live');
    config()->set('leagues.scoring.versions.2.starts_on', '2027-02-01');
});

/** A moment of the given Tashkent date and time. */
function spAt(string $local): CarbonImmutable
{
    return CarbonImmutable::parse($local, 'Asia/Tashkent')->utc();
}

it('fixes the week and the month at the first credit with the plan of that moment', function (): void {
    $learner = Learner::factory()->create();
    $now = spAt('2027-02-10 12:00'); // Wednesday

    $fixed = app(ScorePeriodService::class)->fixDay($learner->id, $now, ScoreCategory::fromPlan(Plan::Free), $now);

    expect($fixed['week']->period)->toBe(ScorePeriod::Week)
        ->and($fixed['week']->startsOn)->toBe('2027-02-08')
        ->and($fixed['week']->category)->toBe(ScoreCategory::Free)
        ->and($fixed['week']->rulesVersion)->toBe(2)
        ->and($fixed['week']->created)->toBeTrue()
        ->and($fixed['month']->startsOn)->toBe('2027-02-01')
        ->and($fixed['month']->category)->toBe(ScoreCategory::Free)
        ->and($fixed['month']->created)->toBeTrue()
        ->and(LearnerScorePeriod::query()->where('learner_id', $learner->id)->count())->toBe(2);
});

it('keeps the category of the week and the month after Pro is bought on Thursday; the next week starts in Pro', function (): void {
    $learner = Learner::factory()->create();
    $service = app(ScorePeriodService::class);

    $service->fixDay($learner->id, spAt('2027-02-10 09:00'), ScoreCategory::Free, spAt('2027-02-10 09:00'));

    // Thursday: Pro now. The open week and month stay Free; nothing is created.
    $thursday = $service->fixDay($learner->id, spAt('2027-02-11 20:00'), ScoreCategory::Pro, spAt('2027-02-11 20:00'));
    expect($thursday['week']->category)->toBe(ScoreCategory::Free)
        ->and($thursday['week']->created)->toBeFalse()
        ->and($thursday['month']->category)->toBe(ScoreCategory::Free)
        ->and($thursday['month']->created)->toBeFalse();

    // Monday after: a new week in Pro, the month still Free.
    $monday = $service->fixDay($learner->id, spAt('2027-02-15 00:05'), ScoreCategory::Pro, spAt('2027-02-15 00:05'));
    expect($monday['week']->startsOn)->toBe('2027-02-15')
        ->and($monday['week']->category)->toBe(ScoreCategory::Pro)
        ->and($monday['week']->created)->toBeTrue()
        ->and($monday['month']->category)->toBe(ScoreCategory::Free)
        ->and($monday['month']->created)->toBeFalse();

    // March: the month follows the plan of its first credit.
    $march = $service->fixDay($learner->id, spAt('2027-03-02 10:00'), ScoreCategory::Pro, spAt('2027-03-02 10:00'));
    expect($march['month']->startsOn)->toBe('2027-03-01')
        ->and($march['month']->category)->toBe(ScoreCategory::Pro);
});

it('keeps Pro to the end of the period when the trial or the subscription ends inside it', function (): void {
    $learner = Learner::factory()->create();
    $service = app(ScorePeriodService::class);

    $service->fix($learner->id, ScorePeriod::Week, spAt('2027-02-08 10:00'), ScoreCategory::Pro, spAt('2027-02-08 10:00'));
    $later = $service->fix($learner->id, ScorePeriod::Week, spAt('2027-02-14 23:59'), ScoreCategory::Free, spAt('2027-02-14 23:59'));

    expect($later->category)->toBe(ScoreCategory::Pro)
        ->and($later->created)->toBeFalse()
        ->and($service->find($learner->id, ScorePeriod::Week, spAt('2027-02-12 10:00'))?->category)->toBe(ScoreCategory::Pro)
        ->and($service->find($learner->id, ScorePeriod::Month, spAt('2027-02-12 10:00')))->toBeNull();
});

it('takes the period by the league zone: Sunday evening in UTC is already the next week in Tashkent', function (): void {
    $learner = Learner::factory()->create();
    $now = CarbonImmutable::parse('2027-02-14 19:30', 'UTC'); // Monday 00:30 in Tashkent

    $fixed = app(ScorePeriodService::class)->fixDay($learner->id, $now, ScoreCategory::Free, $now);

    expect($fixed['week']->startsOn)->toBe('2027-02-15')
        ->and($fixed['month']->startsOn)->toBe('2027-02-01');
});

it('writes the version the period starts with and never moves it', function (): void {
    $learner = Learner::factory()->create();
    $service = app(ScorePeriodService::class);

    // The week of 25 January starts before version 2: it is a v1 week to its end; February's month is v2.
    $sunday = spAt('2027-01-31 12:00');
    $before = $service->fixDay($learner->id, $sunday, ScoreCategory::Free, $sunday);
    expect($before['week']->rulesVersion)->toBe(1)
        ->and($before['month']->rulesVersion)->toBe(1);

    $feb = $service->fixDay($learner->id, spAt('2027-02-01 08:00'), ScoreCategory::Free, spAt('2027-02-01 08:00'));
    expect($feb['week']->rulesVersion)->toBe(2)
        ->and($feb['month']->rulesVersion)->toBe(2);

    // The configuration changes afterwards (a rollback to shadow): the fixed periods keep their version.
    config()->set('leagues.scoring.mode', 'shadow');
    $again = $service->fixDay($learner->id, spAt('2027-02-03 08:00'), ScoreCategory::Free, spAt('2027-02-03 08:00'));
    expect($again['week']->rulesVersion)->toBe(2)
        ->and($again['month']->rulesVersion)->toBe(2);
});

it('fixes in shadow mode with version 1', function (): void {
    config()->set('leagues.scoring.mode', 'shadow');
    $learner = Learner::factory()->create();
    $now = spAt('2027-02-10 12:00');

    $fixed = app(ScorePeriodService::class)->fixDay($learner->id, $now, ScoreCategory::Pro, $now);

    expect($fixed['week']->rulesVersion)->toBe(1)
        ->and($fixed['week']->category)->toBe(ScoreCategory::Pro);
});

it('reads both periods with one statement once they are fixed, three on the first credit of a week', function (): void {
    $learner = Learner::factory()->create();
    $service = app(ScorePeriodService::class);
    $service->fixDay($learner->id, spAt('2027-02-10 09:00'), ScoreCategory::Free, spAt('2027-02-10 09:00'));

    $fixedDay = QueryLog::during(fn () => $service->fixDay($learner->id, spAt('2027-02-11 09:00'), ScoreCategory::Free, spAt('2027-02-11 09:00')));
    $newWeek = QueryLog::during(fn () => $service->fixDay($learner->id, spAt('2027-02-15 09:00'), ScoreCategory::Free, spAt('2027-02-15 09:00')));

    expect($fixedDay)->toHaveCount(1)
        ->and($newWeek)->toHaveCount(3);
});

it('keeps the periods of every learner apart', function (): void {
    [$a, $b] = Learner::factory()->count(2)->create();
    $service = app(ScorePeriodService::class);
    $now = spAt('2027-02-10 12:00');

    $service->fixDay($a->id, $now, ScoreCategory::Pro, $now);
    $other = $service->fixDay($b->id, $now, ScoreCategory::Free, $now);

    expect($other['week']->category)->toBe(ScoreCategory::Free)
        ->and($other['week']->created)->toBeTrue();
});
