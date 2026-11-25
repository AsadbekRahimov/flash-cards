<?php

declare(strict_types=1);

use App\Domain\Cup\Enums\CupMessageStatus;
use App\Domain\Cup\Enums\CupParticipantStatus;
use App\Domain\Cup\Enums\CupStatus;
use App\Domain\Telegram\Support\CupCallback;
use App\Models\Cup;
use App\Models\CupMessage;
use App\Models\CupParticipant;
use App\Models\TelegramGroup;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Cup\CupBotKit;
use Tests\Support\Cup\CupKit;

/*
 * docs/33 §3.2, §6.1, §6.2, §4.5 (K6): the bot of the friends' cup in a group - the buttons `cup:new` / `cup:pub:{id}` /
 * `cup:cancel:{id}`, the command `/cup`, the welcome with the button, leaving the chat and the bot coming back.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CupKit::open();
    CupKit::course();
    config(['telegram.bot_username' => 'muhrla_test_bot', 'twa.base_url' => 'https://twa.test', 'app.brand' => 'Muhrla']);
    Storage::fake('public');
    CarbonImmutable::setTestNow('2026-12-01 06:00:00');
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** @param array<string, mixed> $meta */
function cbGroup(array $meta = []): TelegramGroup
{
    $group = CupKit::group($meta);
    $group->update(['title' => 'English Friends']);

    return $group;
}

// --- cup:new -------------------------------------------------------------------------------------------------------------------

it('proposes a cup on «Создать кубок»: a draft and ONE photo with «Опубликовать» / «Настроить» / «Отменить»', function (): void {
    $group = cbGroup();
    $tap = CupBotKit::tap($group, 'cup:new', 777_000_333);

    CupBotKit::dispatch($tap);

    $cup = Cup::query()->sole();
    $photo = CupKit::telegram()->photoMessages[0];
    expect($cup->status)->toBe(CupStatus::Draft)
        ->and(CupBotKit::answerOf($tap))->toBeNull()
        ->and(CupKit::telegram()->photoMessages)->toHaveCount(1)
        ->and($photo['chat_id'])->toBe($group->chat_id)
        ->and($photo['caption'])->toContain('Кубок друзей Muhrla')->toContain('Опубликовать кубок может администратор чата')
        ->and(CupBotKit::buttons($photo['markup']))->toMatchArray([
            '✅ Опубликовать' => 'cup:pub:'.$cup->id,
            '✖️ Отменить' => 'cup:cancel:'.$cup->id,
        ])
        ->and(CupBotKit::buttons($photo['markup'])['⚙️ Настроить'])->toStartWith('https://t.me/muhrla_test_bot?startapp=')
        ->and($cup->fresh()->card_message_id)->toBe($photo['message_id'])
        ->and($cup->fresh()->card_chat_id)->toBe($group->chat_id)
        ->and($cup->fresh()->meta['card_kind'])->toBe('photo')
        ->and(CupBotKit::card($cup)->status)->toBe(CupMessageStatus::Sent);
});

it('answers a second «Создать кубок» with a toast and posts nothing new', function (): void {
    $group = CupKit::group();
    CupBotKit::propose($group);
    $again = CupBotKit::tap($group, 'cup:new', 777_000_444);

    CupBotKit::dispatch($again);

    expect(Cup::query()->count())->toBe(1)
        ->and(CupKit::telegram()->photoMessages)->toHaveCount(1)
        ->and(CupBotKit::answerOf($again))->toBe(['text' => 'В этом чате уже есть кубок — смотрите его карточку.', 'show_alert' => false]);
});

it('posts the proposal into the topic of a forum as text (a photo of the client has no topic)', function (): void {
    $group = CupKit::group();

    CupBotKit::dispatch(CupBotKit::tap($group, 'cup:new', 777_000_333, 1, ['is_topic_message' => true, 'message_thread_id' => 77]));

    $cup = Cup::query()->sole();
    expect(CupKit::telegram()->photoMessages)->toBe([])
        ->and(CupKit::telegram()->groupMessages)->toHaveCount(1)
        ->and(CupKit::telegram()->groupMessages[0]['thread_id'])->toBe(77)
        ->and(CupKit::telegram()->groupMessages[0]['parse_mode'])->toBeNull()
        ->and($cup->card_thread_id)->toBe(77)
        ->and($cup->meta['card_kind'])->toBe('text');
});

