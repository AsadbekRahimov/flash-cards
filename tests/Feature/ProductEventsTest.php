<?php

declare(strict_types=1);

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Learners\Services\GroupMembershipService;
use App\Domain\Learning\Services\LeaderboardBuilder;
use App\Domain\Learning\Services\ReviewReminderLinkBuilder;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Services\IrazasyedTelegramClient;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Domain\Telegram\Services\TelegramKeyboardFactory;
use App\Domain\Telegram\Services\TelegramMessageRateLimiter;
use App\Domain\Twa\Services\InitDataValidator;
use App\Jobs\PostLeaderboardJob;
use App\Jobs\SendReviewReminderJob;
use App\Models\ExamSession;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\NotificationLog;
use App\Models\ProductEvent;
use App\Models\Stage;
use App\Models\TelegramGroup;
use App\Models\User;
use App\Models\Word;
use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramResponseException;
use Telegram\Bot\Objects\Message as MessageObject;
use Telegram\Bot\TelegramRequest;
use Telegram\Bot\TelegramResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Config::set('twa.base_url', 'https://twa.test');
    Config::set('telegram.bot_token', 'test-bot-token-1234567890');
    $this->api = Mockery::mock(TelegramClient::class);
    $this->api->shouldReceive('sendMessage')->zeroOrMoreTimes();
    $this->api->shouldReceive('sendWebAppButton')->zeroOrMoreTimes();
    $this->api->shouldReceive('answerCallbackQuery')->zeroOrMoreTimes();
    $this->app->instance(TelegramClient::class, $this->api);
});

/** @return array{group: TelegramGroup, teacher: User} */
function peTeacherInGroup(int $chatId, int $telegramUserId): array
{
    $group = TelegramGroup::factory()->create(['chat_id' => $chatId, 'status' => 'active']);
    $teacher = User::factory()->create(['telegram_user_id' => $telegramUserId]);
    DB::table('teacher_groups')->insert([
        'user_id' => $teacher->id,
        'telegram_group_id' => $group->id,
        'is_primary' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return compact('group', 'teacher');
}

function peLesson(int $words = 10): Lesson
{
    $stage = Stage::factory()->inDefaultCourse()->create(['number' => 1]);
    $lesson = Lesson::factory()->for($stage)->create(['number' => 1]);
    Word::factory()->count($words)->for($lesson)->create();

    return $lesson;
}

function peEvent(ProductEventName $name): ProductEvent
{
    return ProductEvent::query()->where('name', $name->value)->firstOrFail();
}

// --- Recorder -------------------------------------------------------------

it('stores an event with relations and occurred_at', function (): void {
    $group = TelegramGroup::factory()->create();
    $learner = Learner::factory()->memberOf($group)->create();

    app(ProductEventRecorder::class)->record(ProductEventName::ReminderSent, ['due_count' => 5], learner: $learner, group: $group);

    $event = peEvent(ProductEventName::ReminderSent);
    expect($event->learner_id)->toBe($learner->id)
        ->and($event->telegram_group_id)->toBe($group->id)
        ->and($event->user_id)->toBeNull()
        ->and($event->props)->toBe(['due_count' => 5])
        ->and($event->occurred_at)->toBeInstanceOf(CarbonImmutable::class);
});

it('prunes only events older than the configured retention', function (): void {
    Config::set('operations.product_events.retention_days', 30);
    $old = ProductEvent::create(['name' => 'old', 'props' => [], 'occurred_at' => now()->subDays(31)]);
    $fresh = ProductEvent::create(['name' => 'fresh', 'props' => [], 'occurred_at' => now()->subDays(29)]);

    expect((new ProductEvent)->prunable()->pluck('id')->all())->toBe([$old->id]);

    $this->artisan('model:prune', ['--model' => [ProductEvent::class]])->assertSuccessful();

    expect(ProductEvent::query()->pluck('id')->all())->toBe([$fresh->id]);
});

it('schedules model:prune for product events daily', function (): void {
    $event = collect(Schedule::events())->first(fn ($e): bool => str_contains((string) $e->command, 'model:prune'));

    expect($event)->not->toBeNull()
        ->and((string) $event->command)->toContain(ProductEvent::class)
        ->and($event->expression)->toBe('0 3 * * *');
});

it('keeps only safe scalar props', function (): void {
    $props = app(ProductEventRecorder::class)->sanitize([
        'stage' => 1,
        'ratio' => 0.5,
        'ok' => true,
        'nothing' => null,
        'reason' => str_repeat('x', 500),
        'first_name' => 'Alexander',
        'username' => 'alex',
        'message_text' => 'hello',
        'init_data' => 'query_id=1',
        'nested' => ['a' => 1],
        'object' => new stdClass,
        'Bad Key!' => 'x',
        0 => 'numeric key',
    ]);

    expect(array_keys($props))->toBe(['stage', 'ratio', 'ok', 'nothing', 'reason'])
        ->and(mb_strlen($props['reason']))->toBe(ProductEventRecorder::MAX_STRING_LENGTH);
});

it('caps the number of props', function (): void {
    $many = [];
    for ($i = 0; $i < 50; $i++) {
        $many["key_{$i}"] = $i;
    }

    expect(app(ProductEventRecorder::class)->sanitize($many))->toHaveCount(ProductEventRecorder::MAX_PROPS);
});

it('never throws when the write fails and logs a warning', function (): void {
    Schema::drop('product_events');
    Log::spy();

    app(ProductEventRecorder::class)->record(ProductEventName::ExamStarted, ['stage' => 1]);

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context): bool => $message === 'product_event.record_failed'
            && $context['event'] === 'exam.started',
    );
});

