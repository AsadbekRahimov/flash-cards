<?php

declare(strict_types=1);

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Learners\Services\ReminderQuizAnswerService;
use App\Domain\Learners\Services\ReminderQuizStore;
use App\Domain\Learners\Services\ReminderSender;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Models\Learner;
use App\Models\ProductEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Tests\Support\FakeTelegramClient;
use Tests\Support\ReminderQuizFixtures as Fx;

uses(RefreshDatabase::class);

/*
 * A tap on an option of the reminder question (docs/04 B.5a, docs/12 §7.5): the verdict, the idempotency, every way a
 * button can be stale or forged — and that nothing of learning is written, whatever is tapped.
 */

beforeEach(function (): void {
    config(['twa.base_url' => 'https://twa.test']);
    // 2026-10-18 14:05 UTC = 19:05 in Tashkent.
    $this->travelTo(CarbonImmutable::parse('2026-10-18 14:05:00', 'UTC'));

    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);
});

/**
 * A learner with three due words who got the reminder question; returns the learner and the stored state.
 *
 * @param  array<string, mixed>  $attributes
 * @return array{0: Learner, 1: array<string, mixed>}
 */
function reminderQuizAsked(array $attributes = []): array
{
    $learner = Fx::learner($attributes);
    foreach (array_slice(Fx::lesson(6), 0, 3) as $word) {
        Fx::due($learner, $word);
    }

    app(ReminderSender::class)->send($learner->id, CarbonImmutable::parse('2026-10-18 14:00:00', 'UTC'));
    $state = app(ReminderQuizStore::class)->get($learner->id);
    expect($state)->not->toBeNull();

    return [$learner, $state ?? []];
}

/**
 * @param  array<string, mixed>  $overrides  `from`, `message` and `data` replace the defaults
 * @return array<string, mixed>
 */
function reminderQuizTap(string $token, int $index, array $overrides = []): array
{
    return [
        'update_id' => random_int(1, 1_000_000),
        'callback_query' => [
            'id' => 'cb-'.random_int(1, 1_000_000),
            'from' => ['id' => 777001, 'is_bot' => false, 'first_name' => 'Ann'],
            'message' => ['message_id' => 501, 'date' => 1_760_000_000, 'chat' => ['id' => 777001, 'type' => 'private']],
            'data' => "rq1:{$token}:{$index}",
            ...$overrides,
        ],
    ];
}

/** @param array<string, mixed> $props jsonb does not keep the order of the keys */
function reminderQuizPropsSorted(array $props): array
{
    ksort($props);

    return $props;
}

function reminderQuizDispatch(array $update): void
{
    app(TelegramDispatcher::class)->dispatch($update);
}

function reminderQuizEvents(ProductEventName $name): int
{
    return ProductEvent::query()->where('name', $name->value)->count();
}

/** @param array<string, mixed> $state */
function reminderQuizWrongIndex(array $state): int
{
    return $state['correct'] === 0 ? 1 : 0;
}

it('turns the question into the verdict and one button to the app when the answer is right', function (): void {
    [$learner, $state] = reminderQuizAsked();

    reminderQuizDispatch(reminderQuizTap($state['token'], $state['correct']));

    $edit = $this->tg->lastTextEdit();
    expect($edit)->not->toBeNull()
        ->and($edit['chat_id'])->toBe(777001)
        ->and($edit['message_id'])->toBe(501)
        ->and($edit['parse_mode'])->toBeNull()
        ->and($edit['text'])->toBe("✅ Верно! {$state['word']} — {$state['answer']}.\n\nВ приложении ждут 3 слова — хватит 5 минут.\n\nИзменить время или отключить: /settings")
        ->and($edit['markup'])->toBe(['inline_keyboard' => [[['text' => 'Открыть Muhrla', 'web_app' => ['url' => 'https://twa.test/twa/?from=reminder_quiz']]]]])
        ->and($this->tg->answered)->toHaveCount(1)
        ->and($this->tg->answeredTexts)->toBe([]);

    $event = ProductEvent::query()->where('name', ProductEventName::ReminderQuizAnswered->value)->sole();
    expect($event->learner_id)->toBe($learner->id)
        ->and(reminderQuizPropsSorted($event->props))->toBe(reminderQuizPropsSorted([
            'kind' => 'review', 'word_id' => $state['word_id'], 'exercise' => 'meaning', 'correct' => true,
            'picked' => $state['correct'], 'latency_s' => 0, 'due_count' => 3,
        ]));
});

