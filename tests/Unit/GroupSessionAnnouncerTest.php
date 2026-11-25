<?php

declare(strict_types=1);

use App\Domain\Telegram\Services\GroupBotRemoval;
use App\Domain\Telegram\Services\GroupSessionAnnouncer;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeTelegramClient;

it('sends the group session button immediately, bypassing the deferred rate-limit queue', function (): void {
    $telegram = new FakeTelegramClient;

    $sent = (new GroupSessionAnnouncer($telegram, app(GroupBotRemoval::class)))->announce(-100, 'Exam', 'Open', 'https://twa.test/twa/exam/7', 'failed', static function (): void {});

    expect($sent)->toBeTrue()
        ->and($telegram->immediateButtons)->toBe([-100]);
});

it('logs the internal telegram_group_id, never the raw chat id, when the button is refused or uncertain', function (string $kind, string $line): void {
    Log::spy();
    $telegram = new FakeTelegramClient;
    $telegram->failSends = 2;
    match ($kind) {
        '429' => $telegram->failSendsRetryAfter = 30,
        'transport' => $telegram->failSendsAsTransport = true,
        default => null,
    };

    (new GroupSessionAnnouncer($telegram, app(GroupBotRemoval::class)))
        ->announce(-1001234567, 'Exam', 'Open', 'https://twa.test/twa/exam/7', 'failed', static function (): void {}, telegramGroupId: 42);

    Log::shouldHaveReceived('warning')->with($line, Mockery::on(
        static fn (array $context): bool => ($context['telegram_group_id'] ?? null) === 42 && ! array_key_exists('chat_id', $context),
    ))->once();
})->with([
    '429' => ['429', 'telegram.group_session_announce_rate_limited'],
    'timeout' => ['transport', 'telegram.group_session_announce_uncertain'],
    '4xx' => ['4xx', 'telegram.group_session_announce_failed'],
]);
