<?php

declare(strict_types=1);

use App\Domain\Telegram\Services\MiniAppLinks;

beforeEach(function (): void {
    config(['twa.base_url' => 'https://twa.test/', 'telegram.bot_username' => 'lexi_bot', 'telegram.mini_app_short_name' => '']);
});

it('tells private chats from groups and channels', function (): void {
    $links = new MiniAppLinks;

    expect($links->isPrivateChat(42))->toBeTrue()
        ->and($links->isPrivateChat('42'))->toBeTrue()
        ->and($links->isPrivateChat(-100123))->toBeFalse()
        ->and($links->isPrivateChat('-100123'))->toBeFalse()
        ->and($links->isPrivateChat('@channel'))->toBeFalse()
        ->and($links->isPrivateChat(''))->toBeFalse();
});

it('encodes the start param as base64url without padding', function (): void {
    $links = new MiniAppLinks;

    expect($links->startParam('/training/15'))->toBe('L3RyYWluaW5nLzE1')
        ->and($links->startParam('/a?b=~~~>>>???'))->toMatch('/^[A-Za-z0-9_-]+$/')
        ->and($links->startParam('/x'))->not->toContain('=');
});

it('builds the Main Mini App link and the named Mini App link', function (): void {
    $links = new MiniAppLinks;

    expect($links->forPath('https://twa.test/twa/training/15'))->toBe('https://t.me/lexi_bot?startapp=L3RyYWluaW5nLzE1');

    config(['telegram.mini_app_short_name' => 'learn']);
    expect($links->forPath('https://twa.test/twa/training/15'))->toBe('https://t.me/lexi_bot/learn?startapp=L3RyYWluaW5nLzE1');
});

it('extracts the path after /twa with its query string', function (): void {
    $links = new MiniAppLinks;

    expect($links->pathFromTwaUrl('https://twa.test/twa'))->toBe('/')
        ->and($links->pathFromTwaUrl('https://twa.test/twa/'))->toBe('/')
        ->and($links->pathFromTwaUrl('https://twa.test/twa?from=x'))->toBe('/?from=x')
        ->and($links->pathFromTwaUrl('https://twa.test/twa/?from=a&lesson=3'))->toBe('/?from=a&lesson=3')
        ->and($links->pathFromTwaUrl('https://twa.test/twafoo'))->toBeNull()
        ->and($links->pathFromTwaUrl('https://other.test/twa/exam/1'))->toBeNull();
});

it('has no direct link without a bot username, for a foreign URL or an oversized param', function (): void {
    $links = new MiniAppLinks;

    expect($links->forPath('https://other.test/twa/exam/1'))->toBeNull()
        ->and($links->forPath('https://twa.test/twa/?x='.str_repeat('a', 600)))->toBeNull();

    config(['telegram.bot_username' => '']);
    expect($links->forPath('https://twa.test/twa/exam/1'))->toBeNull();
});
