<?php

declare(strict_types=1);

use App\Domain\Learning\Enums\ExerciseType;
use App\Domain\Learning\Enums\SchedulerKind;
use App\Domain\Practice\Enums\AudioStatus;
use App\Domain\Practice\Enums\BattleEndReason;
use App\Domain\Practice\Enums\BattlePhase;
use App\Domain\Practice\Enums\BattleRole;
use App\Domain\Practice\Enums\BattleStatus;
use App\Domain\Practice\Enums\PracticeItemStatus;
use App\Domain\Practice\Enums\PracticeItemType;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Enums\SessionStatus;
use App\Domain\Practice\Enums\VersionState;
use App\Domain\Practice\Enums\Verdict;
use App\Models\Achievement;
use App\Models\Assignment;
use App\Models\AssignmentResult;
use App\Models\Battle;
use App\Models\BattleAnswer;
use App\Models\BattleParticipant;
use App\Models\Challenge;
use App\Models\Course;
use App\Models\ExamAnswer;
use App\Models\ExamQuestionAttempt;
use App\Models\ExamResult;
use App\Models\ExamSession;
use App\Models\Friendship;
use App\Models\GroupMember;
use App\Models\LeagueGroup;
use App\Models\LeagueMember;
use App\Models\LeagueWeek;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\Lesson;
use App\Models\NotificationLog;
use App\Models\Offer;
use App\Models\Organization;
use App\Models\OrganizationInvite;
use App\Models\OrganizationMember;
use App\Models\OrganizationUser;
use App\Models\Payment;
use App\Models\PaymentOrder;
use App\Models\ProductEvent;
use App\Models\PracticeAttempt;
use App\Models\PracticeItem;
use App\Models\PracticeItemVersion;
use App\Models\PracticeItemWord;
use App\Models\PracticeSession;
use App\Models\PromoCode;
use App\Models\PromoRedemption;
use App\Models\ProviderTransaction;
use App\Models\Referral;
use App\Models\Stage;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use App\Models\TrainingReview;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\Word;
use App\Models\WordRepetition;
use App\Models\WordTranslation;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * `migrate:rollback --step` counts from the newest migration: the number of files from `$firstFile`
 * (inclusive, by name) to the end of `database/migrations`, so a migration added on top later does
 * not shift which files a rollback test reaches.
 */
function migrationStepsBackTo(string $firstFile): int
{
    $files = glob(database_path('migrations/*.php')) ?: [];
    $names = array_map(fn (string $f): string => basename($f, '.php'), $files);
    sort($names);

    return count(array_filter($names, fn (string $n): bool => $n >= $firstFile));
}

$expectedTables = [
    'users', 'password_reset_tokens', 'sessions',
    'telegram_groups', 'teacher_groups', 'learners', 'group_members',
    'stages', 'lessons', 'words', 'word_repetitions',
    'training_sessions', 'training_reviews',
    'exam_sessions', 'exam_question_attempts', 'exam_answers', 'exam_results',
    'notifications_log', 'audit_logs', 'product_events',
    'roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions',
    // Phase 1 (docs/12 §1): learner-first schema.
    'organizations', 'courses', 'word_translations',
    'learner_daily_stats', 'subscriptions', 'promo_codes', 'promo_redemptions',
    // Phase 2 (docs/13 §1.2): payments.
    'offers', 'payment_orders', 'provider_transactions', 'payments',
    // Phase 2 (docs/13 §1.3): organizations — staff, invites, members / seats.
    'organization_users', 'organization_invites', 'organization_members',
    // Phase 2 (docs/13 §1.4): assignments of a center.
    'assignments', 'assignment_results',
    // Phase 2 (docs/13 §1.5): referrals, leagues.
    'referrals',
    'league_weeks', 'league_groups', 'league_members',
    // Phase 2 (docs/13 §1.5): friends and challenges.
    'friendships', 'challenges',
    // Phase 2 (docs/13 §1.5): achievements.
    'achievements',
    // Practice and battle (docs/16 §2).
    'practice_items', 'practice_item_versions', 'practice_item_words', 'practice_sessions', 'practice_attempts',
    'battles', 'battle_participants', 'battle_answers',
];

/**
 * Runs the callback in a savepoint: on PostgreSQL a failed statement aborts the
 * whole (RefreshDatabase) transaction, so later queries in the test would fail.
 */
function expectConstraintViolation(Closure $callback): void
{
    expect(fn () => DB::transaction($callback))->toThrow(QueryException::class);
}

it('creates all expected tables', function () use ($expectedTables): void {
    foreach ($expectedTables as $table) {
        expect(Schema::hasTable($table))->toBeTrue("Missing table: {$table}");
    }
});

it('has no students table and no student_id column anywhere (learner-first, docs/12 §2)', function (): void {
    expect(Schema::hasTable('students'))->toBeFalse();

    foreach (Schema::getTableListing(schemaQualified: false) as $table) {
        expect(Schema::hasColumn($table, 'student_id'))->toBeFalse("{$table}.student_id still exists");
    }

    foreach (['word_repetitions', 'training_reviews', 'exam_answers', 'exam_results', 'exam_question_attempts', 'notifications_log', 'product_events'] as $table) {
        expect(Schema::hasColumn($table, 'learner_id'))->toBeTrue("{$table}.learner_id missing");
    }
});

it('enforces unique chat_id on telegram_groups', function (): void {
    TelegramGroup::factory()->create(['chat_id' => -555]);
    expectConstraintViolation(fn () => TelegramGroup::factory()->create(['chat_id' => -555]));
});

it('enforces unique (learner_id, word_id) on word_repetitions: one progress row per person and word', function (): void {
    $learner = Learner::factory()->create();
    $word = Word::factory()->create();
    WordRepetition::factory()->create(['learner_id' => $learner->id, 'word_id' => $word->id]);

    expectConstraintViolation(fn () => WordRepetition::factory()->create(['learner_id' => $learner->id, 'word_id' => $word->id]));

    // Another learner may track the same word; the same learner another word.
    WordRepetition::factory()->create(['learner_id' => Learner::factory()->create()->id, 'word_id' => $word->id]);
    WordRepetition::factory()->create(['learner_id' => $learner->id, 'word_id' => Word::factory()->create()->id]);
    expect(WordRepetition::count())->toBe(3);
});

it('enforces (lesson_id, word) uniqueness', function (): void {
    $lesson = Lesson::factory()->for(Stage::factory())->create();
    Word::factory()->create(['lesson_id' => $lesson->id, 'word' => 'apple']);
    expectConstraintViolation(fn () => Word::factory()->create(['lesson_id' => $lesson->id, 'word' => 'apple']));
});

it('cascades learner deletion to word_repetitions', function (): void {
    $learner = Learner::factory()->create();
    $keep = Learner::factory()->create();
    $word = Word::factory()->for(Lesson::factory()->for(Stage::factory()))->create();
    WordRepetition::factory()->create(['learner_id' => $learner->id, 'word_id' => $word->id]);
    WordRepetition::factory()->create(['learner_id' => $keep->id, 'word_id' => $word->id]);

    $learner->forceDelete();

    expect(WordRepetition::where('learner_id', $learner->id)->count())->toBe(0)
        ->and(WordRepetition::where('learner_id', $keep->id)->count())->toBe(1);
});

it('cascades learner deletion to training_reviews, exam_question_attempts and notifications_log', function (): void {
    $learner = Learner::factory()->create();
    $review = TrainingReview::factory()->create(['learner_id' => $learner->id]);
    NotificationLog::factory()->create(['learner_id' => $learner->id]);
    ExamQuestionAttempt::query()->create([
        'exam_session_id' => ExamSession::factory()->create()->id,
        'learner_id' => $learner->id,
        'question_index' => 0,
        'served_at' => now(),
    ]);

    $learner->forceDelete();

    expect(TrainingReview::whereKey($review->id)->exists())->toBeFalse()
        ->and(NotificationLog::count())->toBe(0)
        ->and(ExamQuestionAttempt::count())->toBe(0);
});

it('keeps exam answers, exam results and product events (learner_id set to NULL) when the learner is deleted', function (): void {
    $learner = Learner::factory()->create();
    $answer = ExamAnswer::factory()->create(['learner_id' => $learner->id]);
    $result = ExamResult::factory()->create(['learner_id' => $learner->id]);
    $event = ProductEvent::create(['name' => 'x', 'learner_id' => $learner->id, 'props' => [], 'occurred_at' => now()]);

    $learner->forceDelete();

    expect($answer->fresh()?->learner_id)->toBeNull()
        ->and($result->fresh()?->learner_id)->toBeNull()
        ->and($event->fresh()?->learner_id)->toBeNull();
});

it('enforces one exam answer per learner and word in a session', function (): void {
    $session = ExamSession::factory()->create();
    $learner = Learner::factory()->memberOf($session->telegram_group_id)->create();
    $word = Word::factory()->create(['lesson_id' => $session->lesson_id]);

    ExamAnswer::factory()->create([
        'exam_session_id' => $session->id,
        'learner_id' => $learner->id,
        'word_id' => $word->id,
    ]);

    expectConstraintViolation(fn () => ExamAnswer::factory()->create([
        'exam_session_id' => $session->id,
        'learner_id' => $learner->id,
        'word_id' => $word->id,
    ]));
});

it('enforces one exam result per learner in a session', function (): void {
    $session = ExamSession::factory()->create();
    $learner = Learner::factory()->memberOf($session->telegram_group_id)->create();

    ExamResult::factory()->create([
        'exam_session_id' => $session->id,
        'learner_id' => $learner->id,
    ]);

    expectConstraintViolation(fn () => ExamResult::factory()->create([
        'exam_session_id' => $session->id,
        'learner_id' => $learner->id,
    ]));
});

it('enforces one open exam per group at the database level', function (): void {
    $first = ExamSession::factory()->create(['status' => 'open']);

    expectConstraintViolation(fn () => ExamSession::factory()->create([
        'telegram_group_id' => $first->telegram_group_id,
        'status' => 'open',
    ]));

    ExamSession::factory()->closed()->create([
        'telegram_group_id' => $first->telegram_group_id,
    ]);
});

it('enforces one open training per group and lesson at the database level', function (): void {
    $first = TrainingSession::factory()->create(['status' => 'open']);

    expectConstraintViolation(fn () => TrainingSession::factory()->create([
        'telegram_group_id' => $first->telegram_group_id,
        'lesson_id' => $first->lesson_id,
        'status' => 'open',
    ]));

    TrainingSession::factory()->closed()->create([
        'telegram_group_id' => $first->telegram_group_id,
        'lesson_id' => $first->lesson_id,
    ]);
});

it('enforces one open personal daily_review session per learner', function (): void {
    $learner = Learner::factory()->create();
    TrainingSession::factory()->personal($learner, 'daily_review')->create();

    expectConstraintViolation(fn () => TrainingSession::factory()->personal($learner, 'daily_review')->create());

    // A closed one, another learner's, and a lesson-mode session are all fine.
    TrainingSession::factory()->personal($learner, 'daily_review')->closed()->create();
    TrainingSession::factory()->personal(Learner::factory()->create(), 'daily_review')->create();
    TrainingSession::factory()->personal($learner, 'lesson')->create();

    expect(TrainingSession::where('learner_id', $learner->id)->count())->toBe(3);
});

it('enforces one open personal lesson session per (learner, lesson)', function (): void {
    $learner = Learner::factory()->create();
    $lesson = Lesson::factory()->for(Stage::factory())->create();
    TrainingSession::factory()->personal($learner)->create(['lesson_id' => $lesson->id]);

    expectConstraintViolation(fn () => TrainingSession::factory()->personal($learner)->create(['lesson_id' => $lesson->id]));

    // Another lesson, another learner and a closed session do not conflict.
    TrainingSession::factory()->personal($learner)->create();
    TrainingSession::factory()->personal(Learner::factory()->create())->create(['lesson_id' => $lesson->id]);
    TrainingSession::factory()->personal($learner)->closed()->create(['lesson_id' => $lesson->id]);

    expect(TrainingSession::where('learner_id', $learner->id)->count())->toBe(3);
});

