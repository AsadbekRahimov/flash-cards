<?php

declare(strict_types=1);

use App\Domain\Learning\Services\LearningCache;
use App\Models\ExamResult;
use App\Models\ExamSession;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\Student;
use App\Models\TelegramGroup;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Config::set('cache.default', 'array');
    Cache::flush();
});

it('caches lesson word counts until the lesson cache is cleared', function (): void {
    $lesson = Lesson::factory()->for(Stage::factory())->create();
    Word::factory()->count(2)->create(['lesson_id' => $lesson->id]);

    $cache = app(LearningCache::class);

    expect($cache->lessonWordCount((int) $lesson->id))->toBe(2);

    Word::factory()->create(['lesson_id' => $lesson->id]);

    expect($cache->lessonWordCount((int) $lesson->id))->toBe(2);

    $cache->forgetLesson($lesson);

    expect($cache->lessonWordCount((int) $lesson->id))->toBe(3);
});

it('caches exam leaderboards until the exam cache is cleared', function (): void {
    $group = TelegramGroup::factory()->create();
    $session = ExamSession::factory()->create([
        'telegram_group_id' => $group->id,
        'status' => 'closed',
        'closed_at' => now(),
    ]);
    $alice = Student::factory()->create(['telegram_group_id' => $group->id, 'first_name' => 'Alice']);
    $bob = Student::factory()->create(['telegram_group_id' => $group->id, 'first_name' => 'Bob']);

    ExamResult::factory()->create([
        'exam_session_id' => $session->id,
        'student_id' => $alice->id,
        'total_score' => 30,
        'rank' => 1,
    ]);

    $cache = app(LearningCache::class);
    $first = $cache->examLeaderboard($session);

    expect($first['total_participants'])->toBe(1)
        ->and($first['rows'][0]['name'])->toBe('Alice');

    ExamResult::factory()->create([
        'exam_session_id' => $session->id,
        'student_id' => $bob->id,
        'total_score' => 20,
        'rank' => 2,
    ]);

    expect($cache->examLeaderboard($session)['total_participants'])->toBe(1);

    $cache->forgetExam($session);

    expect($cache->examLeaderboard($session)['total_participants'])->toBe(2);
});
