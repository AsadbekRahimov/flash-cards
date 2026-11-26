<?php

declare(strict_types=1);

/*
 * docs/36 §3б (N6): the address of a Mini App screen is built by App\Domain\Telegram\Services\BotButtons::twaUrl() only - one place
 * for `twa.base_url` + `/twa` + the path of a TwaScreen. This guard reads the code of `app/` (comments and docblocks may name the
 * key) and fails on `twa.base_url` anywhere else than the builder, MiniAppLinks (it takes a TWA address apart for `startapp`),
 * `telegram:setup-bot` (the address of the Mini App at BotFather) and BotMenuBuilder (the menu button).
 */

const TWA_URL_GUARD_ALLOWED = [
    'app/Domain/Telegram/Services/BotButtons.php',
    'app/Domain/Telegram/Services/MiniAppLinks.php',
    'app/Domain/Telegram/Services/BotMenuBuilder.php',
    'app/Console/Commands/TelegramSetupBot.php',
];

it('finds twa.base_url in app/ only in the button builder and the three allowed places', function (): void {
    $offenders = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen(base_path()) + 1));
        if (in_array($relative, TWA_URL_GUARD_ALLOWED, true)) {
            continue;
        }

        $code = implode('', array_map(
            static fn (PhpToken $t): string => $t->is([T_COMMENT, T_DOC_COMMENT]) ? '' : $t->text,
            PhpToken::tokenize((string) file_get_contents($file->getPathname())),
        ));

        if (str_contains($code, 'twa.base_url')) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe([]);
});

it('still finds the key in each allowed place, so the list does not rot', function (string $relative): void {
    expect((string) file_get_contents(base_path($relative)))->toContain('twa.base_url');
})->with(TWA_URL_GUARD_ALLOWED);
