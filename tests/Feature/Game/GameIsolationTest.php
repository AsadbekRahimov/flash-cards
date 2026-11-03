<?php

declare(strict_types=1);

use App\Models\Achievement;
use App\Models\AssignmentResult;
use App\Models\Battle;
use App\Models\Challenge;
use App\Models\GameProfile;
use App\Models\GameRun;
use App\Models\GameWallet;
use App\Models\LeagueMember;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\Lesson;
use App\Models\PracticeSession;
use App\Models\Referral;
use App\Models\Stage;
use App\Models\TrainingReview;
use App\Models\TrainingSession;
use App\Models\Word;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Practice\PracticeKit;
use Tests\Support\QueryLog;

/*
 * docs/18 §12.1 "Чего игра не делает", §12.4, §12.13: the game writes its OWN tables (§12.2) and `product_events` and nothing else -
 * and in `word_repetitions` only `next_review_at`, `is_hard` and `updated_at` (rule §5, from G2.2a). It never touches the schedule of
 * the repetitions, the training, the practice, the battles, the assignments, the challenges or the referrals.
 *
 * This is the skeleton of G2.1, the way PracticeIsolationTest was the skeleton of 18.1: the harness and the three nets, run over what
 * exists so far (the schema, the models, the access and the block of `/me`). The runs of the game themselves come with G2.2a: the task
 * that adds them adds the full run of every kind of run HERE (the checksums, the written tables, the events of docs/18 §12.8) and
 * puts the tables it writes into GAME_ISOLATION_WRITTEN. The nets:
 *   1. the rows of the protected tables are the same after the run (a hash of every column of every row);
 *   2. the run issued no INSERT / UPDATE / DELETE against any table but those the game owns;
 *   3. the code of the game imports none of the services and models that write the learning of a learner.
 */

uses(RefreshDatabase::class);

/** The tables the game must not change (docs/18 §12.1); `word_repetitions` has its own narrow rule from G2.2a, see gameIsolationChecksums(). */
const GAME_ISOLATION_TABLES = [
    'training_reviews', 'training_sessions', 'practice_items', 'practice_item_versions', 'practice_sessions', 'practice_attempts',
    'battles', 'battle_participants', 'battle_answers', 'assignment_results', 'challenges', 'referrals', 'league_members', 'achievements',
];

/** What the game writes (docs/18 §12.2, §12.8), and no more: its tables and the events. */
const GAME_ISOLATION_WRITTEN = [
    'class_caravan_members', 'class_caravans', 'game_booster_uses', 'game_city_progress', 'game_coin_ledger', 'game_inventory', 'game_items',
    'game_profiles', 'game_run_attempts', 'game_runs', 'game_stop_progress', 'game_ticket_ledger', 'game_wallets', 'game_word_misses',
    'product_events',
];

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-05 10:00:00');
    config(['game.access' => 'all']);
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * A learner with a life: introduced words, a training with a review, a day of statistics, a streak, a league, an assignment, an
 * achievement, a challenge, a practice session, a battle and a referral - so that "nothing changed" means something.
 */
function gameIsolationWorld(): Learner
{
    $learner = Learner::factory()->create(['streak_current' => 4, 'streak_best' => 9, 'streak_last_date' => '2026-11-04', 'streak_freezes' => 2, 'daily_goal' => 35]);
    $lesson = Lesson::factory()->for(Stage::factory()->create())->create();
    $word = Word::factory()->for($lesson)->create();
    $friend = Learner::factory()->create();

    WordRepetition::factory()->create(['learner_id' => $learner->id, 'word_id' => $word->id]);
    $training = TrainingSession::factory()->personal($learner)->closed()->create();
    TrainingReview::factory()->create(['training_session_id' => $training->id, 'learner_id' => $learner->id, 'word_id' => $word->id, 'quality' => 4]);
    LearnerDailyStats::factory()->create(['learner_id' => $learner->id, 'reviews_count' => 12, 'new_words_count' => 3, 'correct_count' => 9, 'time_spent_ms' => 120_000]);
    LeagueMember::factory()->create(['learner_id' => $learner->id]);
    AssignmentResult::factory()->create(['learner_id' => $learner->id, 'progress_pct' => 40]);
    Achievement::factory()->create(['learner_id' => $learner->id]);
    Challenge::factory()->active()->create(['challenger_id' => $learner->id, 'opponent_id' => $friend->id]);
    PracticeSession::factory()->create(['learner_id' => $learner->id]);
    Battle::factory()->create(['challenger_id' => $learner->id, 'opponent_id' => $friend->id]);
    Referral::factory()->create(['referrer_learner_id' => $learner->id]);

    return $learner;
}

