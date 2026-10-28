<?php

declare(strict_types=1);

use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Services\BotMenuBuilder;

/*
 * `php artisan telegram:setup-bot` (docs/04 B.3): the "/" command list for private chats and groups in ru and uz,
 * and the permanent "LexiFlow" Mini App button. The base TestCase binds a recording FakeTelegramClient.
 *
 * The "/" menu of a group is shown to every member: `all_group_chats` holds `/help` only, the teacher's commands go to
 * `all_chat_administrators` (a learner must not be offered commands that answer "not linked to a teacher").
 */

beforeEach(function (): void {
    config(['twa.base_url' => 'https://twa.test']);

    $this->telegram = app(TelegramClient::class);
});

it('sets the command list of private chats, group members and group administrators: once without a language (ru) and once for uz', function (): void {
    $this->artisan('telegram:setup-bot')->assertSuccessful();

    expect(array_map(fn (array $call): array => [$call['scope'], $call['language_code']], $this->telegram->myCommands))->toBe([
        [['type' => 'all_private_chats'], null],
        [['type' => 'all_private_chats'], 'uz'],
        [['type' => 'all_group_chats'], null],
        [['type' => 'all_group_chats'], 'uz'],
        [['type' => 'all_chat_administrators'], null],
        [['type' => 'all_chat_administrators'], 'uz'],
    ]);
});

it('lists the private commands in the order of the menu with the Russian descriptions by default', function (): void {
    $this->artisan('telegram:setup-bot')->assertSuccessful();

    expect($this->telegram->myCommands[0]['commands'])->toBe([
        ['command' => 'today', 'description' => 'Занятие на сегодня'],
        ['command' => 'settings', 'description' => 'Настройки'],
        ['command' => 'invite', 'description' => 'Пригласить друга'],
        ['command' => 'pro', 'description' => 'Подписка Pro'],
        ['command' => 'promo', 'description' => 'Активировать промокод'],
        ['command' => 'help', 'description' => 'Справка'],
    ]);
});

it('offers the members of a group only /help: the teacher commands are not in the list of everybody', function (): void {
    $this->artisan('telegram:setup-bot')->assertSuccessful();

    expect($this->telegram->myCommands[2]['scope'])->toBe(['type' => 'all_group_chats'])
        ->and($this->telegram->myCommands[2]['commands'])->toBe([
            ['command' => 'help', 'description' => 'Справка'],
        ]);
});

it('lists the teacher commands for the group administrators in the order of the menu with the Russian descriptions by default', function (): void {
    $this->artisan('telegram:setup-bot')->assertSuccessful();

    expect($this->telegram->myCommands[4]['scope'])->toBe(['type' => 'all_chat_administrators'])
        ->and($this->telegram->myCommands[4]['commands'])->toBe([
            ['command' => 'start_training', 'description' => 'Запустить тренировку'],
            ['command' => 'start_exam', 'description' => 'Запустить экзамен'],
            ['command' => 'close_exam', 'description' => 'Завершить экзамен'],
            ['command' => 'help', 'description' => 'Справка'],
        ]);
});

it('never shows a teacher command in a list that a learner of the group sees', function (): void {
    $teacherCommands = ['start_training', 'start_exam', 'close_exam'];

    foreach (app(BotMenuBuilder::class)->commandSets() as $set) {
        $names = array_column($set['commands'], 'command');

        if ($set['scope']['type'] === 'all_chat_administrators') {
            expect($names)->toBe([...$teacherCommands, 'help']);

            continue;
        }

        // Private chats and group members: no teacher command, in either language.
        expect(array_intersect($names, $teacherCommands))->toBe([]);

        if ($set['scope']['type'] === 'all_group_chats') {
            expect($names)->toBe(['help']);
        }
    }
});

it('writes the uz lists from lang/uz/commands.php: the same commands, Uzbek descriptions', function (): void {
    $this->artisan('telegram:setup-bot')->assertSuccessful();

    foreach ([1 => 'private', 3 => 'group', 5 => 'group'] as $index => $group) {
        $ru = $this->telegram->myCommands[$index - 1]['commands'];
        $uz = $this->telegram->myCommands[$index]['commands'];

        expect(array_column($uz, 'command'))->toBe(array_column($ru, 'command'));

        foreach ($uz as $i => $command) {
            expect($command['description'])->toBe(__("commands.{$group}.{$command['command']}", [], 'uz'))
                ->not->toBe($ru[$i]['description'])
                ->not->toStartWith('commands.');
        }
    }

    expect($this->telegram->myCommands[1]['commands'][0])->toBe(['command' => 'today', 'description' => "Bugungi mashg'ulot"]);
});

it('sets the Mini App as the menu button of every private chat, with a single slash in the URL', function (string $baseUrl): void {
    config(['twa.base_url' => $baseUrl]);

    $this->artisan('telegram:setup-bot')->assertSuccessful();

    expect($this->telegram->menuButtons)->toBe([[
        'type' => 'web_app',
        'text' => 'LexiFlow',
        'web_app' => ['url' => 'https://twa.test/twa/'],
    ]]);
})->with(['no trailing slash' => 'https://twa.test', 'trailing slash' => 'https://twa.test/', 'several slashes' => 'https://twa.test//']);

