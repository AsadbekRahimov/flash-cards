<?php

declare(strict_types=1);

use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Handlers\StartExamHandler;
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
use Tests\Support\FakeTelegramClient;

/*
 * Anybody can press a button of the /start_exam and /start_training pickers in a group. A tap of somebody who is not the
 * teacher is answered to the tapper alone (answerCallbackQuery with an alert), not with a message that the whole group
 * would read on every tap; the tap of a teacher still goes through.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Config::set('twa.base_url', 'https://twa.test');
    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);
    $this->group = TelegramGroup::factory()->create(['chat_id' => -4004, 'status' => 'active']);
    $stage = Stage::factory()->inDefaultCourse()->create(['number' => 1]);
    $lesson = Lesson::factory()->for($stage)->create(['number' => 1]);
    Word::factory()->count(10)->for($lesson)->create();
});

function pickerTap(string $data, int $fromId, string $queryId): void
{
    app(TelegramDispatcher::class)->dispatch(['callback_query' => [
        'id' => $queryId,
        'from' => ['id' => $fromId],
        'data' => $data,
        'message' => ['chat' => ['id' => -4004, 'type' => 'supergroup'], 'message_id' => 5],
    ]]);
}

function pickerTeacherOf(TelegramGroup $group, int $telegramId): User
{
    $teacher = User::factory()->create(['telegram_user_id' => $telegramId]);
    DB::table('teacher_groups')->insert(['user_id' => $teacher->id, 'telegram_group_id' => $group->id, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);

    return $teacher;
}

it('answers an exam button tap of a user who is not linked as a teacher with an alert, and posts nothing to the group', function (string $data): void {
    pickerTap($data, 9001, 'cq-1');

    expect($this->tg->sent)->toBe([])
        ->and($this->tg->answered)->toBe(['cq-1'])
        ->and($this->tg->answeredTexts['cq-1']['show_alert'])->toBeTrue()
        ->and($this->tg->answeredTexts['cq-1']['text'])->toContain('для учителя')
        ->and(mb_strlen($this->tg->answeredTexts['cq-1']['text']))->toBeLessThanOrEqual(200)
        ->and(ExamSession::query()->count())->toBe(0);
})->with([
    'lesson chosen' => [StartExamHandler::CALLBACK_PREFIX.'1:1'],
    'duration chosen' => [StartExamHandler::CALLBACK_PREFIX.'1:1:5'],
]);

it('answers an exam button tap of a teacher of another group with the "only the teacher" alert', function (): void {
    $other = TelegramGroup::factory()->create(['status' => 'active']);
    pickerTeacherOf($other, 9002);

    pickerTap(StartExamHandler::CALLBACK_PREFIX.'1:1:5', 9002, 'cq-2');

    expect($this->tg->sent)->toBe([])
        ->and($this->tg->answeredTexts['cq-2']['text'])->toBe('Только учитель этой группы может запускать экзамен.')
        ->and(ExamSession::query()->count())->toBe(0);
});

it('still takes the exam tap of the teacher of the group, with an empty answer', function (): void {
    pickerTeacherOf($this->group, 9003);

    pickerTap(StartExamHandler::CALLBACK_PREFIX.'1:1', 9003, 'cq-3');

    expect($this->tg->answered)->toBe(['cq-3'])
        ->and($this->tg->answeredTexts)->toBe([])
        // the duration row follows
        ->and($this->tg->sent)->toHaveCount(1);
});

it('answers a training button tap of a user who is not linked, and of a linked user who is not the teacher, with an alert only', function (): void {
    User::factory()->create(['telegram_user_id' => 9005]);

    pickerTap(StartTrainingHandler::CALLBACK_PREFIX.'1:1', 9004, 'cq-4');
    pickerTap(StartTrainingHandler::CALLBACK_PREFIX.'1:1', 9005, 'cq-5');

    expect($this->tg->sent)->toBe([])
        ->and($this->tg->answered)->toBe(['cq-4', 'cq-5'])
        ->and($this->tg->answeredTexts['cq-4']['text'])->toContain('для учителя')
        ->and($this->tg->answeredTexts['cq-5']['text'])->toBe('Только учитель этой группы может запускать тренировку.')
        ->and($this->tg->answeredTexts['cq-5']['show_alert'])->toBeTrue()
        ->and(TrainingSession::query()->count())->toBe(0);
});

it('still opens the training for the teacher of the group, with an empty answer', function (): void {
    pickerTeacherOf($this->group, 9006);

    pickerTap(StartTrainingHandler::CALLBACK_PREFIX.'1:1', 9006, 'cq-6');

    expect($this->tg->answered)->toBe(['cq-6'])
        ->and($this->tg->answeredTexts)->toBe([])
        ->and(TrainingSession::query()->where('status', 'open')->count())->toBe(1);
});