it('lets personal sessions coexist with a group session of the same lesson', function (): void {
    $group = TrainingSession::factory()->create(['status' => 'open']);
    $learner = Learner::factory()->create();

    TrainingSession::factory()->personal($learner)->create(['lesson_id' => $group->lesson_id]);
    // Personal sessions have a NULL group: NULLs never collide in the group/lesson unique index.
    TrainingSession::factory()->personal(Learner::factory()->create())->create(['lesson_id' => $group->lesson_id]);

    expect(TrainingSession::count())->toBe(3);
});

it('stores the generalised training_sessions columns (mode, source, course, expiry)', function (): void {
    $course = Course::factory()->create();
    $session = TrainingSession::factory()->personal(Learner::factory()->create(), 'daily_review')
        ->create(['course_id' => $course->id, 'source' => 'reminder']);

    $session->refresh();
    expect($session->mode)->toBe('daily_review')
        ->and($session->source)->toBe('reminder')
        ->and($session->telegram_group_id)->toBeNull()
        ->and($session->lesson_id)->toBeNull()
        ->and($session->started_by_user_id)->toBeNull()
        ->and($session->course->is($course))->toBeTrue()
        ->and($session->expires_at)->not->toBeNull();

    $legacy = TrainingSession::factory()->create();
    expect($legacy->fresh()?->mode)->toBe('group_lesson')
        ->and($legacy->fresh()?->source)->toBe('command')
        ->and($legacy->fresh()?->expires_at)->toBeNull();

    $course->delete();
    expect($session->fresh()?->course_id)->toBeNull();
});

it('creates the renamed learner indexes and none of the student ones', function (): void {
    $expected = [
        'word_repetitions' => ['word_repetitions_next_card_idx'],
        'words' => ['words_inactive_idx'],
        'training_reviews' => ['training_reviews_created_learner_idx', 'training_reviews_session_learner_idx'],
        'exam_answers' => ['exam_answers_session_learner_word_unique', 'exam_answers_answered_learner_idx'],
        'exam_results' => ['exam_results_session_learner_unique'],
        'exam_question_attempts' => ['exam_question_attempts_session_learner_index_unique'],
        'training_sessions' => [
            'training_sessions_one_open_per_group_lesson_unique',
            'training_sessions_one_open_daily_per_learner',
            'training_sessions_one_open_lesson_per_learner',
        ],
    ];

    foreach ($expected as $table => $names) {
        $present = array_column(Schema::getIndexes($table), 'name');
        foreach ($names as $name) {
            expect($present)->toContain($name);
        }
        foreach ($present as $name) {
            expect($name)->not->toContain('student');
        }
    }

    // Dropped as unused (2026_10_28_200001): no query filters by is_hard, so a partial index on it could never be read.
    expect(array_column(Schema::getIndexes('word_repetitions'), 'name'))->not->toContain('word_repetitions_learner_is_hard_idx');

    // Dropped as unused (2026_10_28_300001): every query that filters word_repetitions by next_review_at also names the
    // learner (the picker, the learn-ahead pick, dueCount), and the reminders scan learners.next_reminder_at.
    expect(array_column(Schema::getIndexes('word_repetitions'), 'name'))->not->toContain('word_repetitions_due_learners_idx')
        ->and(array_column(Schema::getIndexes('learners'), 'name'))->toContain('learners_next_reminder_at_idx');
});

