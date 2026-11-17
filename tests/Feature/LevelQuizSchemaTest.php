<?php

declare(strict_types=1);

/*
 * The schema of the quiz of the week (docs/03 §2.11, §2.65): one open quiz per learner and one counted result per learner, course and
 * week are the database's rules (partial unique indexes), the CHECK of the shape of each mode knows `level_quiz` on PostgreSQL, and the
 * two migrations go down and up again.
 */

use App\Models\Course;
use App\Models\ExamSession;
use App\Models\Learner;
use App\Models\LevelQuizResult;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function levelQuizResultRow(Learner $learner, Course $course, ExamSession $session, string $week, bool $scored): LevelQuizResult
{
    return LevelQuizResult::query()->create([
        'learner_id' => $learner->id, 'course_id' => $course->id, 'exam_session_id' => $session->id, 'week_start' => $week,
        'scored' => $scored, 'correct' => 5, 'total' => 12, 'finished_at' => now(),
    ]);
}

/** Runs `$fn` in a savepoint: on PostgreSQL a failed statement aborts the test's transaction otherwise. */
function levelQuizSavepoint(Closure $fn): Closure
{
    return fn () => DB::transaction($fn);
}

function levelQuizIndexExists(string $name): bool
{
    return DB::getDriverName() === 'pgsql'
        ? DB::selectOne('select 1 as found from pg_indexes where indexname = ?', [$name]) !== null
        : DB::selectOne("select 1 as found from sqlite_master where type = 'index' and name = ?", [$name]) !== null;
}

it('lets a learner have ONE open quiz, next to an open mock and any number of closed quizzes', function (): void {
    $learner = Learner::factory()->create();
    $course = Course::factory()->published()->create();

    ExamSession::factory()->levelQuiz($learner, $course)->closed()->create();
    ExamSession::factory()->levelQuiz($learner, $course)->closed()->create();
    ExamSession::factory()->levelQuiz($learner, $course)->create();
    ExamSession::factory()->personalMock($learner, $course)->create();

    expect(levelQuizSavepoint(fn () => ExamSession::factory()->levelQuiz($learner, $course)->create()))->toThrow(UniqueConstraintViolationException::class);

    // Another learner has their own.
    ExamSession::factory()->levelQuiz(null, $course)->create();
    expect(ExamSession::query()->where('mode', 'level_quiz')->where('status', 'open')->count())->toBe(2);
});

it('lets a week have ONE counted result of a learner and a course, and any number of practice ones', function (): void {
    $learner = Learner::factory()->create();
    $course = Course::factory()->published()->create();
    $otherCourse = Course::factory()->published()->create();
    $session = fn (Course $c) => ExamSession::factory()->levelQuiz($learner, $c)->closed()->create();

    levelQuizResultRow($learner, $course, $session($course), '2026-11-16', true);
    levelQuizResultRow($learner, $course, $session($course), '2026-11-16', false);
    levelQuizResultRow($learner, $course, $session($course), '2026-11-16', false);
    levelQuizResultRow($learner, $course, $session($course), '2026-11-23', true);
    levelQuizResultRow($learner, $otherCourse, $session($otherCourse), '2026-11-16', true);

    expect(levelQuizSavepoint(fn () => levelQuizResultRow($learner, $course, $session($course), '2026-11-16', true)))->toThrow(UniqueConstraintViolationException::class)
        ->and(LevelQuizResult::query()->where('scored', true)->count())->toBe(3);
});

it('keeps one result per session', function (): void {
    $learner = Learner::factory()->create();
    $course = Course::factory()->published()->create();
    $session = ExamSession::factory()->levelQuiz($learner, $course)->closed()->create();

    levelQuizResultRow($learner, $course, $session, '2026-11-16', false);

    expect(levelQuizSavepoint(fn () => levelQuizResultRow($learner, $course, $session, '2026-11-23', false)))->toThrow(UniqueConstraintViolationException::class);
});

it('rejects a quiz without a learner or a course on PostgreSQL (the CHECK of the shape of the mode)', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('The CHECK is created on PostgreSQL only; sqlite relies on the service.');
    }

    expect(levelQuizSavepoint(fn () => ExamSession::factory()->levelQuiz()->create(['course_id' => null])))->toThrow(QueryException::class)
        ->and(levelQuizSavepoint(fn () => ExamSession::factory()->levelQuiz()->create(['learner_id' => null])))->toThrow(QueryException::class);
});

it('removes the quiz mode and its table on down() and brings them back on up()', function (): void {
    $learner = Learner::factory()->create();
    $course = Course::factory()->published()->create();
    $quiz = ExamSession::factory()->levelQuiz($learner, $course)->closed()->create();
    $mock = ExamSession::factory()->personalMock($learner, $course)->create();
    levelQuizResultRow($learner, $course, $quiz, '2026-11-16', true);

    $results = require database_path('migrations/2026_11_18_100002_create_level_quiz_results_table.php');
    $sessions = require database_path('migrations/2026_11_18_100001_add_level_quiz_mode_to_exam_sessions.php');

    $results->down();
    $sessions->down();

    expect(Schema::hasTable('level_quiz_results'))->toBeFalse()
        ->and(levelQuizIndexExists('exam_sessions_one_open_quiz_per_learner'))->toBeFalse()
        ->and(ExamSession::query()->whereKey($quiz->id)->exists())->toBeFalse()
        ->and(ExamSession::query()->whereKey($mock->id)->exists())->toBeTrue() // the mock of the older migration stays
        ->and(levelQuizIndexExists('exam_sessions_one_open_mock_per_learner'))->toBeTrue();

    $sessions->up();
    $results->up();

    expect(Schema::hasTable('level_quiz_results'))->toBeTrue()
        ->and(levelQuizIndexExists('exam_sessions_one_open_quiz_per_learner'))->toBeTrue()
        ->and(levelQuizIndexExists('level_quiz_results_one_scored_per_week'))->toBeTrue();
});
