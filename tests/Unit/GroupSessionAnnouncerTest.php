<?php

declare(strict_types=1);

use App\Domain\Telegram\Services\GroupSessionAnnouncer;
use Tests\Support\FakeTelegramClient;

it('sends the group session button immediately, bypassing the deferred rate-limit queue', function (): void {
    $telegram = new FakeTelegramClient;

    $sent = (new GroupSessionAnnouncer($telegram))->announce(-100, 'Exam', 'Open', 'https://twa.test/twa/exam/7', 'failed', static function (): void {});

    expect($sent)->toBeTrue()
        ->and($telegram->immediateButtons)->toBe([-100]);
});