it('keeps the outer transaction usable when the write fails', function (): void {
    $user = User::factory()->create();

    DB::transaction(function () use ($user): void {
        // NAN cannot be JSON-encoded: the insert fails inside the savepoint only.
        app(ProductEventRecorder::class)->record(ProductEventName::ExamStarted, ['stage' => NAN]);
        $user->update(['name' => 'Still works']);
    });

    expect($user->fresh()->name)->toBe('Still works');
});

it('can roll back the product_events migration', function (): void {
    expect(Schema::hasTable('product_events'))->toBeTrue();

    $migration = require database_path('migrations/2026_10_18_000001_create_product_events_table.php');
    $migration->down();

    expect(Schema::hasTable('product_events'))->toBeFalse();
});

// --- twa.authenticated ----------------------------------------------------

it('records twa.authenticated on successful TWA auth without PII', function (): void {
    $group = TelegramGroup::factory()->create(['status' => 'active']);
    $learner = Learner::factory()->memberOf($group)->create([
        'telegram_user_id' => 555,
        'first_name' => 'Zinaida',
        'username' => 'zina_private']);

    $initData = InitDataValidator::sign('test-bot-token-1234567890', [
        'query_id' => 'AAHdF6IQAAAAAN0XohDhrOrc',
        'user' => json_encode(['id' => 555, 'first_name' => 'Zinaida']),
        'auth_date' => (string) time(),
    ]);

    $this->postJson('/api/twa/auth', ['init_data' => $initData])->assertOk();

    $event = peEvent(ProductEventName::TwaAuthenticated);
    // A learner belongs to many groups: the event carries no group of its own.
    expect($event->learner_id)->toBe($learner->id)
        ->and($event->telegram_group_id)->toBeNull()
        ->and($event->props)->toBe(['context' => 'none']);

    $raw = json_encode($event->getAttributes());
    expect($raw)->not->toContain('Zinaida')->and($raw)->not->toContain('zina_private');
});

it('does not record twa.authenticated when init_data is invalid', function (): void {
    $this->postJson('/api/twa/auth', ['init_data' => 'garbage'])->assertStatus(401);

    expect(ProductEvent::query()->count())->toBe(0);
});

// --- training -------------------------------------------------------------

it('records training.session_opened for /start_training', function (): void {
    ['group' => $group, 'teacher' => $teacher] = peTeacherInGroup(-1001, 777);
    peLesson();

    app(TelegramDispatcher::class)->dispatch([
        'message' => [
            'chat' => ['id' => -1001, 'type' => 'supergroup'],
            'from' => ['id' => 777, 'first_name' => 'Teacher Name'],
            'text' => '/start_training 1 1',
        ],
    ]);

    $event = peEvent(ProductEventName::TrainingSessionOpened);
    expect($event->user_id)->toBe($teacher->id)
        ->and($event->telegram_group_id)->toBe($group->id)
        ->and($event->props)->toMatchArray(['stage' => 1, 'lesson' => 1, 'source' => 'command', 'reused' => false]);
    expect(json_encode($event->getAttributes()))->not->toContain('Teacher Name');
});

it('records training.session_opened from the lesson picker callback', function (): void {
    peTeacherInGroup(-1002, 778);
    peLesson();

    app(TelegramDispatcher::class)->dispatch([
        'callback_query' => [
            'id' => 'cb1',
            'from' => ['id' => 778],
            'message' => ['chat' => ['id' => -1002, 'type' => 'supergroup']],
            'data' => 'tstart:1:1',
        ],
    ]);

    expect(peEvent(ProductEventName::TrainingSessionOpened)->props)->toMatchArray(['source' => 'picker']);
});

