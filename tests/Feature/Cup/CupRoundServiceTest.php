<?php

declare(strict_types=1);

use App\Domain\Cup\DTO\CupDaySnapshot;
use App\Domain\Cup\DTO\CupRoundView;
use App\Domain\Cup\Exceptions\CupException;
use App\Domain\Cup\Services\CupLifecycleService;
use App\Domain\Cup\Services\CupParticipationService;
use App\Domain\Cup\Services\CupProjection;
use App\Domain\Cup\Services\CupRoundService;
use App\Domain\Cup\Services\CupScheduler;
use App\Domain\Cup\Services\CupStandings;
use App\Domain\Game\DTO\RunState;
use App\Domain\Practice\Support\SeededShuffle;
use App\Models\Cup;
use App\Models\CupDay;
use App\Models\CupParticipant;
use App\Models\CupRoundAttempt;
use App\Models\Learner;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Cup\CupKit;

/*
 * docs/33 §5.1-§5.3 (K5): the round of the day - one snapshot per day shared by everybody, a personal order, one attempt per day,
 * the deadline, idempotent answers, the points of the day, no leak of the answers, nothing written outside the cup.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CupKit::open();
    CupKit::course();
});

afterEach(fn () => CarbonImmutable::setTestNow());

function cupRounds(): CupRoundService
{
    return app(CupRoundService::class);
}

function dayOf(Cup $cup, int $day): CupDay
{
    return CupDay::query()->where('cup_id', $cup->id)->where('day', $day)->firstOrFail();
}

/** Answers the current task of the view; `$right` picks the right or a wrong answer. */
function answerCurrent(Learner $learner, CupRoundView $view, bool $right, CarbonImmutable $now, ?string $requestId = null): array
{
    $dayId = (int) CupRoundAttempt::query()->whereKey($view->attemptId)->value('cup_day_id');
    $elementId = (string) $view->current['id'];
    $answer = $right ? CupKit::rightAnswer($dayId, $elementId) : CupKit::wrongAnswer($dayId, $elementId);
    $outcome = cupRounds()->answer($learner, $view->attemptId, $requestId ?? (string) Str::uuid(), $elementId, $answer, $now);

    return [$outcome, $elementId];
}

it('gives two participants of a day the same words, kinds and right answers in a different order', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b], 'start' => $now] = CupKit::running(2);

    $va = cupRounds()->startToday($a, $cup, $now)->view;
    $vb = cupRounds()->startToday($b, $cup, $now)->view;

    $oa = CupRoundAttempt::query()->findOrFail($va->attemptId)->order;
    $ob = CupRoundAttempt::query()->findOrFail($vb->attemptId)->order;
    expect($oa)->toHaveCount(10)
        ->and(collect($oa)->sort()->values()->all())->toBe(collect($ob)->sort()->values()->all())
        ->and($oa)->not->toBe($ob);

    // one snapshot: the same element ids, hence the same words and answers
    expect(DB::table('cup_days')->where('cup_id', $cup->id)->whereNotNull('snapshot')->count())->toBe(1);
    $snapshot = json_decode((string) dayOf($cup, 1)->getRawOriginal('snapshot'), true);
    expect(collect($snapshot['elements'])->pluck('word_id')->unique())->toHaveCount(10)
        ->and($snapshot['locales'])->toBe(['ru', 'uz']);

    // the same task shows its options in each participant's own order, with the same ids
    $shared = $va->current['id'];
    $first = cupRounds()->find($a, $va->attemptId, $now)->current;
    expect($first['id'])->toBe($shared);
});