it('shows the right answer when the pick is wrong, in uz too', function (): void {
    [, $state] = reminderQuizAsked(['interface_locale' => 'uz']);
    $wrong = reminderQuizWrongIndex($state);

    reminderQuizDispatch(reminderQuizTap($state['token'], $wrong));

    expect($this->tg->lastTextEdit()['text'] ?? '')->toBe("❌ Xato. {$state['word']} — {$state['answer']}.\n\nIlovada sizni 3 ta so'z kutmoqda — 5 daqiqa yetarli.\n\nEslatma vaqtini o'zgartirish yoki eslatmalarni o'chirish uchun /settings buyrug'ini yuboring.")
        ->and($this->tg->lastTextEdit()['markup']['inline_keyboard'][0][0]['text'] ?? '')->toBe("Muhrla'ni ochish");

    $event = ProductEvent::query()->where('name', ProductEventName::ReminderQuizAnswered->value)->sole();
    expect($event->props['correct'])->toBeFalse()->and($event->props['picked'])->toBe($wrong);
});

it('counts the words that wait NOW in the verdict, and the latency of the tap', function (): void {
    [$learner, $state] = reminderQuizAsked();
    // Two of the three were repeated in the app meanwhile.
    $learner->repetitions()->orderBy('id')->limit(2)->update(['next_review_at' => now()->addDays(3)]);

    $this->travelTo(now()->addMinutes(7));
    reminderQuizDispatch(reminderQuizTap($state['token'], 0));

    expect($this->tg->lastTextEdit()['text'] ?? '')->toContain('В приложении ждут 1 слово — хватит 5 минут.');
    $props = ProductEvent::query()->where('name', ProductEventName::ReminderQuizAnswered->value)->sole()->props;
    expect($props['latency_s'])->toBe(420)->and($props['due_count'])->toBe(3);

    $learner->repetitions()->update(['next_review_at' => now()->addDays(3)]);
    $this->tg->textEdits = [];
    reminderQuizDispatch(reminderQuizTap($state['token'], 0));

    expect($this->tg->lastTextEdit()['text'] ?? '')->toContain('Продолжите в приложении — там новые слова.');
});

it('writes nothing of learning: no repetition, no session, no review, no daily stats, no streak', function (): void {
    [$learner, $state] = reminderQuizAsked();
    $before = Fx::learningWrites();
    $streak = $learner->fresh()?->only(['streak_current', 'streak_best', 'streak_last_date']);

    foreach ([$state['correct'], reminderQuizWrongIndex($state), $state['correct']] as $index) {
        reminderQuizDispatch(reminderQuizTap($state['token'], $index));
    }
    // And the taps that are refused.
    reminderQuizDispatch(reminderQuizTap('zzzzzzzzzz', 1));
    reminderQuizDispatch(reminderQuizTap($state['token'], 1, ['from' => ['id' => 999, 'first_name' => 'Eve'], 'message' => ['message_id' => 1, 'date' => 1, 'chat' => ['id' => 999, 'type' => 'private']]]));

    expect(Fx::learningWrites())->toBe($before)
        ->and($learner->fresh()?->only(['streak_current', 'streak_best', 'streak_last_date']))->toBe($streak);
});

