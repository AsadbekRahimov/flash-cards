<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
 * DatabaseTruncation migrates the database in the set-up of the FIRST test of a process, before the `beforeEach` of a PostgreSQL-only
 * file can skip it. On SQLite that "migrates" an empty :memory: database and sets RefreshDatabaseState::$migrated, so the next
 * RefreshDatabase file of the same parallel worker finds no tables: `pest tests/Feature/Practice --parallel` gave 700 "no such table"
 * when a worker began with such a file. A file that needs the trait attaches it only where it runs - by the environment
 * (`getenv('DB_CONNECTION')`), before the call of `uses()`, as the *ConcurrencyTest files do.
 */

it('attaches DatabaseTruncation only where the file runs on pgsql, never unconditionally', function (): void {
    $attached = [];
    $unguarded = [];

    foreach ((new Finder)->files()->in(base_path('tests'))->name('*.php') as $file) {
        if ($file->getRealPath() === __FILE__) {
            continue;
        }

        $code = $file->getContents();
        // the call of uses(), not the words in a comment
        if (preg_match('/^[ \t]*uses\(\s*DatabaseTruncation::class\s*\)/m', $code, $match, PREG_OFFSET_CAPTURE) !== 1) {
            continue;
        }

        $attached[] = $file->getRelativePathname();
        $before = (string) preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m'], '', substr($code, 0, $match[0][1]));

        if (! str_contains($before, "getenv('DB_CONNECTION')")) {
            $unguarded[] = $file->getRelativePathname();
        }
    }

    // the files that need real parallel connections: if the search finds none, the search is what is broken
    expect(count($attached))->toBeGreaterThanOrEqual(12)
        ->and($unguarded)->toBe([]);
});