it('shows each participant the options of a task in his own stable order', function (): void {
    ['cup' => $cup, 'learners' => $learners, 'start' => $now] = CupKit::running(6);
    $views = [];
    foreach ($learners as $learner) {
        $views[] = cupRounds()->startToday($learner, $cup, $now)->view;
    }
    $snapshot = CupDaySnapshot::fromArray(json_decode((string) dayOf($cup, 1)->getRawOriginal('snapshot'), true));
    $task = collect($snapshot->elements)->first(fn ($e) => in_array($e->kind, ['translate_fwd', 'translate_back', 'listen'], true));
    $daySeed = hash_hmac('sha256', 'day:1', $cup->seed_secret, true);
    $projection = app(CupProjection::class);

    $orders = [];
    foreach ($views as $view) {
        $options = $projection->project($task, 'ru', new RunState, $daySeed, $view->participantId)['options'];
        $orders[] = array_column($options, 'id');
        expect(collect(array_column($options, 'id'))->sort()->values()->all())->toBe(collect(array_column($task->get('options'), 'id'))->sort()->values()->all());
    }
    expect(collect($orders)->unique(fn (array $o): string => implode(',', $o))->count())->toBeGreaterThan(1);

    // a reload shows the same order
    $again = cupRounds()->find($learners[0], $views[0]->attemptId, $now)->current;
    expect($again)->toBe($views[0]->current);
});

it('starts the round once a day: a second start or a second tab returns the same attempt with its original deadline', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $now] = CupKit::running(2);

    $first = cupRounds()->startToday($a, $cup, $now);
    $second = cupRounds()->startToday($a, $cup, $now->addMinutes(3));

    expect($first->created)->toBeTrue()->and($second->created)->toBeFalse()
        ->and($second->view->attemptId)->toBe($first->view->attemptId)
        ->and($second->view->expiresAt)->toBe($first->view->expiresAt)
        ->and(CupRoundAttempt::query()->count())->toBe(1);
});

it('resumes an interrupted round with its accepted answers and the same deadline', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $now] = CupKit::running(2);
    $view = cupRounds()->startToday($a, $cup, $now)->view;
    answerCurrent($a, $view, true, $now->addSeconds(20));

    $resumed = cupRounds()->find($a, $view->attemptId, $now->addMinutes(4));

    expect($resumed->answered)->toBe(1)
        ->and($resumed->answers)->toHaveCount(1)
        ->and($resumed->expiresAt)->toBe($view->expiresAt)
        ->and($resumed->position)->toBe(2)
        ->and($resumed->current['id'])->not->toBe($view->current['id']);
});

it('refuses a second round of the day once the first is played', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $now] = CupKit::running(2);
    $view = cupRounds()->startToday($a, $cup, $now)->view;
    for ($i = 0; $i < 10; $i++) {
        [$outcome] = answerCurrent($a, $view, true, $now->addSeconds(10 + $i));
        $view = $outcome->view;
    }

    expect($view->status)->toBe('finished')->and($view->points)->toBe(20)->and($view->review)->toHaveCount(10);
    expect(fn () => cupRounds()->startToday($a, $cup, $now->addMinutes(30)))
        ->toThrow(fn (CupException $e) => expect([$e->errorCode, $e->details])->toBe(['cup_round_done', ['points' => 20]]));
});

it('caps a perfect round at 20 points and writes them to the day of the participant', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $now] = CupKit::running(2);
    $view = cupRounds()->startToday($a, $cup, $now)->view;
    for ($i = 0; $i < 10; $i++) {
        [$outcome] = answerCurrent($a, $view, true, $now->addSeconds(10 + $i));
        $view = $outcome->view;
    }

    $participant = CupParticipant::query()->where('learner_id', $a->id)->firstOrFail();
    expect(DB::table('cup_day_scores')->where('participant_id', $participant->id)->value('round_points'))->toBe(20)
        ->and($participant->best_total)->toBe(20)
        ->and($participant->best_round_points)->toBe(20)
        ->and($participant->days_played)->toBe(1);
});

