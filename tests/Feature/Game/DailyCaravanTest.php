<?php

declare(strict_types=1);

use App\Domain\Game\Enums\RunFinishReason;
use App\Domain\Game\Enums\RunKind;
use App\Domain\Game\Services\Runs\RunService;
use App\Domain\Learning\Services\LearnerStatsService;
use App\Models\Course;
use App\Models\GameRun;
use App\Models\GameWallet;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\ProductEvent;
use App\Models\Word;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\Support\Game\GameKit;
use Tests\Support\Practice\PracticeKit;
use Tests\Support\QueryLog;

/*
 * docs/18 §12.3.1 (the pool of the daily caravan), §12.4, §12.5.3, §12.7.2, §12.8; docs/04 A.2a and A.18: the caravan of the day
 * is made of the due words (the hard first, topped up when there are few), one per local day, pays the `daily` reward, leaves the
 * cards where they are, and shows on `/today` and on the map.
 */

uses(RefreshDatabase::class);

const DAILY_NOW = '2026-11-05 10:00:00';

beforeEach(function (): void {
    CarbonImmutable::setTestNow(DAILY_NOW);
    Cache::flush();
    GameKit::openAccess();
    GameKit::noEvents();
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * A learner with an active course and `$due` due words and `$fresh` words that are NOT due (reviewed `10 - i` days ago, next review
 * in the future).
 *
 * @return array{0: Learner, 1: Course, 2: Lesson, 3: list<Word>, 4: list<Word>}
 */
function dailyWorld(int $due, int $fresh = 0, ?Lesson $in = null): array
{
    if ($in === null) {
        [$learner, $course, $lesson] = GameKit::stop(0);
    } else {
        [$learner, $lesson] = [Learner::factory()->create(), $in];
        $course = Course::query()->findOrFail($in->stage?->course_id);
    }
    $learner->update(['active_course_id' => $course->id, 'timezone' => 'UTC']);

    $dueWords = [];
    for ($i = 1; $i <= $due; $i++) {
        $word = GameKit::word($lesson, $learner, "dueword{$learner->id}x{$i}", "срок {$i}");
        WordRepetition::query()->where('word_id', $word->id)->update(['next_review_at' => now()->subHours($i), 'is_hard' => false, 'last_reviewed_at' => now()->subDays(3)]);
        $dueWords[] = $word;
    }
    $freshWords = [];
    for ($i = 1; $i <= $fresh; $i++) {
        $word = GameKit::word($lesson, $learner, "freshword{$learner->id}x{$i}", "позже {$i}");
        WordRepetition::query()->where('word_id', $word->id)->update(['next_review_at' => now()->addDays(5), 'is_hard' => false, 'last_reviewed_at' => now()->subDays(10 - $i)]);
        $freshWords[] = $word;
    }

    return [$learner, $course, $lesson, $dueWords, $freshWords];
}

function dailyPost(Learner $learner, array $extra = []): TestResponse
{
    return test()->withHeaders(PracticeKit::headers($learner))->postJson('/api/twa/game/runs', ['kind' => 'daily', ...$extra]);
}

function dailyToday(Learner $learner): TestResponse
{
    return test()->withHeaders(PracticeKit::headers($learner))->getJson('/api/twa/today');
}

/** @return list<int> the ids of the words the elements of the run are made of */
function dailyWordIds(GameRun $run): array
{
    $ids = array_map(static fn (array $element): int => (int) $element['word_id'], $run->refresh()->snapshot['elements']);
    sort($ids);

    return $ids;
}

/** @param list<Word> $words @return list<int> */
function dailyIds(array $words): array
{
    $ids = array_map(static fn (Word $word): int => $word->id, $words);
    sort($ids);

    return $ids;
}

/** @return array{code: string, details: array<string, mixed>} */
function dailyError(TestResponse $response, int $status, string $code): array
{
    $response->assertStatus($status)->assertJsonPath('error.code', $code);

    return ['code' => $code, 'details' => (array) $response->json('error.details', [])];
}

it('starts the caravan of the day from the due words only: tasks of words, no pairs, no authored, no free slot, the day key', function (): void {
    [$learner, $course, , $due] = dailyWorld(8, 4);

    $id = dailyPost($learner)->assertCreated()->assertJsonPath('kind', 'daily')->assertJsonPath('status', 'active')->json('id');

    $run = GameRun::query()->findOrFail($id);
    expect(dailyWordIds($run))->toBe(dailyIds($due))
        ->and($run->kind)->toBe(RunKind::Daily)
        ->and($run->course_id)->toBe($course->id)
        ->and($run->daily_key)->toBe($learner->id.':2026-11-05')
        ->and($run->local_date->toDateString())->toBe('2026-11-05')
        ->and($run->free_slot)->toBeNull()
        ->and($run->stage_id)->toBeNull()->and($run->lesson_id)->toBeNull()
        ->and($run->total)->toBe(8)
        ->and(array_unique(array_column($run->snapshot['elements'], 'kind')))->not->toContain('pairs_round', 'collocation', 'sentence', 'dictation');
});

it('puts the hard words first and stops at size_max: the oldest due words fill the rest', function (): void {
    [$learner, , , $due] = dailyWorld(14);
    // The last three are the hard ones, with the LATEST next review: they still come first.
    $hard = array_slice($due, -3);
    WordRepetition::query()->whereIn('word_id', dailyIds($hard))->update(['is_hard' => true]);

    $run = GameRun::query()->findOrFail(dailyPost($learner)->assertCreated()->json('id'));

    // `dailyWorld` makes word i due i hours ago: the larger i, the older. Not hard: 11 down to 1 -> ... the hard 3 + the 7 oldest of the rest.
    $rest = array_slice($due, 0, 11);
    $oldest = array_slice(array_reverse($rest), 0, 7);
    expect(dailyWordIds($run))->toBe(dailyIds([...$hard, ...$oldest]))
        ->and($run->total)->toBe(10);
});

it('does not count the words that are not due, inactive, or still in a fresh step of learning', function (): void {
    [$learner, , $lesson, $due] = dailyWorld(7);
    $gone = GameKit::word($lesson, $learner, 'goneword', 'ушло');
    WordRepetition::query()->where('word_id', $gone->id)->update(['next_review_at' => now()->subDay()]);
    $gone->update(['is_active' => false]);
    $step = GameKit::word($lesson, $learner, 'stepword', 'шаг', state: 'learning');
    WordRepetition::query()->where('word_id', $step->id)->update(['next_review_at' => now()->subMinute()]);

    $run = GameRun::query()->findOrFail(dailyPost($learner)->assertCreated()->json('id'));

    expect(dailyWordIds($run))->not->toContain($gone->id, $step->id)->and(dailyWordIds($run))->toBe(dailyIds($due));
});

it('tops up a short pool to size_min with the introduced words repeated the longest ago', function (): void {
    [$learner, , , $due, $fresh] = dailyWorld(3, 6);
    // `dailyWorld` reviewed freshword i (10 - i) days ago: the first is the oldest. A word never answered goes after the answered ones.
    $never = GameKit::word(Lesson::query()->firstOrFail(), $learner, 'neverword', 'никогда');
    WordRepetition::query()->where('word_id', $never->id)->update(['next_review_at' => now()->addDays(2), 'last_reviewed_at' => null]);

    $run = GameRun::query()->findOrFail(dailyPost($learner)->assertCreated()->json('id'));

    expect(dailyWordIds($run))->toBe(dailyIds([...$due, ...array_slice($fresh, 0, 5)]))
        ->and($run->total)->toBe(8);
});

it('plays a pool of exactly min_elements words: the top-up is not needed', function (): void {
    [$learner, , , $due] = dailyWorld(6, 3);

    $run = GameRun::query()->findOrFail(dailyPost($learner)->assertCreated()->json('id'));

    expect(dailyWordIds($run))->toBe(dailyIds($due));
});

it('refuses with 422 game_not_enough_material {reason: no_words} when even the introduced words are too few, and writes the event', function (): void {
    [$learner] = dailyWorld(3, 2);

    $details = dailyError(dailyPost($learner), 422, 'game_not_enough_material')['details'];

    expect($details)->toMatchArray(['available' => 5, 'required' => 6, 'reason' => 'no_words'])
        ->and(GameRun::query()->count())->toBe(0)
        ->and(ProductEvent::query()->where('name', 'game.not_enough_material')->count())->toBe(1);
});

it('refuses a learner without any word with the same 422', function (): void {
    $learner = Learner::factory()->create();

    dailyError(dailyPost($learner), 422, 'game_not_enough_material');
});

it('uses the course of the first word when the learner has no active course', function (): void {
    [$learner, $course] = dailyWorld(7);
    $learner->update(['active_course_id' => null]);

    $run = GameRun::query()->findOrFail(dailyPost($learner)->assertCreated()->json('id'));

    expect($run->course_id)->toBe($course->id);
});

it('is one per local day: a second start is 409 game_daily_done with resets_at, while the run is live and after it ends', function (): void {
    [$learner] = dailyWorld(8);
    $id = dailyPost($learner)->assertCreated()->json('id');

    $live = dailyError(dailyPost($learner), 409, 'game_daily_done')['details'];
    expect($live)->toBe(['resets_at' => '2026-11-06T00:00:00.000Z']);

    $run = GameRun::query()->findOrFail($id);
    GameKit::play(app(RunService::class), $run, $learner);
    expect(dailyError(dailyPost($learner), 409, 'game_daily_done')['details'])->toBe(['resets_at' => '2026-11-06T00:00:00.000Z'])
        ->and(GameRun::query()->where('daily_key', $learner->id.':2026-11-05')->count())->toBe(1);

    // The next local day the caravan is there again.
    CarbonImmutable::setTestNow('2026-11-06 09:00:00');
    WordRepetition::query()->update(['next_review_at' => now()->subHour()]);
    $next = dailyPost($learner)->assertCreated()->json('id');
    expect(GameRun::query()->findOrFail($next)->daily_key)->toBe($learner->id.':2026-11-06');
});

it('counts the day by the learner\'s zone', function (): void {
    [$learner] = dailyWorld(8);
    $learner->update(['timezone' => 'Asia/Tashkent']); // 10:00 UTC = 15:00 on 2026-11-05, the day ends at 19:00 UTC

    $id = dailyPost($learner)->assertCreated()->json('id');

    expect(GameRun::query()->findOrFail($id)->daily_key)->toBe($learner->id.':2026-11-05')
        ->and(dailyError(dailyPost($learner), 409, 'game_daily_done')['details'])->toBe(['resets_at' => '2026-11-05T19:00:00.000Z']);
});

it('keeps the day spent when the caravan expired unplayed and was not swept yet', function (): void {
    [$learner] = dailyWorld(8);
    $id = dailyPost($learner)->assertCreated()->json('id');
    CarbonImmutable::setTestNow('2026-11-05 11:00:00');

    dailyError(dailyPost($learner), 409, 'game_daily_done');
    expect(GameRun::query()->findOrFail($id)->daily_key)->not->toBeNull();
});

it('does not start over another live run, unless asked: the other one is abandoned', function (): void {
    [$learner, , $lesson] = dailyWorld(8);
    $stop = app(RunService::class)->start($learner, Course::query()->firstOrFail()->slug, $lesson->id);

    $busy = dailyError(dailyPost($learner), 409, 'game_run_active')['details'];
    expect($busy['run_id'])->toBe($stop->id)->and(GameRun::query()->where('kind', 'daily')->count())->toBe(0);

    $id = dailyPost($learner, ['replace_active' => true])->assertCreated()->json('id');
    expect($stop->refresh()->finish_reason)->toBe(RunFinishReason::Abandoned)->and(GameRun::query()->findOrFail($id)->isActive())->toBeTrue();
});

it('is closed with 403 game_unavailable while the game is off', function (): void {
    [$learner] = dailyWorld(8);
    config(['game.access' => 'off']);

    dailyError(dailyPost($learner), 403, 'game_unavailable');
    expect(GameRun::query()->count())->toBe(0);
});

it('validates the body: a stop still needs its course and lesson, a caravan needs neither', function (): void {
    [$learner] = dailyWorld(8);

    test()->withHeaders(PracticeKit::headers($learner))->postJson('/api/twa/game/runs', ['kind' => 'stop'])->assertStatus(422);
    test()->withHeaders(PracticeKit::headers($learner))->postJson('/api/twa/game/runs', ['kind' => 'boss'])->assertStatus(422);
    dailyPost($learner, ['course' => 'ignored', 'lesson_id' => 5])->assertCreated();
});

it('leaves the due cards where they are: the correct and the wrong answers do not move the schedule', function (): void {
    [$learner, , , $due] = dailyWorld(8);
    $before = WordRepetition::query()->where('learner_id', $learner->id)->orderBy('id')->get()->map->getAttributes()->all();

    $run = GameRun::query()->findOrFail(dailyPost($learner)->assertCreated()->json('id'));
    GameKit::play(app(RunService::class), $run, $learner, wrongAt: [1]);

    $after = WordRepetition::query()->where('learner_id', $learner->id)->orderBy('id')->get()->map->getAttributes()->all();
    // Everything the run could touch is the same: the interval, the ease, the state and the next review stay; a wrong answer on a card
    // that is due already has nothing to pull closer. (`updated_at` and `is_hard` of the pulled ones are the SRS rule's, not the run's.)
    $keep = ['id', 'state', 'repetitions', 'interval_days', 'easiness_factor', 'next_review_at', 'last_reviewed_at', 'lapses', 'stability', 'difficulty', 'scheduled_days'];
    foreach ($before as $i => $row) {
        expect(array_intersect_key($after[$i], array_flip($keep)))->toBe(array_intersect_key($row, array_flip($keep)));
    }
    expect(app(LearnerStatsService::class)->dueCount($learner))->toBe(count($due))
        ->and(DB::table('training_reviews')->count())->toBe(0);
});

it('pays the reward of the day: 10 + 5 per star for a completed caravan, nothing for a failed one', function (): void {
    [$learner, , $lesson] = dailyWorld(8);
    $run = GameRun::query()->findOrFail(dailyPost($learner)->assertCreated()->json('id'));

    $run = GameKit::play(app(RunService::class), $run, $learner);

    expect($run->finish_reason)->toBe(RunFinishReason::Completed)
        ->and($run->stars)->toBe(3)
        ->and($run->coins_awarded)->toBe(25)
        ->and(GameWallet::query()->where('learner_id', $learner->id)->value('balance'))->toBe(25);

    [$loser] = dailyWorld(8, in: $lesson);
    $failing = GameRun::query()->findOrFail(dailyPost($loser)->assertCreated()->json('id'));
    $failing = GameKit::play(app(RunService::class), $failing, $loser, wrongAt: [0, 1, 2, 3]);

    expect($failing->finish_reason)->toBe(RunFinishReason::Failed)
        ->and($failing->coins_awarded)->toBe(0)
        ->and((int) GameWallet::query()->where('learner_id', $loser->id)->value('balance'))->toBe(0);
});

it('pays 10 + 5 per star for a completed caravan with lost flasks: fewer stars, fewer coins', function (): void {
    [$learner] = dailyWorld(8);
    $run = GameRun::query()->findOrFail(dailyPost($learner)->assertCreated()->json('id'));

    $run = GameKit::play(app(RunService::class), $run, $learner, wrongAt: [0]);

    expect($run->finish_reason)->toBe(RunFinishReason::Completed)
        ->and($run->stars)->toBeLessThan(3)
        ->and($run->coins_awarded)->toBe(10 + 5 * $run->stars);
});

it('writes game.daily_completed next to game.run_finished for a completed caravan only', function (): void {
    [$learner, , $lesson] = dailyWorld(8);
    $run = GameRun::query()->findOrFail(dailyPost($learner)->assertCreated()->json('id'));
    $run = GameKit::play(app(RunService::class), $run, $learner, wrongAt: [2]);

    $completed = ProductEvent::query()->where('name', 'game.daily_completed')->get();
    expect($completed)->toHaveCount(1)
        ->and((array) $completed[0]->props)->toEqualCanonicalizing(['run_id' => $run->id, 'due_count' => 8, 'correct' => $run->correct])
        ->and(ProductEvent::query()->where('name', 'game.run_finished')->count())->toBe(1);

    [$loser] = dailyWorld(8, in: $lesson);
    $failing = GameRun::query()->findOrFail(dailyPost($loser)->assertCreated()->json('id'));
    GameKit::play(app(RunService::class), $failing, $loser, wrongAt: [0, 1, 2, 3]);

    expect(ProductEvent::query()->where('name', 'game.daily_completed')->count())->toBe(1)
        ->and(ProductEvent::query()->where('name', 'game.run_finished')->count())->toBe(2);
});

it('shows the caravan on /today: available with due words, the live run, spent after the play, nothing while the game is closed', function (): void {
    [$learner] = dailyWorld(8);

    dailyToday($learner)->assertOk()->assertJsonPath('game', ['daily_available' => true, 'active_run' => null]);

    $id = dailyPost($learner)->assertCreated()->json('id');
    $live = dailyToday($learner)->assertOk()->json('game');
    expect($live['daily_available'])->toBeFalse()
        ->and($live['active_run'])->toBe(['id' => $id, 'kind' => 'daily', 'progress' => ['answered' => 0, 'total' => 8]]);

    GameKit::play(app(RunService::class), GameRun::query()->findOrFail($id), $learner);
    dailyToday($learner)->assertOk()->assertJsonPath('game', ['daily_available' => false, 'active_run' => null]);
});

it('has no caravan on /today without due words', function (): void {
    $learner = Learner::factory()->create();

    dailyToday($learner)->assertOk()->assertJsonPath('game', ['daily_available' => false, 'active_run' => null]);
});

it('reads nothing of the game tables for /today while the game is closed, and one statement while it is open', function (): void {
    [$learner] = dailyWorld(8);
    $headers = PracticeKit::headers($learner);
    $read = fn (): array => QueryLog::during(fn () => test()->withHeaders($headers)->getJson('/api/twa/today')->assertOk());

    config(['game.access' => 'off']);
    $closed = $read();
    config(['game.access' => 'all']);
    $open = $read();

    // The game TABLES (`from` / `join` / `into` / `update` "game_…"): the streak reads the column `learner_daily_stats.game_held_at`
    // whether the game is open or not (G2.10), which is no read of the game.
    expect(QueryLog::matching($closed, '/\b(from|join|into|update)\s+["`]game_/i'))->toBe([])
        ->and(QueryLog::matching($open, '/["`]game_runs["`]/'))->toHaveCount(1)
        ->and(count($open) - count($closed))->toBeLessThanOrEqual(1);
});

it('says the same on the map and on /today: one definition of the day', function (): void {
    [$learner, $course] = dailyWorld(8);
    $map = fn (): array => (array) test()->withHeaders(PracticeKit::headers($learner))->getJson("/api/twa/game/map?course={$course->slug}")->assertOk()->json();

    expect($map()['daily'])->toMatchArray(['available' => true, 'done_today' => false, 'due_count' => 8, 'resets_at' => '2026-11-06T00:00:00.000Z']);

    $id = dailyPost($learner)->assertCreated()->json('id');
    expect($map()['daily'])->toMatchArray(['available' => false, 'done_today' => false])
        ->and($map()['active_run'])->toBe(['id' => $id, 'kind' => 'daily', 'progress' => ['answered' => 0, 'total' => 8]]);

    GameKit::play(app(RunService::class), GameRun::query()->findOrFail($id), $learner);
    expect($map()['daily'])->toMatchArray(['available' => false, 'done_today' => true, 'due_count' => 8])
        ->and($map()['active_run'])->toBeNull();
});
