<?php

declare(strict_types=1);

use App\Domain\Billing\Support\PayloadMasker;

it('replaces key-like values at any depth and keeps the rest', function (): void {
    $masked = PayloadMasker::mask([
        'id' => 'tx1',
        'amount' => 2_900_000,
        'Authorization' => 'Basic abc',
        'sign_string' => 'f00',
        'params' => ['account' => ['order_id' => 'tokentokentoken'], 'time' => 1_700_000_000_000, 'secret_key' => 'k'],
        'list' => [['api_key' => 'x', 'ok' => true]],
    ]);

    expect($masked)->toBe([
        'id' => 'tx1',
        'amount' => 2_900_000,
        'Authorization' => '[redacted]',
        'sign_string' => '[redacted]',
        'params' => ['account' => ['order_id' => '[redacted]'], 'time' => 1_700_000_000_000, 'secret_key' => '[redacted]'],
        'list' => [['api_key' => '[redacted]', 'ok' => true]],
    ]);
});

it('redacts bot tokens inside free text', function (): void {
    $masked = PayloadMasker::mask(['url' => 'https://api.telegram.org/bot123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw/getMe']);

    expect($masked['url'])->toBe('https://api.telegram.org/bot[redacted]/getMe');
});

it('prints an empty payload as a dash and shortens an order token', function (): void {
    expect(PayloadMasker::json([]))->toBe('—')
        ->and(PayloadMasker::json(null))->toBe('—')
        ->and(PayloadMasker::json(['ok' => 1]))->toContain('"ok": 1')
        ->and(PayloadMasker::token('abcdefghijklmnopqrstuvwxyz123456'))->toBe('abcd…3456')
        ->and(PayloadMasker::token('short'))->toBe('•••••')
        ->and(PayloadMasker::token(null))->toBe('—');
});
