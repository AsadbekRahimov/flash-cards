<?php

declare(strict_types=1);

/*
 * docs/34 §4в п. 3 (B2): `learners.dm_blocked_at` is written by App\Domain\Telegram\Services\DmReachability only - the one place
 * that keeps the reason, the first moment and the `bot.dm_closed` / `bot.dm_reopened` events right. This guard reads the sources of
 * `app/` and fails on any other write: an array key `'dm_blocked_at' =>` (update / create / forceFill / fill), an assignment
 * `->dm_blocked_at =`, or raw SQL `dm_blocked_at = …`. Reads (`whereNull('dm_blocked_at')`, `$learner->dm_blocked_at !== null`) pass.
 *
 * The one exception: the synthetic learners of the load test (LoadTestTokenIssuer) are created already marked - made-up Telegram ids
 * that must never get a reminder; a create of a fake row is not a transition of a real DM.
 */

/** A write: an array key (not the `datetime` cast of the model), an assignment, raw SQL. */
const DM_GUARD_WRITE = '/[\'"]dm_blocked_at[\'"]\s*=>(?!\s*[\'"]datetime[\'"])|->dm_blocked_at\s*=(?!=)|dm_blocked_at\s*=\s*(?:now|null|\?|:)/i';

const DM_GUARD_ALLOWED = [
    'app/Domain/Telegram/Services/DmReachability.php',
    'app/Domain/Operations/Services/LoadTestTokenIssuer.php',
];

it('finds no write of dm_blocked_at in app/ outside DmReachability', function (): void {
    $offenders = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen(base_path()) + 1));
        if (in_array($relative, DM_GUARD_ALLOWED, true)) {
            continue;
        }

        $tokens = PhpToken::tokenize((string) file_get_contents($file->getPathname()));
        // Comments and docblocks may talk about the column freely: only code counts.
        $code = implode('', array_map(
            static fn (PhpToken $t): string => $t->is([T_COMMENT, T_DOC_COMMENT]) ? '' : $t->text,
            $tokens,
        ));

        if (preg_match(DM_GUARD_WRITE, $code) === 1) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe([]);
});

it('would catch the forms of a write it is meant to catch', function (string $code): void {
    expect(preg_match(DM_GUARD_WRITE, $code))->toBe(1);
})->with([
    "Learner::query()->update(['dm_blocked_at' => now()]);",
    '$learner->forceFill(["dm_blocked_at" => null])->save();',
    '$learner->dm_blocked_at = now();',
    "DB::update('update learners set dm_blocked_at = ? where id = ?', [\$now, \$id]);",
]);

it('lets the reads through', function (string $code): void {
    expect(preg_match(DM_GUARD_WRITE, $code))->toBe(0);
})->with([
    "->whereNull('dm_blocked_at')",
    '$learner->dm_blocked_at !== null',
    '$learner->dm_blocked_at === null',
    "->where('dm_blocked_at', '>=', \$since)",
    "'dm_blocked_at' => 'datetime',",
]);
