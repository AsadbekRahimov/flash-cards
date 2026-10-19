<?php

declare(strict_types=1);

use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Learning\Services\LeaderboardBuilder;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Jobs\PostLeaderboardJob;
use App\Models\ExamAnswer;
use App\Models\ExamResult;
use App\Models\ExamSession;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\TelegramGroup;
use App\Models\User;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->api = Mockery::mock(TelegramClient::class);
    $this->api->shouldReceive('sendMessage')->zeroOrMoreTimes();
    $this->app->instance(TelegramClient::class, $this->api);
});

function seedOpenExam(int $chatId, int $teacherTgId): array
{
    $group = TelegramGroup::factory()->create(['chat_id' => $chatId, 'status' => 'active']);
    $teacher = User::factory()->create(['telegram_user_id' => $teacherTgId]);
    DB::table('teacher_groups')->insert([
        'user_id' => $teacher->id,
        'telegram_group_id' => $group->id,
        'is_primary' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $lesson = Lesson::factory()->for(Stage::factory()->create())->create();

    $session = ExamSession::factory()->withQuestions(4)->create([
        'telegram_group_id' => $group->id,
        'lesson_id' => $lesson->id,
        'started_by_user_id' => $teacher->id,
        'status' => 'open',
        'started_at' => now()->subMinutes(5),
        'ends_at' => now()->addMinute(),
    ]);
    $correct = Word::where('lesson_id', $lesson->id)->first();

    return compact('group', 'teacher', 'lesson', 'session', 'correct');
}

it('/close_exam closes session and dispatches PostLeaderboardJob', function (): void {
    Bus::fake();
    $s = seedOpenExam(-7777, 111);

    app(TelegramDispatcher::class)->dispatch([
        'message' => [
            'chat' => ['id' => -7777, 'type' => 'supergroup'],
            'from' => ['id' => 111],
            'text' => '/close_exam',
        ],
    ]);

    expect($s['session']->fresh()->status)->toBe('closed');
    Bus::assertDispatched(PostLeaderboardJob::class, fn (PostLeaderboardJob $j): bool => $j->examSessionId === $s['session']->id);
});

it('/close_exam from non-teacher is rejected', function (): void {
    $s = seedOpenExam(-7778, 222);
    User::factory()->create(['telegram_user_id' => 333]); // not attached as teacher

    app(TelegramDispatcher::class)->dispatch([
        'message' => [
            'chat' => ['id' => -7778, 'type' => 'supergroup'],
            'from' => ['id' => 333],
            'text' => '/close_exam',
        ],
    ]);

    expect($s['session']->fresh()->status)->toBe('open');
});

it('/close_exam reports "no open exam" when there is none', function (): void {
    $group = TelegramGroup::factory()->create(['chat_id' => -9999, 'status' => 'active']);
    $teacher = User::factory()->create(['telegram_user_id' => 444]);
    DB::table('teacher_groups')->insert([
        'user_id' => $teacher->id,
        'telegram_group_id' => $group->id,
        'is_primary' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // no exam in the group
    app(TelegramDispatcher::class)->dispatch([
        'message' => [
            'chat' => ['id' => -9999, 'type' => 'supergroup'],
            'from' => ['id' => 444],
            'text' => '/close_exam',
        ],
    ]);

    expect(ExamSession::query()->count())->toBe(0);
});

it('LeaderboardBuilder ranks learners correctly and stores exam_results', function (): void {
    $s = seedOpenExam(-5555, 555);
    $session = $s['session'];

    $alice = Learner::factory()->memberOf($s['group'])->create();
    $bob = Learner::factory()->memberOf($s['group'])->create();
    $carol = Learner::factory()->memberOf($s['group'])->create();

    // Alice: 30 pts, Bob: 30 pts but slower, Carol: 0 pts
    ExamAnswer::query()->insert([
        ['exam_session_id' => $session->id, 'learner_id' => $alice->id, 'word_id' => $s['correct']->id, 'selected_translation' => 'тест', 'is_correct' => true,  'score' => 30, 'time_spent_ms' => 1000, 'answered_at' => now()],
        ['exam_session_id' => $session->id, 'learner_id' => $bob->id,   'word_id' => $s['correct']->id, 'selected_translation' => 'тест', 'is_correct' => true,  'score' => 30, 'time_spent_ms' => 5000, 'answered_at' => now()],
        ['exam_session_id' => $session->id, 'learner_id' => $carol->id, 'word_id' => $s['correct']->id, 'selected_translation' => 'a',    'is_correct' => false, 'score' => 0,  'time_spent_ms' => 2000, 'answered_at' => now()],
    ]);

    $built = app(LeaderboardBuilder::class)->build($session);

    expect(ExamResult::query()->where('exam_session_id', $session->id)->count())->toBe(3);

    $results = ExamResult::query()->where('exam_session_id', $session->id)->orderBy('rank')->get();
    expect($results[0]->learner_id)->toBe($alice->id)
        ->and($results[0]->rank)->toBe(1)
        ->and($results[1]->learner_id)->toBe($bob->id)
        ->and($results[1]->rank)->toBe(2)
        ->and($results[2]->learner_id)->toBe($carol->id)
        ->and($results[2]->rank)->toBe(3);
});

it('LeaderboardBuilder assigns equal rank for full ties', function (): void {
    $s = seedOpenExam(-4444, 666);
    $session = $s['session'];

    $alice = Learner::factory()->memberOf($s['group'])->create();
    $bob = Learner::factory()->memberOf($s['group'])->create();
    $carol = Learner::factory()->memberOf($s['group'])->create();

    // Alice and Bob fully tied; Carol worse
    ExamAnswer::query()->insert([
        ['exam_session_id' => $session->id, 'learner_id' => $alice->id, 'word_id' => $s['correct']->id, 'selected_translation' => 'тест', 'is_correct' => true,  'score' => 20, 'time_spent_ms' => 2000, 'answered_at' => now()],
        ['exam_session_id' => $session->id, 'learner_id' => $bob->id,   'word_id' => $s['correct']->id, 'selected_translation' => 'тест', 'is_correct' => true,  'score' => 20, 'time_spent_ms' => 2000, 'answered_at' => now()],
        ['exam_session_id' => $session->id, 'learner_id' => $carol->id, 'word_id' => $s['correct']->id, 'selected_translation' => 'a',    'is_correct' => false, 'score' => 0,  'time_spent_ms' => 3000, 'answered_at' => now()],
    ]);

    app(LeaderboardBuilder::class)->build($session);

    $ranks = ExamResult::query()
        ->where('exam_session_id', $session->id)
        ->orderBy('learner_id')
        ->pluck('rank', 'learner_id');

    expect($ranks[$alice->id])->toBe(1);
    expect($ranks[$bob->id])->toBe(1);
    // Carol gets rank 3 (competition ranking, not dense: 1, 1, 3).
    expect($ranks[$carol->id])->toBe(3);
});

it('exams:close-expired closes past-due exam and dispatches the leaderboard job', function (): void {
    Bus::fake();

    $s = seedOpenExam(-3333, 777);
    $s['session']->update(['ends_at' => now()->subMinutes(2)]);

    $this->artisan('exams:close-expired', ['--grace' => 0])->assertSuccessful();

    expect($s['session']->fresh()->status)->toBe('closed');
    Bus::assertDispatched(PostLeaderboardJob::class, fn ($j): bool => $j->examSessionId === $s['session']->id);
});

it('exams:close-expired respects grace period', function (): void {
    Bus::fake();

    $s = seedOpenExam(-2222, 888);
    // ended 10 seconds ago; grace 30s → should still be open after the command
    $s['session']->update(['ends_at' => now()->subSeconds(10)]);

    $this->artisan('exams:close-expired', ['--grace' => 30])->assertSuccessful();

    expect($s['session']->fresh()->status)->toBe('open');
    Bus::assertNotDispatched(PostLeaderboardJob::class);
});

it('posts a leaderboard only once when the job is delivered repeatedly', function (): void {
    $s = seedOpenExam(-1111, 999);
    $s['session']->update(['status' => 'closed', 'closed_at' => now()]);

    $telegram = Mockery::mock(TelegramClient::class);
    $telegram->shouldReceive('sendMessage')->once();
    $job = new PostLeaderboardJob($s['session']->id);

    $job->handle($telegram, app(LeaderboardBuilder::class), app(ProductEventRecorder::class));
    $job->handle($telegram, app(LeaderboardBuilder::class), app(ProductEventRecorder::class));

    expect($s['session']->fresh()->leaderboard_posted_at)->not->toBeNull();
});

it('does not post a leaderboard while the exam is still open', function (): void {
    $s = seedOpenExam(-1010, 1001);
    $telegram = Mockery::mock(TelegramClient::class);
    $telegram->shouldNotReceive('sendMessage');

    (new PostLeaderboardJob($s['session']->id))
        ->handle($telegram, app(LeaderboardBuilder::class), app(ProductEventRecorder::class));

    expect($s['session']->fresh()->leaderboard_posted_at)->toBeNull();
});