it('only acknowledges the button while the cup is switched off - no draft, no text, nothing in the chat', function (string $access): void {
    config(['cup.access' => $access]);
    $group = CupKit::group();
    $tap = CupBotKit::tap($group, 'cup:new', 777_000_333);

    CupBotKit::dispatch($tap);

    expect(Cup::query()->count())->toBe(0)
        ->and(CupBotKit::answerOf($tap))->toBeNull()
        ->and(CupKit::telegram()->photoMessages)->toBe([])
        ->and(CupKit::telegram()->groupMessages)->toBe([]);
})->with(['off', 'pilot']);

// --- cup:pub ---------------------------------------------------------------------------------------------------------------------

it('answers a member who is not an administrator with a short toast and posts nothing to the chat', function (): void {
    $group = CupKit::group();
    $cup = CupBotKit::propose($group);
    $member = CupBotKit::plainMember();
    $tap = CupBotKit::tap($group, CupCallback::publish($cup->id), $member, (int) $cup->fresh()->card_message_id);

    CupBotKit::dispatch($tap);

    expect(CupBotKit::answerOf($tap))->toBe(['text' => 'Это может сделать только администратор чата.', 'show_alert' => false])
        ->and($cup->fresh()->status)->toBe(CupStatus::Draft)
        ->and(CupKit::telegram()->photoMessages)->toHaveCount(1)
        ->and(CupKit::telegram()->groupMessages)->toBe([])
        ->and(CupKit::telegram()->captionEdits)->toBe([]);
});

it('turns the proposal into the card on «Опубликовать» of an administrator: the same message, edited in place', function (): void {
    $group = cbGroup();
    $cup = CupBotKit::propose($group);
    $admin = CupKit::admin();
    $messageId = (int) $cup->fresh()->card_message_id;
    $tap = CupBotKit::tap($group, CupCallback::publish($cup->id), $admin, $messageId, [], 'uz');

    CupBotKit::dispatch($tap);

    $cup->refresh();
    $edit = CupKit::telegram()->captionEdits[0];
    expect($cup->status)->toBe(CupStatus::Scheduled)
        ->and(CupBotKit::answerOf($tap))->toBe(['text' => "Kubok e'lon qilindi!", 'show_alert' => false])
        ->and(CupKit::telegram()->photoMessages)->toHaveCount(1)
        ->and($edit['message_id'])->toBe($messageId)
        ->and($edit['caption'])->toContain('«English Friends» · A2')->toContain('Старт 02.12 в 00:00 · финиш 09.12, 00:00')
        ->and(array_keys(CupBotKit::buttons($edit['markup'])))->toBe(['🏆 Участвовать', '📊 Таблица', '📖 Правила'])
        ->and($cup->card_text_hash)->toHaveLength(40);
});

