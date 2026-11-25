<?php

declare(strict_types=1);

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Cup\Enums\CupStatus;
use App\Domain\Cup\Services\CupPilotReport;
use App\Domain\Cup\Services\CupRoundService;
use App\Models\Course;
use App\Models\Cup;
use App\Models\CupParticipant;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Cup\CupKit;
use Tests\Support\QueryLog;

/*
 * docs/33 §8 (K9): `cups:pilot-report` - the cups by status, the funnel of the invitation, the rounds played to the end, the return on
 * day 3, the second cup of a group; a fixed number of grouped statements whatever the number of cups.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CupKit::open();
    CupKit::course();
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('counts the pilot: the funnel, the rounds, the return on day 3 and the second cup', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b, $c], 'start' => $start] = CupKit::running(3);
    $rounds = app(CupRoundService::class);
    // Day 1: a and b play; a answers everything (completed), b runs out of time.
    $view = $rounds->startToday($a, $cup, $start)->view;
    while ($view->current !== null) {
        $view = $rounds->answer($a, $view->attemptId, (string) Str::uuid(), (string) $view->current['id'], ['skip' => true], $start->addSeconds(30))->view;
    }
    $rounds->startToday($b, $cup, $start);
    $rounds->closeExpired($start->addMinutes(15), 100);
    // Day 3: a comes back; b does not.
    $day3 = $start->addDays(2)->addHour();
    CarbonImmutable::setTestNow($day3);
    $rounds->startToday($a, $cup, $day3);
    DB::table('cup_participants')->where('learner_id', $c->id)->update(['status' => 'left']);

    // The invitation: three viewers, two of them joined (the joins of CupKit were recorded as cup.joined).
    $viewer = Learner::factory()->create();
    foreach ([$a, $b, $viewer] as $learner) {
        app(ProductEventRecorder::class)->record(ProductEventName::CupInviteViewed, ['cup_id' => $cup->id], learner: $learner);
    }

    // A group that finished a cup and published the next one 5 days later; another that finished and did not.
    $course = Course::query()->firstOrFail();
    $again = CupKit::group();
    Cup::factory()->create(['telegram_group_id' => $again->id, 'course_id' => $course->id, 'status' => CupStatus::Finished, 'starts_at' => $day3->subDays(20), 'ends_at' => $day3->subDays(13), 'finished_at' => $day3->subDays(13), 'confirmed_at' => $day3->subDays(21)]);
    Cup::factory()->scheduled()->create(['telegram_group_id' => $again->id, 'course_id' => $course->id, 'confirmed_at' => $day3->subDays(8)]);
    $once = CupKit::group();
    Cup::factory()->create(['telegram_group_id' => $once->id, 'course_id' => $course->id, 'status' => CupStatus::Finished, 'starts_at' => $day3->subDays(9), 'ends_at' => $day3->subDays(2), 'finished_at' => $day3->subDays(2), 'confirmed_at' => $day3->subDays(10)]);

    $report = app(CupPilotReport::class)->report($day3->subDays(30), $day3);

    expect($report['cups'])->toBe(['draft' => 0, 'scheduled' => 1, 'running' => 1, 'finished' => 2, 'canceled' => 0])
        ->and($report['participants'])->toBe(['joined' => 3, 'played' => 2, 'left' => 1])
        ->and($report['invitation'])->toBe(['viewers' => 3, 'joined' => 2])
        ->and($report['rounds'])->toBe(['started' => 3, 'completed' => 1, 'expired' => 1, 'active' => 1, 'avg_points' => 0.0])
        ->and($report['return_day3'])->toBe(['early' => 2, 'returned' => 1])
        ->and($report['second_cup'])->toBe(['finished_groups' => 2, 'again' => 1, 'window_open' => 1]);

    $this->artisan('cups:pilot-report', ['--since' => $day3->subDays(30)->toDateString()])
        ->expectsOutputToContain('came back on day 3')
        ->assertSuccessful();
    $this->artisan('cups:pilot-report', ['--since' => 'not a date'])->assertExitCode(2);
});

it('reads the report in a fixed number of grouped statements, whatever the number of cups', function (int $cups): void {
    CarbonImmutable::setTestNow('2026-12-10 06:00:00');
    $course = Course::query()->firstOrFail();
    for ($i = 0; $i < $cups; $i++) {
        $group = CupKit::group();
        $cup = Cup::factory()->create(['telegram_group_id' => $group->id, 'course_id' => $course->id, 'status' => CupStatus::Finished, 'starts_at' => now()->subDays(9), 'ends_at' => now()->subDays(2), 'finished_at' => now()->subDays(2), 'confirmed_at' => now()->subDays(10)]);
        CupParticipant::factory()->count(3)->create(['cup_id' => $cup->id, 'days_played' => 1]);
    }

    $queries = QueryLog::during(fn () => app(CupPilotReport::class)->report(CarbonImmutable::now()->subDays(30)));

    expect($queries)->toHaveCount(7); // cups, participants, invitation, rounds, day 3, finishes, publications
})->with([2, 6]);
