<?php

declare(strict_types=1);

use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Game\Enums\ClassCaravanStatus;
use App\Domain\Game\Services\Class\ClassBossService;
use App\Domain\Game\Services\Class\ClassCaravanService;
use App\Domain\Game\Services\Class\ClassCaravanSummary;
use App\Domain\Game\Services\Runs\RunService;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Jobs\PostClassCaravanSummaryJob;
use App\Models\ClassCaravan;
use App\Models\ClassCaravanMember;
use App\Models\GameRun;
use App\Models\GroupMember;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Game\GameKit;
use Tests\Support\Game\GameRace;
use Tests\Support\Practice\PracticeKit;

/*
 * docs/18 §12.5.4 (G2.9a): the real races of the caravan of a class. Several OS processes, each with its own PostgreSQL connection, hit
 * the same group / caravan at the same instant. The unique `class_caravans.active_group_id`, the `FOR UPDATE` of the caravan's row at the
 * end of every finish and the cache lock plus the persisted marker of the summary job are what the assertions rely on, so this file runs
 * only on pgsql with pcntl (and a shared cache store for the lock: Redis). Data is committed (DatabaseTruncation).
 */

if ((getenv('DB_CONNECTION') ?: 'sqlite') === 'pgsql') {
    uses(DatabaseTruncation::class);
}

beforeEach(function (): void {
    if (config('database.default') !== 'pgsql') {
        $this->markTestSkipped('Needs PostgreSQL: real parallel connections and row locks.');
    }
    if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
        $this->markTestSkipped('Needs the pcntl and posix extensions to fork workers.');
    }

    CarbonImmutable::setTestNow('2026-11-05 10:00:00');
    GameKit::openAccess();
    GameKit::noEvents();
    config(['game.class.centers_only' => true]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    if (config('database.default') === 'pgsql') {
        DB::reconnect();
        $this->truncateTablesForAllConnections();
    }
});

/**
 * A course with a stop of 8 words, `$members` learners who know them all, the active group of a live center, its teacher.
 *
 * @return array{group: TelegramGroup, teacher: User, learners: list<Learner>, slug: string, lesson_id: int}
 */
