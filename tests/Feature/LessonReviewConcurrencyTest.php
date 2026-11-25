<?php

declare(strict_types=1);

/*
 * Real races on PostgreSQL around the review of a lesson (`lesson_review`, docs/06 §15): parallel opens (two tabs, a double tap) end
 * with one session; the same answer delivered twice at once is counted once; opens of two lessons at once leave one open round.
 * Each contender is a forked process with its own connection; committed rows: DatabaseTruncation. Skipped on sqlite.
 */

use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Learning\Exceptions\ReviewSubmissionException;
use App\Domain\Learning\Services\LessonReviewService;
use App\Domain\Learning\Services\TrainingFlowService;
use App\Models\Learner;
use App\Models\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Support\CardAnswers;
use Tests\Support\ForkSafe;
use Tests\Support\HotPathFixtures as Fx;

$onPostgres = (getenv('DB_CONNECTION') ?: 'sqlite') === 'pgsql';

if (! $onPostgres) {
    it('races the review of a lesson on PostgreSQL', function (): void {})
        ->skip('Requires PostgreSQL (DB_CONNECTION=pgsql): real parallel transactions need committed rows and row locks.');

    return;
}

uses(DatabaseTruncation::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-25 05:00:00');
    config(['learning.lesson_review' => ['enabled' => true, 'max_words' => 20, 'max_serves_per_word' => 3]]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    $this->truncateTablesForAllConnections();
});

/**
 * @param  list<callable(): mixed>  $tasks
 * @return list<array{ok: bool, result?: mixed, error?: string, message?: string}>
 */
function lessonReviewRace(array $tasks): array
{
    ForkSafe::releaseConnections();

    $dir = sys_get_temp_dir().'/lexiflow-lesson-review-race-'.getmypid().'-'.bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    $go = "{$dir}/go";
    $pids = [];

    foreach ($tasks as $i => $task) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('pcntl_fork failed');
        }

        if ($pid === 0) {
            $outcome = [];
            try {
                ForkSafe::releaseConnections();
                DB::statement("SET lock_timeout = '10s'");

                $deadline = microtime(true) + 10;
                while (! file_exists($go) && microtime(true) < $deadline) {
                    usleep(500);
                }

                $outcome = ['ok' => true, 'result' => $task()];
            } catch (Throwable $e) {
                $outcome = ['ok' => false, 'error' => $e::class, 'message' => $e->getMessage()];
            } finally {
                file_put_contents("{$dir}/{$i}.json", json_encode($outcome, JSON_THROW_ON_ERROR));
                DB::disconnect();
                posix_kill(posix_getpid(), SIGKILL);
            }
        }

        $pids[] = $pid;
    }

    touch($go);
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $results = [];
    foreach (array_keys($tasks) as $i) {
        /** @var array{ok: bool, result?: mixed, error?: string, message?: string} $decoded */
        $decoded = json_decode((string) file_get_contents("{$dir}/{$i}.json"), true, 512, JSON_THROW_ON_ERROR);
        $results[] = $decoded;
        unlink("{$dir}/{$i}.json");
    }
    unlink($go);
    rmdir($dir);

    return $results;
}

/** @return array{learner: Learner, lessons: array<string, int>} a learner who met the 5 words of two lessons today */
function lessonReviewRaceWorld(): array
{
    ['course' => $course, 'lessons' => $lessons, 'words' => $words] = Fx::course([1 => [1 => 5, 2 => 5]]);
    $learner = Learner::factory()->onboarded()->create(['timezone' => 'Asia/Tashkent', 'active_course_id' => $course->id]);
    Fx::learn($learner, [...$words['1.1']['active'], ...$words['1.2']['active']]);

    return ['learner' => $learner, 'lessons' => ['1.1' => $lessons['1.1']->id, '1.2' => $lessons['1.2']->id]];
}

function lessonReviewRaceOpen(int $learnerId, int $lessonId): int
{
    $lesson = app(CourseCatalogService::class)->lessonWithContext($lessonId);
    expect($lesson)->not->toBeNull();

    return (int) app(LessonReviewService::class)->open(Learner::query()->findOrFail($learnerId), $lesson)->id;
}

it('opens one round when three opens of the same lesson race', function (): void {
    ['learner' => $learner, 'lessons' => $lessons] = lessonReviewRaceWorld();

    $results = lessonReviewRace(array_fill(0, 3, fn (): int => lessonReviewRaceOpen($learner->id, $lessons['1.1'])));

    foreach ($results as $result) {
        expect($result['ok'])->toBeTrue(($result['error'] ?? '').' '.($result['message'] ?? ''));
    }
    expect(array_unique(array_column($results, 'result')))->toHaveCount(1)
        ->and(TrainingSession::query()->where('learner_id', $learner->id)->where('mode', 'lesson_review')->count())->toBe(1);
});

it('leaves one open round when the opens of two lessons race', function (): void {
    ['learner' => $learner, 'lessons' => $lessons] = lessonReviewRaceWorld();

    $results = lessonReviewRace([
        fn (): int => lessonReviewRaceOpen($learner->id, $lessons['1.1']),
        fn (): int => lessonReviewRaceOpen($learner->id, $lessons['1.2']),
    ]);

    foreach ($results as $result) {
        expect($result['ok'])->toBeTrue(($result['error'] ?? '').' '.($result['message'] ?? ''));
    }
    expect(TrainingSession::query()->where('learner_id', $learner->id)->where('mode', 'lesson_review')->where('status', 'open')->count())->toBe(1)
        ->and(TrainingSession::query()->where('learner_id', $learner->id)->where('mode', 'lesson_review')->count())->toBe(2);
});

it('counts an answer delivered twice at the same instant once', function (): void {
    ['learner' => $learner, 'lessons' => $lessons] = lessonReviewRaceWorld();
    $sessionId = lessonReviewRaceOpen($learner->id, $lessons['1.1']);
    $session = TrainingSession::query()->findOrFail($sessionId);
    $step = app(TrainingFlowService::class)->nextStep($session, $learner);
    $card = CardAnswers::cardOf($step);
    $answer = CardAnswers::correctAnswer($card);
    $before = DB::table('word_repetitions')->where('learner_id', $learner->id)->orderBy('id')->get()->map(fn (object $r): array => (array) $r)->all();

    $results = lessonReviewRace(array_fill(0, 2, function () use ($sessionId, $learner, $card, $answer): string {
        try {
            app(TrainingFlowService::class)->answer(
                TrainingSession::query()->findOrFail($sessionId),
                Learner::query()->findOrFail($learner->id),
                (int) $card['word_id'],
                $answer,
                800,
                (string) $card['serve_token'],
            );

            return 'accepted';
        } catch (ReviewSubmissionException $e) {
            return $e->errorCode;
        }
    }));

    $outcomes = array_column($results, 'result');
    sort($outcomes);
    expect($outcomes)->toBe(['accepted', 'card_not_due'])
        ->and(TrainingSession::query()->findOrFail($sessionId)->meta)->toMatchArray(['answers' => 1, 'correct' => 1])
        ->and(DB::table('word_repetitions')->where('learner_id', $learner->id)->orderBy('id')->get()->map(fn (object $r): array => (array) $r)->all())->toBe($before)
        ->and(DB::table('training_reviews')->count())->toBe(0)
        ->and(DB::table('learner_daily_stats')->count())->toBe(0);
});