it('gives 2 points for a right answer and 0 for a wrong one, a typo or a skip (owner К4)', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $now] = CupKit::running(2);
    $view = cupRounds()->startToday($a, $cup, $now)->view;
    $attempt = CupRoundAttempt::query()->findOrFail($view->attemptId);

    $points = [];
    $verdicts = [];
    for ($i = 0; $i < 10; $i++) {
        $elementId = (string) $view->current['id'];
        $element = CupKit::element($attempt->cup_day_id, $elementId);
        $answer = match (true) {
            $i === 1 => ['skip' => true],
            $i === 2 => CupKit::wrongAnswer($attempt->cup_day_id, $elementId),
            $element['kind'] === 'spell' => ['text' => substr((string) $element['expected'], 0, -1).'x'], // one letter off: a typo
            default => CupKit::rightAnswer($attempt->cup_day_id, $elementId),
        };
        $outcome = cupRounds()->answer($a, $view->attemptId, (string) Str::uuid(), $elementId, $answer, $now->addSeconds(5 + $i));
        $points[] = $outcome->body['points'];
        $verdicts[] = $outcome->body['verdict'];
        $view = $outcome->view;
    }

    foreach ($verdicts as $i => $verdict) {
        expect($points[$i])->toBe($verdict === 'correct' ? 2 : 0);
    }
    expect($verdicts)->toContain('skipped')->toContain('wrong');
    if (in_array('typo', $verdicts, true)) {
        expect($points[array_search('typo', $verdicts, true)])->toBe(0);
    }
    expect($view->points)->toBe(2 * count(array_keys($verdicts, 'correct', true)));
});

it('answers a repeated request_id with the stored body and changes nothing', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $now] = CupKit::running(2);
    $view = cupRounds()->startToday($a, $cup, $now)->view;
    $requestId = (string) Str::uuid();
    [$first, $elementId] = answerCurrent($a, $view, true, $now->addSeconds(5), $requestId);

    $again = cupRounds()->answer($a, $view->attemptId, strtoupper($requestId), $elementId, ['option_id' => 'oNOPE'], $now->addSeconds(9));

    expect($again->replayed)->toBeTrue()
        ->and($again->body)->toBe($first->body)
        ->and(DB::table('cup_round_answers')->count())->toBe(1)
        ->and(CupRoundAttempt::query()->findOrFail($view->attemptId)->points)->toBe(2)
        ->and(DB::table('cup_day_scores')->value('round_points'))->toBe(2);
});

it('refuses a second answer to a task and an answer to a task out of turn', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $now] = CupKit::running(2);
    $view = cupRounds()->startToday($a, $cup, $now)->view;
    $attempt = CupRoundAttempt::query()->findOrFail($view->attemptId);
    [, $elementId] = answerCurrent($a, $view, false, $now->addSeconds(5));

    expect(fn () => cupRounds()->answer($a, $view->attemptId, (string) Str::uuid(), $elementId, CupKit::rightAnswer($attempt->cup_day_id, $elementId), $now->addSeconds(6)))
        ->toThrow(fn (CupException $e) => expect([$e->errorCode, $e->details['verdict']])->toBe(['answer_already_accepted', 'wrong']));

    $later = $attempt->order[5];
    expect(fn () => cupRounds()->answer($a, $view->attemptId, (string) Str::uuid(), $later, CupKit::rightAnswer($attempt->cup_day_id, $later), $now->addSeconds(7)))
        ->toThrow(fn (CupException $e) => expect([$e->errorCode, $e->details['current']])->toBe(['element_not_current', $attempt->order[1]]));
    expect(fn () => cupRounds()->answer($a, $view->attemptId, (string) Str::uuid(), 'eNOTATASK01', ['option_id' => 'x'], $now->addSeconds(8)))
        ->toThrow(fn (CupException $e) => expect($e->errorCode)->toBe('unknown_element_id'));
    expect(fn () => cupRounds()->answer($a, $view->attemptId, (string) Str::uuid(), $attempt->order[1], ['nonsense' => true], $now->addSeconds(8)))
        ->toThrow(fn (CupException $e) => expect($e->httpStatus())->toBe(422));

    expect(CupRoundAttempt::query()->findOrFail($view->attemptId)->answered)->toBe(1);
});

it('takes an answer within the grace after the deadline, closes the attempt as expired after it and keeps the earlier points', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $now] = CupKit::running(2);
    $view = cupRounds()->startToday($a, $cup, $now)->view;
    answerCurrent($a, $view, true, $now->addMinute());
    $view = cupRounds()->find($a, $view->attemptId, $now->addMinutes(2));

    // 10 min + 1 s: inside the grace of 1.5 s
    [$inGrace] = answerCurrent($a, $view, true, $now->addMinutes(10)->addSecond());
    expect($inGrace->body['points'])->toBe(2);

    $view = $inGrace->view;
    expect(fn () => answerCurrent($a, $view, true, $now->addMinutes(10)->addSeconds(2)))
        ->toThrow(fn (CupException $e) => expect([$e->errorCode, $e->details])->toBe(['cup_round_expired', ['points' => 4]]));

    $attempt = CupRoundAttempt::query()->findOrFail($view->attemptId);
    expect($attempt->status->value)->toBe('finished')
        ->and($attempt->finish_reason?->value)->toBe('expired')
        ->and($attempt->points)->toBe(4)
        ->and(DB::table('cup_day_scores')->value('round_points'))->toBe(4);
});