it('answers a double tap with the same verdict: one event, the message edited again, a refused edit swallowed', function (): void {
    [, $state] = reminderQuizAsked();
    Log::spy();

    reminderQuizDispatch(reminderQuizTap($state['token'], $state['correct']));
    // The second tap picks another option: the first pick stands.
    reminderQuizDispatch(reminderQuizTap($state['token'], reminderQuizWrongIndex($state)));

    expect($this->tg->textEdits)->toHaveCount(2)
        ->and($this->tg->textEdits[1]['text'])->toBe($this->tg->textEdits[0]['text'])
        ->and(reminderQuizEvents(ProductEventName::ReminderQuizAnswered))->toBe(1);

    // Telegram says "message is not modified" to an identical edit: a warning, not an error.
    $this->tg->failTextEdits = true;
    reminderQuizDispatch(reminderQuizTap($state['token'], $state['correct']));

    expect($this->tg->textEdits)->toHaveCount(2)
        ->and(reminderQuizEvents(ProductEventName::ReminderQuizAnswered))->toBe(1)
        ->and($this->tg->answered)->toHaveCount(3);
    Log::shouldHaveReceived('warning')->with('telegram.reminder_quiz_edit_failed', Mockery::any())->once();
});

it('is idempotent for the same update delivered twice (a retried job), and heals a lost edit', function (): void {
    [, $state] = reminderQuizAsked();
    $update = reminderQuizTap($state['token'], $state['correct']);

    // The first delivery recorded the answer, but its edit was refused (an outage).
    $this->tg->failTextEdits = true;
    reminderQuizDispatch($update);
    expect($this->tg->textEdits)->toBe([])->and(reminderQuizEvents(ProductEventName::ReminderQuizAnswered))->toBe(1);

    // The retry meets the recorded answer, shows the verdict and records nothing again.
    $this->tg->failTextEdits = false;
    reminderQuizDispatch($update);

    expect($this->tg->textEdits)->toHaveCount(1)
        ->and($this->tg->textEdits[0]['text'])->toStartWith('✅ Верно!')
        ->and(reminderQuizEvents(ProductEventName::ReminderQuizAnswered))->toBe(1);
});

it('says "outdated" and keeps only the app button for a token that is not the state\'s', function (): void {
    [$learner, $state] = reminderQuizAsked();

    $update = reminderQuizTap('Zz9Zz9Zz9Z', 2);
    reminderQuizDispatch($update);

    $id = $update['callback_query']['id'];
    expect($this->tg->answeredTexts[$id])->toBe(['text' => 'Этот вопрос устарел. Откройте приложение — там свежие слова.', 'show_alert' => true])
        ->and($this->tg->textEdits)->toBe([])
        ->and($this->tg->edits)->toBe([['chat_id' => 777001, 'message_id' => 501, 'markup' => ['inline_keyboard' => [[['text' => 'Открыть Muhrla', 'web_app' => ['url' => 'https://twa.test/twa/?from=reminder']]]]]]])
        ->and(ProductEvent::query()->where('name', ProductEventName::ReminderQuizExpired->value)->sole()->props)->toBe(['reason' => 'token_mismatch'])
        // The real question is still there to be answered.
        ->and(app(ReminderQuizStore::class)->get($learner->id)['answered_at'])->toBeNull();

    reminderQuizDispatch(reminderQuizTap($state['token'], $state['correct']));
    expect($this->tg->lastTextEdit()['text'] ?? '')->toStartWith('✅ Верно!');
});

it('says "outdated" once the question has lived its 26 hours, or when no state exists', function (): void {
    [$learner, $state] = reminderQuizAsked();

    $this->travelTo(now()->addHours(27));
    $update = reminderQuizTap($state['token'], $state['correct']);
    reminderQuizDispatch($update);

    expect($this->tg->answeredTexts[$update['callback_query']['id']]['show_alert'])->toBeTrue()
        ->and($this->tg->textEdits)->toBe([])
        ->and($this->tg->edits)->toHaveCount(1)
        ->and(reminderQuizEvents(ProductEventName::ReminderQuizAnswered))->toBe(0);

    // A state that was never written (Redis restarted): the same, with another reason.
    Cache::forget(ReminderQuizStore::key($learner->id));
    reminderQuizDispatch(reminderQuizTap($state['token'], 0));

    expect($this->tg->edits)->toHaveCount(2)
        ->and(ProductEvent::query()->where('name', ProductEventName::ReminderQuizExpired->value)->orderBy('id')->pluck('props')->map(fn ($p) => $p['reason'])->all())->toBe(['missing', 'missing']);
});