it('does not record training.session_opened when opening fails', function (): void {
    peTeacherInGroup(-1003, 779);

    app(TelegramDispatcher::class)->dispatch([
        'message' => [
            'chat' => ['id' => -1003, 'type' => 'supergroup'],
            'from' => ['id' => 779],
            'text' => '/start_training 9 9',
        ],
    ]);

    expect(ProductEvent::query()->count())->toBe(0);
});

// --- exams ----------------------------------------------------------------

it('records exam.started for /start_exam', function (): void {
    ['teacher' => $teacher] = peTeacherInGroup(-2001, 801);
    peLesson(20);

    app(TelegramDispatcher::class)->dispatch([
        'message' => [
            'chat' => ['id' => -2001, 'type' => 'supergroup'],
            'from' => ['id' => 801],
            'text' => '/start_exam 1 1 5',
        ],
    ]);

    $event = peEvent(ProductEventName::ExamStarted);
    expect($event->user_id)->toBe($teacher->id)
        ->and($event->props)->toMatchArray(['stage' => 1, 'lesson' => 1, 'minutes' => 5])
        ->and($event->props['exam_session_id'])->toBe(ExamSession::query()->firstOrFail()->id);
});

it('still starts the exam when event recording is broken', function (): void {
    peTeacherInGroup(-2002, 802);
    peLesson(20);
    Schema::drop('product_events');

    app(TelegramDispatcher::class)->dispatch([
        'message' => [
            'chat' => ['id' => -2002, 'type' => 'supergroup'],
            'from' => ['id' => 802],
            'text' => '/start_exam 1 1 5',
        ],
    ]);

    expect(ExamSession::query()->where('status', 'open')->count())->toBe(1);
});

it('records exam.closed for /close_exam', function (): void {
    Bus::fake();
    ['group' => $group, 'teacher' => $teacher] = peTeacherInGroup(-2003, 803);
    $session = ExamSession::factory()->create([
        'telegram_group_id' => $group->id,
        'started_by_user_id' => $teacher->id,
    ]);

    app(TelegramDispatcher::class)->dispatch([
        'message' => [
            'chat' => ['id' => -2003, 'type' => 'supergroup'],
            'from' => ['id' => 803],
            'text' => '/close_exam',
        ],
    ]);

    $event = peEvent(ProductEventName::ExamClosed);
    expect(ksorted($event->props))->toBe(ksorted(['exam_session_id' => $session->id, 'source' => 'manual']))
        ->and($event->telegram_group_id)->toBe($group->id);
    Bus::assertDispatched(PostLeaderboardJob::class);
});

it('records exam.closed with source=scheduler when exams:close-expired closes an exam', function (): void {
    Bus::fake();
    $group = TelegramGroup::factory()->create();
    $expired = ExamSession::factory()->create([
        'telegram_group_id' => $group->id,
        'started_at' => now()->subMinutes(10),
        'ends_at' => now()->subMinutes(5),
    ]);

    $this->artisan('exams:close-expired', ['--grace' => 0])->assertSuccessful();

    $event = peEvent(ProductEventName::ExamClosed);
    expect(ksorted($event->props))->toBe(ksorted(['exam_session_id' => $expired->id, 'source' => 'scheduler']))
        ->and($event->telegram_group_id)->toBe($group->id)
        ->and(ProductEvent::query()->where('name', 'exam.closed')->count())->toBe(1);
});

it('does not record exam.closed for exams that are still running', function (): void {
    Bus::fake();
    ExamSession::factory()->create(['ends_at' => now()->addMinutes(5)]);

    $this->artisan('exams:close-expired', ['--grace' => 0])->assertSuccessful();

    expect(ProductEvent::query()->where('name', 'exam.closed')->count())->toBe(0);
});

it('records exam.leaderboard_posted once even if the job is delivered twice', function (): void {
    ['group' => $group] = peTeacherInGroup(-2004, 804);
    $session = ExamSession::factory()->closed()->create(['telegram_group_id' => $group->id]);

    $job = new PostLeaderboardJob($session->id);
    $job->handle($this->api, app(LeaderboardBuilder::class), app(ProductEventRecorder::class));
    $job->handle($this->api, app(LeaderboardBuilder::class), app(ProductEventRecorder::class));

    expect(ProductEvent::query()->where('name', 'exam.leaderboard_posted')->count())->toBe(1)
        ->and(peEvent(ProductEventName::ExamLeaderboardPosted)->props)->toBe(['exam_session_id' => $session->id]);
});