it('credits an attempt started at 23:58 to the day it started, even when answered after midnight', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $start] = CupKit::running(2);
    $late = CarbonImmutable::instance(dayOf($cup, 2)->starts_at)->subMinutes(2); // 23:58 of day 1 in Tashkent
    $view = cupRounds()->startToday($a, $cup, $late)->view;

    expect($view->day)->toBe(1);
    answerCurrent($a, $view, true, $late->addMinutes(3)); // 00:01 of day 2

    $participant = CupParticipant::query()->where('learner_id', $a->id)->firstOrFail();
    expect(DB::table('cup_day_scores')->where('participant_id', $participant->id)->pluck('round_points', 'day')->all())->toBe([1 => 2]);

    // and day 2 is a new round of its own
    $day2 = cupRounds()->startToday($a, $cup, $late->addMinutes(4));
    expect($day2->created)->toBeTrue()->and($day2->view->day)->toBe(2);
});

it('closes abandoned attempts on the scheduler and keeps their points', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b], 'start' => $now] = CupKit::running(2);
    $view = cupRounds()->startToday($a, $cup, $now)->view;
    answerCurrent($a, $view, true, $now->addMinute());
    cupRounds()->startToday($b, $cup, $now->addMinutes(5));

    expect(cupRounds()->closeExpired($now->addMinutes(11), 100))->toBe(1)
        ->and(cupRounds()->closeExpired($now->addMinutes(11), 100))->toBe(0)
        ->and(cupRounds()->closeExpired($now->addMinutes(16), 100))->toBe(1);

    $attempt = CupRoundAttempt::query()->findOrFail($view->attemptId);
    expect($attempt->finish_reason?->value)->toBe('expired')->and($attempt->points)->toBe(2);
});

it('answers 404 for a stranger\'s attempt, the same as for a missing one', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b], 'start' => $now] = CupKit::running(2);
    $view = cupRounds()->startToday($a, $cup, $now)->view;
    $stranger = Learner::factory()->create();

    foreach ([[$b, $view->attemptId], [$stranger, $view->attemptId], [$a, $view->attemptId + 999]] as [$who, $id]) {
        expect(fn () => cupRounds()->find($who, $id, $now))->toThrow(fn (CupException $e) => expect([$e->errorCode, $e->httpStatus()])->toBe(['cup_round_not_found', 404]));
        expect(fn () => cupRounds()->answer($who, $id, (string) Str::uuid(), (string) $view->current['id'], ['option_id' => 'x'], $now))
            ->toThrow(fn (CupException $e) => expect($e->errorCode)->toBe('cup_round_not_found'));
        expect(cupRounds()->audioOf($who, $id, (string) $view->current['id']))->toBeNull();
    }
});

it('never shows the right answer of a task before it is answered', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $now] = CupKit::running(2);
    $view = cupRounds()->startToday($a, $cup, $now)->view;
    $forbidden = ['correct_option_id', 'expected', 'correct_text', 'full_text', 'word_id', 'word', 'lexeme_id', 'audio_path', 'audio_disk', 'accepted', 'pairs', 'correct_ids'];

    for ($i = 0; $i < 10; $i++) {
        $keys = array_keys($view->current);
        expect(array_intersect($keys, $forbidden))->toBe([]);
        expect(json_encode($view->toArray()))->not->toContain('correct_option_id');
        [$outcome] = answerCurrent($a, $view, $i % 2 === 0, $now->addSeconds(3 + $i));
        $view = $outcome->view;
    }
});