it('has the CHECK constraint on the shape of training_sessions by mode', function (): void {
    $row = DB::selectOne(
        "SELECT 1 AS x FROM pg_constraint WHERE conname = 'training_sessions_mode_shape_check'",
    );
    expect($row)->not->toBeNull();

    $learner = Learner::factory()->create();
    $base = ['status' => 'open', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now()];

    // A group lesson needs a group and a lesson; a personal mode needs a learner.
    expectConstraintViolation(fn () => DB::table('training_sessions')->insert([...$base, 'mode' => 'group_lesson']));
    expectConstraintViolation(fn () => DB::table('training_sessions')->insert([...$base, 'mode' => 'daily_review']));
    DB::table('training_sessions')->insert([...$base, 'mode' => 'daily_review', 'learner_id' => $learner->id]);
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'The CHECK constraint is PostgreSQL-only; the personal-session service (task 1.3a) will duplicate the shape invariant.');

it('has no partial index on word_repetitions for is_hard: nothing filters by it, only CardPicker orders by it', function (): void {
    $exists = DB::selectOne(
        "SELECT 1 AS x FROM pg_indexes
         WHERE tablename = 'word_repetitions'
           AND indexname = 'word_repetitions_learner_is_hard_idx'",
    );
    expect($exists)->toBeNull();

    // The order of CardPicker is served by the next-card index instead.
    $nextCard = DB::selectOne(
        "SELECT 1 AS x FROM pg_indexes
         WHERE tablename = 'word_repetitions'
           AND indexname = 'word_repetitions_next_card_idx'",
    );
    expect($nextCard)->not->toBeNull();
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'pg_indexes catalog is PostgreSQL-only.');

it('has composite index (learner_id, next_review_at)', function (): void {
    $row = DB::selectOne(
        "SELECT 1 AS x FROM pg_indexes
         WHERE tablename = 'word_repetitions'
           AND indexdef LIKE '%(learner_id, next_review_at)%'",
    );
    expect($row)->not->toBeNull();
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'pg_indexes catalog is PostgreSQL-only.');

// --- hot-path indexes of the picker and of dueCount (docs/03 §2.7, §2.8; perf report 2026-10-28 P2-3 / P2-9) ---

it('has the next-card index in the exact order of CardPicker and the partial index of inactive words, and no stale predecessor', function (): void {
    $defs = DB::table('pg_indexes')
        ->whereIn('tablename', ['word_repetitions', 'words'])
        ->pluck('indexdef', 'indexname');

    expect($defs->get('word_repetitions_next_card_idx'))
        ->toContain('(learner_id, is_hard DESC, next_review_at, id)')
        ->and($defs->get('words_inactive_idx'))
        ->toContain('ON public.words USING btree (id) WHERE (is_active = false)')
        ->and($defs->has('word_repetitions_learner_hard_due_idx'))->toBeFalse();

    // Both are plain valid btree indexes (a CONCURRENTLY build that failed would leave an INVALID one).
    $invalid = DB::table('pg_index')
        ->join('pg_class', 'pg_class.oid', '=', 'pg_index.indexrelid')
        ->whereIn('pg_class.relname', ['word_repetitions_next_card_idx', 'words_inactive_idx'])
        ->where('pg_index.indisvalid', false)
        ->count();
    expect($invalid)->toBe(0);
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'pg_indexes catalog is PostgreSQL-only.');

it('restores the old hard-due index on rollback of the next-card index migration and swaps it back on re-run', function (): void {
    $migration = require database_path('migrations/2026_10_28_000001_add_next_card_and_inactive_words_indexes.php');
    $names = fn (string $table): array => array_column(Schema::getIndexes($table), 'name');

    $migration->down();
    expect($names('word_repetitions'))->toContain('word_repetitions_learner_hard_due_idx')
        ->and($names('word_repetitions'))->not->toContain('word_repetitions_next_card_idx')
        ->and($names('words'))->not->toContain('words_inactive_idx');

    $migration->up();
    expect($names('word_repetitions'))->toContain('word_repetitions_next_card_idx')
        ->and($names('word_repetitions'))->not->toContain('word_repetitions_learner_hard_due_idx')
        ->and($names('words'))->toContain('words_inactive_idx');

    // Running `up()` twice (a retry after an interrupted deploy) is harmless.
    $migration->up();
    expect(array_count_values($names('word_repetitions'))['word_repetitions_next_card_idx'])->toBe(1)
        ->and(array_count_values($names('words'))['words_inactive_idx'])->toBe(1);
});

it('rebuilds the dropped is_hard partial index exactly as it was on rollback and drops it again on re-run', function (): void {
    $migration = require database_path('migrations/2026_10_28_200001_drop_word_repetitions_learner_is_hard_idx.php');
    $name = 'word_repetitions_learner_is_hard_idx';
    $copies = fn (): int => count(array_filter(Schema::getIndexes('word_repetitions'), fn (array $index): bool => $index['name'] === $name));
    $definition = fn (): ?string => DB::getDriverName() === 'pgsql'
        ? DB::table('pg_indexes')->where('indexname', $name)->value('indexdef')
        : DB::table('sqlite_master')->where('type', 'index')->where('name', $name)->value('sql');

    expect($copies())->toBe(0);

    // down(): back to the original — a partial index on learner_id for is_hard = true.
    $migration->down();
    expect($copies())->toBe(1)
        ->and(array_column(Schema::getIndexes('word_repetitions'), 'columns', 'name')[$name])->toBe(['learner_id']);
    expect((string) $definition())->toMatch(DB::getDriverName() === 'pgsql'
        ? '/USING btree \(learner_id\) WHERE \(is_hard = true\)$/'
        : '/ON word_repetitions \(learner_id\)\s+WHERE is_hard = true$/');

    $migration->up();
    expect($copies())->toBe(0);

    // A retry after an interrupted deploy is harmless in either direction.
    $migration->up();
    $migration->down();
    $migration->down();
    expect($copies())->toBe(1);
    $migration->up();
    expect($copies())->toBe(0);
});

it('rebuilds the dropped due_learners index exactly as it was on rollback and drops it again on re-run', function (): void {
    $migration = require database_path('migrations/2026_10_28_300001_drop_word_repetitions_due_learners_idx.php');
    $name = 'word_repetitions_due_learners_idx';
    $copies = fn (): int => count(array_filter(Schema::getIndexes('word_repetitions'), fn (array $index): bool => $index['name'] === $name));
    $definition = fn (): ?string => DB::getDriverName() === 'pgsql'
        ? DB::table('pg_indexes')->where('indexname', $name)->value('indexdef')
        : DB::table('sqlite_master')->where('type', 'index')->where('name', $name)->value('sql');

    expect($copies())->toBe(0);

    // down(): back to the original - a plain btree on (next_review_at, learner_id), in that order.
    $migration->down();
    expect($copies())->toBe(1)
        ->and(array_column(Schema::getIndexes('word_repetitions'), 'columns', 'name')[$name])->toBe(['next_review_at', 'learner_id'])
        ->and(array_column(Schema::getIndexes('word_repetitions'), 'unique', 'name')[$name])->toBeFalse();
    expect((string) $definition())->toMatch(DB::getDriverName() === 'pgsql'
        ? '/USING btree \(next_review_at, learner_id\)$/'
        : '/ON word_repetitions \(next_review_at, learner_id\)$/');

    $migration->up();
    expect($copies())->toBe(0);

    // A retry after an interrupted deploy is harmless in either direction.
    $migration->up();
    $migration->down();
    $migration->down();
    expect($copies())->toBe(1);
    $migration->up();
    expect($copies())->toBe(0);
});

// --- 17.14-S: a module may have no name of its own (docs/03 §2.5) ------------------------------------

/** @return array<string, mixed> the `title` column of `stages` as the schema reports it */
function stageTitleColumn(): array
{
    $column = collect(Schema::getColumns('stages'))->firstWhere('name', 'title');
    assert(is_array($column));

    return $column;
}

it('turns the placeholder titles "Stage N" of modules into NULL and back, and leaves real names alone', function (): void {
    $migration = require database_path('migrations/2026_10_28_400001_make_stages_title_nullable.php');
    $first = Course::factory()->create();
    $second = Course::factory()->create();
    $key = fn (Course $course, int $number): string => "{$course->slug}#{$number}";
    // Sorted by key: PostgreSQL does not hand the rows back in the order they were inserted.
    $titles = fn (): array => ksorted(DB::table('stages')
        ->join('courses', 'courses.id', '=', 'stages.course_id')
        ->get(['courses.slug', 'stages.number', 'stages.title'])
        ->mapWithKeys(fn (object $row): array => ["{$row->slug}#{$row->number}" => $row->title])
        ->all());

    // The shape before the migration: the title is NOT NULL, and a module that has no name gets "Stage N".
    $migration->down();
    expect(stageTitleColumn()['nullable'])->toBeFalse();

    $before = [
        [$first, 1, 'Stage 1'],     // the placeholder of its own number -> NULL
        [$first, 2, 'Основы'],      // a real name stays
        [$first, 3, 'Stage 3'],     // the placeholder -> NULL
        [$first, 4, 'Stage 3'],     // "Stage 3" on module 4 is not its placeholder: stays
        [$first, 5, 'stage 5'],     // other case: stays
        [$first, 6, 'Stage 06'],    // zero-padded: stays
        [$first, 7, 'Stage 7 '],    // trailing space: stays
        [$second, 1, 'Stage 1'],    // the same number in another course -> NULL
        [$second, 2, 'Stage 12'],   // "12" is not "2": stays
        [$second, 12, 'Stage 12'],  // -> NULL
    ];
    foreach ($before as [$course, $number, $title]) {
        DB::table('stages')->insert(['course_id' => $course->id, 'number' => $number, 'title' => $title, 'created_at' => now(), 'updated_at' => now()]);
    }
    $named = ksorted(collect($before)->mapWithKeys(fn (array $row): array => [$key($row[0], $row[1]) => $row[2]])->all());

    $migration->up();

    $afterUp = $named;
    foreach ([$key($first, 1), $key($first, 3), $key($second, 1), $key($second, 12)] as $placeholder) {
        $afterUp[$placeholder] = null;
    }
    $afterUp = ksorted($afterUp);
    expect(stageTitleColumn()['nullable'])->toBeTrue()
        ->and($titles())->toBe($afterUp);

    // Rebuilding the table on sqlite keeps the unique (course_id, number) and the foreign key to the course.
    $unique = collect(Schema::getIndexes('stages'))->first(fn (array $index): bool => $index['unique'] && $index['columns'] === ['course_id', 'number']);
    $courseKey = collect(Schema::getForeignKeys('stages'))->first(fn (array $fk): bool => $fk['columns'] === ['course_id']);
    expect($unique)->not->toBeNull()
        ->and($courseKey['foreign_table'] ?? null)->toBe('courses')
        ->and($courseKey['on_delete'] ?? null)->toBe('cascade');

    // A retry after an interrupted deploy changes nothing.
    $migration->up();
    expect($titles())->toBe($afterUp);

    // down(): the NULL titles get "Stage N" back, the others are untouched, and the title is NOT NULL again.
    $migration->down();
    expect(stageTitleColumn()['nullable'])->toBeFalse()
        ->and($titles())->toBe($named)
        ->and(fn () => DB::transaction(fn () => DB::table('stages')->insert(['course_id' => $first->id, 'number' => 99, 'title' => null, 'created_at' => now(), 'updated_at' => now()])))
        ->toThrow(QueryException::class);

    $migration->up();
    expect($titles())->toBe($afterUp);
});

// This test runs inside the transaction of RefreshDatabase on purpose: rebuilding the table of the stages there (what
// `->change()` does on sqlite) would delete their lessons and words through the foreign keys.
it('keeps the lessons and words of the modules while the title column changes, also inside a transaction on sqlite', function (): void {
    $migration = require database_path('migrations/2026_10_28_400001_make_stages_title_nullable.php');
    $stage = Stage::factory()->create(['title' => 'Stage 1', 'number' => 1]);
    $lesson = Lesson::factory()->for($stage)->create();
    Word::factory()->count(2)->for($lesson)->create();

    $migration->down();
    $migration->up();

    expect(Stage::query()->findOrFail($stage->id)->title)->toBeNull()
        ->and(Lesson::query()->whereKey($lesson->id)->exists())->toBeTrue()
        ->and(Word::query()->where('lesson_id', $lesson->id)->count())->toBe(2);
});

it('runs migrate:fresh --seed and produces demo data', function (): void {
    $this->artisan('migrate:fresh', ['--seed' => true])->assertSuccessful();

    expect(DB::table('users')->where('email', 'admin@local')->exists())->toBeTrue();
    expect(DB::table('users')->where('email', 'teacher@local')->exists())->toBeTrue();
    expect(DB::table('telegram_groups')->where('chat_id', -1001234567890)->exists())->toBeTrue();
    expect(DB::table('stages')->count())->toBe(2);
    expect(DB::table('lessons')->count())->toBe(6);
    expect(DB::table('words')->count())->toBe(120);
    expect(DB::table('learners')->count())->toBe(5);
    expect(DB::table('group_members')->count())->toBe(6);
    expect(DB::table('group_members')->where('status', 'active')->count())->toBe(6);
    expect(DB::table('telegram_groups')->count())->toBe(2);
    // The first learner studies in both groups, with one shared progress per word.
    expect(DB::table('group_members')->select('learner_id')->groupBy('learner_id')->havingRaw('COUNT(*) = 2')->get())->toHaveCount(1);
    expect(DB::table('word_repetitions')->selectRaw('learner_id, word_id, COUNT(*) AS c')->groupBy('learner_id', 'word_id')->havingRaw('COUNT(*) > 1')->get())->toHaveCount(0);
    expect(DB::table('learners')->where('onboarding_state', 'done')->count())->toBe(5);
    expect(Schema::hasTable('students'))->toBeFalse();
    expect(DB::table('courses')->count())->toBe(1);
    expect(DB::table('courses')->where('slug', config('content.default_course_slug'))->exists())->toBeTrue();
    expect(DB::table('stages')->where('course_id', DB::table('courses')->value('id'))->count())->toBe(2);
    expect(DB::table('telegram_groups')->where('chat_id', -1001234567890)->value('course_id'))
        ->toBe(DB::table('courses')->value('id'));
    expect(DB::table('word_translations')->where('locale', 'ru')->count())->toBe(120);
    expect(DB::table('word_repetitions')->count())->toBeGreaterThan(0);
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'migrate:fresh runs VACUUM which requires PostgreSQL or a non-transactional SQLite file.');

// --- Phase 1, task 1.1a: learner-first tables (docs/12 §1) -------------------

it('enforces unique learners.telegram_user_id', function (): void {
    Learner::factory()->create(['telegram_user_id' => 777]);
    expectConstraintViolation(fn () => Learner::factory()->create(['telegram_user_id' => 777]));
});

it('applies learner defaults and casts', function (): void {
    $learner = Learner::factory()->create();
    $learner->refresh();

    expect($learner->interface_locale)->toBe('ru')
        ->and($learner->timezone)->toBe('Asia/Tashkent')
        ->and($learner->daily_goal)->toBe(20)
        ->and($learner->onboarding_state)->toBe('new')
        ->and($learner->meta)->toBe([])
        ->and($learner->streak_current)->toBe(0);

    $raw = Learner::query()->insertGetId(['telegram_user_id' => 4242, 'created_at' => now(), 'updated_at' => now()]);
    expect(DB::table('learners')->where('id', $raw)->value('reminder_time'))->toStartWith('19:00');
});

it('soft deletes learners', function (): void {
    $learner = Learner::factory()->create();
    $learner->delete();

    expect(Learner::count())->toBe(0)
        ->and(Learner::withTrashed()->count())->toBe(1);
});

it('enforces unique membership per (telegram_group_id, learner_id)', function (): void {
    $group = TelegramGroup::factory()->create();
    $learner = Learner::factory()->create();
    GroupMember::factory()->create(['telegram_group_id' => $group->id, 'learner_id' => $learner->id]);

    expectConstraintViolation(fn () => GroupMember::factory()->create(['telegram_group_id' => $group->id, 'learner_id' => $learner->id]));

    // The same learner in another group is a different membership.
    GroupMember::factory()->create(['learner_id' => $learner->id]);
    expect($learner->memberships()->count())->toBe(2)
        ->and($learner->groups()->count())->toBe(2);
});

it('allows the same stage number in two courses but not twice in one', function (): void {
    $courseA = Course::factory()->create();
    $courseB = Course::factory()->create();

    Stage::factory()->create(['course_id' => $courseA->id, 'number' => 1]);
    Stage::factory()->create(['course_id' => $courseB->id, 'number' => 1]);

    expectConstraintViolation(fn () => Stage::factory()->create(['course_id' => $courseA->id, 'number' => 1]));
    expect(Stage::where('number', 1)->count())->toBe(2);
});

it('enforces unique (word_id, locale) on word_translations', function (): void {
    $word = Word::factory()->create();
    WordTranslation::factory()->create(['word_id' => $word->id, 'locale' => 'ru']);
    WordTranslation::factory()->uz()->create(['word_id' => $word->id]);

    expectConstraintViolation(fn () => WordTranslation::factory()->create(['word_id' => $word->id, 'locale' => 'ru']));
    expect($word->translations()->count())->toBe(2);
});

it('enforces unique (learner_id, date) on learner_daily_stats', function (): void {
    $learner = Learner::factory()->create();
    LearnerDailyStats::factory()->create(['learner_id' => $learner->id, 'date' => '2026-10-18']);
    LearnerDailyStats::factory()->create(['learner_id' => $learner->id, 'date' => '2026-10-19']);

    expectConstraintViolation(fn () => LearnerDailyStats::factory()->create(['learner_id' => $learner->id, 'date' => '2026-10-18']));
});

it('enforces unique (promo_code_id, learner_id) on promo_redemptions', function (): void {
    $code = PromoCode::factory()->create();
    $learner = Learner::factory()->create();
    PromoRedemption::factory()->create(['promo_code_id' => $code->id, 'learner_id' => $learner->id]);

    expectConstraintViolation(fn () => PromoRedemption::factory()->create(['promo_code_id' => $code->id, 'learner_id' => $learner->id]));
});

it('enforces the billing uniqueness keys: payments (provider, provider_event_id), provider_transactions (provider, provider_transaction_id), payment_orders.token, one open offer per (learner, kind)', function (): void {
    Payment::factory()->create(['provider' => 'payme', 'provider_event_id' => 'tx1:perform']);
    Payment::factory()->create(['provider' => 'click', 'provider_event_id' => 'tx1:perform']); // another provider: fine
    expectConstraintViolation(fn () => Payment::factory()->create(['provider' => 'payme', 'provider_event_id' => 'tx1:perform']));

    $order = PaymentOrder::factory()->payme()->create(['token' => str_repeat('a', 32)]);
    expectConstraintViolation(fn () => PaymentOrder::factory()->create(['token' => str_repeat('a', 32)]));

    ProviderTransaction::factory()->create(['provider' => 'payme', 'provider_transaction_id' => 'ptx1', 'payment_order_id' => $order->id]);
    expectConstraintViolation(fn () => ProviderTransaction::factory()->create(['provider' => 'payme', 'provider_transaction_id' => 'ptx1', 'payment_order_id' => $order->id]));

    $learner = Learner::factory()->create();
    Offer::factory()->create(['learner_id' => $learner->id, 'kind' => 'post_org_discount']);
    Offer::factory()->create(['learner_id' => $learner->id, 'kind' => 'winback']); // another kind: fine
    Offer::factory()->redeemed()->create(['learner_id' => $learner->id, 'kind' => 'post_org_discount']); // redeemed: not counted
    expectConstraintViolation(fn () => Offer::factory()->create(['learner_id' => $learner->id, 'kind' => 'post_org_discount']));
    expect(Offer::query()->where('learner_id', $learner->id)->count())->toBe(3);
});

it('cascades and nulls the billing foreign keys as designed (docs/13 §1.2)', function (): void {
    $learner = Learner::factory()->create();
    $offer = Offer::factory()->create(['learner_id' => $learner->id]);
    $order = PaymentOrder::factory()->payme()->create(['subscriber_id' => $learner->id, 'offer_id' => $offer->id]);
    $offer->update(['payment_order_id' => $order->id, 'redeemed_at' => now()]);
    $transaction = ProviderTransaction::factory()->create(['payment_order_id' => $order->id]);
    $subscription = Subscription::factory()->create(['subscriber_id' => $learner->id, 'provider' => 'payme']);
    $charge = Payment::factory()->create(['payment_order_id' => $order->id, 'provider_transaction_id' => $transaction->id, 'subscription_id' => $subscription->id, 'subscriber_id' => $learner->id]);
    $refund = Payment::factory()->create(['refunded_payment_id' => $charge->id, 'kind' => 'refund', 'subscriber_id' => $learner->id]);

    // An order with a provider transaction cannot be deleted (restrict) ...
    expectConstraintViolation(fn () => $order->delete());

    // ... deleting the subscription / transaction / charge nulls the references, the ledger survives.
    $subscription->delete();
    $transaction->delete();
    expect($charge->fresh()?->subscription_id)->toBeNull()
        ->and($charge->fresh()?->provider_transaction_id)->toBeNull()
        ->and($order->fresh()?->subscription_id)->toBeNull();

    $charge->delete();
    expect($refund->fresh()?->refunded_payment_id)->toBeNull();

    // Deleting the offer nulls the order's offer_id; deleting the order nulls the offer's back-reference
    // (that FK exists on PostgreSQL only — see the payment_orders migration).
    $offer->delete();
    expect($order->fresh()?->offer_id)->toBeNull();
    $offer2 = Offer::factory()->create(['learner_id' => $learner->id, 'kind' => 'winback', 'payment_order_id' => $order->id]);
    $order->delete();
    if (DB::getDriverName() === 'pgsql') {
        expect($offer2->fresh()?->payment_order_id)->toBeNull();
    }
    expect(Payment::query()->count())->toBe(1); // the refund row: payment_order_id nulled, row kept
});

it('enforces unique promo_codes.code and organizations.slug and courses.slug', function (): void {
    PromoCode::factory()->create(['code' => 'WELCOME']);
    expectConstraintViolation(fn () => PromoCode::factory()->create(['code' => 'WELCOME']));

    Organization::factory()->create(['slug' => 'acme']);
    expectConstraintViolation(fn () => Organization::factory()->create(['slug' => 'acme']));

    Course::factory()->create(['slug' => 'ielts']);
    expectConstraintViolation(fn () => Course::factory()->create(['slug' => 'ielts']));
});

it('allows at most one live subscription per subscriber', function (): void {
    $learner = Learner::factory()->create();
    $other = Learner::factory()->create();
    $organization = Organization::factory()->create();

    Subscription::factory()->create(['subscriber_id' => $learner->id]);

    // Second live subscription of the same subscriber is rejected (`canceled` is live, docs/13 §2.1) ...
    expectConstraintViolation(fn () => Subscription::factory()->create(['subscriber_id' => $learner->id, 'status' => 'trialing']));
    expectConstraintViolation(fn () => Subscription::factory()->create(['subscriber_id' => $learner->id, 'status' => 'past_due']));
    expectConstraintViolation(fn () => Subscription::factory()->create(['subscriber_id' => $learner->id, 'status' => 'canceled']));

    // ... history rows, other subscribers and the same id under another subscriber_type are fine.
    Subscription::factory()->expired()->create(['subscriber_id' => $learner->id]);
    Subscription::factory()->create(['subscriber_id' => $other->id]);
    Subscription::factory()->create([
        'subscriber_type' => 'organization',
        'subscriber_id' => $organization->id,
    ]);

    expect($learner->subscriptions()->count())->toBe(2);
});

it('cascades learner deletion to group_members, learner_daily_stats and promo_redemptions', function (): void {
    $learner = Learner::factory()->create();
    $keep = Learner::factory()->create();
    GroupMember::factory()->create(['learner_id' => $learner->id]);
    GroupMember::factory()->create(['learner_id' => $keep->id]);
    LearnerDailyStats::factory()->create(['learner_id' => $learner->id]);
    PromoRedemption::factory()->create(['learner_id' => $learner->id]);

    $learner->forceDelete();

    expect(GroupMember::where('learner_id', $learner->id)->count())->toBe(0)
        ->and(LearnerDailyStats::where('learner_id', $learner->id)->count())->toBe(0)
        ->and(PromoRedemption::where('learner_id', $learner->id)->count())->toBe(0)
        ->and(GroupMember::where('learner_id', $keep->id)->count())->toBe(1);
});

it('cascades group deletion to group_members', function (): void {
    $group = TelegramGroup::factory()->create();
    GroupMember::factory()->create(['telegram_group_id' => $group->id]);

    $group->delete();

    expect(GroupMember::count())->toBe(0)
        ->and(Learner::count())->toBe(1);
});

it('cascades course deletion to stages (and further down to lessons and words)', function (): void {
    $course = Course::factory()->create();
    $stage = Stage::factory()->create(['course_id' => $course->id]);
    $lesson = Lesson::factory()->create(['stage_id' => $stage->id]);
    Word::factory()->create(['lesson_id' => $lesson->id]);
    $otherStage = Stage::factory()->create();

    $course->delete();

    expect(Stage::whereKey($stage->id)->exists())->toBeFalse()
        ->and(Lesson::whereKey($lesson->id)->exists())->toBeFalse()
        ->and(Word::count())->toBe(0)
        ->and(Stage::whereKey($otherStage->id)->exists())->toBeTrue();
});

it('cascades word deletion to word_translations', function (): void {
    $translation = WordTranslation::factory()->create();

    Word::whereKey($translation->word_id)->first()?->delete();

    expect(WordTranslation::count())->toBe(0);
});

it('nulls telegram_groups.course_id / organization_id and learners.active_course_id when the target is deleted', function (): void {
    $course = Course::factory()->create();
    $organization = Organization::factory()->create();
    $group = TelegramGroup::factory()->create(['course_id' => $course->id, 'organization_id' => $organization->id]);
    $learner = Learner::factory()->create(['active_course_id' => $course->id]);

    $course->delete();
    $organization->delete();

    expect($group->fresh()?->course_id)->toBeNull()
        ->and($group->fresh()?->organization_id)->toBeNull()
        ->and($learner->fresh()?->active_course_id)->toBeNull()
        ->and(TelegramGroup::whereKey($group->id)->exists())->toBeTrue()
        ->and(Learner::whereKey($learner->id)->exists())->toBeTrue();
});

it('nulls learners.referrer_learner_id when the referrer is deleted', function (): void {
    $referrer = Learner::factory()->create();
    $learner = Learner::factory()->create(['referrer_learner_id' => $referrer->id]);

    $referrer->forceDelete();

    expect($learner->fresh()?->referrer_learner_id)->toBeNull();
});

it('nulls subscriptions.granted_by_user_id, promo_codes.created_by_user_id and promo_redemptions.subscription_id on delete', function (): void {
    $user = User::factory()->create();
    $subscription = Subscription::factory()->create(['granted_by_user_id' => $user->id]);
    $code = PromoCode::factory()->create(['created_by_user_id' => $user->id]);
    $redemption = PromoRedemption::factory()->create([
        'promo_code_id' => $code->id,
        'subscription_id' => $subscription->id,
    ]);

    $user->delete();
    expect($subscription->fresh()?->granted_by_user_id)->toBeNull()
        ->and($code->fresh()?->created_by_user_id)->toBeNull();

    $subscription->delete();
    expect($redemption->fresh()?->subscription_id)->toBeNull();
});

it('adds audio_path and is_active to words', function (): void {
    $word = Word::factory()->create();

    expect(Schema::hasColumns('words', ['audio_path', 'is_active']))->toBeTrue()
        ->and($word->fresh()?->is_active)->toBeTrue()
        ->and($word->fresh()?->audio_path)->toBeNull();
});

it('has partial index learners_next_reminder_at_idx on learners', function (): void {
    $row = DB::selectOne(
        "SELECT indexdef FROM pg_indexes
         WHERE tablename = 'learners' AND indexname = 'learners_next_reminder_at_idx'",
    );

    expect($row)->not->toBeNull()
        ->and($row->indexdef)->toContain('next_reminder_at IS NOT NULL')
        ->and($row->indexdef)->toContain('dm_blocked_at IS NULL');
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'pg_indexes catalog is PostgreSQL-only.');

it('has partial unique index subscriptions_one_live_per_subscriber', function (): void {
    $row = DB::selectOne(
        "SELECT indexdef FROM pg_indexes
         WHERE tablename = 'subscriptions' AND indexname = 'subscriptions_one_live_per_subscriber'",
    );

    expect($row)->not->toBeNull()
        ->and($row->indexdef)->toContain('UNIQUE')
        ->and($row->indexdef)->toContain('subscriber_type, subscriber_id')
        ->and($row->indexdef)->toContain("'canceled'");

    $offers = DB::selectOne(
        "SELECT indexdef FROM pg_indexes
         WHERE tablename = 'offers' AND indexname = 'offers_one_open_per_learner_kind'",
    );
    expect($offers)->not->toBeNull()
        ->and($offers->indexdef)->toContain('UNIQUE')
        ->and($offers->indexdef)->toContain('redeemed_at IS NULL');
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'pg_indexes catalog is PostgreSQL-only.');

it('rolls back and re-applies the organization migrations (2B.1) in FK order', function (): void {
    $tables = ['organization_members', 'organization_invites', 'organization_users'];

    // Everything from the first 2B.1 file up: the three 2B.1 files (members -> invites (FK invite_id) -> users) and what came later.
    $this->artisan('migrate:rollback', ['--step' => migrationStepsBackTo('2026_10_21_000001_create_organization_users_table')])->assertSuccessful();
    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeFalse("Table {$table} survived rollback");
    }
    expect(Schema::hasTable('payments'))->toBeTrue()
        ->and(Schema::hasTable('organizations'))->toBeTrue();

    $this->artisan('migrate')->assertSuccessful();
    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue("Table {$table} missing after re-migrate");
    }

    if (DB::getDriverName() === 'pgsql') {
        $memberKeys = collect(array_column(Schema::getForeignKeys('organization_members'), 'columns'))->flatten()->all();
        expect($memberKeys)->toContain('organization_id', 'learner_id', 'invite_id');
    }
});

it('enforces the organization uniqueness keys: members (organization_id, learner_id), invites.code, users (organization_id, user_id)', function (): void {
    $org = Organization::factory()->create();
    $learner = Learner::factory()->create();
    $user = User::factory()->create();

    OrganizationMember::factory()->create(['organization_id' => $org->id, 'learner_id' => $learner->id]);
    expectConstraintViolation(fn () => OrganizationMember::factory()->left()->create(['organization_id' => $org->id, 'learner_id' => $learner->id]));
    // The same learner in another organization is fine.
    OrganizationMember::factory()->create(['learner_id' => $learner->id]);

    OrganizationInvite::factory()->create(['organization_id' => $org->id, 'code' => 'ABCDEFGHJK']);
    expectConstraintViolation(fn () => OrganizationInvite::factory()->create(['code' => 'ABCDEFGHJK']));

    OrganizationUser::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id]);
    expectConstraintViolation(fn () => OrganizationUser::factory()->teacher()->create(['organization_id' => $org->id, 'user_id' => $user->id]));
});

it('cascades and nulls the organization foreign keys as designed (docs/13 §1.3)', function (): void {
    $org = Organization::factory()->create();
    $learner = Learner::factory()->create();
    $staff = User::factory()->create();
    $creator = User::factory()->create();
    $group = TelegramGroup::factory()->create(['organization_id' => $org->id]);
    $invite = OrganizationInvite::factory()->create(['organization_id' => $org->id, 'telegram_group_id' => $group->id, 'created_by_user_id' => $creator->id]);
    $member = OrganizationMember::factory()->create(['organization_id' => $org->id, 'learner_id' => $learner->id, 'invite_id' => $invite->id]);
    OrganizationUser::factory()->create(['organization_id' => $org->id, 'user_id' => $staff->id, 'invited_by_user_id' => $creator->id]);

    // nullOnDelete: the invite keeps living without its group / creator, the member without its invite.
    $group->delete();
    $creator->delete();
    expect($invite->fresh()?->telegram_group_id)->toBeNull()
        ->and($invite->fresh()?->created_by_user_id)->toBeNull()
        ->and(OrganizationUser::query()->sole()->invited_by_user_id)->toBeNull();

    $invite->delete();
    expect($member->fresh()?->invite_id)->toBeNull()
        ->and($member->fresh()?->status)->toBe('active');

    // cascade: a hard-deleted learner / user / organization takes its rows along.
    $learner->forceDelete();
    expect(OrganizationMember::query()->count())->toBe(0);

    $staff->delete();
    expect(OrganizationUser::query()->count())->toBe(0);

    OrganizationMember::factory()->create(['organization_id' => $org->id]);
    OrganizationInvite::factory()->create(['organization_id' => $org->id]);
    OrganizationUser::factory()->create(['organization_id' => $org->id]);
    $org->delete();
    expect(OrganizationMember::query()->count())->toBe(0)
        ->and(OrganizationInvite::query()->count())->toBe(0)
        ->and(OrganizationUser::query()->count())->toBe(0);
});

it('rolls back and re-applies the billing migrations (2A.1) in FK order', function (): void {
    $tables = ['payments', 'provider_transactions', 'payment_orders', 'offers'];

    // Everything from the first 2A.1 billing file up (the 2B.1 / 2B.4 / 2C files sit on top of the four billing files).
    $this->artisan('migrate:rollback', ['--step' => migrationStepsBackTo('2026_10_20_000001_create_offers_table')])->assertSuccessful();
    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeFalse("Table {$table} survived rollback");
    }
    expect(Schema::hasTable('subscriptions'))->toBeTrue();

    $this->artisan('migrate')->assertSuccessful();
    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue("Table {$table} missing after re-migrate");
    }
    expect(Schema::hasColumns('subscriptions', ['tier']))->toBeTrue()
        ->and(Schema::hasColumns('offers', ['payment_order_id']))->toBeTrue();

    if (DB::getDriverName() === 'pgsql') {
        $offerKeys = array_column(Schema::getForeignKeys('offers'), 'columns');
        expect(collect($offerKeys)->flatten()->all())->toContain('payment_order_id');
    }

    // The partial predicate of the offers index survived (sqlite would lose it on a table rebuild).
    $learner = Learner::factory()->create();
    Offer::factory()->redeemed()->create(['learner_id' => $learner->id, 'kind' => 'campaign']);
    Offer::factory()->create(['learner_id' => $learner->id, 'kind' => 'campaign']);
    expect(Offer::query()->count())->toBe(2);
});

it('rolls back and re-applies the learner-first migrations (1.1a + 1.1b)', function (): void {
    $newTables = [
        'achievements',
        'friendships', 'challenges',
        'league_members', 'league_groups', 'league_weeks',
        'referrals',
        'payments', 'provider_transactions', 'payment_orders', 'offers',
        'promo_redemptions', 'promo_codes', 'subscriptions', 'learner_daily_stats',
        'word_translations', 'group_members', 'learners', 'courses', 'organizations',
    ];

    // Rolling back migrations of the 1.1a tables requires dropping everything created after them
    // in the same order as `migrate:rollback`, so go through the full batch and re-apply it.
    $this->artisan('migrate:rollback', ['--step' => 1000])->assertSuccessful();
    foreach ([...$newTables, 'telegram_groups', 'stages', 'words'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse("Table {$table} survived rollback");
    }

    $this->artisan('migrate')->assertSuccessful();
    foreach ($newTables as $table) {
        expect(Schema::hasTable($table))->toBeTrue("Table {$table} missing after re-migrate");
    }
    expect(Schema::hasColumns('telegram_groups', ['organization_id', 'course_id']))->toBeTrue()
        ->and(Schema::hasColumn('stages', 'course_id'))->toBeTrue()
        ->and(Schema::hasColumns('words', ['audio_path', 'is_active']))->toBeTrue();

    // 1.1b: the schema comes back in its learner-first shape, not the student one.
    expect(Schema::hasTable('students'))->toBeFalse()
        ->and(Schema::hasColumn('word_repetitions', 'learner_id'))->toBeTrue()
        ->and(Schema::hasColumn('word_repetitions', 'student_id'))->toBeFalse()
        ->and(Schema::hasColumns('training_sessions', ['learner_id', 'mode', 'source', 'course_id', 'expires_at', 'done_reason']))->toBeTrue();

    $indexes = array_column(Schema::getIndexes('training_sessions'), 'name');
    expect($indexes)->toContain('training_sessions_one_open_daily_per_learner')
        ->and($indexes)->toContain('training_sessions_one_open_lesson_per_learner');
});

it('drops the learner-first indexes on rollback of the open-session migration', function (): void {
    $migration = require database_path('migrations/2026_07_13_000002_add_open_session_unique_indexes.php');

    $migration->down();
    $indexes = array_column(Schema::getIndexes('training_sessions'), 'name');
    expect($indexes)->not->toContain('training_sessions_one_open_daily_per_learner')
        ->and($indexes)->not->toContain('training_sessions_one_open_lesson_per_learner')
        ->and($indexes)->not->toContain('training_sessions_one_open_per_group_lesson_unique');

    $migration->up();
    $indexes = array_column(Schema::getIndexes('training_sessions'), 'name');
    expect($indexes)->toContain('training_sessions_one_open_daily_per_learner')
        ->and($indexes)->toContain('training_sessions_one_open_lesson_per_learner')
        ->and($indexes)->toContain('training_sessions_one_open_per_group_lesson_unique');

    if (DB::getDriverName() === 'pgsql') {
        $migration->down();
        expect(DB::selectOne("SELECT 1 AS x FROM pg_constraint WHERE conname = 'training_sessions_mode_shape_check'"))->toBeNull();
        $migration->up();
        expect(DB::selectOne("SELECT 1 AS x FROM pg_constraint WHERE conname = 'training_sessions_mode_shape_check'"))->not->toBeNull();
    }
});

it('rolls back and re-applies the assignment migrations (2B.4) in FK order', function (): void {
    $this->artisan('migrate:rollback', ['--step' => migrationStepsBackTo('2026_10_21_000004_create_assignments_table')])->assertSuccessful();
    expect(Schema::hasTable('assignment_results'))->toBeFalse()
        ->and(Schema::hasTable('assignments'))->toBeFalse()
        ->and(Schema::hasTable('organization_members'))->toBeTrue();

    $this->artisan('migrate')->assertSuccessful();
    expect(Schema::hasColumns('assignments', ['organization_id', 'telegram_group_id', 'lesson_id', 'due_at', 'announced_at', 'reminded_at', 'status', 'completion_threshold', 'meta']))->toBeTrue()
        ->and(Schema::hasColumns('assignment_results', ['assignment_id', 'learner_id', 'progress_pct', 'completed_at', 'computed_at']))->toBeTrue();
});

it('enforces assignment_results (assignment_id, learner_id) unique, cascades the results and restricts lesson deletion', function (): void {
    $assignment = Assignment::factory()->create();
    $learner = Learner::factory()->create();

    AssignmentResult::factory()->create(['assignment_id' => $assignment->id, 'learner_id' => $learner->id]);
    expectConstraintViolation(fn () => AssignmentResult::factory()->create(['assignment_id' => $assignment->id, 'learner_id' => $learner->id]));

    // A lesson with assignments cannot be deleted (RESTRICT) — on sqlite only with FK enforcement on.
    if (DB::getDriverName() === 'pgsql') {
        expectConstraintViolation(fn () => DB::table('lessons')->where('id', $assignment->lesson_id)->delete());
    }

    $assignment->group()->delete();
    expect(Assignment::query()->count())->toBe(0)
        ->and(AssignmentResult::query()->count())->toBe(0);
});

it('adds the streak freeze columns (2C.2) and rolls them back', function (): void {
    expect(Schema::hasColumns('learners', ['streak_freezes', 'streak_freezes_refilled_on']))->toBeTrue()
        ->and(Schema::hasColumn('learner_daily_stats', 'frozen_at'))->toBeTrue();

    $learner = Learner::factory()->create();
    expect($learner->fresh()?->streak_freezes)->toBe(0)
        ->and($learner->fresh()?->streak_freezes_refilled_on)->toBeNull();

    $this->artisan('migrate:rollback', ['--step' => migrationStepsBackTo('2026_10_22_200001_add_streak_freeze_columns')])->assertSuccessful();
    expect(Schema::hasColumn('learners', 'streak_freezes'))->toBeFalse()
        ->and(Schema::hasColumn('learners', 'streak_freezes_refilled_on'))->toBeFalse()
        ->and(Schema::hasColumn('learner_daily_stats', 'frozen_at'))->toBeFalse()
        ->and(Schema::hasTable('learners'))->toBeTrue();

    $this->artisan('migrate')->assertSuccessful();
    expect(Schema::hasColumns('learners', ['streak_freezes', 'streak_freezes_refilled_on']))->toBeTrue()
        ->and(Schema::hasColumn('learner_daily_stats', 'frozen_at'))->toBeTrue();
});

// --- 2D.2 exercises (docs/13 §1.6) ----------------------------------------------

it('adds the exercise / scheduler columns of training_reviews (2D.2) and rolls them back', function (): void {
    expect(Schema::hasColumns('training_reviews', ['exercise', 'answer_text', 'scheduler', 'interval_before_days']))->toBeTrue();

    $review = TrainingReview::factory()->create();
    expect($review->fresh()?->exercise)->toBe(ExerciseType::Flash)
        ->and($review->fresh()?->answer_text)->toBeNull()
        ->and($review->fresh()?->scheduler)->toBe('sm2')
        ->and($review->fresh()?->interval_before_days)->toBeNull();

    $this->artisan('migrate:rollback', ['--step' => migrationStepsBackTo('2026_10_23_200001_add_exercise_columns_to_training_reviews_table')])->assertSuccessful();
    expect(Schema::hasColumn('training_reviews', 'exercise'))->toBeFalse()
        ->and(Schema::hasColumn('training_reviews', 'answer_text'))->toBeFalse()
        ->and(Schema::hasColumn('training_reviews', 'scheduler'))->toBeFalse()
        ->and(Schema::hasColumn('training_reviews', 'interval_before_days'))->toBeFalse()
        ->and(Schema::hasTable('training_reviews'))->toBeTrue();

    $this->artisan('migrate')->assertSuccessful();
    expect(Schema::hasColumns('training_reviews', ['exercise', 'answer_text', 'scheduler', 'interval_before_days']))->toBeTrue();
});

// --- 2D.3 FSRS A/B (docs/13 §1.5, §1.6) ------------------------------------------

it('adds learners.scheduler and the FSRS columns of word_repetitions (2D.3) and rolls them back', function (): void {
    expect(Schema::hasColumn('learners', 'scheduler'))->toBeTrue()
        ->and(Schema::hasColumns('word_repetitions', ['stability', 'difficulty', 'scheduler', 'scheduled_days']))->toBeTrue();

    $learner = Learner::factory()->create(['scheduler' => null]);
    $rep = WordRepetition::factory()->create(['learner_id' => $learner->id]);
    expect($learner->fresh()?->scheduler)->toBeNull()
        ->and($rep->fresh()?->stability)->toBeNull()
        ->and($rep->fresh()?->difficulty)->toBeNull()
        ->and($rep->fresh()?->scheduler)->toBe(SchedulerKind::Sm2)
        ->and($rep->fresh()?->scheduled_days)->toBeNull();

    $rep->update(['stability' => 12345.1234, 'difficulty' => 9.8765, 'scheduler' => SchedulerKind::Fsrs, 'scheduled_days' => 12345]);
    expect($rep->fresh()?->stability)->toEqualWithDelta(12345.1234, 1e-6)
        ->and($rep->fresh()?->difficulty)->toEqualWithDelta(9.8765, 1e-6)
        ->and($rep->fresh()?->scheduler)->toBe(SchedulerKind::Fsrs);

    $this->artisan('migrate:rollback', ['--step' => migrationStepsBackTo('2026_10_23_300001_add_scheduler_to_learners_table')])->assertSuccessful();
    expect(Schema::hasColumn('learners', 'scheduler'))->toBeFalse()
        ->and(Schema::hasColumn('word_repetitions', 'stability'))->toBeFalse()
        ->and(Schema::hasColumn('word_repetitions', 'difficulty'))->toBeFalse()
        ->and(Schema::hasColumn('word_repetitions', 'scheduler'))->toBeFalse()
        ->and(Schema::hasColumn('word_repetitions', 'scheduled_days'))->toBeFalse()
        ->and(Schema::hasTable('word_repetitions'))->toBeTrue();

    $this->artisan('migrate')->assertSuccessful();
    expect(Schema::hasColumn('learners', 'scheduler'))->toBeTrue()
        ->and(Schema::hasColumns('word_repetitions', ['stability', 'difficulty', 'scheduler', 'scheduled_days']))->toBeTrue();
});

// --- 2C.1 referrals (docs/13 §1.5) ----------------------------------------------

it('keeps one referral per invited learner and one referral code per learner', function (): void {
    $friend = Learner::factory()->create();
    Referral::factory()->create(['referee_learner_id' => $friend->id]);
    expectConstraintViolation(fn () => Referral::factory()->create(['referee_learner_id' => $friend->id]));

    Learner::factory()->create(['referral_code' => 'ABCDEFGH']);
    expectConstraintViolation(fn () => Learner::factory()->create(['referral_code' => 'ABCDEFGH']));
    // NULL codes do not collide (generated lazily).
    Learner::factory()->count(2)->create(['referral_code' => null]);

    $indexes = array_column(Schema::getIndexes('referrals'), 'columns');
    expect($indexes)->toContain(['referrer_learner_id', 'status', 'linked_at'])
        ->toContain(['status', 'activated_at'])
        ->toContain(['status', 'rewarded_at']);
});

it('rolls back and re-applies the referral recovery indexes (2R.10)', function (): void {
    $this->artisan('migrate:rollback', ['--step' => migrationStepsBackTo('2026_10_23_500001_add_recovery_indexes_to_referrals_table')])->assertSuccessful();
    expect(array_column(Schema::getIndexes('referrals'), 'columns'))->not->toContain(['status', 'activated_at'])
        ->and(Schema::hasTable('referrals'))->toBeTrue();

    $this->artisan('migrate')->assertSuccessful();
    expect(array_column(Schema::getIndexes('referrals'), 'columns'))->toContain(['status', 'rewarded_at']);
});

it('cascades referrals with either learner', function (): void {
    $inviter = Learner::factory()->create();
    $friend = Learner::factory()->create();
    Referral::factory()->create(['referrer_learner_id' => $inviter->id, 'referee_learner_id' => $friend->id]);
    Referral::factory()->create(['referrer_learner_id' => $friend->id]);

    $friend->forceDelete();
    expect(Referral::query()->count())->toBe(0);
});

it('rolls back and re-applies the referrals migration (2C.1)', function (): void {
    $this->artisan('migrate:rollback', ['--step' => migrationStepsBackTo('2026_10_22_100001_create_referrals_table')])->assertSuccessful();
    expect(Schema::hasTable('referrals'))->toBeFalse()
        ->and(Schema::hasTable('assignment_results'))->toBeTrue();

    $this->artisan('migrate')->assertSuccessful();
    expect(Schema::hasTable('referrals'))->toBeTrue()
        ->and(Schema::hasColumn('learners', 'referral_code'))->toBeTrue();
});

// --- 2D.4 personal mocks (docs/13 §1.6) -----------------------------------------

it('generalizes exam_sessions for personal mocks: one open mock per learner, mode shape, rollback', function (): void {
    expect(Schema::hasColumns('exam_sessions', ['learner_id', 'mode', 'course_id']))->toBeTrue();

    $learner = Learner::factory()->create();
    $course = Course::factory()->published()->create(['exam_tag' => 'ielts']);

    ExamSession::factory()->personalMock($learner, $course)->create();
    expectConstraintViolation(fn () => ExamSession::factory()->personalMock($learner, $course)->create());
    // A closed mock does not block a new one; another learner is independent.
    ExamSession::factory()->personalMock($learner, $course)->closed()->create();
    ExamSession::factory()->personalMock(null, $course)->create();
    expect(ExamSession::query()->where('mode', 'personal_mock')->count())->toBe(3);

    // Group exams keep their own invariant (one open per group) next to NULL-group mocks.
    $group = TelegramGroup::factory()->create();
    ExamSession::factory()->create(['telegram_group_id' => $group->id]);
    expectConstraintViolation(fn () => ExamSession::factory()->create(['telegram_group_id' => $group->id]));
    ExamSession::factory()->closed()->create(['telegram_group_id' => $group->id]);

    if (DB::getDriverName() === 'pgsql') {
        expectConstraintViolation(fn () => ExamSession::factory()->personalMock($learner, $course)->closed()->create(['course_id' => null]));
        expectConstraintViolation(fn () => ExamSession::factory()->personalMock($learner, $course)->closed()->create(['learner_id' => null]));
        expectConstraintViolation(fn () => ExamSession::factory()->closed()->create(['telegram_group_id' => null]));
        expectConstraintViolation(fn () => ExamSession::factory()->closed()->create(['lesson_id' => null]));
        expectConstraintViolation(fn () => ExamSession::factory()->closed()->create(['started_by_user_id' => null]));
    }

    // Cascades: the mock goes with its learner and with its course.
    $gone = ExamSession::factory()->personalMock(null, $course)->closed()->create();
    $gone->learner?->forceDelete();
    expect(ExamSession::query()->whereKey($gone->id)->exists())->toBeFalse();
    $course->delete();
    expect(ExamSession::query()->where('mode', 'personal_mock')->count())->toBe(0);

    $this->artisan('migrate:rollback', ['--step' => migrationStepsBackTo('2026_10_23_400001_generalize_exam_sessions_for_personal_mock')])->assertSuccessful();
    expect(Schema::hasColumn('exam_sessions', 'learner_id'))->toBeFalse()
        ->and(Schema::hasColumn('exam_sessions', 'mode'))->toBeFalse()
        ->and(Schema::hasColumn('exam_sessions', 'course_id'))->toBeFalse()
        ->and(Schema::hasTable('exam_sessions'))->toBeTrue();

    // The group invariant survived the rollback (sqlite rebuilds the table). Raw rows: the factory
    // now fills the dropped columns.
    $group2 = TelegramGroup::factory()->create();
    $lesson = Lesson::factory()->create();
    $teacher = User::factory()->create();
    $groupExam = fn (string $status): array => [
        'telegram_group_id' => $group2->id,
        'lesson_id' => $lesson->id,
        'started_by_user_id' => $teacher->id,
        'status' => $status,
        'started_at' => now(),
        'ends_at' => now()->addHour(),
        'config' => '{}',
    ];
    DB::table('exam_sessions')->insert($groupExam('open'));
    expectConstraintViolation(fn () => DB::table('exam_sessions')->insert($groupExam('open')));
    DB::table('exam_sessions')->insert($groupExam('closed'));

    $this->artisan('migrate')->assertSuccessful();
    expect(Schema::hasColumns('exam_sessions', ['learner_id', 'mode', 'course_id']))->toBeTrue();
    ExamSession::factory()->personalMock()->create();
});

// --- 2C.3 leagues (docs/13 §1.5) ------------------------------------------------

it('keeps one league week per Monday and one membership per learner and week', function (): void {
    LeagueWeek::factory()->startingOn('2026-10-19')->create();
    expectConstraintViolation(fn () => LeagueWeek::factory()->startingOn('2026-10-19')->create());

    $week = LeagueWeek::factory()->startingOn('2026-10-12')->create();
    $a = LeagueGroup::factory()->create(['league_week_id' => $week->id, 'tier' => 0]);
    $b = LeagueGroup::factory()->create(['league_week_id' => $week->id, 'tier' => 0]);
    $learner = Learner::factory()->create();
    LeagueMember::factory()->create(['league_group_id' => $a->id, 'learner_id' => $learner->id]);
    expectConstraintViolation(fn () => LeagueMember::factory()->create(['league_group_id' => $b->id, 'learner_id' => $learner->id]));

    expect(array_column(Schema::getIndexes('league_groups'), 'columns'))->toContain(['league_week_id', 'tier', 'members_count'])
        ->and(array_column(Schema::getIndexes('league_members'), 'columns'))->toContain(['league_group_id'])
        ->and(array_column(Schema::getIndexes('league_members'), 'columns'))->toContain(['learner_id', 'league_week_id']);
});

it('cascades league groups and members with the week, and members with the learner', function (): void {
    $week = LeagueWeek::factory()->create();
    $group = LeagueGroup::factory()->create(['league_week_id' => $week->id]);
    $learner = Learner::factory()->create();
    LeagueMember::factory()->create(['league_group_id' => $group->id, 'learner_id' => $learner->id]);
    LeagueMember::factory()->create(['league_group_id' => $group->id]);

    $learner->forceDelete();
    expect(LeagueMember::query()->count())->toBe(1);

    $week->delete();
    expect(LeagueGroup::query()->count())->toBe(0)
        ->and(LeagueMember::query()->count())->toBe(0);
});

it('rolls back and re-applies the league migration (2C.3)', function (): void {
    $this->artisan('migrate:rollback', ['--step' => migrationStepsBackTo('2026_10_22_300001_create_league_tables')])->assertSuccessful();
    expect(Schema::hasTable('league_weeks'))->toBeFalse()
        ->and(Schema::hasTable('league_groups'))->toBeFalse()
        ->and(Schema::hasTable('league_members'))->toBeFalse()
        ->and(Schema::hasTable('referrals'))->toBeTrue();

    $this->artisan('migrate')->assertSuccessful();
    expect(Schema::hasTable('league_weeks'))->toBeTrue()
        ->and(Schema::hasTable('league_groups'))->toBeTrue()
        ->and(Schema::hasTable('league_members'))->toBeTrue();
});

it('adds league_groups.closed_at, stamps the groups of already closed weeks on migrate, and rolls back (P1-2)', function (): void {
    $this->artisan('migrate:rollback', ['--step' => migrationStepsBackTo('2026_10_28_100001_add_closed_at_to_league_groups_table')])->assertSuccessful();
    expect(Schema::hasColumn('league_groups', 'closed_at'))->toBeFalse()
        ->and(Schema::hasTable('league_groups'))->toBeTrue();

    // Rows written by the whole-week close of 2C.3: a closed week whose groups know nothing of the stamp.
    $closedAt = CarbonImmutable::parse('2026-10-18 19:05:00', 'UTC');
    $closed = LeagueWeek::factory()->startingOn('2026-10-12')->create(['status' => 'closed', 'closed_at' => $closedAt]);
    $open = LeagueWeek::factory()->startingOn('2026-10-19')->create();
    $stamped = LeagueGroup::factory()->create(['league_week_id' => $closed->id]);
    $live = LeagueGroup::factory()->create(['league_week_id' => $open->id]);

    $this->artisan('migrate')->assertSuccessful();
    expect(Schema::hasColumn('league_groups', 'closed_at'))->toBeTrue()
        ->and($stamped->fresh()?->closed_at?->utc()->toDateTimeString())->toBe('2026-10-18 19:05:00')
        ->and($live->fresh()?->closed_at)->toBeNull();
});

// --- 2C.5 friends and challenges (docs/13 §1.5) ----------------------------------

it('keeps ONE friendships row per normalised pair and, on PostgreSQL, refuses a pair in the wrong order', function (): void {
    [$a, $b] = [Learner::factory()->create(), Learner::factory()->create()];
    [$low, $high] = $a->id < $b->id ? [$a, $b] : [$b, $a];

    Friendship::query()->create(['learner_id' => $low->id, 'friend_id' => $high->id, 'status' => 'pending', 'requested_by_learner_id' => $low->id]);
    expectConstraintViolation(fn () => Friendship::query()->create(['learner_id' => $low->id, 'friend_id' => $high->id, 'status' => 'accepted', 'requested_by_learner_id' => $high->id]));

    if (DB::getDriverName() === 'pgsql') {
        // A→B and B→A cannot be two rows: the reversed pair violates CHECK (learner_id < friend_id).
        expectConstraintViolation(fn () => Friendship::query()->create(['learner_id' => $high->id, 'friend_id' => $low->id, 'status' => 'pending', 'requested_by_learner_id' => $high->id]));
        expectConstraintViolation(fn () => Friendship::query()->create(['learner_id' => $low->id, 'friend_id' => $low->id, 'status' => 'pending', 'requested_by_learner_id' => $low->id]));
    }

    expect(Friendship::query()->count())->toBe(1);
});

it('cascades friendships and challenges with their learners', function (): void {
    [$a, $b] = [Learner::factory()->create(), Learner::factory()->create()];
    Friendship::factory()->between($a, $b)->create();
    Challenge::factory()->create(['challenger_id' => $a->id, 'opponent_id' => $b->id, 'winner_learner_id' => $a->id, 'status' => 'finished']);

    $a->forceDelete();
    expect(Friendship::query()->count())->toBe(0)
        ->and(Challenge::query()->count())->toBe(0);
});

it('allows ONE open challenge per pair through the open_pair unique index, and many closed ones', function (): void {
    [$a, $b] = [Learner::factory()->create(), Learner::factory()->create()];
    Challenge::factory()->create(['challenger_id' => $a->id, 'opponent_id' => $b->id, 'open_pair' => '1:2']);
    expectConstraintViolation(fn () => Challenge::factory()->create(['challenger_id' => $b->id, 'opponent_id' => $a->id, 'open_pair' => '1:2']));

    Challenge::factory()->count(3)->create(['challenger_id' => $a->id, 'opponent_id' => $b->id, 'status' => 'finished', 'open_pair' => null]);
    expect(Challenge::query()->count())->toBe(4);
});

it('rolls back and re-applies the friends migrations (2C.5)', function (): void {
    $this->artisan('migrate:rollback', ['--step' => migrationStepsBackTo('2026_10_22_500001_create_friendships_table')])->assertSuccessful();
    expect(Schema::hasTable('friendships'))->toBeFalse()
        ->and(Schema::hasTable('challenges'))->toBeFalse()
        ->and(Schema::hasTable('referrals'))->toBeTrue();

    $this->artisan('migrate')->assertSuccessful();
    expect(Schema::hasTable('friendships'))->toBeTrue()
        ->and(Schema::hasTable('challenges'))->toBeTrue()
        ->and(Schema::hasColumn('challenges', 'open_pair'))->toBeTrue();
});

// --- 2C.4 achievements (docs/13 §1.5) --------------------------------------------

it('keeps ONE achievements row per (learner, key): the unique index makes a key unlock once', function (): void {
    [$a, $b] = [Learner::factory()->create(), Learner::factory()->create()];
    Achievement::factory()->create(['learner_id' => $a->id, 'key' => 'streak_7']);

    expectConstraintViolation(fn () => Achievement::factory()->create(['learner_id' => $a->id, 'key' => 'streak_7']));
    // The conditional insert of AchievementService just does not insert.
    expect(Achievement::query()->insertOrIgnore(['learner_id' => $a->id, 'key' => 'streak_7', 'unlocked_at' => now()]))->toBe(0);

    Achievement::factory()->create(['learner_id' => $a->id, 'key' => 'streak_30']);
    Achievement::factory()->create(['learner_id' => $b->id, 'key' => 'streak_7']);
    expect(Achievement::query()->count())->toBe(3);
});

it('cascades achievements with their learner', function (): void {
    $learner = Learner::factory()->create();
    Achievement::factory()->create(['learner_id' => $learner->id]);

    $learner->forceDelete();

    expect(Achievement::query()->count())->toBe(0);
});

it('rolls back and re-applies the achievements migration (2C.4)', function (): void {
    $this->artisan('migrate:rollback', ['--step' => migrationStepsBackTo('2026_10_22_000003_create_achievements_table')])->assertSuccessful();
    expect(Schema::hasTable('achievements'))->toBeFalse()
        ->and(Schema::hasTable('learners'))->toBeTrue();

    $this->artisan('migrate')->assertSuccessful();
    expect(Schema::hasTable('achievements'))->toBeTrue()
        ->and(Schema::hasColumns('achievements', ['learner_id', 'key', 'unlocked_at', 'shared_at', 'meta']))->toBeTrue();
});

// --- 18.1 practice and battle (docs/16 §2) ----------------------------------------

/**
 * A raw `practice_sessions` row (for the tests of what only PostgreSQL's CHECKs refuse).
 *
 * @param  array<string, mixed>  $override
 * @return array<string, mixed>
 */
function practiceRawSession(array $override = []): array
{
    $learner = Learner::factory()->create();

    return [
        'learner_id' => $learner->id,
        'active_learner_id' => $learner->id,
        'mode' => 'pairs',
        'course_id' => Course::factory()->create()->id,
        'source' => 'hub',
        'status' => 'active',
        'snapshot' => '{}',
        'state' => '{}',
        'total' => 3,
        'locale' => 'ru',
        'expires_at' => now()->addDay(),
        'started_at' => now(),
        ...$override,
    ];
}

/**
 * A raw `battles` row.
 *
 * @param  array<string, mixed>  $override
 * @return array<string, mixed>
 */
function practiceRawBattle(array $override = []): array
{
    return [
        'challenger_id' => Learner::factory()->create()->id,
        'opponent_id' => Learner::factory()->create()->id,
        'course_id' => Course::factory()->create()->id,
        'mode' => 'pairs',
        'status' => 'invited',
        'snapshot' => '{}',
        'invite_expires_at' => now()->addMinutes(5),
        ...$override,
    ];
}

it('rolls back and re-applies the four practice migrations (18.1) in FK order', function (): void {
    $tables = ['battle_answers', 'battle_participants', 'battles', 'practice_attempts', 'practice_sessions', 'practice_item_words', 'practice_item_versions', 'practice_items'];

    $this->artisan('migrate:rollback', ['--step' => migrationStepsBackTo('2026_10_30_100001_add_practice_pilot_at_to_learners_table')])->assertSuccessful();
    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeFalse("Table {$table} survived rollback");
    }
    expect(Schema::hasColumn('learners', 'practice_pilot_at'))->toBeFalse()
        ->and(Schema::hasTable('learners'))->toBeTrue()
        ->and(Schema::hasTable('friendships'))->toBeTrue();

    $this->artisan('migrate')->assertSuccessful();
    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue("Table {$table} missing after re-migrate");
    }
    expect(Schema::hasColumn('learners', 'practice_pilot_at'))->toBeTrue();

    // The invariants are back with the tables: the unique "one active session" and the partial index.
    $learner = Learner::factory()->create();
    PracticeSession::factory()->create(['learner_id' => $learner->id]);
    expectConstraintViolation(fn () => PracticeSession::factory()->create(['learner_id' => $learner->id]));
    expect(array_column(Schema::getIndexes('practice_item_versions'), 'name'))->toContain('practice_item_versions_audio_pending_idx');
});