// --- group lifecycle ------------------------------------------------------

it('records group.bot_added when the bot joins a new group', function (): void {
    app(TelegramDispatcher::class)->dispatch([
        'my_chat_member' => [
            'chat' => ['id' => -3001, 'type' => 'supergroup', 'title' => 'Secret Class Title'],
            'old_chat_member' => ['status' => 'left'],
            'new_chat_member' => ['status' => 'member'],
        ],
    ]);

    $event = peEvent(ProductEventName::GroupBotAdded);
    expect($event->telegram_group_id)->toBe(TelegramGroup::query()->where('chat_id', -3001)->value('id'))
        ->and(ksorted($event->props))->toBe(ksorted(['chat_type' => 'supergroup', 'readded' => false]));
    expect(json_encode($event->getAttributes()))->not->toContain('Secret Class Title');
});

it('does not record group.bot_added for role changes in a known group', function (): void {
    TelegramGroup::factory()->create(['chat_id' => -3002, 'status' => 'active']);

    app(TelegramDispatcher::class)->dispatch([
        'my_chat_member' => [
            'chat' => ['id' => -3002, 'type' => 'supergroup', 'title' => 'X'],
            'old_chat_member' => ['status' => 'member'],
            'new_chat_member' => ['status' => 'administrator'],
        ],
    ]);

    expect(ProductEvent::query()->count())->toBe(0);
});

it('records group.bot_added with readded=true when the bot returns after leaving', function (): void {
    TelegramGroup::factory()->create(['chat_id' => -3003, 'status' => 'disabled']);

    app(TelegramDispatcher::class)->dispatch([
        'my_chat_member' => [
            'chat' => ['id' => -3003, 'type' => 'supergroup', 'title' => 'X'],
            'old_chat_member' => ['status' => 'kicked'],
            'new_chat_member' => ['status' => 'member'],
        ],
    ]);

    expect(peEvent(ProductEventName::GroupBotAdded)->props)->toMatchArray(['readded' => true]);
});

it('records group.member_joined only when a membership is created or re-activated', function (): void {
    $group = TelegramGroup::factory()->create(['chat_id' => -3004, 'status' => 'active']);
    $update = [
        'message' => [
            'chat' => ['id' => -3004, 'type' => 'supergroup'],
            'new_chat_members' => [
                ['id' => 111, 'first_name' => 'Alice', 'username' => 'alice_u', 'is_bot' => false],
                ['id' => 333, 'first_name' => 'Bot', 'is_bot' => true],
            ],
        ],
    ];

    app(TelegramDispatcher::class)->dispatch($update);
    app(TelegramDispatcher::class)->dispatch($update); // duplicate delivery: nothing changed

    $events = ProductEvent::query()->where('name', 'group.member_joined')->get();
    expect($events)->toHaveCount(1)
        ->and($events[0]->telegram_group_id)->toBe($group->id)
        ->and($events[0]->props)->toBe(['via' => 'group'])
        ->and($events[0]->learner_id)->toBe(Learner::query()->where('telegram_user_id', 111)->value('id'));
    expect(json_encode($events[0]->getAttributes()))->not->toContain('Alice')->and(json_encode($events[0]->getAttributes()))->not->toContain('alice_u');
});

it('records group.member_left for a chat_member leave and group.member_joined again on re-join', function (): void {
    $group = TelegramGroup::factory()->create(['chat_id' => -3005, 'status' => 'active']);
    $learner = Learner::factory()->memberOf($group)->create(['telegram_user_id' => 111]);
    $chatMember = fn (string $status): array => ['chat_member' => [
        'chat' => ['id' => -3005, 'type' => 'supergroup'],
        'new_chat_member' => ['status' => $status, 'user' => ['id' => 111, 'first_name' => 'Alice', 'is_bot' => false]],
    ]];

    app(TelegramDispatcher::class)->dispatch($chatMember('left'));
    app(TelegramDispatcher::class)->dispatch($chatMember('left')); // already left: no second event

    $left = ProductEvent::query()->where('name', 'group.member_left')->get();
    expect($left)->toHaveCount(1)
        ->and($left[0]->learner_id)->toBe($learner->id)
        ->and($left[0]->telegram_group_id)->toBe($group->id)
        ->and($left[0]->props)->toBe(['reason' => 'left']);

    app(TelegramDispatcher::class)->dispatch($chatMember('member'));

    expect(ProductEvent::query()->where('name', 'group.member_joined')->get()->pluck('props.via')->all())
        ->toBe(['chat_member']);
});