it('reports what it has set', function (): void {
    $this->artisan('telegram:setup-bot')
        ->expectsOutputToContain('Commands all_private_chats, default (ru): /today /settings /invite /pro /promo /help')
        ->expectsOutputToContain('Commands all_private_chats, uz: /today /settings /invite /pro /promo /help')
        ->expectsOutputToContain('Commands all_group_chats, default (ru): /help')
        ->expectsOutputToContain('Commands all_group_chats, uz: /help')
        ->expectsOutputToContain('Commands all_chat_administrators, default (ru): /start_training /start_exam /close_exam /help')
        ->expectsOutputToContain('Commands all_chat_administrators, uz: /start_training /start_exam /close_exam /help')
        ->expectsOutputToContain('Menu button: "LexiFlow" opens https://twa.test/twa/')
        ->assertSuccessful();
});

it('skips the menu button with a warning while the Mini App is not on https, but sets the commands', function (string $baseUrl): void {
    config(['twa.base_url' => $baseUrl]);

    $this->artisan('telegram:setup-bot')
        ->expectsOutputToContain('Menu button skipped: Telegram accepts only https')
        ->assertSuccessful();

    expect($this->telegram->menuButtons)->toBe([])
        ->and($this->telegram->myCommands)->toHaveCount(6);
})->with([
    'http' => 'http://twa.test',
    'localhost' => 'http://localhost',
    'empty TWA_BASE_URL' => '',
    'no host' => 'https://',
]);

it('fails, but still tries the menu button, when Telegram refuses the commands', function (): void {
    $this->telegram->failMyCommands = true;

    $this->artisan('telegram:setup-bot')
        ->expectsOutputToContain('Commands all_private_chats, default (ru) not set: Bad Request: invalid commands')
        ->expectsOutputToContain('Menu button: "LexiFlow" opens https://twa.test/twa/')
        ->assertFailed();

    expect($this->telegram->myCommands)->toBe([])
        ->and($this->telegram->menuButtons)->toHaveCount(1);
});

it('fails, but leaves the commands set, when Telegram refuses the menu button', function (): void {
    $this->telegram->failMenuButton = true;

    $this->artisan('telegram:setup-bot')
        ->expectsOutputToContain('Menu button not set: Bad Request: invalid menu button')
        ->assertFailed();

    expect($this->telegram->myCommands)->toHaveCount(6)
        ->and($this->telegram->menuButtons)->toBe([]);
});

it('fails when Telegram answers false instead of confirming a change', function (): void {
    $client = Mockery::mock(TelegramClient::class);
    $client->shouldReceive('setMyCommands')->times(6)->andReturn(false);
    $client->shouldReceive('setChatMenuButton')->once()->andReturn(false);
    $this->app->instance(TelegramClient::class, $client);

    $this->artisan('telegram:setup-bot')
        ->expectsOutputToContain('Menu button not set: Telegram did not confirm the change.')
        ->assertFailed();
});

it('hands Telegram valid lists: command names, description lengths, no duplicates', function (): void {
    $sets = app(BotMenuBuilder::class)->commandSets();

    expect($sets)->toHaveCount(6);

    foreach ($sets as $set) {
        $names = array_column($set['commands'], 'command');

        expect($names)->toBe(array_values(array_unique($names)))
            ->and(count($names))->toBeLessThanOrEqual(100);

        foreach ($set['commands'] as $command) {
            expect($command['command'])->toMatch('/^[a-z0-9_]{1,32}$/')
                ->and(mb_strlen($command['description']))->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(256)
                // A key missing from the lang file would come back as the key itself.
                ->and($command['description'])->not->toStartWith('commands.');
        }
    }
});

it('words the teacher commands the same in the menu and in the /help of the group, and /close_exam in both /help texts', function (string $locale): void {
    $help = ['private' => __('bot.help.private', [], $locale), 'group' => __('bot.help.group', [], $locale)];

    // «Завершить экзамен» in the menu, «закрыть экзамен» in the group /help made one command look like two.
    foreach (['start_training', 'start_exam', 'close_exam'] as $command) {
        expect($help['group'])->toContain("/{$command} — ".mb_strtolower(__("commands.group.{$command}", [], $locale)));
    }

    expect($help['private'])->toContain('/close_exam — '.mb_strtolower(__('commands.group.close_exam', [], $locale)));
})->with(['ru', 'uz']);

it('keeps the menu and /help in step: every menu command is described in the /help of the same chat type', function (string $locale): void {
    $help = ['private' => __('bot.help.private', [], $locale), 'group' => __('bot.help.group', [], $locale)];

    foreach (BotMenuBuilder::SCOPES as [$group, $commands]) {
        foreach ($commands as $command) {
            // /help does not list itself in the learner part.
            if ($group === 'private' && $command === 'help') {
                continue;
            }

            expect($help[$group])->toContain("/{$command}");
        }
    }
})->with(['ru', 'uz']);