function classRaceWorld(int $members): array
{
    [$course, $lesson] = PracticeKit::course('class-race');
    $learners = [];
    for ($i = 0; $i < $members; $i++) {
        $learners[] = Learner::factory()->create(['first_name' => "Racer {$i}"]);
    }
    for ($n = 1; $n <= 8; $n++) {
        $word = GameKit::word($lesson, $learners[0], "raceword{$n}", "перевод {$n}");
        foreach (array_slice($learners, 1) as $learner) {
            PracticeKit::introduce($learner, $word, 'review');
        }
    }
    $organization = Organization::factory()->create();
    Subscription::factory()->forOrganization($organization)->create();
    $group = TelegramGroup::factory()->create(['organization_id' => $organization->id, 'course_id' => $course->id]);
    foreach ($learners as $learner) {
        GroupMember::factory()->create(['telegram_group_id' => $group->id, 'learner_id' => $learner->id]);
    }
    $teacher = User::factory()->create(['is_active' => true]);
    DB::table('teacher_groups')->insert(['user_id' => $teacher->id, 'telegram_group_id' => $group->id, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);

    return ['group' => $group, 'teacher' => $teacher, 'learners' => $learners, 'slug' => $course->slug, 'lesson_id' => $lesson->id];
}

/**
 * A run of the learner played to its last answer (all right), which is NOT sent: the worker sends it.
 *
 * @return array{run_id: int, element_id: string, answer: array<string, mixed>}
 */
function classRaceToLastAnswer(Learner $learner, string $slug, int $lessonId): array
{
    $runs = app(RunService::class);
    $run = $runs->start($learner, $slug, $lessonId);
    for ($i = 0; $i < $run->total - 1; $i++) {
        $element = GameKit::current($run);
        foreach (GameKit::rightAnswers($element) as $answer) {
            $runs->answer($run, $learner, (string) Str::uuid(), $element['id'], $answer);
        }
    }
    $last = GameKit::current($run);
    $answers = GameKit::rightAnswers($last);
    $final = array_pop($answers);
    foreach ($answers as $answer) {
        $runs->answer($run, $learner, (string) Str::uuid(), $last['id'], $answer);
    }

    return ['run_id' => $run->id, 'element_id' => $last['id'], 'answer' => $final];
}

it('starts one caravan when two teachers start the caravan of the same group at once: the other is told class_caravan_active', function (): void {
    $w = classRaceWorld(1);
    $groupId = $w['group']->id;
    $teacherId = $w['teacher']->id;

    $results = GameRace::run(array_fill(0, 2, fn (): int => app(ClassCaravanService::class)->start(TelegramGroup::query()->findOrFail($groupId), User::query()->findOrFail($teacherId))->id));

    expect(GameRace::crashes($results))->toBe([])
        ->and(GameRace::outcomes($results))->toBe(['class_caravan_active', 'ok'])
        ->and(ClassCaravan::query()->where('telegram_group_id', $groupId)->count())->toBe(1)
        ->and(ClassCaravan::query()->where('active_group_id', $groupId)->count())->toBe(1)
        ->and(GameRace::events('game.class_started'))->toHaveCount(1);
});

it('adds the stars of three members who finish their runs at the same instant: the sum, three runs, three rows, no deadlock', function (): void {
    $w = classRaceWorld(3);
    $caravan = app(ClassCaravanService::class)->start($w['group'], $w['teacher']);
    $pending = [];
    foreach ($w['learners'] as $learner) {
        $pending[] = ['learner_id' => $learner->id, ...classRaceToLastAnswer($learner, $w['slug'], $w['lesson_id'])];
    }

    $results = GameRace::run(array_map(
        fn (array $p): Closure => fn (): string => app(RunService::class)->answer($p['run_id'], GameRace::learner($p['learner_id']), (string) Str::uuid(), $p['element_id'], $p['answer'])->run->status->value,
        $pending,
    ));

    $caravan->refresh();
    $runs = GameRun::query()->whereIn('id', array_column($pending, 'run_id'))->get();
    expect(GameRace::crashes($results))->toBe([])
        ->and(GameRace::outcomes($results))->toBe(['ok', 'ok', 'ok'])
        ->and($runs->every(fn (GameRun $r): bool => $r->status->value === 'finished' && $r->class_caravan_id === $caravan->id))->toBeTrue()
        ->and($runs->sum('stars'))->toBe(9)
        ->and($caravan->stars_total)->toBe(9)
        ->and($caravan->runs_total)->toBe(3)
        ->and(ClassCaravanMember::query()->where('class_caravan_id', $caravan->id)->count())->toBe(3)
        ->and((int) ClassCaravanMember::query()->where('class_caravan_id', $caravan->id)->sum('stars'))->toBe(9)
        ->and((int) ClassCaravanMember::query()->where('class_caravan_id', $caravan->id)->sum('runs'))->toBe(3);
});

it('posts the summary once when the job is delivered three times at once (two workers and a retry): one message, one marker, one event', function (): void {
    $w = classRaceWorld(2);
    $caravan = ClassCaravan::factory()->finished()->create(['telegram_group_id' => $w['group']->id, 'course_id' => ClassCaravan::factory()->make()->course_id ?? null]);
    ClassCaravanMember::factory()->create(['class_caravan_id' => $caravan->id, 'learner_id' => $w['learners'][0]->id, 'runs' => 2, 'stars' => 5]);
    $caravanId = $caravan->id;
    $sends = sys_get_temp_dir().'/lexiflow-class-summary-'.bin2hex(random_bytes(4)).'.log';
    @unlink($sends);

    $results = GameRace::run(array_fill(0, 3, function () use ($caravanId, $sends): string {
        $telegram = Mockery::mock(TelegramClient::class);
        $telegram->shouldReceive('sendMessage')->andReturnUsing(function (int|string $chatId, string $text) use ($sends): void {
            file_put_contents($sends, $chatId.' '.md5($text)."\n", FILE_APPEND | LOCK_EX);
        });
        app()->instance(TelegramClient::class, $telegram);
        (new PostClassCaravanSummaryJob($caravanId))->handle($telegram, app(ClassCaravanService::class), app(ClassCaravanSummary::class), app(ProductEventRecorder::class), app(LocaleResolver::class));

        return 'done';
    }));

    $lines = is_file($sends) ? array_values(array_filter(explode("\n", (string) file_get_contents($sends)))) : [];
    @unlink($sends);
    expect(GameRace::crashes($results))->toBe([])
        ->and(GameRace::outcomes($results))->toBe(['ok', 'ok', 'ok'])
        ->and($lines)->toHaveCount(1)
        ->and($lines[0])->toStartWith($w['group']->chat_id.' ')
        ->and($caravan->fresh()?->summary_posted_at)->not->toBeNull()
        ->and($caravan->fresh()?->status)->toBe(ClassCaravanStatus::Finished)
        ->and(GameRace::events('game.class_summary_posted'))->toHaveCount(1);
});

it('lets a member fight again when the untouched fight is closed and started over at the same instant: the old one lets go of its key, one fight is on, no deadlock', function (string $pair): void {
    $w = classRaceWorld(2);
    $caravan = app(ClassCaravanService::class)->start($w['group'], $w['teacher']);
    $caravan->update(['boss_unlocked_at' => now()]);
    $learner = $w['learners'][0];
    $learnerId = $learner->id;
    $caravanId = $caravan->id;
    $first = app(ClassBossService::class)->start($learner, $caravan->id);
    $firstId = $first->id;
    $key = ClassCaravanService::classBossKey($caravan, $learner);

    $exit = fn (): string => app(RunService::class)->abandon($firstId, GameRace::learner($learnerId))->finish_reason?->value ?? 'active';
    $over = fn (): int => app(ClassBossService::class)->start(GameRace::learner($learnerId), $caravanId, true)->id;
    $results = GameRace::run($pair === 'an exit and a start over' ? [$exit, $over] : [$over, $over]);

    // G2.13 (docs/18 §12.11): a fight without an answer was no attempt. The exit frees the key under the run's row; the start over
    // either closes the fight itself (and frees the key in the same UPDATE, before its INSERT) or finds it closed; two starts over meet
    // on the unique key, and the loser gets the fight the winner has just started - never "class_boss_done".
    $old = GameRun::query()->findOrFail($firstId);
    $live = GameRun::query()->where('active_learner_id', $learnerId)->first();
    expect(GameRace::crashes($results))->toBe([])
        ->and(GameRace::outcomes($results))->toBe(['ok', 'ok'], (string) json_encode($results))
        ->and($old->finish_reason?->value)->toBe('abandoned')
        ->and($old->class_boss_key)->toBeNull()
        ->and($old->answered)->toBe(0)
        ->and($live?->kind->value)->toBe('class_boss')
        ->and($live?->class_boss_key)->toBe($key)
        ->and(GameRun::query()->where('kind', 'class_boss')->where('learner_id', $learnerId)->count())->toBe(2)
        ->and(GameRun::query()->where('class_boss_key', $key)->count())->toBe(1);
    if ($pair === 'two starts over') {
        expect(array_unique(array_column($results, 'value')))->toHaveCount(1);
    }
})->with(['an exit and a start over', 'two starts over']);
