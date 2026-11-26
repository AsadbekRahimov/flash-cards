<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Engagement\DTO\AllTimeOverview;
use App\Domain\Engagement\DTO\BoardPageCursor;
use App\Domain\Engagement\DTO\BoardRow;
use App\Domain\Engagement\DTO\RegionChoice;
use App\Models\Learner;
use Illuminate\Http\Request;

/**
 * GET /api/twa/league/all-time (docs/04 A.19a, docs/32 §8.3, §12.11) - in the form of {@see LeagueBoardResource}:
 *
 *   enabled, period (`all_time`), rules_version (2), counted_since (`Y-m-d` - the start of the points v2: "учёт с …"),
 *   category (the table shown), my_category (the viewer's: of their current month, else of their plan), read_only (the viewer has no
 *   place in the table shown), scope (`all` / `region`), region ({code, name, participants, shown, min_participants} | null),
 *   participants, top (rows as LeagueBoardResource::row), next_cursor, neighbours ({above, below} | null),
 *   me ({category, rank, points, long_words, active_days, participant, not_counted_reason, first_day, last_day, next_gap,
 *       region: {code, name, participants, shown, min_participants, rank} | null} | null - the viewer's row of the category shown),
 *   my_total ({points, free, pro}: the personal sum over both categories; the opt-out does not hide it from its owner).
 *
 * Not `live` (the word points off or in `shadow`): `{enabled: false, period: all_time}` only. Of other learners only the first name,
 * the points, the tie-break facts and the emoji of Pro leave the server; a region of another learner never does.
 *
 * @property AllTimeOverview $resource
 */
final class AllTimeRatingResource extends TwaResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $o = $this->resource;
        if (! $o->enabled || $o->category === null) {
            return ['enabled' => false, 'period' => 'all_time'];
        }

        $locale = (string) app()->getLocale();
        $learner = $request->attributes->get('learner');
        $myId = $learner instanceof Learner ? $learner->id : 0;
        $row = static fn (BoardRow $r): array => LeagueBoardResource::row($r, $myId);
        $me = $o->me;

        return [
            'enabled' => true,
            'period' => 'all_time',
            'rules_version' => 2,
            'counted_since' => $o->countedSince,
            'category' => $o->category->value,
            'my_category' => $o->myCategory?->value,
            'read_only' => $o->readOnly,
            'opted_out' => $o->optedOut,
            'scope' => ($o->choice ?? RegionChoice::all())->scope(),
            'region' => $o->region?->toArray($locale),
            'participants' => $o->participants,
            'top' => array_map($row, $o->top),
            'next_cursor' => $o->nextCursor === null ? null : BoardPageCursor::encodeAllTime($o->category, $o->nextCursor, $o->region?->code),
            'neighbours' => $o->neighbours === null ? null : [
                'above' => array_map($row, $o->neighbours['above']),
                'below' => array_map($row, $o->neighbours['below']),
            ],
            'me' => $me === null ? null : [
                'category' => $me->category->value,
                'rank' => $me->rank,
                'points' => $me->points,
                'long_words' => $me->longWords,
                'active_days' => $me->activeDays,
                'participant' => $me->participant,
                'not_counted_reason' => $me->notCountedReason,
                'first_day' => $me->firstDay,
                'last_day' => $me->lastDay,
                'next_gap' => $me->nextGap,
                'region' => $me->region?->toMeArray($locale),
            ],
            'my_total' => [
                'points' => $o->myTotal,
                'free' => $o->byCategory['free'] ?? null,
                'pro' => $o->byCategory['pro'] ?? null,
            ],
        ];
    }
}
