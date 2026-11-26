<?php

declare(strict_types=1);

use App\Domain\Telegram\Services\BotButtons;
use App\Domain\Telegram\Support\BotButton;
use App\Domain\Telegram\Support\TwaScreen;

/*
 * The buttons under the messages of the bot (docs/36 §3б, N1): the address of a Mini App screen with its parameters and `from`,
 * `web_app` in a private chat and a `startapp` link in a group, `callback_data` within Telegram's 64 bytes, and the plain address
 * in a group when no bot username is configured (as `sendWebAppButton` does).
 */

beforeEach(function (): void {
    config(['twa.base_url' => 'https://twa.test/', 'telegram.bot_username' => 'muhrla_bot', 'telegram.mini_app_short_name' => '']);
});

it('builds the address of a screen with its parameters and its query', function (): void {
    $buttons = new BotButtons;

    expect($buttons->twaUrl(TwaScreen::Today))->toBe('https://twa.test/twa/')
        ->and($buttons->twaUrl(TwaScreen::Today, query: ['from' => 'reminder']))->toBe('https://twa.test/twa/?from=reminder')
        ->and($buttons->twaUrl(TwaScreen::League))->toBe('https://twa.test/twa/league')
        ->and($buttons->twaUrl(TwaScreen::LeagueMonth, query: ['from' => 'month_won']))->toBe('https://twa.test/twa/league/month?from=month_won')
        ->and($buttons->twaUrl(TwaScreen::Friends, query: ['challenge' => 15, 'from' => 'challenge_accepted']))
        ->toBe('https://twa.test/twa/friends?challenge=15&from=challenge_accepted')
        ->and($buttons->twaUrl(TwaScreen::Battle, ['id' => 7]))->toBe('https://twa.test/twa/battles/7')
        ->and($buttons->twaUrl(TwaScreen::Course, ['slug' => 'english a1']))->toBe('https://twa.test/twa/courses/english%20a1');
});

it('refuses a screen whose parameter is missing', function (): void {
    (new BotButtons)->twaUrl(TwaScreen::Battle);
})->throws(InvalidArgumentException::class, 'id');

it('gives a web_app button in a private chat', function (): void {
    $buttons = new BotButtons;
    $button = $buttons->open('▶️ Начать занятие', TwaScreen::Today, query: ['from' => 'promo']);

    expect($button->kind)->toBe(BotButton::KIND_WEB_APP)
        ->and($buttons->keyboard(42, [[$button]]))->toBe(['inline_keyboard' => [[
            ['text' => '▶️ Начать занятие', 'web_app' => ['url' => 'https://twa.test/twa/?from=promo']],
        ]]])
        ->and($buttons->single('42', $button))->toBe($buttons->keyboard(42, [[$button]]));
});

it('turns a web_app button into the startapp link of its screen in a group', function (): void {
    $buttons = new BotButtons;
    $button = $buttons->open('🏅 Открыть лигу', TwaScreen::League);

    expect($buttons->keyboard(-100123, [[$button]]))->toBe(['inline_keyboard' => [[
        ['text' => '🏅 Открыть лигу', 'url' => 'https://t.me/muhrla_bot?startapp=L2xlYWd1ZQ'],
    ]]]);
});

it('gives the plain address in a group when no bot username is configured', function (): void {
    config(['telegram.bot_username' => '']);
    $buttons = new BotButtons;

    expect($buttons->single(-100123, $buttons->open('x', TwaScreen::Friends, query: ['from' => 'x'])))->toBe(['inline_keyboard' => [[
        ['text' => 'x', 'url' => 'https://twa.test/twa/friends?from=x'],
    ]]]);
});

it('keeps callback and link buttons as they are, row by row', function (): void {
    $buttons = new BotButtons;

    expect($buttons->keyboard(-5, [
        [$buttons->callback('Да', 'fr1:cacc:12'), $buttons->callback('Нет', 'fr1:cdec:12')],
        [$buttons->url('Сайт', 'https://example.test')],
    ]))->toBe(['inline_keyboard' => [
        [['text' => 'Да', 'callback_data' => 'fr1:cacc:12'], ['text' => 'Нет', 'callback_data' => 'fr1:cdec:12']],
        [['text' => 'Сайт', 'url' => 'https://example.test']],
    ]]);
});

it('takes callback data of exactly 64 bytes', function (): void {
    expect((new BotButtons)->callback('x', str_repeat('a', 64))->value)->toHaveLength(64);
});

it('refuses callback data longer than 64 bytes, counted in bytes', function (string $data): void {
    (new BotButtons)->callback('x', $data);
})->with([
    '65 ASCII bytes' => [str_repeat('a', 65)],
    '33 two-byte letters' => [str_repeat('ж', 33)],
    'empty' => [''],
])->throws(InvalidArgumentException::class);

it('has a text for every button in both languages', function (): void {
    $ru = require lang_path('ru/buttons.php');
    $uz = require lang_path('uz/buttons.php');

    expect(array_keys($uz))->toBe(array_keys($ru))
        ->and(array_filter($uz, static fn (string $text): bool => trim($text) === ''))->toBe([]);
});
