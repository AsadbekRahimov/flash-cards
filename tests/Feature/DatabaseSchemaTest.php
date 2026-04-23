<?php

declare(strict_types=1);

use App\Models\Lesson;
use App\Models\Stage;
use App\Models\Student;
use App\Models\TelegramGroup;
use App\Models\Word;
use App\Models\WordRepetition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

$expectedTables = [
    'users', 'password_reset_tokens', 'sessions',
    'telegram_groups', 'teacher_groups', 'students',
    'stages', 'lessons', 'words', 'word_repetitions',
    'training_sessions', 'training_reviews',
    'exam_sessions', 'exam_answers', 'exam_results',
    'notifications_log', 'audit_logs',
    'roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions',
];

it('creates all expected tables', function () use ($expectedTables): void {
    foreach ($expectedTables as $table) {
        expect(Schema::hasTable($table))->toBeTrue("Missing table: {$table}");
    }
});

it('enforces unique chat_id on telegram_groups', function (): void {
    TelegramGroup::factory()->create(['chat_id' => -555]);
    expect(fn () => TelegramGroup::factory()->create(['chat_id' => -555]))
        ->toThrow(Throwable::class);
});

it('enforces unique student per (telegram_user_id, telegram_group_id)', function (): void {
    $group = TelegramGroup::factory()->create();
    Student::factory()->create(['telegram_user_id' => 12345, 'telegram_group_id' => $group->id]);
    expect(fn () => Student::factory()->create(['telegram_user_id' => 12345, 'telegram_group_id' => $group->id]))
        ->toThrow(Throwable::class);
});

it('enforces (lesson_id, word) uniqueness', function (): void {
    $lesson = Lesson::factory()->for(Stage::factory())->create();
    Word::factory()->create(['lesson_id' => $lesson->id, 'word' => 'apple']);
    expect(fn () => Word::factory()->create(['lesson_id' => $lesson->id, 'word' => 'apple']))
        ->toThrow(Throwable::class);
});

it('cascades student deletion to word_repetitions', function (): void {
    $student = Student::factory()->for(TelegramGroup::factory(), 'group')->create();
    $word = Word::factory()->for(Lesson::factory()->for(Stage::factory()))->create();
    WordRepetition::factory()->create(['student_id' => $student->id, 'word_id' => $word->id]);

    $student->delete();

    expect(WordRepetition::where('student_id', $student->id)->count())->toBe(0);
});

it('has partial index on word_repetitions for is_hard', function (): void {
    $exists = DB::selectOne(
        "SELECT 1 AS x FROM pg_indexes
         WHERE tablename = 'word_repetitions'
           AND indexname = 'word_repetitions_student_is_hard_idx'"
    );
    expect($exists)->not->toBeNull();
});

it('has composite index (student_id, next_review_at)', function (): void {
    $row = DB::selectOne(
        "SELECT 1 AS x FROM pg_indexes
         WHERE tablename = 'word_repetitions'
           AND indexdef LIKE '%(student_id, next_review_at)%'"
    );
    expect($row)->not->toBeNull();
});

it('runs migrate:fresh --seed and produces demo data', function (): void {
    $this->artisan('migrate:fresh', ['--seed' => true])->assertSuccessful();

    expect(DB::table('users')->where('email', 'admin@local')->exists())->toBeTrue();
    expect(DB::table('users')->where('email', 'teacher@local')->exists())->toBeTrue();
    expect(DB::table('telegram_groups')->where('chat_id', -1001234567890)->exists())->toBeTrue();
    expect(DB::table('stages')->count())->toBe(2);
    expect(DB::table('lessons')->count())->toBe(6);
    expect(DB::table('words')->count())->toBe(120);
    expect(DB::table('students')->count())->toBe(5);
    expect(DB::table('word_repetitions')->count())->toBeGreaterThan(0);
});