it('adds learners.practice_pilot_at as a nullable timestamp', function (): void {
    $learner = Learner::factory()->create();
    expect($learner->fresh()?->practice_pilot_at)->toBeNull();

    $learner->update(['practice_pilot_at' => '2026-10-30 10:00:00']);
    expect($learner->fresh()?->practice_pilot_at?->toDateTimeString())->toBe('2026-10-30 10:00:00');
});

it('allows ONE active practice session per learner through the unique active_learner_id, and many finished ones', function (): void {
    $learner = Learner::factory()->create();
    PracticeSession::factory()->create(['learner_id' => $learner->id]);

    expectConstraintViolation(fn () => PracticeSession::factory()->create(['learner_id' => $learner->id]));

    // A finished session lets go of the column (NULLs never collide), another learner is independent.
    PracticeSession::factory()->count(3)->finished()->create(['learner_id' => $learner->id]);
    PracticeSession::factory()->create();
    expect(PracticeSession::query()->where('learner_id', $learner->id)->count())->toBe(4)
        ->and(PracticeSession::query()->where('learner_id', $learner->id)->whereNotNull('active_learner_id')->count())->toBe(1);
});

it('allows ONE active battle per learner through the unique battle_participants.active_learner_id', function (): void {
    $learner = Learner::factory()->create();
    $first = Battle::factory()->create(['challenger_id' => $learner->id]);
    BattleParticipant::factory()->create(['battle_id' => $first->id, 'learner_id' => $learner->id, 'active_learner_id' => $learner->id]);

    $second = Battle::factory()->create(['challenger_id' => $learner->id]);
    expectConstraintViolation(fn () => BattleParticipant::factory()->create(['battle_id' => $second->id, 'learner_id' => $learner->id, 'active_learner_id' => $learner->id]));

    // A player who is not busy with the match (NULL) is not limited by that column - only one row per (battle, player).
    BattleParticipant::factory()->create(['battle_id' => $second->id, 'learner_id' => $learner->id, 'active_learner_id' => null]);
    expectConstraintViolation(fn () => BattleParticipant::factory()->create(['battle_id' => $second->id, 'learner_id' => $learner->id, 'active_learner_id' => null]));
    expect(BattleParticipant::query()->where('learner_id', $learner->id)->count())->toBe(2);
});

