<?php

declare(strict_types=1);

use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Handlers\StartTrainingHandler;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Models\ExamSession;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\TelegramGroup;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Config::set('twa.base_url', 'https://twa.test');
    $this->api = Mockery::mock(TelegramClient::class);
    $this->api->shouldReceive('sendMessage')->zeroOrMoreTimes();
    $this->api->shouldReceive('sendWebAppButton')->zeroOrMoreTimes();
    $this->api->shouldReceive('answerCallbackQuery')->zeroOrMoreTimes();
    $this->app->instance(TelegramClient::class, $this->api);
});

/** @return array{group: TelegramGroup, teacher: User} */
function inactiveTeacherFixture(): array
{
    $group = TelegramGroup::factory()->create(['chat_id' => -4100, 'status' => 'active']);
    $teacher = User::factory()->create(['telegram_user_id' => 4100]);
    DB::table('teacher_groups')->insert([
        'user_id' => $teacher->id,
        'telegram_group_id' => $group->id,
        'is_primary' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $stage = Stage::factory()->inDefaultCourse()->create(['number' => 1]);
    $lesson = Lesson::factory()->for($stage)->create(['number' => 1]);
    Word::factory()->count(10)->for($lesson)->create();
    $lesson2 = Lesson::factory()->for($stage)->create(['number' => 2]);
    Word::factory()->count(10)->for($lesson2)->create();

    return compact('group', 'teacher');
}

function teacherUpdate(string $text): array
{
    return ['message' => ['chat' => ['id' => -4100, 'type' => 'supergroup'], 'from' => ['id' => 4100], 'text' => $text]];
}

it('blocks /start_training for a deactivated teacher and works again after reactivation', function (): void {
    ['teacher' => $teacher] = inactiveTeacherFixture();
    $teacher->forceFill(['is_active' => false])->save();

    app(TelegramDispatcher::class)->dispatch(teacherUpdate('/start_training 1 1'));
    expect(TrainingSession::query()->count())->toBe(0);

    $teacher->forceFill(['is_active' => true])->save();
    app(TelegramDispatcher::class)->dispatch(teacherUpdate('/start_training 1 1'));
    expect(TrainingSession::query()->where('status', 'open')->count())->toBe(1);
});

it('blocks the lesson picker callback for a deactivated teacher and works again after reactivation', function (): void {
    ['teacher' => $teacher] = inactiveTeacherFixture();
    $callback = ['callback_query' => [
        'id' => 'cq-inactive',
        'from' => ['id' => 4100],
        'data' => StartTrainingHandler::CALLBACK_PREFIX.'1:1',
        'message' => ['chat' => ['id' => -4100, 'type' => 'supergroup']],
    ]];

    $teacher->forceFill(['is_active' => false])->save();
    app(TelegramDispatcher::class)->dispatch($callback);
    expect(TrainingSession::query()->count())->toBe(0);

    $teacher->forceFill(['is_active' => true])->save();
    app(TelegramDispatcher::class)->dispatch($callback);
    expect(TrainingSession::query()->where('status', 'open')->count())->toBe(1);
});

it('blocks /start_exam for a deactivated teacher and works again after reactivation', function (): void {
    ['teacher' => $teacher] = inactiveTeacherFixture();
    $teacher->forceFill(['is_active' => false])->save();

    app(TelegramDispatcher::class)->dispatch(teacherUpdate('/start_exam 1 1 5'));
    expect(ExamSession::query()->count())->toBe(0);

    $teacher->forceFill(['is_active' => true])->save();
    app(TelegramDispatcher::class)->dispatch(teacherUpdate('/start_exam 1 1 5'));
    expect(ExamSession::query()->where('status', 'open')->count())->toBe(1);
});

it('blocks /close_exam for a deactivated teacher and works again after reactivation', function (): void {
    ['teacher' => $teacher] = inactiveTeacherFixture();
    app(TelegramDispatcher::class)->dispatch(teacherUpdate('/start_exam 1 1 5'));
    expect(ExamSession::query()->where('status', 'open')->count())->toBe(1);

    $teacher->forceFill(['is_active' => false])->save();
    app(TelegramDispatcher::class)->dispatch(teacherUpdate('/close_exam'));
    expect(ExamSession::query()->where('status', 'open')->count())->toBe(1);

    $teacher->forceFill(['is_active' => true])->save();
    app(TelegramDispatcher::class)->dispatch(teacherUpdate('/close_exam'));
    expect(ExamSession::query()->where('status', 'open')->count())->toBe(0);
});
