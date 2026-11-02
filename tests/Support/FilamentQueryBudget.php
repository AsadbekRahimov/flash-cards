<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Livewire\Component;
use Livewire\Livewire;

/**
 * Query budget of a Filament list page (docs/20 §1.1 and §1.13): the number of SQL statements of its first render must not
 * grow with the number of rows, and must stay within a ceiling. It is the guard against N+1 - a relation read inside a
 * column closure shows up as "one more statement per row".
 *
 * The page is rendered with 10 rows and again with 50 (`$perPage` rows fit on one page, so every row is rendered):
 *
 *     FilamentQueryBudget::assert(ListLearners::class, fn (int $rows) => seedLearnersUpTo($rows), ceiling: 25);
 *
 * The caller has signed in (and entered the tenant) before; `$fill` only brings the table to the asked number of
 * records, with whatever related rows the columns read - a budget test on bare rows proves nothing.
 */
final class FilamentQueryBudget
{
    public const SMALL = 10;

    public const LARGE = 50;

    /**
     * Statements of the first render of $page, per number of rows.
     *
     * @param  class-string<Component>  $page
     * @param  Closure(int): void  $fill  brings the table to exactly this many records (called with 10, then 50)
     * @param  array<string, mixed>  $parameters  mount parameters of the page
     * @param  list<int>  $sizes
     * @return array<int, list<string>> SQL text (with `?` placeholders) of every statement, by number of rows
     */
    public static function measure(string $page, Closure $fill, array $parameters = [], array $sizes = [self::SMALL, self::LARGE], int $perPage = self::LARGE): array
    {
        // A render that nobody measures first: per-process work that happens once (loading permissions, resolving
        // the panel, compiling views) must not be counted against the first size only.
        Livewire::test($page, $parameters);

        $queries = [];

        foreach ($sizes as $rows) {
            $fill($rows);

            // Each measured render starts like a new request: whatever the application remembers for one request
            // (scoped bindings, e.g. the role memory of the `/org` cabinet) is forgotten, as the queue worker and
            // Octane do, so the count includes the statements such a memory saves within a request only.
            app()->forgetScopedInstances();

            $component = null;
            $queries[$rows] = QueryLog::during(function () use (&$component, $page, $parameters, $perPage): void {
                $component = Livewire::test($page, $parameters)->set('tableRecordsPerPage', $perPage);

                // A table with deferred loading has no rows (and no query) until it is asked for them (docs/20 §1.8).
                if ($component->instance()->getTable()->isLazyLoaded()) {
                    $component->loadTable();
                }
            });

            // Outside the measured window: the rows really were rendered, so the count above means something.
            $component?->assertCountTableRecords($rows);
        }

        return $queries;
    }

    /**
     * @param  class-string<Component>  $page
     * @param  Closure(int): void  $fill
     * @param  int  $ceiling  the most statements the page may run (with 50 rows)
     * @param  array<string, mixed>  $parameters
     * @param  int  $knownPerRow  statements per row that a page is known to run and waits to be fixed (an N+1 recorded
     *                            in docs/20); 0 = the budget proper: the count does not grow at all
     * @return array<int, list<string>> the measurement, for callers that want to report it
     */
    public static function assert(string $page, Closure $fill, int $ceiling, array $parameters = [], int $knownPerRow = 0): array
    {
        $queries = self::measure($page, $fill, $parameters);

        $small = $queries[self::SMALL];
        $large = $queries[self::LARGE];

        expect(count($large) - count($small))->toBeLessThanOrEqual(
            $knownPerRow * (self::LARGE - self::SMALL),
            sprintf(
                "%s runs %d statements for %d rows and %d for %d: %s per row.\nRepeated per row:\n%s",
                class_basename($page),
                count($small),
                self::SMALL,
                count($large),
                self::LARGE,
                round((count($large) - count($small)) / (self::LARGE - self::SMALL), 1),
                implode("\n", self::grown($small, $large)) ?: '(none identified: the statements differ in text)',
            ),
        );

        expect(count($large))->toBeLessThanOrEqual(
            $ceiling,
            sprintf("%s runs %d statements against a budget of %d:\n%s", class_basename($page), count($large), $ceiling, implode("\n", $large)),
        );

        return $queries;
    }

    /**
     * The statements that ran more often with the larger table.
     *
     * @param  list<string>  $small
     * @param  list<string>  $large
     * @return list<string> "N x -> M x: SQL"
     */
    public static function grown(array $small, array $large): array
    {
        $before = array_count_values($small);
        $after = array_count_values($large);
        $lines = [];

        foreach ($after as $sql => $times) {
            $was = $before[$sql] ?? 0;

            if ($times > $was) {
                $lines[] = sprintf('%dx -> %dx: %s', $was, $times, mb_substr((string) $sql, 0, 200));
            }
        }

        return $lines;
    }
}
