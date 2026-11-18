<?php

declare(strict_types=1);

/*
 * The one statement of scalars of `GET /today` (docs/19 K10) on PostgreSQL: every member is planned as an InitPlan of its own on its own
 * index - folding the reads into one statement must not turn several indexed lookups into one scan. Also the equivalence of the folded
 * values with the separate reads they replaced (on any driver).
 */

use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Learning\Services\HardRoundService;
use App\Domain\Learning\Services\LevelQuizService;
use App\Domain\Twa\Services\JwtService;
use App\Models\Course;
use App\Models\ExamSession;
use App\Models\Learner;
use App\Models\LevelQuizResult;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\HotPathFixtures as Fx;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-18 06:00:00'); // Wednesday; the week of the quiz starts on 2026-11-16
    config(['game.access' => 'all', 'readiness.enabled' => true, 'learning.hard_round.enabled' => true]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** @return array{learner: Learner, course: Course, headers: array<string, string>} a Free learner with due, hard and quiz data */
function scalarsWorld(): array
{
    ['course' => $course, 'words' => $words] = Fx::course([1 => [1 => 12]]);
    $learner = Learner::factory()->onboarded()->create(['timezone' => 'Asia/Tashkent', 'active_course_id' => $course->id]);
    $now = CarbonImmutable::now();
    foreach ($words['1.1']['active'] as $i => $wordId) {
        WordRepetition::factory()->create([
            'learner_id' => $learner->id, 'word_id' => $wordId, 'repetitions' => 3, 'state' => RepetitionState::Review->value,
            'is_hard' => $i < 8, 'lapses' => $i < 8 ? 2 : 0, 'interval_days' => 6, 'easiness_factor' => 2.1 + $i / 10,
            'next_review_at' => $now->subHour(), 'last_reviewed_at' => $now->subDays(2), 'created_at' => $now->subDays(2),
        ]);
    }
    $session = ExamSession::factory()->levelQuiz($learner, $course, '2026-11-16')->closed()->create();
    LevelQuizResult::query()->create([
        'learner_id' => $learner->id, 'course_id' => $course->id, 'exam_session_id' => $session->id, 'week_start' => '2026-11-16',
        'correct' => 9, 'total' => 12, 'scored' => true, 'finished_at' => $now->subDay(),
    ]);

    return ['learner' => $learner, 'course' => $course, 'headers' => ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']]];
}

it('gives the same values folded as the separate reads gave: the due cards, the candidates of the round, the quiz of the week', function (): void {
    ['learner' => $learner, 'course' => $course, 'headers' => $h] = scalarsWorld();

    $today = $this->withHeaders($h)->getJson('/api/twa/today')->assertOk()->json();

    $separateQuiz = app(LevelQuizService::class)->weekLine($learner, $course, CarbonImmutable::now())->toTodayArray();
    $separateCandidates = count(app(HardRoundService::class)->candidates($learner->id, app(HardRoundService::class)->window($learner, CarbonImmutable::now())->startUtc, CarbonImmutable::now(), 10));

    expect($today['due_count'])->toBe(12)
        ->and($today['hard_round'])->toMatchArray(['available' => true, 'words' => 8])
        ->and($separateCandidates)->toBe(8)
        ->and($today['active_course']['readiness']['quiz'])->toBe($separateQuiz)
        ->and($separateQuiz['this_week'])->toMatchArray(['correct' => 9, 'total' => 12, 'percent' => 75]);
});

it('plans every scalar as an InitPlan on an index of its own (PostgreSQL plan)', function (): void {
    ['learner' => $learner, 'course' => $course, 'headers' => $h] = scalarsWorld();
    $this->withHeaders($h)->getJson('/api/twa/today')->assertOk(); // warm the caches of the request

    $captured = null;
    DB::listen(function ($query) use (&$captured): void {
        if (str_starts_with($query->sql, 'select (select count(*) from "word_repetitions"')) {
            $captured = [$query->sql, $query->bindings];
        }
    });
    $this->withHeaders($h)->getJson('/api/twa/today')->assertOk();
    expect($captured)->not->toBeNull();
    [$sql, $bindings] = $captured;

    // A table of a dozen rows carries no statistics; what is asked is whether every member CAN be served by an index as its own
    // InitPlan (the choice among real indexes is measured on seeded data, docs/19 K10), so a seq scan is not offered.
    DB::statement('SET LOCAL enable_seqscan = off');
    $plan = collect(DB::select('EXPLAIN '.$sql, $bindings))->map(fn ($row): string => (string) ((array) $row)['QUERY PLAN'])->implode("\n");

    // due + served + hard_candidates + quiz_correct + quiz_total + quiz_finished_at: six independent subplans, each an index scan of the
    // learner's rows (a dozen rows carry no statistics, so WHICH index of `word_repetitions` the planner ties on is not pinned here: on
    // the seeded data the due cards go by the learner's index, the candidates by `word_repetitions_hard_idx` - docs/19 K10).
    expect(substr_count($plan, 'InitPlan'))->toBe(6)
        ->and($plan)->not->toContain('Seq Scan')
        ->and(substr_count($plan, 'Index Scan using word_repetitions_') + substr_count($plan, 'Index Only Scan using word_repetitions_'))->toBeGreaterThanOrEqual(3)
        ->and($plan)->toContain('level_quiz_results_');

    // The cold snapshot adds the memberships: its member is an index lookup too.
    DB::statement('SET LOCAL enable_seqscan = off');
    $memberships = DB::table('group_members as gm')
        ->join('telegram_groups as tg', fn ($join) => $join->on('tg.id', '=', 'gm.telegram_group_id')->where('tg.status', 'active'))
        ->where('gm.learner_id', $learner->id)->where('gm.status', 'active')->selectRaw('count(*)');
    $cold = collect(DB::select('EXPLAIN '.$memberships->toSql(), $memberships->getBindings()))
        ->map(fn ($row): string => (string) ((array) $row)['QUERY PLAN'])->implode("\n");
    expect($cold)->toContain('group_members_learner_id_status_index')->and($cold)->not->toContain('Seq Scan');

    expect(CourseCatalogService::courseRowKey($course->id))->toContain(':n');
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'EXPLAIN is PostgreSQL-only.');