it('does not let another person answer: a forwarded question finds no state of theirs and the owner\'s is untouched', function (): void {
    [$learner, $state] = reminderQuizAsked();
    $other = Fx::learner(['telegram_user_id' => 888002]);

    // The question forwarded to the other person's own chat: the tap is theirs (`from.id` = their chat), the token is the owner's.
    $update = reminderQuizTap($state['token'], $state['correct'], [
        'from' => ['id' => 888002, 'is_bot' => false, 'first_name' => 'Bob'],
        'message' => ['message_id' => 77, 'date' => 1_760_000_000, 'chat' => ['id' => 888002, 'type' => 'private']],
    ]);
    reminderQuizDispatch($update);

    expect($this->tg->answeredTexts[$update['callback_query']['id']]['show_alert'])->toBeTrue()
        ->and($this->tg->textEdits)->toBe([])
        ->and(app(ReminderQuizStore::class)->get($learner->id)['answered_at'])->toBeNull()
        ->and(app(ReminderQuizStore::class)->get($other->id))->toBeNull()
        ->and(ProductEvent::query()->where('name', ProductEventName::ReminderQuizExpired->value)->sole()->learner_id)->toBe($other->id)
        ->and(reminderQuizEvents(ProductEventName::ReminderQuizAnswered))->toBe(0);
});

it('only acknowledges a tap whose message is not in the sender\'s own private chat, or whose data is malformed', function (array $overrides): void {
    [, $state] = reminderQuizAsked();
    $update = reminderQuizTap($state['token'], $state['correct'], $overrides);

    reminderQuizDispatch($update);

    expect($this->tg->answered)->toBe([$update['callback_query']['id']])
        ->and($this->tg->answeredTexts)->toBe([])
        ->and($this->tg->textEdits)->toBe([])
        ->and($this->tg->edits)->toBe([])
        ->and(ProductEvent::query()->whereIn('name', [ProductEventName::ReminderQuizAnswered->value, ProductEventName::ReminderQuizExpired->value])->count())->toBe(0);
})->with([
    'chat is not the sender' => [['message' => ['message_id' => 501, 'date' => 1_760_000_000, 'chat' => ['id' => 555000, 'type' => 'private']]]],
    'group chat' => [['message' => ['message_id' => 501, 'date' => 1_760_000_000, 'chat' => ['id' => -100123, 'type' => 'supergroup']]]],
    'no message' => [['message' => null]],
    'index out of range' => [['data' => 'rq1:AbC123xyZ9:4']],
    'token too short' => [['data' => 'rq1:AbC123:1']],
    'token with a symbol' => [['data' => 'rq1:AbC123xyZ_:1']],
    'trailing text' => [['data' => "rq1:AbC123xyZ9:1\n"]],
    'extra part' => [['data' => 'rq1:AbC123xyZ9:1:2']],
    'no index' => [['data' => 'rq1:AbC123xyZ9']],
]);

it('answers a message that is no longer accessible with the alert only, and edits nothing', function (): void {
    [, $state] = reminderQuizAsked();
    // Bot API 7+: an InaccessibleMessage has the chat and the id, and `date` = 0.
    $update = reminderQuizTap($state['token'], $state['correct'], ['message' => ['message_id' => 501, 'date' => 0, 'chat' => ['id' => 777001, 'type' => 'private']]]);

    reminderQuizDispatch($update);

    expect($this->tg->answeredTexts[$update['callback_query']['id']]['show_alert'])->toBeTrue()
        ->and($this->tg->textEdits)->toBe([])
        ->and($this->tg->edits)->toBe([])
        ->and(ProductEvent::query()->where('name', ProductEventName::ReminderQuizExpired->value)->sole()->props)->toBe(['reason' => 'no_message'])
        ->and(app(ReminderQuizStore::class)->get(Learner::query()->sole()->id)['answered_at'])->toBeNull();
});

