<?php

declare(strict_types=1);

use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Services\BotMenuBuilder;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Models\AuditLog;
use App\Models\Learner;
use App\Models\TelegramGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeTelegramClient;

/*
 * `/delete_account` and the button of `/settings`: the confirmation with two buttons, the deletion only on "yes",
 * private chat only, no revival by asking, a blocked learner cannot shed the ban this way.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);
});

/** @param array<string, mixed> $chat */
function delSend(int $tgId, string $text, array $chat = [], string $lang = 'ru'): void
{
    app(TelegramDispatcher::class)->dispatch(['message' => [
        'chat' => ['id' => $tgId, 'type' => 'private', ...$chat],
        'from' => ['id' => $tgId, 'first_name' => 'Ali', 'language_code' => $lang],
        'text' => $text,
    ]]);
}

function delTap(int $tgId, string $data, int $messageId = 55, string $chatType = 'private'): void
{
    app(TelegramDispatcher::class)->dispatch(['callback_query' => [
        'id' => 'cb1',
        'from' => ['id' => $tgId, 'first_name' => 'Ali'],
        'message' => ['message_id' => $messageId, 'chat' => ['id' => $tgId, 'type' => $chatType]],
        'data' => $data,
    ]]);
}

it('asks for a confirmation with two buttons and deletes nothing yet', function (): void {
    $learner = Learner::factory()->create(['telegram_user_id' => 880_201, 'interface_locale' => 'ru']);

    delSend(880_201, '/delete_account');

    $sent = $this->tg->sent[0];
    expect($sent['text'])->toContain('Удалить аккаунт')->toContain('/start')->toContain('30')
        ->and($sent['markup']['inline_keyboard'][0][0])->toBe(['text' => 'Да, удалить', 'callback_data' => 'del1:yes'])
        ->and($sent['markup']['inline_keyboard'][0][1])->toBe(['text' => 'Отмена', 'callback_data' => 'del1:no'])
        ->and(Learner::query()->find($learner->id))->not->toBeNull();
});

it('speaks uz to a learner who chose it', function (): void {
    Learner::factory()->create(['telegram_user_id' => 880_202, 'interface_locale' => 'uz']);

    delSend(880_202, '/delete_account', lang: 'uz');

    expect($this->tg->sent[0]['text'])->toContain("o'chirasizmi")
        ->and($this->tg->sent[0]['markup']['inline_keyboard'][0][0]['text'])->toBe("Ha, o'chirish");
});

it('deletes the account on "yes", removes the buttons and tells how to come back', function (): void {
    $learner = Learner::factory()->create(['telegram_user_id' => 880_203, 'interface_locale' => 'ru']);

    delTap(880_203, 'del1:yes');

    expect(Learner::query()->find($learner->id))->toBeNull()
        ->and(AuditLog::query()->where('action', 'learner.deleted')->where('entity_id', $learner->id)->count())->toBe(1)
        ->and($this->tg->edits)->toBe([['chat_id' => 880_203, 'message_id' => 55, 'markup' => null]])
        ->and($this->tg->sent[0]['text'])->toContain('Аккаунт удалён')->toContain('/start')
        ->and($this->tg->answered)->toBe(['cb1']);
});

it('keeps the account on "no"', function (): void {
    $learner = Learner::factory()->create(['telegram_user_id' => 880_204, 'interface_locale' => 'ru']);

    delTap(880_204, 'del1:no');

    expect(Learner::query()->find($learner->id))->not->toBeNull()
        ->and($this->tg->sent[0]['text'])->toContain('не удалён')
        ->and($this->tg->edits)->toHaveCount(1);
});

it('does nothing on a second tap on "yes" and does not revive the account', function (): void {
    $learner = Learner::factory()->create(['telegram_user_id' => 880_205]);

    delTap(880_205, 'del1:yes');
    $this->tg->sent = [];
    delTap(880_205, 'del1:yes');

    expect(Learner::query()->find($learner->id))->toBeNull()
        ->and($this->tg->sent)->toBe([])
        ->and(AuditLog::query()->where('action', 'learner.deleted')->count())->toBe(1);
});

it('does not revive a deleted account by /delete_account, and points to /start', function (): void {
    $learner = Learner::factory()->create(['telegram_user_id' => 880_206]);
    delTap(880_206, 'del1:yes');
    $this->tg->sent = [];

    delSend(880_206, '/delete_account');

    expect(Learner::query()->find($learner->id))->toBeNull()
        ->and($this->tg->sent[0]['text'])->toContain('/start')
        ->and($this->tg->sent[0]['markup'])->toBeNull();
});

it('works only in the private chat: a group gets a hint and no confirmation', function (): void {
    TelegramGroup::factory()->create(['chat_id' => -880_300]);
    $learner = Learner::factory()->create(['telegram_user_id' => 880_207]);

    delSend(880_207, '/delete_account', ['id' => -880_300, 'type' => 'supergroup']);

    expect($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['markup'])->toBeNull()
        ->and($this->tg->sent[0]['text'])->toContain('личном чате');

    // A button tapped in a group message is ignored.
    $this->tg->sent = [];
    delTap(880_207, 'del1:yes', chatType: 'supergroup');
    expect(Learner::query()->find($learner->id))->not->toBeNull()
        ->and($this->tg->sent)->toBe([]);
});

it('stays silent in a group that is not active (Group Lock)', function (): void {
    Learner::factory()->create(['telegram_user_id' => 880_208]);

    delSend(880_208, '/delete_account', ['id' => -880_301, 'type' => 'supergroup']);

    expect($this->tg->sent)->toBe([]);
});

it('does not let a blocked learner shed the ban by deleting the account', function (): void {
    $learner = Learner::factory()->create(['telegram_user_id' => 880_209, 'blocked_at' => now()]);

    delSend(880_209, '/delete_account');
    delTap(880_209, 'del1:yes');

    expect(Learner::query()->find($learner->id))->not->toBeNull()
        ->and($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['markup'])->toBeNull();
});

it('offers "Delete account" in /settings and in /help, and in the command menu', function (): void {
    Learner::factory()->create(['telegram_user_id' => 880_210, 'onboarding_state' => 'done', 'interface_locale' => 'ru']);

    delSend(880_210, '/settings');
    $buttons = collect($this->tg->sent[0]['markup']['inline_keyboard'])->flatten(1)->all();
    expect(end($buttons))->toBe(['text' => 'Удалить аккаунт', 'callback_data' => 'del1:ask']);

    // The button asks for the confirmation, it does not delete.
    $this->tg->sent = [];
    delTap(880_210, 'del1:ask');
    expect($this->tg->sent[0]['markup']['inline_keyboard'][0][0]['callback_data'])->toBe('del1:yes');

    $this->tg->sent = [];
    delSend(880_210, '/help');
    expect($this->tg->sent[0]['text'])->toContain('/delete_account');

    expect(BotMenuBuilder::SCOPES['all_private_chats'][1])->toContain('delete_account');
});