it('keeps the idempotency keys of attempts and battle answers unique', function (): void {
    $session = PracticeSession::factory()->create();
    $requestId = '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d';
    PracticeAttempt::factory()->create(['session_id' => $session->id, 'learner_id' => $session->learner_id, 'request_id' => $requestId]);

    expectConstraintViolation(fn () => PracticeAttempt::factory()->create(['session_id' => $session->id, 'learner_id' => $session->learner_id, 'request_id' => $requestId]));
    PracticeAttempt::factory()->create(['session_id' => $session->id, 'learner_id' => $session->learner_id]); // another request
    PracticeAttempt::factory()->create(['request_id' => $requestId]); // the same key in another session

    $battle = Battle::factory()->create();
    $player = $battle->challenger_id;
    BattleAnswer::factory()->create(['battle_id' => $battle->id, 'learner_id' => $player, 'round' => 1, 'request_id' => $requestId]);

    // One final answer per (battle, round, player); one request per (battle, player).
    expectConstraintViolation(fn () => BattleAnswer::factory()->create(['battle_id' => $battle->id, 'learner_id' => $player, 'round' => 1]));
    expectConstraintViolation(fn () => BattleAnswer::factory()->create(['battle_id' => $battle->id, 'learner_id' => $player, 'round' => 2, 'request_id' => $requestId]));
    BattleAnswer::factory()->create(['battle_id' => $battle->id, 'learner_id' => $player, 'round' => 2]);
    BattleAnswer::factory()->create(['battle_id' => $battle->id, 'learner_id' => $battle->opponent_id, 'round' => 1, 'request_id' => $requestId]);
    expect(BattleAnswer::query()->count())->toBe(3);
});

