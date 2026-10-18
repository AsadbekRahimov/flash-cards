<?php

declare(strict_types=1);

use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Learning\Exceptions\ExamSessionException;
use App\Domain\Learning\Exceptions\TrainingSessionException;
use App\Domain\Learning\Services\ExamSessionService;
use App\Domain\Learning\Services\TrainingSessionService;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\TelegramGroup;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\Word;
use App\Models\WordTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Two courses that both have "stage 1 / lesson 1" (plus stage 5 only in the second one).
 * Group `$default` has no course (→ the default course), group `$other` studies the second course.
 *
 * @return array{default: TelegramGroup, other: TelegramGroup, teacher: User, defaultLesson: Lesson, otherLesson: Lesson, otherCourse: Course}
 */
function twoCoursesWithSameStageNumber(): array
{
    $defaultCourse = app(CourseCatalogService::class)->defaultCourse();
    $otherCourse = Course::factory()->published()->create(['slug' => 'english-a1']);

    $lessons = [];
    foreach (['default' => $defaultCourse, 'other' => $otherCourse] as $key => $course) {
        $stage = Stage::factory()->create(['course_id' => $course->id, 'number' => 1]);
        $lessons[$key] = Lesson::factory()->for($stage)->create(['number' => 1]);
        $words = Word::factory()->count(8)->for($lessons[$key])->create();
        // Distractor pool in a second lesson of the same stage.
        Word::factory()->count(12)->for(Lesson::factory()->for($stage)->create(['number' => 2]))->create();
        foreach ($words as $word) {
            WordTranslation::factory()->uz()->create(['word_id' => $word->id, 'translation' => "uz-{$course->slug}-{$word->id}"]);
        }
    }
    // A stage that exists in the second course only.
    Lesson::factory()->for(Stage::factory()->create(['course_id' => $otherCourse->id, 'number' => 5]))->create(['number' => 1]);

    $teacher = User::factory()->create(['telegram_user_id' => 777]);
    $groups = [];
    foreach (['default' => null, 'other' => $otherCourse->id] as $key => $courseId) {
        $groups[$key] = TelegramGroup::factory()->create(['status' => 'active', 'course_id' => $courseId]);
        DB::table('teacher_groups')->insert([
            'user_id' => $teacher->id,
            'telegram_group_id' => $groups[$key]->id,
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return [
        'default' => $groups['default'],
        'other' => $groups['other'],
        'teacher' => $teacher,
        'defaultLesson' => $lessons['default'],
        'otherLesson' => $lessons['other'],
        'otherCourse' => $otherCourse,
    ];
}

it('opens the stage of the group course when two courses share a stage number (training)', function (): void {
    $s = twoCoursesWithSameStageNumber();
    $service = app(TrainingSessionService::class);

    $fromDefault = $service->open($s['default'], 1, 1, $s['teacher']);
    $fromOther = $service->open($s['other'], 1, 1, $s['teacher']);

    expect($fromDefault->lesson_id)->toBe($s['defaultLesson']->id)
        ->and($fromOther->lesson_id)->toBe($s['otherLesson']->id)
        ->and($fromDefault->lesson_id)->not->toBe($fromOther->lesson_id);
});

it('does not see stages of another course (training)', function (): void {
    $s = twoCoursesWithSameStageNumber();
    $service = app(TrainingSessionService::class);

    // Stage 5 exists in the second course only.
    expect($service->open($s['other'], 5, 1, $s['teacher'])->exists)->toBeTrue();

    try {
        $service->open($s['default'], 5, 1, $s['teacher']);
        $this->fail('Expected TrainingSessionException');
    } catch (TrainingSessionException $e) {
        expect($e->reason)->toBe(TrainingSessionException::REASON_STAGE_NOT_FOUND);
    }

    try {
        $service->open($s['other'], 1, 9, $s['teacher']);
        $this->fail('Expected TrainingSessionException');
    } catch (TrainingSessionException $e) {
        expect($e->reason)->toBe(TrainingSessionException::REASON_LESSON_NOT_FOUND);
    }
});

it('opens a session for a known lesson regardless of the course of the group', function (): void {
    $s = twoCoursesWithSameStageNumber();

    $session = app(TrainingSessionService::class)->openForLesson($s['default'], $s['otherLesson'], $s['teacher'], 'reminder');

    expect($session->lesson_id)->toBe($s['otherLesson']->id)
        ->and($session->source)->toBe('reminder')
        ->and($session->telegram_group_id)->toBe($s['default']->id);
});

it('keeps the teacher and group checks on openForLesson', function (): void {
    $s = twoCoursesWithSameStageNumber();
    $stranger = User::factory()->create();
    $inactive = TelegramGroup::factory()->create(['status' => 'pending']);
    DB::table('teacher_groups')->insert([
        'user_id' => $s['teacher']->id,
        'telegram_group_id' => $inactive->id,
        'is_primary' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $service = app(TrainingSessionService::class);

    expect(fn () => $service->openForLesson($s['default'], $s['defaultLesson'], $stranger))
        ->toThrow(TrainingSessionException::class, 'not a teacher')
        ->and(fn () => $service->openForLesson($inactive, $s['defaultLesson'], $s['teacher']))
        ->toThrow(TrainingSessionException::class, 'not active');
});

it('opens the exam on the stage of the group course and fixes the locale of the group', function (): void {
    $s = twoCoursesWithSameStageNumber();
    $s['other']->update(['meta' => ['locale' => 'uz']]);
    $service = app(ExamSessionService::class);

    $ru = $service->open($s['default'], 1, 1, 5, $s['teacher'], totalQuestions: 5);
    $uz = $service->open($s['other'], 1, 1, 5, $s['teacher'], totalQuestions: 5);

    expect($ru->lesson_id)->toBe($s['defaultLesson']->id)
        ->and($uz->lesson_id)->toBe($s['otherLesson']->id)
        ->and($ru->config['locale'])->toBe('ru')
        ->and($uz->config['locale'])->toBe('uz');

    // The uz exam is made of uz texts: the answers come from the course of the group, and the
    // distractors are uz as well (the pool may reach into other courses).
    foreach ($uz->config['questions'] as $question) {
        expect($question['correct_translation'])->toStartWith('uz-english-a1-');
        foreach ($question['options'] as $option) {
            expect($option)->toStartWith('uz-');
        }
    }
    // The ru exam uses words.translation / ru rows.
    foreach ($ru->config['questions'] as $question) {
        expect($question['correct_translation'])->not->toStartWith('uz-');
    }
});

it('does not see stages of another course (exam)', function (): void {
    $s = twoCoursesWithSameStageNumber();

    try {
        app(ExamSessionService::class)->open($s['default'], 5, 1, 5, $s['teacher']);
        $this->fail('Expected ExamSessionException');
    } catch (ExamSessionException $e) {
        expect($e->reason)->toBe(ExamSessionException::REASON_STAGE_NOT_FOUND);
    }
});

it('resolves /start_training numbers in the course of the group chat', function (): void {
    Config::set('twa.base_url', 'https://twa.test');
    $api = Mockery::mock(TelegramClient::class);
    $this->app->instance(TelegramClient::class, $api);
    $api->shouldReceive('sendWebAppButton')->once();

    $s = twoCoursesWithSameStageNumber();

    app(TelegramDispatcher::class)->dispatch([
        'message' => [
            'chat' => ['id' => $s['other']->chat_id, 'type' => 'supergroup'],
            'from' => ['id' => 777],
            'text' => '/start_training 1 1',
        ],
    ]);

    $session = TrainingSession::query()->sole();
    expect($session->telegram_group_id)->toBe($s['other']->id)
        ->and($session->lesson_id)->toBe($s['otherLesson']->id);
});

it('lists only the lessons of the group course in the /start_training picker', function (): void {
    $api = Mockery::mock(TelegramClient::class);
    $this->app->instance(TelegramClient::class, $api);

    $s = twoCoursesWithSameStageNumber();

    $api->shouldReceive('sendMessage')
        ->once()
        ->withArgs(function (int $chatId, string $text, ?string $parseMode, ?array $replyMarkup) use ($s): bool {
            $buttons = collect($replyMarkup['inline_keyboard'] ?? [])->flatten(1)->pluck('callback_data')->all();

            // The default course has 1.1 and 1.2; "5:1" exists in the other course only.
            return $chatId === $s['default']->chat_id
                && $buttons === ['tstart:1:1', 'tstart:1:2'];
        });

    app(TelegramDispatcher::class)->dispatch([
        'message' => [
            'chat' => ['id' => $s['default']->chat_id, 'type' => 'supergroup'],
            'from' => ['id' => 777],
            'text' => '/start_training',
        ],
    ]);
});