it('writes nothing outside the cup: no daily stats, league, word points, SRS, game runs or coins', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $now] = CupKit::running(2);
    $tables = ['learner_daily_stats', 'game_runs', 'game_run_attempts', 'word_repetitions', 'league_members', 'league_month_scores', 'learner_score_days', 'score_credits', 'learner_lexemes', 'game_wallets', 'game_coin_ledger', 'training_reviews', 'practice_sessions'];
    $before = [];
    foreach ($tables as $table) {
        $before[$table] = DB::table($table)->count();
    }

    $view = cupRounds()->startToday($a, $cup, $now)->view;
    for ($i = 0; $i < 10; $i++) {
        [$outcome] = answerCurrent($a, $view, true, $now->addSeconds(3 + $i));
        $view = $outcome->view;
    }

    foreach ($tables as $table) {
        expect(DB::table($table)->count())->toBe($before[$table], "{$table} was written");
    }
    expect($a->fresh()?->streak_current)->toBe(0);
});

it('plays the same round for a Free and a Pro participant', function (): void {
    ['cup' => $cup, 'learners' => [$free, $pro], 'start' => $now] = CupKit::running(2);
    Subscription::factory()->create(['subscriber_id' => $pro->id]);

    $vf = cupRounds()->startToday($free, $cup, $now)->view;
    $vp = cupRounds()->startToday($pro, $cup, $now)->view;

    expect($vf->total)->toBe($vp->total)->and($vf->maxPoints)->toBe(20)->and($vp->maxPoints)->toBe(20);
});

it('keeps the rounds of a learner in two cups apart', function (): void {
    ['cup' => $one, 'learners' => [$a, $b], 'start' => $now] = CupKit::running(2);
    $other = CupKit::group();
    $second = CupKit::scheduled($other, $now->subHours(13)); // published at 11:00 of the eve -> starts at this midnight
    foreach ([$a, $b] as $learner) {
        app(CupParticipationService::class)->join($second->fresh(['telegramGroup']), $learner, null, $now->subHours(12));
    }
    app(CupScheduler::class)->advance($now);
    $second = Cup::query()->with('telegramGroup')->findOrFail($second->id);
    expect($second->status->value)->toBe('running');

    $r1 = cupRounds()->startToday($a, $one, $now)->view;
    $r2 = cupRounds()->startToday($a, $second, $now)->view;
    answerCurrent($a, $r1, true, $now->addSeconds(4));

    expect($r1->attemptId)->not->toBe($r2->attemptId)->and($r1->cupId)->not->toBe($r2->cupId)
        ->and(DB::table('cup_day_scores')->where('cup_id', $second->id)->sum('round_points'))->toBe(0)
        ->and(DB::table('cup_day_scores')->where('cup_id', $one->id)->sum('round_points'))->toBe(2);
});

it('builds the snapshot of a day lazily at the first start when the scheduler did not', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $now] = CupKit::running(2);
    DB::table('cup_days')->where('cup_id', $cup->id)->update(['snapshot' => null, 'built_at' => null]);

    $view = cupRounds()->startToday($a, $cup, $now)->view;

    expect($view->total)->toBe(10)->and(dayOf($cup, 1)->built_at)->not->toBeNull();
});

it('builds tomorrow\'s snapshot an hour before the day, once', function (): void {
    ['cup' => $cup, 'start' => $now] = CupKit::running(2);
    $eve = CarbonImmutable::instance(dayOf($cup, 2)->starts_at)->subMinutes(50);

    expect(dayOf($cup, 2)->built_at)->toBeNull();
    expect(cupRounds()->buildUpcoming($eve, 50))->toBe(1)
        ->and(dayOf($cup, 2)->built_at)->not->toBeNull()
        ->and(cupRounds()->buildUpcoming($eve, 50))->toBe(0);

    $one = json_decode((string) dayOf($cup, 1)->getRawOriginal('snapshot'), true);
    $two = json_decode((string) dayOf($cup, 2)->getRawOriginal('snapshot'), true);
    expect(array_intersect(array_column($one['elements'], 'word_id'), array_column($two['elements'], 'word_id')))->toBe([]);
});