it('keeps the task keys unique per course, the versions per task and the target words per task', function (): void {
    $course = Course::factory()->published()->create();
    $item = PracticeItem::factory()->bare()->forCourse($course)->create(['key' => 'k1']);

    expectConstraintViolation(fn () => PracticeItem::factory()->bare()->forCourse($course)->create(['key' => 'k1']));
    PracticeItem::factory()->bare()->create(['key' => 'k1']); // the same key in another course

    PracticeItemVersion::factory()->create(['item_id' => $item->id, 'number' => 1]);
    expectConstraintViolation(fn () => PracticeItemVersion::factory()->create(['item_id' => $item->id, 'number' => 1]));
    PracticeItemVersion::factory()->create(['item_id' => $item->id, 'number' => 2]);

    $word = Word::factory()->create();
    PracticeItemWord::factory()->create(['item_id' => $item->id, 'word_id' => $word->id]);
    expectConstraintViolation(fn () => PracticeItemWord::factory()->create(['item_id' => $item->id, 'word_id' => $word->id]));
    PracticeItemWord::factory()->create(['item_id' => $item->id]);
    expect(PracticeItemWord::query()->where('item_id', $item->id)->count())->toBe(2);
});

it('has the partial index of the versions whose audio is pending, with its predicate', function (): void {
    $definition = DB::getDriverName() === 'pgsql'
        ? DB::table('pg_indexes')->where('indexname', 'practice_item_versions_audio_pending_idx')->value('indexdef')
        : DB::table('sqlite_master')->where('type', 'index')->where('name', 'practice_item_versions_audio_pending_idx')->value('sql');

    expect((string) $definition)->toContain('audio_status')
        ->toContain('WHERE')
        ->toContain("'pending'");
});

