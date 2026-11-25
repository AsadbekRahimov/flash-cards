<?php

declare(strict_types=1);

/*
 * The word points in the game (docs/32 S6, §3.2, §3.6; owner's decision В6): the lexeme of a word rides in the snapshot of the run, the
 * final answer to a word task (translate_fwd / translate_back / listen / spell / gap) is credited under the lock of the run - a right
 * answer or a typo passes, a wrong one or a skip fails, a hint of the element before the answer assists (both close the attempt of the
 * day); a round of pairs and the authored tasks never count. A repeated request_id answers the stored body and credits nothing. A week
 * on v2 takes the run's league points from its credits and stops the v1 `game_points`; `leagues.scoring.game_enabled = false` takes the
 * game out. With the points off the game is what it was (GameLeagueStreakTest, GameIsolationTest: unchanged).
 */

use App\Domain\Engagement\Enums\ScoreKind;
use App\Domain\Engagement\Enums\ScoreSource;
use App\Domain\Game\DTO\RunState;
use App\Domain\Game\Enums\TaskKind;
use App\Domain\Game\Services\Runs\RunService;
use App\Models\GameRun;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\LearnerLexeme;
use App\Models\Lexeme;
use App\Models\ScoreCredit;
use App\Models\Word;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Game\GameKit;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Thursday 2026-11-05 15:00 in Tashkent.
    CarbonImmutable::setTestNow('2026-11-05 10:00:00');
    GameKit::openAccess();
    GameKit::noEvents();
    config([
        'leagues.timezone' => 'Asia/Tashkent',
        'leagues.scoring.mode' => 'live',
        'leagues.scoring.game_enabled' => true,
        'leagues.scoring.versions.2.starts_on' => '2026-11-01',
    ]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * A stop whose words all have a lexeme, the learner's cards due after a long interval and their lexemes confirmed (or not).
 *
 * @return array{0: Learner, 1: GameRun, 2: list<Word>}
 */
function gwsRun(bool $confirmed = true, int $words = 8, bool $linked = true): array
{
    [$learner, $course, $lesson, $list] = GameKit::stop($words);
    foreach ($list as $word) {
        if ($linked) {
            DB::table('words')->where('id', $word->id)->update(['lexeme_id' => Lexeme::factory()->create()->id]);
        }
    }
    DB::table('word_repetitions')->where('learner_id', $learner->id)->update([
        'state' => 'review', 'repetitions' => 3, 'interval_days' => 8,
        'last_reviewed_at' => now()->subDays(8)->subHour(), 'next_review_at' => now()->subHour(),
    ]);
    if ($linked && $confirmed) {
        foreach (Word::query()->whereIn('id', array_map(fn (Word $w): int => $w->id, $list))->pluck('lexeme_id') as $lexemeId) {
            LearnerLexeme::factory()->create(['learner_id' => $learner->id, 'lexeme_id' => $lexemeId, 'confirmed_on' => '2026-10-20']);
        }
    }

    return [$learner, app(RunService::class)->start($learner, $course->slug, $lesson->id), $list];
}

/** @return array<int, array<string, mixed>> index => element of the word tasks of the run */
function gwsWordTasks(GameRun $run): array
{
    return array_filter($run->refresh()->snapshot['elements'], fn (array $e): bool => TaskKind::from($e['kind'])->isWordTask());
}

/** Answers the current element; `$right` false gives the wrong answer. @return array<string, mixed> the stored body */
function gwsAnswer(GameRun $run, Learner $learner, bool $right = true, ?string $requestId = null): array
{
    $element = GameKit::current($run) ?? throw new RuntimeException('The run is over.');
    $answers = $right ? GameKit::rightAnswers($element) : [GameKit::wrongAnswer($element)];
    $body = [];
    foreach ($answers as $answer) {
        $body = app(RunService::class)->answer($run, $learner, $requestId ?? (string) Str::uuid(), $element['id'], $answer)->body;
    }

    return $body;
}

it('carries the lexeme of a word in its word tasks, never to the client, and none for a word outside the catalog', function (): void {
    [$learner, $run] = gwsRun();
    $tasks = gwsWordTasks($run);
    expect($tasks)->not->toBe([]);
    foreach ($tasks as $element) {
        expect($element['lexeme_id'])->toBe((int) Word::query()->whereKey($element['word_id'])->value('lexeme_id'));
    }
    foreach ($run->snapshot['elements'] as $element) {
        if (! TaskKind::from($element['kind'])->isWordTask()) {
            expect($element)->not->toHaveKey('lexeme_id');
        }
    }
    $view = app(RunService::class)->view($run, $learner);
    expect(json_encode($view->current))->not->toContain('lexeme_id');
});

it('puts no lexeme into the tasks of words outside the catalog, and such a run credits nothing', function (): void {
    [$learner, $run] = gwsRun(linked: false);
    foreach (gwsWordTasks($run) as $element) {
        expect($element)->not->toHaveKey('lexeme_id');
    }

    GameKit::play(app(RunService::class), $run, $learner);

    expect(LearnerLexeme::query()->count())->toBe(0)
        ->and(ScoreCredit::query()->count())->toBe(0);
});

it('credits each word of a run once, from the game, and makes the run\'s league points of its weekly credits', function (): void {
    [$learner, $run] = gwsRun();
    $wordTasks = gwsWordTasks($run);

    GameKit::play(app(RunService::class), $run, $learner);
    $run->refresh();

    $credits = ScoreCredit::query()->where('learner_id', $learner->id)->get();
    $distinct = collect($wordTasks)->pluck('lexeme_id')->unique()->count();
    expect($credits)->toHaveCount($distinct)
        ->and($credits->pluck('source')->unique()->all())->toBe([ScoreSource::Game])
        ->and($credits->pluck('source_id')->unique()->all())->toBe([$run->id])
        ->and($credits->pluck('kind')->unique()->all())->toBe([ScoreKind::Long])
        ->and($run->league_points)->toBe($credits->sum('week_points'))
        ->and($run->league_points)->toBe(5 * $distinct)
        // Both periods on v2: the v1 game points are not written any more; the day is still held by the game.
        ->and(LearnerDailyStats::query()->where('learner_id', $learner->id)->sole()->game_points)->toBe(0)
        ->and(LearnerDailyStats::query()->where('learner_id', $learner->id)->sole()->game_held_at)->not->toBeNull()
        ->and(app(RunService::class)->view($run, $learner)->summary['league_points'] ?? null)->toBe(5 * $distinct);
});

it('shows the score of a counted answer in the stored body and answers a repeated request_id from it without a second credit', function (): void {
    [$learner, $run] = gwsRun();
    // Up to the first word task.
    for ($guard = 0; $guard < 20 && ! TaskKind::from(GameKit::current($run)['kind'])->isWordTask(); $guard++) {
        gwsAnswer($run, $learner);
    }
    $before = ScoreCredit::query()->count();

    $requestId = (string) Str::uuid();
    $body = gwsAnswer($run, $learner, requestId: $requestId);
    $element = collect($run->refresh()->snapshot['elements'])->firstWhere('id', DB::table('game_run_attempts')->where('request_id', $requestId)->value('element_id'));
    $again = app(RunService::class)->answer($run, $learner, $requestId, $element['id'], GameKit::rightAnswers($element)[0])->body;

    expect($body['score'] ?? null)->toMatchArray(['kind' => 'long', 'reason' => 'credited', 'week' => 5])
        ->and($again)->toBe($body)
        ->and(ScoreCredit::query()->count())->toBe($before + 1);
});

it('closes the attempt of the day with a wrong answer and with a hint before a right one', function (bool $hint): void {
    [$learner, $run] = gwsRun();
    for ($guard = 0; $guard < 20 && ! TaskKind::from(GameKit::current($run)['kind'])->isWordTask(); $guard++) {
        gwsAnswer($run, $learner);
    }
    $element = GameKit::current($run);
    if ($hint) {
        // The hint of the bazaar or of the booster, as RunService / BoosterService leave it on the element.
        $state = RunState::fromArray($run->refresh()->state);
        $state->markHinted($element['id'], ['kind' => 'test']);
        $run->forceFill(['state' => $state->toArray()])->save();
    }

    $body = gwsAnswer($run, $learner, right: $hint);

    $lexeme = LearnerLexeme::query()->where('learner_id', $learner->id)->where('lexeme_id', $element['lexeme_id'])->sole();
    expect($body['score']['reason'] ?? null)->toBe('closed')
        ->and($lexeme->last_attempt_on?->toDateString())->toBe('2026-11-05')
        ->and(ScoreCredit::query()->where('lexeme_id', $element['lexeme_id'])->count())->toBe(0);
})->with(['wrong' => false, 'hinted' => true]);

it('never counts a round of pairs or an authored task', function (): void {
    [$learner, $course, $lesson] = GameKit::richStop();
    DB::table('words')->whereIn('lesson_id', [$lesson->id])->get(['id'])->each(fn (object $w) => DB::table('words')->where('id', $w->id)->update(['lexeme_id' => Lexeme::factory()->create()->id]));
    $run = app(RunService::class)->start($learner, $course->slug, $lesson->id);

    $kinds = [];
    for ($guard = 0; $guard < 60 && ($element = GameKit::current($run)) !== null; $guard++) {
        $credits = ScoreCredit::query()->count();
        $attempts = LearnerLexeme::query()->count();
        foreach (GameKit::rightAnswers($element) as $answer) {
            if (! $run->refresh()->isActive()) {
                break;
            }
            app(RunService::class)->answer($run, $learner, (string) Str::uuid(), $element['id'], $answer);
        }
        if (! TaskKind::from($element['kind'])->isWordTask()) {
            $kinds[] = $element['kind'];
            expect(ScoreCredit::query()->count())->toBe($credits)
                ->and(LearnerLexeme::query()->count())->toBe($attempts);
        }
    }

    expect($kinds)->not->toBe([]);
});

it('takes the game out of the word points with the switch', function (): void {
    config(['leagues.scoring.game_enabled' => false]);
    [$learner, $run] = gwsRun();

    GameKit::play(app(RunService::class), $run, $learner);

    expect(ScoreCredit::query()->count())->toBe(0)
        ->and(LearnerLexeme::query()->whereNotNull('last_attempt_on')->count())->toBe(0)
        ->and($run->refresh()->league_points)->toBe(0)
        ->and(LearnerDailyStats::query()->where('learner_id', $learner->id)->sole()->game_points)->toBe(0);
});

it('keeps the v1 game points of a run with the points recorded in shadow', function (): void {
    config(['leagues.scoring.mode' => 'shadow']);
    [$learner, $run] = gwsRun(confirmed: false);

    GameKit::play(app(RunService::class), $run, $learner);
    $run->refresh();

    $day = LearnerDailyStats::query()->where('learner_id', $learner->id)->sole();
    expect(ScoreCredit::query()->where('learner_id', $learner->id)->pluck('kind')->unique()->all())->toBe([ScoreKind::New])
        ->and(ScoreCredit::query()->sum('week_points'))->toBe(0)
        ->and($day->game_points)->toBeGreaterThan(0)
        ->and($run->league_points)->toBe($day->game_points)
        ->and(DB::table('game_run_attempts')->where('run_id', $run->id)->get()->every(fn (object $a): bool => ! str_contains((string) $a->result, '"score"')))->toBeTrue();
});

it('changes nothing of the game with the points off but the lexeme in the snapshot', function (): void {
    config(['leagues.scoring.mode' => 'off']);
    [$learner, $run] = gwsRun();

    GameKit::play(app(RunService::class), $run, $learner);

    expect(LearnerLexeme::query()->whereNotNull('last_attempt_on')->count())->toBe(0)
        ->and(ScoreCredit::query()->count())->toBe(0)
        ->and(DB::table('learner_score_days')->count())->toBe(0)
        ->and(LearnerDailyStats::query()->where('learner_id', $learner->id)->sole()->game_points)->toBeGreaterThan(0);
});