/**
 * A hash of every protected table (all its rows, all its columns), of `word_repetitions` and `learner_daily_stats` without the columns
 * the game is allowed to write (from G2.2a / G2.10), and of what the learners keep of the streak and the goal.
 *
 * @return array<string, string>
 */
function gameIsolationChecksums(): array
{
    $sums = [];
    foreach (GAME_ISOLATION_TABLES as $table) {
        $sums[$table] = md5(json_encode(DB::table($table)->orderBy('id')->get(), JSON_THROW_ON_ERROR));
    }

    // The game moves `next_review_at` / `is_hard` / `updated_at` of the words it was wrong about (docs/18 §12.4); nothing else.
    $repetitions = array_values(array_diff(Schema::getColumnListing('word_repetitions'), ['next_review_at', 'is_hard', 'updated_at']));
    $sums['word_repetitions'] = md5(json_encode(DB::table('word_repetitions')->orderBy('id')->get($repetitions), JSON_THROW_ON_ERROR));

    // The day of the learner gets only the `game_*` columns (docs/18 §12.2.7); the rest is the learning's.
    $stats = array_values(array_filter(Schema::getColumnListing('learner_daily_stats'), static fn (string $column): bool => ! str_starts_with($column, 'game_') && $column !== 'updated_at'));
    $sums['learner_daily_stats'] = md5(json_encode(DB::table('learner_daily_stats')->orderBy('id')->get($stats), JSON_THROW_ON_ERROR));

    $columns = array_values(array_filter(
        Schema::getColumnListing('learners'),
        static fn (string $column): bool => $column === 'id' || $column === 'daily_goal' || str_starts_with($column, 'streak_'),
    ));
    $sums['learners'] = md5(json_encode(DB::table('learners')->orderBy('id')->get($columns), JSON_THROW_ON_ERROR));

    return $sums;
}

/**
 * The tables an INSERT / UPDATE / DELETE of the log went to.
 *
 * @param  list<string>  $queries
 * @return list<string>
 */
function gameIsolationWrittenTables(array $queries): array
{
    $tables = [];
    foreach ($queries as $sql) {
        if (preg_match('/^\s*(?:insert\s+(?:or\s+\w+\s+)?into|update|delete\s+from)\s+["`]?([a-z_]+)["`]?/i', $sql, $match) === 1) {
            $tables[$match[1]] = true;
        }
    }

    $names = array_keys($tables);
    sort($names);

    return $names;
}

it('leaves the protected tables as they were when the game is read and its own rows are made', function (): void {
    $learner = gameIsolationWorld();
    $before = gameIsolationChecksums();

    // What exists so far: the block of /me with the game open, and the rows of every table of the game.
    $this->withHeaders(PracticeKit::headers($learner))->getJson('/api/twa/me')->assertOk()->assertJsonPath('game.available', true);
    GameWallet::factory()->withBalance(30)->create(['learner_id' => $learner->id]);
    GameProfile::factory()->withTickets(2)->create(['learner_id' => $learner->id]);
    GameRun::factory()->finished()->create(['learner_id' => $learner->id]);

    expect(gameIsolationChecksums())->toBe($before);
});

it('writes nothing at all when the access and the block of /me are read', function (): void {
    $learner = gameIsolationWorld();
    $headers = PracticeKit::headers($learner);
    $this->withHeaders($headers)->getJson('/api/twa/me')->assertOk(); // warms the caches

    $queries = QueryLog::during(fn () => $this->withHeaders($headers)->getJson('/api/twa/me')->assertOk());

    expect(gameIsolationWrittenTables($queries))->toBe([]);
});

it('tells a change of a protected table from none: the checksums are not blind', function (): void {
    $learner = gameIsolationWorld();
    $before = gameIsolationChecksums();

    // A column the game may write does not move the sum ...
    DB::table('word_repetitions')->update(['is_hard' => true, 'next_review_at' => now()]);
    DB::table('learner_daily_stats')->update(['game_points' => 40, 'game_runs_count' => 1, 'game_held_at' => now()]);
    expect(gameIsolationChecksums())->toBe($before);

    // ... any other does.
    foreach ([
        fn () => DB::table('word_repetitions')->update(['repetitions' => 9]),
        fn () => DB::table('learner_daily_stats')->update(['reviews_count' => 99]),
        fn () => DB::table('training_reviews')->update(['quality' => 1]),
        fn () => DB::table('practice_sessions')->update(['answered' => 2]),
        fn () => DB::table('battles')->update(['current_round' => 3]),
        fn () => DB::table('referrals')->update(['updated_at' => now()->addDay()]),
        fn () => DB::table('learners')->where('id', $learner->id)->update(['streak_current' => 5]),
    ] as $change) {
        $change();
        expect(gameIsolationChecksums())->not->toBe($before);
        $before = gameIsolationChecksums();
    }
});