it('stores the jsonb columns of the practice as arrays and the enumerations as enums', function (): void {
    $item = PracticeItem::factory()->sentence()->published()->create();
    $version = PracticeItemVersion::query()->findOrFail($item->fresh()?->published_version_id);

    expect($item->fresh()?->type)->toBe(PracticeItemType::Sentence)
        ->and($item->fresh()?->status)->toBe(PracticeItemStatus::Published)
        ->and($version->state)->toBe(VersionState::Published)
        ->and($version->audio_status)->toBe(AudioStatus::None)
        ->and($version->content)->toBeArray()->toHaveKeys(['prompt', 'fragments', 'accepted']);

    $session = PracticeSession::factory()->ofMode(PracticeMode::Dictation)->create();
    expect($session->fresh()?->mode)->toBe(PracticeMode::Dictation)
        ->and($session->fresh()?->status)->toBe(SessionStatus::Active)
        ->and($session->fresh()?->snapshot)->toBeArray()->toHaveKey('elements')
        ->and($session->fresh()?->state)->toBeArray()->toHaveKey('current')
        ->and($session->fresh()?->expires_at)->toBeInstanceOf(CarbonImmutable::class);

    $battle = Battle::factory()->withParticipants()->create();
    expect($battle->fresh()?->status)->toBe(BattleStatus::Invited)
        ->and($battle->fresh()?->end_reason)->toBeNull()
        ->and($battle->fresh()?->phase)->toBeNull()
        ->and($battle->fresh()?->meta)->toBe([])
        ->and($battle->participants()->orderBy('id')->pluck('role')->all())->toBe([BattleRole::Challenger, BattleRole::Opponent])
        ->and($battle->participants()->whereNotNull('active_learner_id')->count())->toBe(1);

    $playing = Battle::factory()->create(['status' => BattleStatus::Playing, 'phase' => BattlePhase::Answering, 'current_round' => 1, 'end_reason' => BattleEndReason::Completed]);
    expect($playing->fresh()?->phase)->toBe(BattlePhase::Answering)
        ->and($playing->fresh()?->end_reason)->toBe(BattleEndReason::Completed);

    $answer = BattleAnswer::factory()->create(['verdict' => Verdict::Timeout, 'points' => 0]);
    expect($answer->fresh()?->verdict)->toBe(Verdict::Timeout);
});