it('gives the same toast to every stale or strange tap: another message, another chat, a closed cup, a malformed payload', function (Closure $make): void {
    $group = CupKit::group();
    $cup = CupBotKit::propose($group);
    $admin = CupKit::admin();
    $tap = $make($group, $cup->fresh(), $admin);

    CupBotKit::dispatch($tap);

    expect(CupBotKit::answerOf($tap))->toBe(['text' => 'Эта кнопка больше не действует.', 'show_alert' => false])
        ->and(Cup::query()->whereKey($cup->id)->value('status'))->toBe(CupStatus::Draft)
        ->and(CupKit::telegram()->captionEdits)->toBe([]);
})->with([
    'an old message' => fn (TelegramGroup $g, Cup $c, int $a): array => CupBotKit::tap($g, CupCallback::publish($c->id), $a, (int) $c->card_message_id + 5),
    'another chat' => fn (TelegramGroup $g, Cup $c, int $a): array => CupBotKit::tap(CupKit::group(), CupCallback::publish($c->id), $a, (int) $c->card_message_id),
    'an unknown cup' => fn (TelegramGroup $g, Cup $c, int $a): array => CupBotKit::tap($g, CupCallback::publish($c->id + 100), $a, (int) $c->card_message_id),
    'a leading zero' => fn (TelegramGroup $g, Cup $c, int $a): array => CupBotKit::tap($g, 'cup:pub:0'.$c->id, $a, (int) $c->card_message_id),
    'a trailing newline' => fn (TelegramGroup $g, Cup $c, int $a): array => CupBotKit::tap($g, 'cup:pub:'.$c->id."\n", $a, (int) $c->card_message_id),
    'an unknown action' => fn (TelegramGroup $g, Cup $c, int $a): array => CupBotKit::tap($g, 'cup:start:'.$c->id, $a, (int) $c->card_message_id),
    'a private chat' => fn (TelegramGroup $g, Cup $c, int $a): array => CupBotKit::tap($g, CupCallback::publish($c->id), $a, (int) $c->card_message_id, ['chat' => ['id' => $a, 'type' => 'private']]),
    'an inaccessible message' => fn (TelegramGroup $g, Cup $c, int $a): array => CupBotKit::tap($g, CupCallback::publish($c->id), $a, (int) $c->card_message_id, ['date' => 0]),
]);

it('refuses to publish while the bot is not an administrator of the chat (owner decision К1)', function (): void {
    $group = CupKit::group();
    $cup = CupBotKit::propose($group);
    CupKit::telegram()->chatMemberStatus = 'member';
    $tap = CupBotKit::tap($group, CupCallback::publish($cup->id), CupKit::admin(), (int) $cup->fresh()->card_message_id);

    CupBotKit::dispatch($tap);

    expect(CupBotKit::answerOf($tap))->toBe(['text' => 'Сделайте бота администратором чата. Особые права не нужны.', 'show_alert' => false])
        ->and($cup->fresh()->status)->toBe(CupStatus::Draft);
});

// --- cup:cancel ------------------------------------------------------------------------------------------------------------------

it('cancels on «Отменить» of an administrator: «Кубок отменён» without buttons; a member gets the toast', function (): void {
    $group = CupKit::group();
    $cup = CupBotKit::propose($group);
    $messageId = (int) $cup->fresh()->card_message_id;
    $memberTap = CupBotKit::tap($group, CupCallback::cancel($cup->id), CupBotKit::plainMember(), $messageId);
    CupBotKit::dispatch($memberTap);
    expect($cup->fresh()->status)->toBe(CupStatus::Draft)
        ->and(CupBotKit::answerOf($memberTap)['text'])->toBe('Это может сделать только администратор чата.');

    $adminTap = CupBotKit::tap($group, CupCallback::cancel($cup->id), CupKit::admin(), $messageId);
    CupBotKit::dispatch($adminTap);

    $edit = CupKit::telegram()->captionEdits[0];
    expect($cup->fresh()->status)->toBe(CupStatus::Canceled)
        ->and(CupBotKit::answerOf($adminTap)['text'])->toBe('Кубок отменён.')
        ->and($edit['caption'])->toContain('Кубок отменён')
        ->and($edit['markup'])->toBeNull();

    // the button pressed again: the cup is closed - the stale toast, nothing edited
    $again = CupBotKit::tap($group, CupCallback::cancel($cup->id), CupKit::admin(), $messageId);
    CupBotKit::dispatch($again);
    expect(CupBotKit::answerOf($again)['text'])->toBe('Эта кнопка больше не действует.')
        ->and(CupKit::telegram()->captionEdits)->toHaveCount(1);
});

// --- /cup ------------------------------------------------------------------------------------------------------------------------

