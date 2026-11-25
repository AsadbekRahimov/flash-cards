<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Engagement\DTO\BoardRow;
use App\Domain\Engagement\DTO\MonthlyBoardPage;
use App\Models\Learner;
use Illuminate\Http\Request;

/**
 * A page of a table of the word points v2 after a cursor - `GET /api/twa/league/month?category=&after=` (docs/04 A.19, docs/32 §5.3):
 * `{rules_version, category, read_only, rows, next_cursor}`. A row carries the competition place, the first name (may be null), the
 * points, the tie-break facts, `is_me` and the emoji of Pro; nothing else of other people (no ids, usernames, Telegram data).
 * `next_cursor` - the `?after=` of the next page, null on the last one. A page of "all time" (`GET /league/all-time?after=`) adds
 * `period: all_time`, `scope`, `region`; a page of a region of a month adds `scope: region`, `region` ({code, name, participants, shown,
 * min_participants}; a hidden region - no rows).
 *
 * @property MonthlyBoardPage $resource
 */
final class LeagueBoardResource extends TwaResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $page = $this->resource;
        $learner = $request->attributes->get('learner');
        $myId = $learner instanceof Learner ? $learner->id : 0;

        $payload = [
            'rules_version' => 2,
            'category' => $page->category->value,
            'read_only' => $page->readOnly,
            'rows' => array_map(static fn (BoardRow $row): array => self::row($row, $myId), $page->rows),
            'next_cursor' => $page->nextCursor(),
        ];

        // "All time" and the tables of a region (docs/32 §12.11, docs/35 §2.4) name their table; a page of a whole category of a month
        // keeps the shape of S12.
        if ($page->allTime) {
            $payload['period'] = 'all_time';
        }
        if ($page->allTime || $page->region !== null) {
            $payload['scope'] = $page->region === null ? 'all' : 'region';
            $payload['region'] = $page->region?->toArray((string) app()->getLocale());
        }

        return $payload;
    }

    /** @return array{rank: int, name: string|null, points: int, long_words: int, active_days: int, is_me: bool, pro_emoji: string|null} */
    public static function row(BoardRow $row, int $myId): array
    {
        return [
            'rank' => $row->rank,
            'name' => $row->name,
            'points' => $row->points,
            'long_words' => $row->longWords,
            'active_days' => $row->activeDays,
            'is_me' => $row->learnerId === $myId,
            'pro_emoji' => $row->proEmoji,
        ];
    }
}