// --- reminders ------------------------------------------------------------

function peReminderLearner(): Learner
{
    $group = TelegramGroup::factory()->create(['status' => 'active']);

    return Learner::factory()->memberOf($group)->create(['telegram_user_id' => 900100]);
}

it('records reminder.sent after a successful reminder', function (): void {
    $learner = peReminderLearner();

    (new SendReviewReminderJob($learner->id, 7))->handle(
        $this->api,
        app(ReviewReminderLinkBuilder::class),
        app(ProductEventRecorder::class),
        app(LocaleResolver::class),
        app(GroupMembershipService::class),
    );

    $event = peEvent(ProductEventName::ReminderSent);
    expect($event->learner_id)->toBe($learner->id)
        ->and($event->props)->toBe(['due_count' => 7]);
});

it('records reminder.failed and does not fail the job on Telegram 403', function (): void {
    $learner = peReminderLearner();
    Log::spy();

    $telegram = Mockery::mock(TelegramClient::class);
    $telegram->shouldReceive('sendWebAppButton')
        ->once()
        ->andThrow(new TelegramForbiddenException(TelegramForbiddenException::REASON_BOT_BLOCKED));

    (new SendReviewReminderJob($learner->id, 7))->handle(
        $telegram,
        app(ReviewReminderLinkBuilder::class),
        app(ProductEventRecorder::class),
        app(LocaleResolver::class),
        app(GroupMembershipService::class),
    );

    $event = peEvent(ProductEventName::ReminderFailed);
    expect($event->learner_id)->toBe($learner->id)
        ->and(ksorted($event->props))->toBe(ksorted(['reason' => 'bot_blocked', 'due_count' => 7]));
    expect(ProductEvent::query()->where('name', 'reminder.sent')->exists())->toBeFalse();
    expect(NotificationLog::query()->where('learner_id', $learner->id)->exists())->toBeFalse();

    Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) use ($learner): bool {
        return $message === 'telegram.review_reminder_forbidden'
            && $context === ['learner_id' => $learner->id, 'reason' => 'bot_blocked'];
    })->once();
});

it('still rethrows other Telegram errors from the reminder job', function (): void {
    $learner = peReminderLearner();

    $telegram = Mockery::mock(TelegramClient::class);
    $telegram->shouldReceive('sendWebAppButton')->once()->andThrow(new RuntimeException('Telegram is down'));

    expect(fn () => (new SendReviewReminderJob($learner->id, 7))->handle(
        $telegram,
        app(ReviewReminderLinkBuilder::class),
        app(ProductEventRecorder::class),
        app(LocaleResolver::class),
        app(GroupMembershipService::class),
    ))->toThrow(RuntimeException::class, 'Telegram is down');

    expect(ProductEvent::query()->count())->toBe(0);
});

// --- Telegram client boundary ----------------------------------------------

it('translates a Telegram 403 from the SDK into TelegramForbiddenException', function (): void {
    $forbidden = fn (string $description): TelegramResponseException => (new TelegramResponse(
        new TelegramRequest('token', 'POST', 'sendMessage'),
        new PsrResponse(403, [], json_encode(['ok' => false, 'error_code' => 403, 'description' => $description], JSON_THROW_ON_ERROR)),
    ))->getThrownException();

    $makeClient = fn (TelegramResponseException $e): IrazasyedTelegramClient => new IrazasyedTelegramClient(
        new class($e) extends Api
        {
            public function __construct(private readonly Throwable $error)
            {
                parent::__construct('test-token');
            }

            /** @param array<string, mixed> $params */
            public function sendMessage(array $params): MessageObject
            {
                throw $this->error;
            }
        },
        new TelegramKeyboardFactory,
        new TelegramMessageRateLimiter,
    );

    $cases = [
        'Forbidden: bot was blocked by the user' => TelegramForbiddenException::REASON_BOT_BLOCKED,
        "Forbidden: bot can't initiate conversation with a user" => TelegramForbiddenException::REASON_NOT_STARTED,
        'Forbidden: user is deactivated' => TelegramForbiddenException::REASON_USER_DEACTIVATED,
        'Forbidden: something else' => TelegramForbiddenException::REASON_FORBIDDEN,
    ];

    foreach ($cases as $description => $reason) {
        try {
            $makeClient($forbidden($description))->sendImmediately(1, 'hi');
            $this->fail('Expected TelegramForbiddenException');
        } catch (TelegramForbiddenException $e) {
            expect($e->reason)->toBe($reason);
        }
    }
});
