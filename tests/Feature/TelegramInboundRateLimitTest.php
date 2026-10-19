<?php

declare(strict_types=1);

use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Models\Learner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeTelegramClient;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['twa.base_url' => 'https://twa.test']);

    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);
});

function rlMessage(int $tgId, string $text = 'hello'): void
{
    app(TelegramDispatcher::class)->dispatch(['message' => [
        'chat' => ['id' => $tgId, 'type' => 'private'],
        'from' => ['id' => $tgId, 'first_name' => 'Ali', 'language_code' => 'ru'],
        'text' => $text,
    ]]);
}

function rlTap(int $tgId, string $data, string $id): void
{
    app(TelegramDispatcher::class)->dispatch(['callback_query' => [
        'id' => $id,
        'from' => ['id' => $tgId, 'first_name' => 'Ali', 'language_code' => 'ru'],
        'message' => ['message_id' => 1, 'chat' => ['id' => $tgId, 'type' => 'private']],
        'data' => $data,
    ]]);
}

it('drops the 31st incoming message of a minute without a reply, a write or a last_seen_at bump', function (): void {
    $learner = Learner::factory()->onboarded()->create(['telegram_user_id' => 9101, 'last_seen_at' => now()->subDay()]);

    for ($i = 0; $i < 30; $i++) {
        rlMessage(9101);
    }
    expect($this->tg->sent)->toHaveCount(30);

    $learner->forceFill(['last_seen_at' => now()->subDay()])->save();
    $seenBefore = $learner->fresh()->last_seen_at;

    rlMessage(9101);
    rlMessage(9101, '/start');

    expect($this->tg->sent)->toHaveCount(30)
        ->and($learner->fresh()->last_seen_at->equalTo($seenBefore))->toBeTrue();
});

it('counts every sender separately and lets the sender back in when the minute is over', function (): void {
    for ($i = 0; $i < 31; $i++) {
        rlMessage(9102);
    }
    expect($this->tg->sent)->toHaveCount(30);

    rlMessage(9103);
    expect($this->tg->sent)->toHaveCount(31)
        ->and(Learner::query()->where('telegram_user_id', 9103)->exists())->toBeTrue();

    $this->travel(61)->seconds();
    rlMessage(9102);

    expect($this->tg->sent)->toHaveCount(32);
});

it('answers a callback over the limit with an empty ack only', function (): void {
    $learner = Learner::factory()->create(['telegram_user_id' => 9104, 'onboarding_state' => 'locale']);

    for ($i = 0; $i < 30; $i++) {
        rlMessage(9104);
    }
    $sentBefore = count($this->tg->sent);

    rlTap(9104, 'onb1:locale:uz', 'cb-over');

    expect($this->tg->answered)->toBe(['cb-over'])
        ->and($this->tg->sent)->toHaveCount($sentBefore)
        ->and($this->tg->edits)->toBe([])
        ->and($learner->fresh()->onboarding_state)->toBe('locale');
});

it('does not fail on an over-limit callback when the ack itself is refused', function (): void {
    for ($i = 0; $i < 30; $i++) {
        rlMessage(9105);
    }
    $this->tg->failAnswers = true;

    rlTap(9105, 'onb1:locale:uz', 'cb-late');

    expect($this->tg->answered)->toBe([]);
});

it('leaves updates without a sender as they are', function (): void {
    for ($i = 0; $i < 35; $i++) {
        app(TelegramDispatcher::class)->dispatch(['message' => [
            'chat' => ['id' => 9106, 'type' => 'private'],
            'text' => '/help',
        ]]);
    }

    expect($this->tg->sent)->toHaveCount(35);
});

it('takes the limit from the config', function (): void {
    config(['telegram.inbound_rate_limit.max_messages' => 2]);

    rlMessage(9107);
    rlMessage(9107);
    rlMessage(9107);

    expect($this->tg->sent)->toHaveCount(2);
});