it('reads the writes of the log: its own tables are told from the learning tables', function (): void {
    $queries = QueryLog::during(function (): void {
        DB::table('game_runs')->where('id', 0)->update(['score' => 1]);
        DB::table('game_wallets')->where('id', 0)->delete();
        DB::table('word_repetitions')->where('id', 0)->delete();
        DB::table('game_profiles')->where('id', 0)->lockForUpdate()->get();
    });

    expect(gameIsolationWrittenTables($queries))->toBe(['game_runs', 'game_wallets', 'word_repetitions']);
});

it('has exactly the tables of the game that GAME_ISOLATION_WRITTEN lists: a new table is a conscious decision', function (): void {
    $tables = array_values(array_filter(
        Schema::getTableListing(schemaQualified: false),
        static fn (string $table): bool => str_starts_with($table, 'game_') || str_starts_with($table, 'class_caravan'),
    ));
    sort($tables);

    $written = array_values(array_diff(GAME_ISOLATION_WRITTEN, ['product_events']));
    sort($written);

    expect($tables)->toBe($written);
});

it('imports none of the services and models that write the learning of a learner anywhere in the code of the game', function (): void {
    $forbidden = [
        // the schedule of the repetitions (rule §5 of docs/18 §12.4 is a conditional UPDATE of GameSrsAdjuster, not a review)
        'App\Domain\Learning\Services\SpacedRepetitionEngine', 'App\Domain\Learning\Services\Sm2Scheduler', 'App\Domain\Learning\Services\FsrsScheduler',
        'App\Domain\Learning\Services\ReviewSubmissionService', 'App\Domain\Learning\Services\CardPicker',
        // the training, the exams
        'App\Domain\Learning\Services\TrainingSessionService', 'App\Domain\Learning\Services\TrainingFlowService',
        'App\Domain\Learning\Services\ExamAnswerService', 'App\Domain\Learning\Services\ExamFlowService',
        'App\Domain\Learning\Services\ExamSessionService', 'App\Domain\Learning\Services\MockExamService',
        // the statistics of the review (the game writes its own `game_*` columns of the day)
        'App\Domain\Learners\Services\DailyStatsService',
        // the practice and the battles
        'App\Domain\Practice\Services\PracticeSessionService', 'App\Domain\Practice\Services\Battle\BattleService',
        'App\Models\TrainingSession', 'App\Models\TrainingReview', 'App\Models\PracticeSession', 'App\Models\PracticeAttempt',
        'App\Models\Battle', 'App\Models\BattleParticipant', 'App\Models\BattleAnswer',
        // the assignments, the challenges
        'App\Domain\Engagement\Services\ChallengeService', 'App\Models\Assignment', 'App\Models\AssignmentResult', 'App\Models\Challenge',
    ];

    // A name that no longer exists would silently guard nothing.
    foreach ($forbidden as $class) {
        expect(class_exists($class))->toBeTrue("{$class} is gone: update the list");
    }

    $files = [
        ...array_map(static fn ($file): string => $file->getPathname(), File::allFiles(app_path('Domain/Game'))),
        ...glob(app_path('Http/Controllers/Api/Twa/Game*.php')) ?: [],
        ...glob(app_path('Http/Requests/Twa/Game*.php')) ?: [],
        ...glob(app_path('Http/Resources/Twa/Game*.php')) ?: [],
        ...array_filter([app_path('Domain/Billing/Enums/ProGamePromise.php')], 'is_file'),
    ];
    expect(count($files))->toBeGreaterThan(20);

    $offences = [];
    foreach ($files as $file) {
        preg_match_all('/^use\s+([^;\s]+)(?:\s+as\s+\w+)?;/m', (string) file_get_contents($file), $imports);
        foreach (array_intersect($imports[1], $forbidden) as $class) {
            $offences[] = basename($file).' imports '.$class;
        }
    }

    expect($offences)->toBe([]);
});
