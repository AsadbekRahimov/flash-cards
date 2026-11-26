<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Services\Scoring;

use App\Domain\Engagement\DTO\BoardCursor;
use App\Domain\Engagement\DTO\BoardKeys;
use App\Domain\Engagement\DTO\BoardRow;
use App\Domain\Engagement\DTO\BoardScope;
use App\Domain\Learners\Services\ProEmojiService;
use App\Domain\Operations\Support\PureCache;

/**
 * The cached reading of one table of the word points v2 - a region of a month (docs/35 §2.4, R6-R7), "all time" and its regions
 * (docs/32 §5, S10) - over {@see ScoreBoard} (the statements) and {@see ProEmojiService} (the emoji of Pro of a list, one statement).
 * What is cached and how long is the caller's choice ({@see BoardKeys}, the TTLs); a null key = read without the cache (a closed
 * month: its places are final, the reads are rare).
 *
 *   top         the first `$size` rows with their places and emoji - arrays of scalars under `top`, dropped by the writers;
 *   count       the participants - an int under `count`, lives and dies with the top;
 *   rank        the competition place of a tuple - an int under `rank:{tuple}`;
 *   neighbours  two above, two below, the place and the gap of the viewer - under `near:{learner}:{tuple}`, the place passed in when the
 *               caller has it (no second count), the gap read off the rows above when it can be ({@see ScoreBoard::gapFromAbove()});
 *   page        a page after a cursor with the emoji - not cached (any position of any list).
 *
 * @phpstan-import-type BoardRowArray from BoardRow
 */
final class BoardReader
{
    public function __construct(
        private readonly ScoreBoard $board,
        private readonly ProEmojiService $proEmoji,
    ) {}

    /** @return list<BoardRow> */
    public function top(BoardScope $scope, BoardKeys $keys, int $size, int $ttl): array
    {
        /** @var list<BoardRowArray> $rows */
        $rows = PureCache::store()->remember(
            $keys->top,
            max(1, $ttl),
            fn (): array => array_map(static fn (BoardRow $row): array => $row->toArray(), $this->withEmoji($this->board->page($scope, null, $size)['rows'])),
        );

        return array_map(BoardRow::fromArray(...), $rows);
    }

    /** The participants of the table; Redis returns a top-level number as a string (docs/19 §3 п. 11) - hence the cast. */
    public function count(BoardScope $scope, BoardKeys $keys, int $ttl): int
    {
        return (int) PureCache::store()->remember($keys->count, max(1, $ttl), fn (): int => $this->board->count($scope));
    }

    public function rank(BoardScope $scope, ?BoardKeys $keys, int $ttl, int $points, int $longWords, int $activeDays): int
    {
        $count = fn (): int => $this->board->rank($scope, $points, $longWords, $activeDays);

        return $keys === null ? $count() : (int) PureCache::store()->remember($keys->rank($points, $longWords, $activeDays), max(1, $ttl), $count);
    }

    /**
     * The participants right above and below the row (2 and 2), its place and the points that overtake the next place (null for the
     * leader), with the emoji of the rows.
     *
     * @return array{above: list<BoardRow>, below: list<BoardRow>, rank: int, next_gap: int|null}
     */
    public function neighbours(BoardScope $scope, ?BoardKeys $keys, int $ttl, BoardCursor $me, ?int $rank = null, int $n = 2): array
    {
        $compute = function () use ($scope, $me, $rank, $n): array {
            $around = $this->board->neighbours($scope, $me, $n, $rank);

            // The gap is read off the rows above; a statement only when all of them share the viewer's place and the list is full.
            $gap = $this->board->gapFromAbove($around['above'], $me->points, $me->longWords, $me->activeDays, count($around['above']) < $n);
            if ($gap === false) {
                $gap = $this->board->gapToNext($scope, $me->points, $me->longWords, $me->activeDays);
            }

            // One statement of the emoji for both lists.
            $rows = array_map(static fn (BoardRow $row): array => $row->toArray(), $this->withEmoji([...$around['above'], ...$around['below']]));

            return [
                'above' => array_slice($rows, 0, count($around['above'])),
                'below' => array_slice($rows, count($around['above'])),
                'rank' => $around['rank'],
                'next_gap' => $gap,
            ];
        };

        /** @var array{above: list<BoardRowArray>, below: list<BoardRowArray>, rank: int, next_gap: int|null} $data */
        $data = $keys === null
            ? $compute()
            : PureCache::store()->remember($keys->near($me->learnerId, $me->points, $me->longWords, $me->activeDays), max(1, $ttl), $compute);

        return [
            'above' => array_map(BoardRow::fromArray(...), $data['above']),
            'below' => array_map(BoardRow::fromArray(...), $data['below']),
            'rank' => (int) $data['rank'],
            'next_gap' => $data['next_gap'] === null ? null : (int) $data['next_gap'],
        ];
    }

    /**
     * A page after a cursor (the first page without one) with the emoji of its rows; not cached.
     *
     * @return array{rows: list<BoardRow>, next: BoardCursor|null}
     */
    public function page(BoardScope $scope, ?BoardCursor $after, int $limit): array
    {
        $page = $this->board->page($scope, $after, $limit);

        return ['rows' => $this->withEmoji($page['rows']), 'next' => $page['next']];
    }

    /**
     * The rows with the emoji of Pro of their learners: ONE statement for the whole list, none for an empty one.
     *
     * @param  list<BoardRow>  $rows
     * @return list<BoardRow>
     */
    public function withEmoji(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $emoji = $this->proEmoji->visibleForMany(array_map(static fn (BoardRow $row): int => $row->learnerId, $rows));

        return array_map(static fn (BoardRow $row): BoardRow => $row->withEmoji($emoji[$row->learnerId] ?? null), $rows);
    }
}