it('proposes a cup on /cup and on /start cup, then answers /cup with one line and a button - at most once in 10 minutes', function (): void {
    $group = cbGroup();

    CupBotKit::dispatch(CupBotKit::say($group, '/start@muhrla_test_bot cup', 777_000_333));
    expect(Cup::query()->count())->toBe(1)->and(CupKit::telegram()->photoMessages)->toHaveCount(1);

    CupBotKit::dispatch(CupBotKit::say($group, '/cup', 777_000_444));
    expect(CupKit::telegram()->groupMessages)->toBe([]); // the cooldown of the chat: silence

    RateLimiter::clear('cup-chat:'.$group->chat_id);
    CupBotKit::dispatch(CupBotKit::say($group, '/cup@muhrla_test_bot', 777_000_444));

    $reply = CupKit::telegram()->groupMessages[0];
    expect(Cup::query()->count())->toBe(1)
        ->and($reply['text'])->toBe('В чате уже есть кубок «English Friends». Таблица и участие — по кнопке ниже.')
        ->and(array_keys(CupBotKit::buttons($reply['markup'])))->toBe(['🏆 Открыть кубок']);
});

it('treats /cup as an unknown command while the cup is switched off', function (): void {
    config(['cup.access' => 'off']);
    $group = CupKit::group();

    CupBotKit::dispatch(CupBotKit::say($group, '/cup', 777_000_333));

    expect(Cup::query()->count())->toBe(0)
        ->and(CupKit::telegram()->sent)->toBe([])
        ->and(CupKit::telegram()->groupMessages)->toBe([])
        ->and(CupKit::telegram()->photoMessages)->toBe([]);
});

it('writes the messages of the cup in its language: an Uzbek group gets an Uzbek proposal', function (): void {
    $group = cbGroup(['locale' => 'uz']);

    CupBotKit::propose($group);

    expect(CupKit::telegram()->photoMessages[0]['caption'])->toContain("Muhrla do'stlar kubogi")
        ->and(array_keys(CupBotKit::buttons(CupKit::telegram()->photoMessages[0]['markup'])))->toBe(["✅ E'lon qilish", '⚙️ Sozlash', '✖️ Bekor qilish']);
});

// --- the welcome, leaving, the bot coming back -----------------------------------------------------------------------------------

it('offers the cup in the welcome of a new group only while the cup is open to it', function (string $access, bool $button): void {
    config(['cup.access' => $access]);

    CupBotKit::dispatch(['my_chat_member' => [
        'chat' => ['id' => -1_001_234_567, 'type' => 'supergroup', 'title' => 'Friends'],
        'from' => ['id' => 42, 'is_bot' => false, 'first_name' => 'Owner'],
        'date' => 1_764_000_000,
        'old_chat_member' => ['status' => 'left', 'user' => ['id' => 5550001, 'is_bot' => true]],
        'new_chat_member' => ['status' => 'administrator', 'user' => ['id' => 5550001, 'is_bot' => true]],
    ]]);

    $welcome = CupKit::telegram()->sent[0];
    expect($welcome['chat_id'])->toBe(-1_001_234_567)
        ->and(CupBotKit::buttons($welcome['markup']))->toBe($button ? ['🏆 Создать кубок друзей' => 'cup:new'] : [])
        ->and(str_contains($welcome['text'], 'кубок друзей'))->toBe($button);
})->with([['all', true], ['off', false]]);

it('marks a participant `left` when Telegram says he left the chat; the score stays', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b]] = CupKit::running(2);
    CupParticipant::query()->where('learner_id', $a->id)->update(['best_total' => 12]);

    CupBotKit::dispatch(['message' => [
        'message_id' => 5,
        'date' => CarbonImmutable::now()->getTimestamp(),
        'chat' => ['id' => $cup->telegramGroup->chat_id, 'type' => 'supergroup'],
        'from' => ['id' => $a->telegram_user_id, 'is_bot' => false, 'first_name' => 'A'],
        'left_chat_member' => ['id' => $a->telegram_user_id, 'is_bot' => false, 'first_name' => 'A'],
    ]]);

    $left = CupParticipant::query()->where('learner_id', $a->id)->sole();
    expect($left->status)->toBe(CupParticipantStatus::Left)
        ->and($left->best_total)->toBe(12)
        ->and(CupParticipant::query()->where('learner_id', $b->id)->value('status'))->toBe(CupParticipantStatus::Active);
});

