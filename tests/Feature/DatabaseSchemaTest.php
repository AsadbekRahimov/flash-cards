<?php

declare(strict_types=1);

use App\Models\Course;
use App\Models\ExamAnswer;
use App\Models\ExamResult;
use App\Models\ExamSession;
use App\Models\GroupMember;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\Lesson;
use App\Models\Organization;
use App\Models\PromoCode;
use App\Models\PromoRedemption;
use App\Models\Stage;
use App\Models\Student;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\Word;
use App\Models\WordRepetition;
use App\Models\WordTranslation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

$expectedTables = [
    'users', 'password_reset_tokens', 'sessions',
    'telegram_groups', 'teacher_groups', 'students',
    'stages', 'lessons', 'words', 'word_repetitions',
    'training_sessions', 'training_reviews',
    'exam_sessions', 'exam_question_attempts', 'exam_answers', 'exam_results',
    'notifications_log', 'audit_logs', 'product_events',
    'roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions',
    // Phase 1 (docs/12 §1): learner-first schema, additive in 1.1a.
    'organizations', 'courses', 'learners', 'group_members', 'word_translations',
    'learner_daily_stats', 'subscriptions', 'promo_codes', 'promo_redemptions',
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

it('enforces unique chat_id on telegram_groups', function (): void {
    TelegramGroup::factory()->create(['chat_id' => -555]);
    expectConstraintViolation(fn () => TelegramGroup::factory()->create(['chat_id' => -555]));
});

it('enforces unique student per (telegram_user_id, telegram_group_id)', function (): void {
    $group = TelegramGroup::factory()->create();
    Student::factory()->create(['telegram_user_id' => 12345, 'telegram_group_id' => $group->id]);
    expectConstraintViolation(fn () => Student::factory()->create(['telegram_user_id' => 12345, 'telegram_group_id' => $group->id]));
});

it('enforces (lesson_id, word) uniqueness', function (): void {
    $lesson = Lesson::factory()->for(Stage::factory())->create();
    Word::factory()->create(['lesson_id' => $lesson->id, 'word' => 'apple']);
    expectConstraintViolation(fn () => Word::factory()->create(['lesson_id' => $lesson->id, 'word' => 'apple']));
});

it('cascades student deletion to word_repetitions', function (): void {
    $student = Student::factory()->for(TelegramGroup::factory(), 'group')->create();
    $word = Word::factory()->for(Lesson::factory()->for(Stage::factory()))->create();
    WordRepetition::factory()->create(['student_id' => $student->id, 'word_id' => $word->id]);

    $student->delete();

    expect(WordRepetition::where('student_id', $student->id)->count())->toBe(0);
});

it('enforces one exam answer per student and word in a session', function (): void {
    $session = ExamSession::factory()->create();
    $student = Student::factory()->create(['telegram_group_id' => $session->telegram_group_id]);
    $word = Word::factory()->create(['lesson_id' => $session->lesson_id]);

    ExamAnswer::factory()->create([
        'exam_session_id' => $session->id,
        'student_id' => $student->id,
        'word_id' => $word->id,
    ]);

    expectConstraintViolation(fn () => ExamAnswer::factory()->create([
        'exam_session_id' => $session->id,
        'student_id' => $student->id,
        'word_id' => $word->id,
    ]));
});

it('enforces one exam result per student in a session', function (): void {
    $session = ExamSession::factory()->create();
    $student = Student::factory()->create(['telegram_group_id' => $session->telegram_group_id]);

    ExamResult::factory()->create([
        'exam_session_id' => $session->id,
        'student_id' => $student->id,
    ]);

    expectConstraintViolation(fn () => ExamResult::factory()->create([
        'exam_session_id' => $session->id,
        'student_id' => $student->id,
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

it('has partial index on word_repetitions for is_hard', function (): void {
    $exists = DB::selectOne(
        "SELECT 1 AS x FROM pg_indexes
         WHERE tablename = 'word_repetitions'
           AND indexname = 'word_repetitions_student_is_hard_idx'",
    );
    expect($exists)->not->toBeNull();
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'pg_indexes catalog is PostgreSQL-only.');

it('has composite index (student_id, next_review_at)', function (): void {
    $row = DB::selectOne(
        "SELECT 1 AS x FROM pg_indexes
         WHERE tablename = 'word_repetitions'
           AND indexdef LIKE '%(student_id, next_review_at)%'",
    );
    expect($row)->not->toBeNull();
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'pg_indexes catalog is PostgreSQL-only.');

it('runs migrate:fresh --seed and produces demo data', function (): void {
    $this->artisan('migrate:fresh', ['--seed' => true])->assertSuccessful();

    expect(DB::table('users')->where('email', 'admin@local')->exists())->toBeTrue();
    expect(DB::table('users')->where('email', 'teacher@local')->exists())->toBeTrue();
    expect(DB::table('telegram_groups')->where('chat_id', -1001234567890)->exists())->toBeTrue();
    expect(DB::table('stages')->count())->toBe(2);
    expect(DB::table('lessons')->count())->toBe(6);
    expect(DB::table('words')->count())->toBe(120);
    expect(DB::table('students')->count())->toBe(5);
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

    // Second live subscription of the same subscriber is rejected ...
    expectConstraintViolation(fn () => Subscription::factory()->create(['subscriber_id' => $learner->id, 'status' => 'trialing']));
    expectConstraintViolation(fn () => Subscription::factory()->create(['subscriber_id' => $learner->id, 'status' => 'past_due']));

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
        ->and($row->indexdef)->toContain('subscriber_type, subscriber_id');
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'pg_indexes catalog is PostgreSQL-only.');

it('rolls back and re-applies the 1.1a migrations', function (): void {
    $newTables = [
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
});
