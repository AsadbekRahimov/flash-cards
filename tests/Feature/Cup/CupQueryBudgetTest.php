<?php

declare(strict_types=1);

use App\Domain\Cup\Services\CupLifecycleService;
use App\Domain\Cup\Services\CupParticipationService;
use App\Domain\Cup\Services\CupRoundService;
use App\Domain\Cup\Services\CupScheduler;
use App\Domain\Cup\Services\CupStandings;
use App\Jobs\PostCupMessageJob;
use App\Models\Cup;
use App\Models\CupRoundAttempt;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Cup\CupKit;
use Tests\Support\QueryLog;

/*
 * The owner's requirement on SQL (docs/33 §11, docs/38): the statements of the hot paths of the cup are pinned here. A change that
 * adds a statement fails this file and has to say why. Product events are counted too (outside an HTTP request they are written at
 * once; in a request they wait for the end of the response, ProductEventBuffer).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CupKit::open();
    CupKit::course();
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('answers a task in 4 statements when wrong and 9 when right (5 of them the credit under the lock of the participant)', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $now] = CupKit::running(2);
    $rounds = app(CupRoundService::class);
    $view = $rounds->startToday($a, $cup, $now)->view;
    $dayId = (int) CupRoundAttempt::query()->whereKey($view->attemptId)->value('cup_day_id');
    $attemptId = $view->attemptId;

    $element = (string) $view->current['id'];
    $answer = CupKit::wrongAnswer($dayId, $element);
    $wrong = QueryLog::during(fn () => $rounds->answer($a, $attemptId, (string) Str::uuid(), $element, $answer, $now->addSeconds(5)));
    $element = (string) $rounds->find($a, $attemptId, $now->addSeconds(6))->current['id'];
    $answer = CupKit::rightAnswer($dayId, $element);
    $requestId = (string) Str::uuid();
    $right = QueryLog::during(fn () => $rounds->answer($a, $attemptId, $requestId, $element, $answer, $now->addSeconds(9)));
    $replay = QueryLog::during(fn () => $rounds->answer($a, $attemptId, $requestId, $element, $answer, $now->addSeconds(12)));

    expect($wrong)->toHaveCount(4)   // the attempt with its day and cup (FOR UPDATE OF the attempt), the request_id, INSERT answer, UPDATE attempt
        ->and($right)->toHaveCount(9) // + lock participant, read the status of the cup, upsert the day, read the days, UPDATE best_*
        ->and($replay)->toHaveCount(2) // the attempt, the stored result
        ->and(QueryLog::matching($right, '/^update ["`]?cups["`]?/i'))->toBe([]);
});

it('closes the round with its last answer in the same statements plus the event', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $now] = CupKit::running(2);
    $rounds = app(CupRoundService::class);
    $view = $rounds->startToday($a, $cup, $now)->view;
    $dayId = (int) CupRoundAttempt::query()->whereKey($view->attemptId)->value('cup_day_id');
    for ($i = 0; $i < 9; $i++) {
        $element = (string) $view->current['id'];
        $view = $rounds->answer($a, $view->attemptId, (string) Str::uuid(), $element, CupKit::wrongAnswer($dayId, $element), $now->addSeconds(5 + $i))->view;
    }
    $element = (string) $view->current['id'];
    $answer = CupKit::wrongAnswer($dayId, $element);
    $attemptId = $view->attemptId;
    $last = QueryLog::during(fn () => $rounds->answer($a, $attemptId, (string) Str::uuid(), $element, $answer, $now->addSeconds(30)));

    expect($last)->toHaveCount(5); // 4 + the event cup.round_finished
});

it('starts the round of the day in a fixed number of statements, and resumes it without Telegram in 4', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $now] = CupKit::running(2);
    $rounds = app(CupRoundService::class);
    Cache::flush();
    CupKit::telegram()->chatMemberLookups = [];

    $start = QueryLog::during(fn () => $rounds->startToday($a, $cup, $now));
    $resume = QueryLog::during(fn () => $rounds->startToday($a, $cup, $now->addMinute()));
    $attemptId = (int) CupRoundAttempt::query()->value('id');
    $find = QueryLog::during(fn () => $rounds->find($a, $attemptId, $now->addMinute()));

    // participant, today, attempt of today, UPDATE checked_at, the snapshot, INSERT … RETURNING, INSERT day score, days_played, event
    expect($start)->toHaveCount(9)
        ->and($resume)->toHaveCount(4) // participant, today, attempt of today, the snapshot
        ->and($find)->toHaveCount(1)
        ->and(CupKit::telegram()->chatMemberLookups)->toHaveCount(1);
});

it('reads the table of a cup in one statement cold and none warm', function (): void {
    ['cup' => $cup] = CupKit::running(8);
    $standings = app(CupStandings::class);
    $standings->forget($cup->id);

    $cold = QueryLog::during(fn () => $standings->table($cup));
    $warm = QueryLog::during(fn () => $standings->table($cup));

    expect($cold)->toHaveCount(1)->and($warm)->toHaveCount(0)->and($standings->table($cup))->toHaveCount(8);
});

it('joins a cup in a fixed number of statements', function (): void {
    CarbonImmutable::setTestNow('2026-12-01 06:00:00');
    $cup = CupKit::scheduled(CupKit::group(), CarbonImmutable::now());
    $learner = Learner::factory()->create();
    CupKit::member($learner);
    $cup = Cup::query()->with('telegramGroup')->findOrFail($cup->id);

    $join = QueryLog::during(fn () => app(CupParticipationService::class)->join($cup, $learner));

    expect($join)->toHaveCount(4); // FOR SHARE of the cup, INSERT ON CONFLICT DO NOTHING, the row FOR UPDATE, the event
});

it('advances N cups with a constant number of statements per cup: starting and finishing', function (int $cups): void {
    CarbonImmutable::setTestNow('2026-12-01 06:00:00');
    $ids = [];
    for ($i = 0; $i < $cups; $i++) {
        $cup = CupKit::scheduled(CupKit::group(), CarbonImmutable::now());
        foreach ([1, 2] as $ignored) {
            $learner = Learner::factory()->create();
            CupKit::member($learner);
            app(CupParticipationService::class)->join($cup, $learner);
        }
        $ids[] = $cup->id;
    }
    $cup = Cup::query()->findOrFail($ids[0]);
    $midnight = CarbonImmutable::instance($cup->starts_at)->addMinute();
    $end = CarbonImmutable::instance($cup->ends_at)->addMinutes(31);

    $idle = QueryLog::during(fn () => app(CupScheduler::class)->advance($midnight->subHours(3)));
    expect($idle)->toHaveCount(5); // one indexed read per step, nothing else

    // the start of each cup: FOR UPDATE, count the players, the course, the plan, INSERT the 7 days, UPDATE the cup, its group, the event
    foreach ($ids as $id) {
        expect(QueryLog::during(fn () => app(CupLifecycleService::class)->start($id, $midnight)))->toHaveCount(8);
    }

    // the snapshots of day 1: two reads for the whole batch, then per day the lock, the material (words, translations, distractors) and the write
    $snapshots = QueryLog::during(fn () => app(CupRoundService::class)->buildUpcoming($midnight, 200));
    expect((count($snapshots) - 2) / $cups)->toBeLessThanOrEqual(10.0);

    app(CupScheduler::class)->advance($end->subDays(2));
    DB::table('cup_days')->whereNull('built_at')->update(['built_at' => now()]);

    // the finish of each cup: FOR UPDATE the cup and its participants, the ranks, UPDATE the cup, the final message, close the days,
    // the group, the event - 8 in the core; + the id of the row `final` for its job (CupScheduler, docs/33 §11.8 п. 9) - 9 per cup
    Bus::fake([PostCupMessageJob::class]);
    $finish = QueryLog::during(fn () => app(CupScheduler::class)->advance($end));
    expect(count($finish))->toBe(5 + 9 * $cups);
    Bus::assertDispatchedTimes(PostCupMessageJob::class, $cups);
})->with([2, 5]);
