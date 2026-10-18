<?php

declare(strict_types=1);

use App\Domain\Localization\Services\LocaleResolver;
use App\Models\Student;
use App\Models\TelegramGroup;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;

// --- LocaleResolver -------------------------------------------------------

it('exposes ru and uz as supported locales with ru default and fallback', function (): void {
    $resolver = new LocaleResolver;

    expect($resolver->supported())->toBe(['ru', 'uz'])
        ->and($resolver->default())->toBe('ru')
        ->and($resolver->fallback())->toBe('ru');
});

it('maps Telegram language codes to supported locales', function (?string $code, string $expected): void {
    expect((new LocaleResolver)->fromLanguageCode($code))->toBe($expected);
})->with([
    'uz' => ['uz', 'uz'],
    'uz region' => ['uz-UZ', 'uz'],
    'uz underscore' => ['uz_Latn', 'uz'],
    'uz uppercase' => ['UZ', 'uz'],
    'ru' => ['ru', 'ru'],
    'ru region' => ['ru-RU', 'ru'],
    'english falls back' => ['en', 'ru'],
    'unknown falls back' => ['xx-YY', 'ru'],
    'null falls back' => [null, 'ru'],
    'empty falls back' => ['', 'ru'],
    'blank falls back' => ['  ', 'ru'],
]);

it('resolves a student locale from language_code', function (): void {
    $resolver = new LocaleResolver;

    expect($resolver->forStudent(new Student(['language_code' => 'uz'])))->toBe('uz')
        ->and($resolver->forStudent(new Student(['language_code' => 'en'])))->toBe('ru')
        ->and($resolver->forStudent(new Student(['language_code' => null])))->toBe('ru');
});

it('resolves a group locale from meta.locale only when supported', function (): void {
    $resolver = new LocaleResolver;

    expect($resolver->forGroup(new TelegramGroup(['meta' => ['locale' => 'uz']])))->toBe('uz')
        ->and($resolver->forGroup(new TelegramGroup(['meta' => ['locale' => 'de']])))->toBe('ru')
        ->and($resolver->forGroup(new TelegramGroup(['meta' => ['locale' => ['uz']]])))->toBe('ru')
        ->and($resolver->forGroup(new TelegramGroup(['meta' => ['type' => 'supergroup']])))->toBe('ru')
        ->and($resolver->forGroup(new TelegramGroup))->toBe('ru');
});

it('always uses the default locale for teachers', function (): void {
    expect((new LocaleResolver)->forTeacher())->toBe('ru');

    Config::set('localization.default', 'uz');
    expect((new LocaleResolver)->forTeacher())->toBe('uz');
});

it('falls back to a supported locale when config is broken', function (): void {
    Config::set('localization.default', 'de');
    Config::set('localization.fallback', 'xx');

    expect((new LocaleResolver)->default())->toBe('ru');

    Config::set('localization.supported', []);

    expect((new LocaleResolver)->supported())->toBe(['ru'])
        ->and((new LocaleResolver)->default())->toBe('ru');
});

// --- translation files ----------------------------------------------------

/** @return array<string, string> dotted key => string, for one locale */
function loadLangFiles(string $locale): array
{
    $result = [];
    foreach (glob(lang_path($locale.'/*.php')) ?: [] as $file) {
        $group = basename($file, '.php');
        foreach (Arr::dot(require $file) as $key => $value) {
            $result["{$group}.{$key}"] = (string) $value;
        }
    }

    return $result;
}

/** @return list<string> */
function placeholders(string $text): array
{
    preg_match_all('/:[a-z_]+/', $text, $m);
    $found = array_values(array_unique($m[0]));
    sort($found);

    return $found;
}

it('has the same translation keys in ru and uz', function (): void {
    $ru = loadLangFiles('ru');
    $uz = loadLangFiles('uz');

    expect($ru)->not->toBeEmpty()
        ->and(array_keys($uz))->toEqualCanonicalizing(array_keys($ru));
});

it('uses the same placeholders in ru and uz', function (): void {
    $ru = loadLangFiles('ru');
    $uz = loadLangFiles('uz');

    foreach ($ru as $key => $text) {
        if (str_starts_with($key, 'reminders.review')) {
            // ru is a plural string; compare against the uz single form below.
            continue;
        }
        expect(placeholders($uz[$key]))->toBe(placeholders($text), "placeholders differ for {$key}");
    }

    expect(placeholders($uz['reminders.review']))->toBe([':count']);
});

it('has no empty translations and no Cyrillic in uz (Latin script)', function (): void {
    foreach (loadLangFiles('uz') as $key => $text) {
        expect(trim($text))->not->toBe('', "empty uz string: {$key}");
        expect(preg_match('/\p{Cyrillic}/u', $text))->toBe(0, "Cyrillic in uz string: {$key}");
    }
});

it('uses the ASCII apostrophe in uz strings', function (): void {
    foreach (loadLangFiles('uz') as $key => $text) {
        expect(preg_match('/[‘’ʻʼ`´]/u', $text))->toBe(0, "non-ASCII apostrophe in {$key}");
    }
});

// --- plural forms ---------------------------------------------------------

it('picks the correct ru plural form for review reminders', function (int $count, string $expected): void {
    expect(trans_choice('reminders.review', $count, [], 'ru'))->toBe("Пора повторить {$count} {$expected} в LexiFlow.");
})->with([
    [0, 'слов'],
    [1, 'слово'],
    [2, 'слова'],
    [3, 'слова'],
    [4, 'слова'],
    [5, 'слов'],
    [11, 'слов'],
    [12, 'слов'],
    [14, 'слов'],
    [21, 'слово'],
    [22, 'слова'],
    [25, 'слов'],
    [101, 'слово'],
    [111, 'слов'],
    [112, 'слов'],
]);

it('does not inflect the noun in uz review reminders', function (int $count): void {
    expect(trans_choice('reminders.review', $count, [], 'uz'))
        ->toBe("LexiFlow'da {$count} ta so'zni takrorlash vaqti keldi.");
})->with([1, 2, 5, 11, 21, 22, 25]);

it('does not change the process locale when translating with an explicit locale', function (): void {
    $before = app()->getLocale();

    __('bot.help.group', [], 'uz');
    trans_choice('reminders.review', 3, [], 'uz');

    expect(app()->getLocale())->toBe($before);
});

// --- no hard-coded Cyrillic in user-facing code ---------------------------

it('keeps Cyrillic out of Telegram, job and 2FA code (comments excluded)', function (): void {
    $files = [
        ...(glob(app_path('Domain/Telegram/*/*.php')) ?: []),
        ...(glob(app_path('Domain/Telegram/Handlers/Contracts/*.php')) ?: []),
        ...(glob(app_path('Domain/Localization/Services/*.php')) ?: []),
        ...(glob(app_path('Jobs/*.php')) ?: []),
        app_path('Http/Controllers/TwoFactorController.php'),
    ];

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (! is_array($token) || in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            expect(preg_match('/\p{Cyrillic}/u', $token[1]))
                ->toBe(0, basename($file).':'.$token[2].' has hard-coded Cyrillic: '.trim($token[1]));
        }
    }

    foreach (glob(resource_path('views/2fa/*.blade.php')) ?: [] as $view) {
        expect(preg_match('/\p{Cyrillic}/u', (string) file_get_contents($view)))
            ->toBe(0, basename($view).' has hard-coded Cyrillic');
    }
});