it('marks a participant `left` on chat_member left / kicked as well', function (string $status): void {
    ['cup' => $cup, 'learners' => [$a]] = CupKit::running(2);

    CupBotKit::dispatch(['chat_member' => [
        'chat' => ['id' => $cup->telegramGroup->chat_id, 'type' => 'supergroup'],
        'from' => ['id' => 1, 'is_bot' => false, 'first_name' => 'Admin'],
        'date' => CarbonImmutable::now()->getTimestamp(),
        'old_chat_member' => ['status' => 'member', 'user' => ['id' => $a->telegram_user_id, 'is_bot' => false, 'first_name' => 'A']],
        'new_chat_member' => ['status' => $status, 'user' => ['id' => $a->telegram_user_id, 'is_bot' => false, 'first_name' => 'A']],
    ]]);

    expect(CupParticipant::query()->where('learner_id', $a->id)->value('status'))->toBe(CupParticipantStatus::Left);
})->with(['left', 'kicked']);

it('posts the card anew when the bot comes back to the chat: no second cup, the same dates', function (): void {
    ['cup' => $cup] = CupKit::running(2);
    $group = $cup->telegramGroup;
    CupMessage::query()->create(['cup_id' => $cup->id, 'dedupe_key' => 'card', 'kind' => 'card', 'status' => 'sent', 'message_id' => 10, 'chat_id' => $group->chat_id, 'attempts' => 1]);
    Cup::query()->whereKey($cup->id)->update(['card_chat_id' => $group->chat_id, 'card_message_id' => 10, 'card_rendered_at' => now()]);
    $memberUpdate = fn (string $old, string $new, int $date): array => ['my_chat_member' => [
        'chat' => ['id' => $group->chat_id, 'type' => 'supergroup', 'title' => $group->title],
        'from' => ['id' => 42, 'is_bot' => false, 'first_name' => 'Owner'],
        'date' => $date,
        'old_chat_member' => ['status' => $old, 'user' => ['id' => 5550001, 'is_bot' => true]],
        'new_chat_member' => ['status' => $new, 'user' => ['id' => 5550001, 'is_bot' => true]],
    ]];
    $startsAt = $cup->starts_at?->toDateTimeString();

    CupBotKit::dispatch($memberUpdate('administrator', 'kicked', CarbonImmutable::now()->getTimestamp()));
    expect(CupKit::telegram()->photoMessages)->toBe([]);

    CupBotKit::dispatch($memberUpdate('kicked', 'administrator', CarbonImmutable::now()->addMinute()->getTimestamp()));

    $cup->refresh();
    expect(Cup::query()->count())->toBe(1)
        ->and($cup->status)->toBe(CupStatus::Running)
        ->and($cup->starts_at?->toDateTimeString())->toBe($startsAt)
        ->and(CupKit::telegram()->photoMessages)->toHaveCount(1)
        ->and($cup->card_message_id)->toBe(CupKit::telegram()->photoMessages[0]['message_id'])
        ->and(CupBotKit::card($cup)->status)->toBe(CupMessageStatus::Sent);
});

it('names the product in the messages of the cup as config does (docs/31 §5г)', function (): void {
    config(['app.brand' => 'Brandy']);
    $group = cbGroup();

    CupBotKit::propose($group);

    $caption = CupKit::telegram()->photoMessages[0]['caption'];
    expect($caption)->toContain('Кубок друзей Brandy')
        ->and(preg_match('/lexiflow|muhrla/i', $caption))->toBe(0);
    foreach (['ru', 'uz'] as $locale) {
        foreach (Arr::dot(trans('cup', [], $locale)) as $key => $text) {
            expect(preg_match('/lexiflow|muhrla/i', (string) $text))->toBe(0, "cup.{$key} ({$locale}) names the product itself: use :brand");
        }
    }
});
