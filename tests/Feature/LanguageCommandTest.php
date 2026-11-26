<?php

declare(strict_types=1);

use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Models\Learner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeTelegramClient;

/*
 * `/language`: the language step of `/settings` at once, under a title in both languages; the buttons are `/settings`' own,
 * so the choice is saved by the same path. Before the onboarding it behaves as `/settings` does: back to the onboarding.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);
});

function langSend(int $tgId, string $text = '/language', string $chatType = 'private'): void
{
    app(TelegramDispatcher::class)->dispatch(['message' => [
        'chat' => ['id' => $chatType === 'private' ? $tgId : -1000, 'type' => $chatType],
        'from' => ['id' => $tgId, 'first_name' => 'Ali', 'language_code' => 'ru'],
        'text' => $text,
    ]]);
}

function langTap(int $tgId, string $data): void
{
    app(TelegramDispatcher::class)->dispatch(['callback_query' => [
        'id' => 'cb-'.md5($data),
        'from' => ['id' => $tgId, 'first_name' => 'Ali', 'language_code' => 'ru'],
        'message' => ['message_id' => 100, 'chat' => ['id' => $tgId, 'type' => 'private']],
        'data' => $data,
    ]]);
}

it('shows the language buttons at once under a bilingual title', function (string $current): void {
    Learner::factory()->create(['telegram_user_id' => 950_001, 'onboarding_state' => 'done', 'interface_locale' => $current]);

    langSend(950_001);

    $buttons = $this->tg->sent[0]['markup']['inline_keyboard'];
    expect($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['text'])->toBe('Tilni tanlang · Выберите язык')
        ->and(array_merge(...$buttons))->toHaveCount(2)
        ->and($this->tg->lastCallbackData())->toBe(['set1:locale:ru', 'set1:locale:uz']);
})->with(['ru', 'uz']);

it('changes the language by the button, by the path of /settings, and answers in the new one', function (): void {
    $learner = Learner::factory()->create(['telegram_user_id' => 950_002, 'onboarding_state' => 'done', 'interface_locale' => 'ru']);

    langSend(950_002);
    langTap(950_002, 'set1:locale:uz');

    expect($learner->fresh()?->interface_locale)->toBe('uz')
        ->and(end($this->tg->sent)['text'])->toBe('Saqlandi.');
});

it('answers /language@BotName too, and not in a group', function (): void {
    Learner::factory()->create(['telegram_user_id' => 950_003, 'onboarding_state' => 'done']);

    langSend(950_003, '/language@LexiFlowBot');
    langSend(950_003, '/language', 'group');

    expect($this->tg->sent)->toHaveCount(1);
});

it('takes a learner who has not finished the onboarding back to it, as /settings does', function (): void {
    Learner::factory()->create(['telegram_user_id' => 950_004, 'onboarding_state' => 'level', 'interface_locale' => 'ru']);

    langSend(950_004);

    expect($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['text'])->toContain('Шаг 3 из 4')
        ->and($this->tg->sent[0]['text'])->not->toContain('Tilni tanlang');
});

it('points a teacher to their own commands and tells a blocked learner so', function (): void {
    User::factory()->create(['telegram_user_id' => 950_005]);
    langSend(950_005);

    expect($this->tg->sent[0]['text'])->toBe('Эта команда — для учеников. Ваши команды учителя: /help');

    Learner::factory()->create(['telegram_user_id' => 950_006, 'blocked_at' => now()]);
    langSend(950_006);

    expect($this->tg->sent[1]['text'])->toContain('ограничен');
});