it('refuses the round before the start, before the late participant\'s first day and after the end', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $now] = CupKit::running(2);
    $late = Learner::factory()->create();
    CupKit::member($late);
    $day3 = CarbonImmutable::instance(dayOf($cup, 3)->starts_at)->addHour();
    app(CupParticipationService::class)->join($cup, $late, 'Late', $day3);

    expect(CupParticipant::query()->where('learner_id', $late->id)->value('first_day'))->toBe(3);
    expect(cupRounds()->startToday($late, $cup, $day3)->view->day)->toBe(3);

    $after = CarbonImmutable::instance($cup->ends_at)->addMinutes(5);
    expect(fn () => cupRounds()->startToday($a, $cup, $after))->toThrow(fn (CupException $e) => expect($e->errorCode)->toBe('cup_round_unavailable'));
    expect(cupRounds()->today($cup, CupParticipant::query()->where('learner_id', $a->id)->first(), $after)['state'])->toBe('not_available');
});

it('reports today\'s state without writing', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $now] = CupKit::running(2);
    $participant = CupParticipant::query()->where('learner_id', $a->id)->firstOrFail();

    expect(cupRounds()->today($cup, $participant, $now)['state'])->toBe('not_started');
    $view = cupRounds()->startToday($a, $cup, $now)->view;
    expect(cupRounds()->today($cup, $participant, $now->addMinute()))->toMatchArray(['state' => 'active', 'attempt_id' => $view->attemptId, 'points' => 0]);
    expect(cupRounds()->today($cup, $participant, $now->addMinutes(11))['state'])->toBe('done');
});

it('drops the cached table when a right answer changes the points', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $now] = CupKit::running(2);
    $before = app(CupStandings::class)->table($cup);
    expect(collect($before)->sum('total'))->toBe(0);

    $view = cupRounds()->startToday($a, $cup, $now)->view;
    answerCurrent($a, $view, true, $now->addSeconds(5));

    $after = app(CupStandings::class)->table($cup);
    expect(collect($after)->firstWhere('total', 2))->not->toBeNull()->and($after[0]['rank'])->toBe(1);
});

it('refuses an answer once the cup is canceled', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $now] = CupKit::running(2);
    $view = cupRounds()->startToday($a, $cup, $now)->view;
    app(CupLifecycleService::class)->cancelByPlatform($cup, $now->addSecond());

    expect(fn () => answerCurrent($a, $view, true, $now->addSeconds(5)))->toThrow(fn (CupException $e) => expect([$e->errorCode, $e->httpStatus()])->toBe(['cup_closed', 410]));
    expect(DB::table('cup_day_scores')->sum('round_points'))->toBe(0);
});

it('derives the order of the tasks from the secret seed only', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $now] = CupKit::running(2);
    $view = cupRounds()->startToday($a, $cup, $now)->view;
    $attempt = CupRoundAttempt::query()->findOrFail($view->attemptId);
    $snapshot = json_decode((string) dayOf($cup, 1)->getRawOriginal('snapshot'), true);
    $ids = array_column($snapshot['elements'], 'id');
    $daySeed = hash_hmac('sha256', 'day:1', $cup->seed_secret, true);
    $expected = array_map(fn (int $i): string => $ids[$i], SeededShuffle::order(hash_hmac('sha256', "order:{$attempt->participant_id}", $daySeed, true), 'cup-order', 10));

    expect($attempt->order)->toBe($expected);
});

it('scores a typo of a typed task 0 (owner К4: only a clean right answer counts)', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $now] = CupKit::running(2);
    $view = cupRounds()->startToday($a, $cup, $now)->view;
    $dayId = (int) CupRoundAttempt::query()->whereKey($view->attemptId)->value('cup_day_id');

    $typo = null;
    for ($i = 0; $i < 10 && $typo === null; $i++) {
        $elementId = (string) $view->current['id'];
        $element = CupKit::element($dayId, $elementId);
        if ($element['kind'] === 'spell') {
            $typo = cupRounds()->answer($a, $view->attemptId, (string) Str::uuid(), $elementId, ['text' => substr((string) $element['expected'], 0, -1).'x'], $now->addSeconds(3 + $i));
            break;
        }
        [$outcome] = answerCurrent($a, $view, true, $now->addSeconds(3 + $i));
        $view = $outcome->view;
    }

    expect($typo)->not->toBeNull('the round has a spelling task')
        ->and($typo->body['verdict'])->toBe('typo')
        ->and($typo->body['points'])->toBe(0);
});