it('does nothing for a staff member', function (): void {
    [, $state] = reminderQuizAsked();
    User::factory()->create(['telegram_user_id' => 999111, 'telegram_verified_at' => now()]);
    $update = reminderQuizTap($state['token'], 1, [
        'from' => ['id' => 999111, 'is_bot' => false, 'first_name' => 'Teacher'],
        'message' => ['message_id' => 5, 'date' => 1_760_000_000, 'chat' => ['id' => 999111, 'type' => 'private']],
    ]);

    reminderQuizDispatch($update);

    expect($this->tg->answered)->toBe([$update['callback_query']['id']])
        ->and($this->tg->textEdits)->toBe([])
        ->and($this->tg->edits)->toBe([])
        ->and(Learner::query()->where('telegram_user_id', 999111)->exists())->toBeFalse()
        ->and(ProductEvent::query()->whereIn('name', [ProductEventName::ReminderQuizAnswered->value, ProductEventName::ReminderQuizExpired->value])->count())->toBe(0);
});

it('does nothing for a banned learner, but answers a learner whose reminders were switched off after the question', function (): void {
    [$learner, $state] = reminderQuizAsked();

    $learner->forceFill(['reminder_time' => null])->save();
    reminderQuizDispatch(reminderQuizTap($state['token'], $state['correct']));
    expect($this->tg->textEdits)->toHaveCount(1); // answering a question already sent is not a reminder

    $learner->forceFill(['blocked_at' => now()])->save();
    reminderQuizDispatch(reminderQuizTap($state['token'], $state['correct']));
    expect($this->tg->textEdits)->toHaveCount(1);
});

it('puts a tap behind the lock of the learner: a held lock times out without a second answer', function (): void {
    [$learner, $state] = reminderQuizAsked();
    $lock = Cache::lock("reminders:quiz:answer:{$learner->id}", 10);
    expect($lock->get())->toBeTrue();

    // The wait of `block()` runs on the fake clock, not for five real seconds.
    Sleep::fake();
    Sleep::whenFakingSleep(fn ($duration) => Carbon::setTestNow(Carbon::now()->add($duration)));

    expect(fn () => app(ReminderQuizAnswerService::class)->answer($learner, $state['token'], $state['correct']))->toThrow(LockTimeoutException::class);

    // Through the handler: the tap is acknowledged and nothing is recorded or edited.
    $update = reminderQuizTap($state['token'], $state['correct']);
    reminderQuizDispatch($update);
    expect($this->tg->answered)->toBe([$update['callback_query']['id']])
        ->and($this->tg->textEdits)->toBe([])
        ->and(app(ReminderQuizStore::class)->get($learner->id)['answered_at'])->toBeNull();

    $lock->release();
    reminderQuizDispatch(reminderQuizTap($state['token'], $state['correct']));
    expect($this->tg->textEdits)->toHaveCount(1)->and(reminderQuizEvents(ProductEventName::ReminderQuizAnswered))->toBe(1);
});

it('never reads the right option from the button: every index is a valid tap and only the stored one is right', function (): void {
    [, $state] = reminderQuizAsked();
    $keyboard = $this->tg->sent[0]['markup']['inline_keyboard'];

    // The four buttons differ in their last character only.
    $data = array_map(static fn (array $row): string => $row[0]['callback_data'], array_slice($keyboard, 0, 4));
    expect(array_map(static fn (string $d): string => substr($d, 0, -1), $data))->each->toBe("rq1:{$state['token']}:")
        ->and(array_map(static fn (string $d): string => substr($d, -1), $data))->toBe(['0', '1', '2', '3']);
});