it('cascades the practice rows with their learner, course and word, and nulls the lesson and the retried session', function (): void {
    $learner = Learner::factory()->create();
    $session = PracticeSession::factory()->create(['learner_id' => $learner->id]);
    PracticeAttempt::factory()->create(['session_id' => $session->id, 'learner_id' => $learner->id]);
    $battle = Battle::factory()->create(['challenger_id' => $learner->id]);
    BattleParticipant::factory()->create(['battle_id' => $battle->id, 'learner_id' => $learner->id]);
    BattleAnswer::factory()->create(['battle_id' => $battle->id, 'learner_id' => $learner->id]);
    $keep = PracticeSession::factory()->create();

    $learner->forceDelete();
    expect(PracticeSession::query()->whereKey($session->id)->exists())->toBeFalse()
        ->and(PracticeAttempt::query()->count())->toBe(0)
        ->and(Battle::query()->whereKey($battle->id)->exists())->toBeFalse()
        ->and(BattleParticipant::query()->count())->toBe(0)
        ->and(BattleAnswer::query()->count())->toBe(0)
        ->and(PracticeSession::query()->whereKey($keep->id)->exists())->toBeTrue();

    // A lesson that goes leaves the sessions and the tasks of the course (SET NULL); a word takes its target-word links along.
    $lesson = Lesson::factory()->for(Stage::factory()->create())->create();
    $word = Word::factory()->for($lesson)->create();
    $item = PracticeItem::factory()->forLesson($lesson)->targeting($word)->published()->create();
    $inLesson = PracticeSession::factory()->create(['lesson_id' => $lesson->id]);
    $retry = PracticeSession::factory()->finished()->create(['retry_of_session_id' => $keep->id]);

    $keep->delete();
    expect($retry->fresh()?->retry_of_session_id)->toBeNull();

    Word::query()->whereKey($word->id)->delete();
    expect(PracticeItemWord::query()->where('item_id', $item->id)->count())->toBe(0);

    Lesson::query()->whereKey($lesson->id)->delete();
    expect($inLesson->fresh()?->lesson_id)->toBeNull()
        ->and($item->fresh()?->lesson_id)->toBeNull();

    // The course takes the tasks (with their versions) and the sessions along.
    $course = Course::factory()->published()->create();
    $courseItem = PracticeItem::factory()->forCourse($course)->published()->create();
    $courseSession = PracticeSession::factory()->create(['course_id' => $course->id]);
    $course->delete();
    expect(PracticeItem::query()->whereKey($courseItem->id)->exists())->toBeFalse()
        ->and(PracticeItemVersion::query()->where('item_id', $courseItem->id)->count())->toBe(0)
        ->and(PracticeSession::query()->whereKey($courseSession->id)->exists())->toBeFalse();
});

it('has the CHECK constraints of the practice tables (PostgreSQL)', function (): void {
    $course = Course::factory()->create();
    $item = ['course_id' => $course->id, 'key' => 'k', 'type' => 'sentence', 'status' => 'draft'];

    // practice_items: the type, the status and the shape "published <=> a published version and not archived".
    expectConstraintViolation(fn () => DB::table('practice_items')->insert([...$item, 'type' => 'pairs']));
    expectConstraintViolation(fn () => DB::table('practice_items')->insert([...$item, 'status' => 'live']));
    expectConstraintViolation(fn () => DB::table('practice_items')->insert([...$item, 'status' => 'published']));
    expectConstraintViolation(fn () => DB::table('practice_items')->insert([...$item, 'published_version_id' => 7]));
    expectConstraintViolation(fn () => DB::table('practice_items')->insert([...$item, 'status' => 'published', 'published_version_id' => 7, 'archived_at' => now()]));
    DB::table('practice_items')->insert([...$item, 'key' => 'k-published', 'status' => 'published', 'published_version_id' => 7]);
    DB::table('practice_items')->insert([...$item, 'key' => 'k-archived', 'status' => 'archived', 'published_version_id' => 7, 'archived_at' => now()]);

    // practice_item_versions
    $itemId = (int) DB::table('practice_items')->where('key', 'k-published')->value('id');
    $version = ['item_id' => $itemId, 'number' => 1, 'state' => 'draft', 'content' => '{}'];
    expectConstraintViolation(fn () => DB::table('practice_item_versions')->insert([...$version, 'state' => 'archived']));
    expectConstraintViolation(fn () => DB::table('practice_item_versions')->insert([...$version, 'audio_status' => 'broken']));
    DB::table('practice_item_versions')->insert($version);

    // practice_sessions: the enumerations, active <=> active_learner_id, active_learner_id = learner_id.
    expectConstraintViolation(fn () => DB::table('practice_sessions')->insert(practiceRawSession(['mode' => 'karaoke'])));
    expectConstraintViolation(fn () => DB::table('practice_sessions')->insert(practiceRawSession(['status' => 'paused'])));
    expectConstraintViolation(fn () => DB::table('practice_sessions')->insert(practiceRawSession(['active_learner_id' => null])));
    expectConstraintViolation(fn () => DB::table('practice_sessions')->insert(practiceRawSession(['status' => 'finished'])));
    expectConstraintViolation(fn () => DB::table('practice_sessions')->insert(practiceRawSession(['active_learner_id' => Learner::factory()->create()->id])));
    DB::table('practice_sessions')->insert(practiceRawSession());
    DB::table('practice_sessions')->insert(practiceRawSession(['status' => 'finished', 'active_learner_id' => null]));

    // battles: two different players, a round 0..5, the status, the phase only while playing.
    $same = Learner::factory()->create()->id;
    expectConstraintViolation(fn () => DB::table('battles')->insert(practiceRawBattle(['challenger_id' => $same, 'opponent_id' => $same])));
    expectConstraintViolation(fn () => DB::table('battles')->insert(practiceRawBattle(['current_round' => 6])));
    expectConstraintViolation(fn () => DB::table('battles')->insert(practiceRawBattle(['status' => 'lobby'])));
    expectConstraintViolation(fn () => DB::table('battles')->insert(practiceRawBattle(['status' => 'playing'])));
    expectConstraintViolation(fn () => DB::table('battles')->insert(practiceRawBattle(['phase' => 'answering'])));
    DB::table('battles')->insert(practiceRawBattle());
    DB::table('battles')->insert(practiceRawBattle(['status' => 'playing', 'phase' => 'answering', 'current_round' => 5]));

    // battle_participants / battle_answers
    $battleId = (int) DB::table('battles')->value('id');
    $player = Learner::factory()->create()->id;
    $participant = ['battle_id' => $battleId, 'learner_id' => $player, 'role' => 'challenger', 'locale' => 'ru'];
    expectConstraintViolation(fn () => DB::table('battle_participants')->insert([...$participant, 'active_learner_id' => Learner::factory()->create()->id]));
    DB::table('battle_participants')->insert([...$participant, 'active_learner_id' => $player]);

    $answer = ['battle_id' => $battleId, 'learner_id' => $player, 'round' => 1, 'payload' => '{}', 'verdict' => 'correct', 'points' => 100, 'accepted_at' => now()];
    expectConstraintViolation(fn () => DB::table('battle_answers')->insert([...$answer, 'request_id' => (string) Str::uuid(), 'points' => 101]));
    expectConstraintViolation(fn () => DB::table('battle_answers')->insert([...$answer, 'request_id' => (string) Str::uuid(), 'round' => 0]));
    expectConstraintViolation(fn () => DB::table('battle_answers')->insert([...$answer, 'request_id' => (string) Str::uuid(), 'round' => 6]));
    DB::table('battle_answers')->insert([...$answer, 'request_id' => (string) Str::uuid()]);
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'The CHECK constraints are PostgreSQL-only; on SQLite the services and the enums hold the same invariants.');
