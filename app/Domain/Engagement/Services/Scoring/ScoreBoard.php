<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Services\Scoring;

use App\Domain\Engagement\DTO\BoardCursor;
use App\Domain\Engagement\DTO\BoardRow;
use App\Domain\Engagement\DTO\BoardScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The reading of a table of the word points v2 (docs/32 §5.3): a category of a month now, "all time" (S10) with the same code.
 *
 * Order: `points desc, long_words desc, active_days desc, learner_id asc`; the place is the COMPETITION rank of the tuple
 * `(points, long_words, active_days)` - 1 + the participants with a strictly better tuple - so a shared place reads "7, 7, 9"; the
 * learner id only keeps the rows of a shared place in a stable order and never leaves as a place. Only participants are in a table.
 *
 * Every statement is an index range of the scope's standings index (equality prefix, `participant`, then `points` as the range):
 *
 *   rank        one count of the rows with `points >= P` that are strictly better - an index-only scan of the rows above;
 *   page        the keyset read after a cursor (`points <= P` as the range, `limit + 1` rows, names by the learners' primary key),
 *               and on a page other than the first two counts for the place and the position of its first row; the places of the
 *               other rows follow in PHP;
 *   neighbours  two keyset reads (the `n` rows before - the index read backwards - and the `n` after) and at most three counts (two
 *               when the caller passes the place of the row it has counted already);
 *   gap         one read of the nearest strictly better row: overtaking it takes `P' − P + 1` points (points are the first key,
 *               docs/32 §5.3 "Отрыв"). A caller that holds the rows above reads it off them ({@see gapFromAbove()}) and asks only
 *               when all of them share the row's place.
 * No caching here: the callers cache what they show (the top) under their keys.
 */
final class ScoreBoard
{
    /** The competition rank of a tuple in the scope: 1 + participants with a strictly better tuple. */
    public function rank(BoardScope $scope, int $points, int $longWords, int $activeDays): int
    {
        return $this->strictlyBetter($this->base($scope), $points, $longWords, $activeDays)->count() + 1;
    }

    /**
     * Up to `$limit` rows after the cursor (the first page without one), with their places, and the cursor of the next page (null on
     * the last one).
     *
     * @return array{rows: list<BoardRow>, next: BoardCursor|null}
     */
    public function page(BoardScope $scope, ?BoardCursor $after, int $limit): array
    {
        $limit = max(1, min(100, $limit));
        $query = $this->named($scope);
        if ($after !== null) {
            $this->after($query, $after);
        }
        $rows = $this->ordered($query)->limit($limit + 1)->get()->all();

        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        if ($rows === []) {
            return ['rows' => [], 'next' => null];
        }

        $first = $this->row($rows[0])->cursor();
        [$firstRank, $firstPosition] = $after === null
            ? [1, 1]
            : [$this->rank($scope, $first->points, $first->longWords, $first->activeDays), $this->position($scope, $first)];

        $out = $this->ranked($rows, $firstRank, $firstPosition);

        return ['rows' => $out, 'next' => $more ? $out[count($out) - 1]->cursor() : null];
    }

    /**
     * The `$n` participants right above and right below the given row, with their places (docs/32 §5.3 "Соседи"). The row itself is
     * not in either list.
     *
     * `$rank` is the place of the row when the caller has it already (its own count, a cached one): the count is not run again.
     *
     * @return array{above: list<BoardRow>, below: list<BoardRow>, rank: int}
     */
    public function neighbours(BoardScope $scope, BoardCursor $me, int $n = 2, ?int $rank = null): array
    {
        $n = max(1, min(10, $n));
        $myRank = $rank ?? $this->rank($scope, $me->points, $me->longWords, $me->activeDays);
        $myPosition = $this->position($scope, $me);

        $above = $this->before($this->named($scope), $me)
            ->orderBy('sc.points')->orderBy('sc.long_words')->orderBy('sc.active_days')->orderByDesc('sc.learner_id')
            ->limit($n)->get()->reverse()->values()->all();
        $below = $this->ordered($this->after($this->named($scope), $me))->limit($n)->get()->all();

        $aboveRows = [];
        if ($above !== []) {
            $top = $this->row($above[0])->cursor();
            $aboveRows = $this->ranked($above, $this->rank($scope, $top->points, $top->longWords, $top->activeDays), $myPosition - count($above));
        }

        $meRow = new BoardRow($me->learnerId, null, $me->points, $me->longWords, $me->activeDays, $myRank);
        $belowRows = $below === [] ? [] : array_slice($this->ranked([$meRow, ...$below], $myRank, $myPosition), 1);

        return ['above' => $aboveRows, 'below' => $belowRows, 'rank' => $myRank];
    }

    /** Points that overtake the nearest participant with a strictly better tuple for sure; null for the leader. */
    public function gapToNext(BoardScope $scope, int $points, int $longWords, int $activeDays): ?int
    {
        $next = $this->strictlyBetter($this->base($scope), $points, $longWords, $activeDays)
            ->orderBy('sc.points')->orderBy('sc.long_words')->orderBy('sc.active_days')->orderByDesc('sc.learner_id')
            ->value('sc.points');

        return $next === null ? null : (int) $next - $points + 1;
    }

    /**
     * {@see gapToNext()} read off the rows right above (as {@see neighbours()} returns them, nearest last), without a statement:
     * the nearest row above with a strictly better tuple is the nearest strictly better participant of the whole table (the rows are
     * in the order of the table). `false` = cannot tell: every row given shares the place and there may be more above (`$complete` is
     * false) - ask {@see gapToNext()}; with `$complete` (fewer rows above than asked for: these are all of them) nobody is strictly
     * better and the answer is null.
     *
     * @param  list<BoardRow>  $above
     */
    public function gapFromAbove(array $above, int $points, int $longWords, int $activeDays, bool $complete): int|false|null
    {
        for ($i = count($above) - 1; $i >= 0; $i--) {
            $row = $above[$i];
            if ($row->points > $points || ($row->points === $points && ($row->longWords > $longWords || ($row->longWords === $longWords && $row->activeDays > $activeDays)))) {
                return $row->points - $points + 1;
            }
        }

        return $complete ? null : false;
    }

    /** The participants of the scope (one category of one period). */
    private function base(BoardScope $scope): Builder
    {
        $query = DB::table($scope->table.' as sc');
        foreach ($scope->equals as $column => $value) {
            $query->where('sc.'.$column, $value);
        }

        return $query->where('sc.participant', true);
    }

    /** The participants with the first names (the learners' primary key; a deleted learner shows no name). */
    private function named(BoardScope $scope): Builder
    {
        return $this->base($scope)
            ->leftJoin('learners as l', 'l.id', '=', 'sc.learner_id')
            ->select(['sc.learner_id', 'sc.points', 'sc.long_words', 'sc.active_days', 'l.first_name', 'l.deleted_at']);
    }

    private function ordered(Builder $query): Builder
    {
        return $query->orderByDesc('sc.points')->orderByDesc('sc.long_words')->orderByDesc('sc.active_days')->orderBy('sc.learner_id');
    }

    /** 1 + the rows before the given one in the order of the table (the strictly better ones and the equal ones of a smaller id). */
    private function position(BoardScope $scope, BoardCursor $row): int
    {
        return $this->before($this->base($scope), $row)->count() + 1;
    }

    private function strictlyBetter(Builder $query, int $points, int $longWords, int $activeDays): Builder
    {
        return $query->where('sc.points', '>=', $points)->where(static function (Builder $q) use ($points, $longWords, $activeDays): void {
            $q->where('sc.points', '>', $points)
                ->orWhere('sc.long_words', '>', $longWords)
                ->orWhere(static fn (Builder $q): Builder => $q->where('sc.long_words', $longWords)->where('sc.active_days', '>', $activeDays));
        });
    }

    /** Rows ordered before the cursor's row (the row itself not included). */
    private function before(Builder $query, BoardCursor $c): Builder
    {
        return $this->keyset($query, $c, '>=', '>', '<');
    }

    /** Rows ordered after the cursor's row (the row itself not included). */
    private function after(Builder $query, BoardCursor $c): Builder
    {
        return $this->keyset($query, $c, '<=', '<', '>');
    }

    /**
     * `points {range} P and (points {cmp} P or long_words {cmp} L or (long_words = L and (active_days {cmp} D or (active_days = D and
     * learner_id {idCmp} id))))` - with `points = P` implied for the inner terms by the range and the first term; `points` is the seek
     * column of the index after the equality prefix.
     */
    private function keyset(Builder $query, BoardCursor $c, string $range, string $cmp, string $idCmp): Builder
    {
        $tail = static function (Builder $q) use ($c, $cmp, $idCmp): void {
            $q->where('sc.active_days', $cmp, $c->activeDays)
                ->orWhere(static fn (Builder $q): Builder => $q->where('sc.active_days', $c->activeDays)->where('sc.learner_id', $idCmp, $c->learnerId));
        };

        return $query->where('sc.points', $range, $c->points)->where(static function (Builder $q) use ($c, $cmp, $tail): void {
            $q->where('sc.points', $cmp, $c->points)
                ->orWhere('sc.long_words', $cmp, $c->longWords)
                ->orWhere(static fn (Builder $q): Builder => $q->where('sc.long_words', $c->longWords)->where($tail));
        });
    }

    /** A row of a read as a board row (rank 0: given by {@see ranked()}); the name only when the learner is not deleted. */
    private function row(object $row): BoardRow
    {
        $data = get_object_vars($row);
        $name = $data['first_name'] ?? null;

        return new BoardRow(
            learnerId: (int) $data['learner_id'],
            name: ($data['deleted_at'] ?? null) === null && is_string($name) && $name !== '' ? $name : null,
            points: (int) $data['points'],
            longWords: (int) $data['long_words'],
            activeDays: (int) $data['active_days'],
            rank: 0,
        );
    }

    /**
     * Places of consecutive rows in the order of the table: the first gets `$firstRank` at `$firstPosition`; a row equal to the one
     * before shares its place, any other gets its position.
     *
     * @param  array<int, object>  $rows  consecutive rows (query rows or board rows)
     * @return list<BoardRow>
     */
    private function ranked(array $rows, int $firstRank, int $firstPosition): array
    {
        $out = [];
        $previous = null;
        foreach (array_values($rows) as $i => $row) {
            $current = $row instanceof BoardRow ? $row : $this->row($row);
            $rank = match (true) {
                $previous === null => $firstRank,
                $previous->points === $current->points && $previous->longWords === $current->longWords && $previous->activeDays === $current->activeDays => $previous->rank,
                default => $firstPosition + $i,
            };
            $current = new BoardRow($current->learnerId, $current->name, $current->points, $current->longWords, $current->activeDays, $rank);
            $out[] = $current;
            $previous = $current;
        }

        return $out;
    }
}
