<?php

declare(strict_types=1);

use App\Domain\Cup\Exceptions\CupException;
use App\Domain\Cup\Support\DisplayName;
use App\Domain\Telegram\Support\TelegramText;

/*
 * Review of release 5, item 4 (docs/33 §11.8 п. 4): a name in a cup is printed by the bot as plain text, and Telegram makes links of
 * URLs, domains, @mentions, /commands, #hashtags and $CASHTAGS by itself - such a name is refused at the input (422 with its reason)
 * and defused at the output (names stored before the rule, Telegram first names). Not checked here: what the live Telegram client
 * links - the look-alike characters of the output are to be checked on a live bot.
 */

/** @return array<string, array{0: string, 1: string}> name => [reason] */
function refusedNames(): array
{
    return [
        'empty' => ['', 'empty'],
        'only invisible' => ["\u{202E}\u{200B}\u{3164}\u{2800}", 'empty'],
        '33 letters' => [str_repeat('x', 33), 'too_long'],
        '33 emoji' => [str_repeat('🦊', 33), 'too_long'],
        'zalgo' => ["Ali\u{0300}\u{0301}\u{0302}\u{0303}", 'marks'],
        'url' => ['https://spam.example', 'link'],
        'scheme only' => ['tg://resolve', 'link'],
        'domain' => ['visit casino.com now', 'link'],
        'domain in caps' => ['SITE.RU', 'link'],
        'cyrillic domain' => ['сайт.рф', 'link'],
        't.me' => ['t.me/channel', 'link'],
        'www' => ['www.x', 'link'],
        'initials' => ['J.R. Tolkien', 'link'],
        'mention' => ['@spam_channel', 'mention'],
        'mention in the middle' => ['Ali @bob', 'mention'],
        'command' => ['/start', 'command'],
        'command after a space' => ['play /cup', 'command'],
        'hashtag' => ['#win', 'tag'],
        'hashtag with digits' => ['#1st', 'tag'],
        'cashtag' => ['$USD', 'tag'],
    ];
}

it('refuses a name Telegram would make a link of, with the reason', function (string $case): void {
    [$name, $reason] = refusedNames()[$case];

    expect(fn () => DisplayName::validate($name))->toThrow(fn (CupException $e) => expect([$e->errorCode, $e->httpStatus(), $e->details])->toBe(['cup_display_name_invalid', 422, ['reason' => $reason]]));
})->with(array_keys(refusedNames()));

it('keeps an ordinary name, with emoji and accents, cleaned and in NFC', function (string $name, string $clean): void {
    expect(DisplayName::validate($name))->toBe($clean);
})->with([
    ['  Umar   the  Great ', 'Umar the Great'],
    ['Malika 🌸', 'Malika 🌸'],
    ['👨‍👩‍👧 family', '👨‍👩‍👧 family'],   // the joiners of an emoji sequence stay
    ["a\u{200D}b", 'ab'],                    // a joiner between letters does not
    ["Ma\u{202E}lika\u{2066}", 'Malika'],    // bidi overrides and isolates
    ["e\u{0301}", "\u{00E9}"],               // NFC
    ['Ali 2.0', 'Ali 2.0'],                  // a version number links nothing
    ['AC/DC', 'AC/DC'],
    ['Player #1', 'Player #1'],
    ['x@y', 'x@y'],
    ['Наташа', 'Наташа'],
    ["O'zbek", "O'zbek"],
    [str_repeat('🦊', 32), str_repeat('🦊', 32)], // 32 graphemes = 64 UTF-16 units, 32 code points
]);

it('counts the length in graphemes: 32 families of 5 code points are too many code points, 12 are fine', function (): void {
    $family = '👨‍👩‍👧';
    expect(DisplayName::graphemeCount(str_repeat($family, 12)))->toBe(12)
        ->and(DisplayName::validate(str_repeat($family, 12)))->toBe(str_repeat($family, 12))
        ->and(fn () => DisplayName::validate(str_repeat($family, 13)))->toThrow(fn (CupException $e) => expect($e->details['reason'])->toBe('too_long'));
});

it('defuses for the chat what an old name or a Telegram first name would link', function (string $name, string $chat): void {
    expect(DisplayName::forChat($name))->toBe($chat)
        // and what the bot prints passes the rule itself
        ->and(DisplayName::validate(DisplayName::forChat($name)))->toBe($chat);
})->with([
    ['visit casino.com', 'visit casino․com'],
    ['@spam', '＠spam'],
    ['/start', '∕start'],
    ['#win', '＃win'],
    ['$USD', '＄USD'],
    ['https://x.io', 'https:⁄⁄x․io'],
    ['t.me/joinchat', 't․me/joinchat'],
    ["Z\u{0300}\u{0301}\u{0302}\u{0303}\u{0304}", "Z\u{0300}\u{0301}"],
    ["Ma\u{202E}lika", 'Malika'],
    ['Malika 🌸', 'Malika 🌸'],
]);

it('takes the Telegram first name defused and cut between graphemes, or a fallback', function (): void {
    expect(DisplayName::fromFirstName('@casino_bot', 7))->toBe('＠casino_bot')
        ->and(DisplayName::fromFirstName("\u{3164}\u{200B}", 7))->toBe('Player 7')
        ->and(DisplayName::fromFirstName(null, 7))->toBe('Player 7')
        ->and(DisplayName::graphemeCount(DisplayName::fromFirstName(str_repeat('🇺🇿', 40), 7)))->toBe(32)
        ->and(DisplayName::fromFirstName(str_repeat('🇺🇿', 40), 7))->toBe(str_repeat('🇺🇿', 32));
});

it('measures a text as Telegram does: UTF-16 code units, cut between graphemes', function (): void {
    expect(TelegramText::length('abc'))->toBe(3)
        ->and(TelegramText::length('🦊'))->toBe(2)
        ->and(TelegramText::length('👨‍👩‍👧'))->toBe(8)
        ->and(TelegramText::length('Ёж'))->toBe(2)
        ->and(TelegramText::fits(str_repeat('🦊', 512), 1024))->toBeTrue()
        ->and(TelegramText::fits(str_repeat('🦊', 513), 1024))->toBeFalse()
        ->and(TelegramText::cut(str_repeat('🦊', 10), 7))->toBe('🦊🦊🦊…')
        ->and(TelegramText::cut('🇺🇿🇺🇿🇺🇿', 9))->toBe('🇺🇿🇺🇿…')
        ->and(TelegramText::cut('short', 1024))->toBe('short');
});
