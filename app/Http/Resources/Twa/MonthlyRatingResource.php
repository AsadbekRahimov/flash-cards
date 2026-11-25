<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Engagement\DTO\BoardPageCursor;
use App\Domain\Engagement\DTO\BoardRow;
use App\Domain\Engagement\DTO\MonthlyPrize;
use App\Domain\Engagement\DTO\MonthlyRatingOverview;
use App\Domain\Engagement\DTO\MonthlyStanding;
use App\Domain\Engagement\Enums\ScoreCategory;
use App\Models\Learner;
use Illuminate\Http\Request;

/**
 * GET /api/twa/league/month (docs/04 A.19). Of other learners only a first name (may be null), the points, the active days and the
 * emoji of Pro (`pro_emoji`, docs/31 §5а: only while their Pro is live, else null) leave the server — no ids, usernames or Telegram
 * data. The prize titles come in the interface locale.
 *
 * A month of the word points v2 (docs/32 §8.3, S12) keeps every key of v1 and adds: `rules_version: 2`, `category` (the table shown:
 * `?category=` or the viewer's own), `my_category`, `read_only` (the other table: no place of the viewer in it), `next_cursor` (the
 * page after the top, `?after=`), `neighbours` (two above and two below the viewer's row in their own table, else null), and in the
 * rows `long_words`; `me` adds `category`, `long_words`, `next_gap`; `month.participants` and `prizes` are those of the table shown;
 * `last_month` of a v2 month splits its winners by table (`winners` - the table shown, `winners_by_category`). A v1 month keeps the
 * shape of before: those keys are absent.
 *
 * @property MonthlyRatingOverview $resource
 */
final class MonthlyRatingResource extends TwaResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $overview = $this->resource;
        $locale = (string) app()->getLocale();
        $myId = $this->learnerId($request);
        $category = $overview->category;
        $month = $overview->month;
        $v2 = $category !== null && $month !== null;

        $payload = [
            'enabled' => $overview->enabled,
            'opted_out' => $overview->optedOut,
            'month' => [
                'starts_on' => $overview->startsOn,
                'ends_on' => $overview->endsOn,
                'closes_at' => $overview->closesAt,
                'days_left' => $overview->daysLeft,
                'status' => $overview->month?->status,
                'participants' => $overview->participantsCount,
            ],
            'rules' => $overview->rules,
            'prizes' => array_map(
                static fn (MonthlyPrize $prize): array => ['place' => $prize->place, 'title' => $prize->title($locale)],
                $overview->prizes,
            ),
            'top' => array_map(
                static fn (MonthlyStanding $row): array => [
                    'rank' => $row->rank,
                    'name' => $row->name,
                    'points' => $row->points,
                    'active_days' => $row->activeDays,
                    'is_me' => $row->learnerId === $myId,
                    'pro_emoji' => $row->proEmoji,
                    ...($v2 ? ['long_words' => $row->longWords] : []),
                ],
                $overview->top,
            ),
            'me' => $overview->me === null ? null : [
                ...$overview->me->toArray(),
                ...($v2 ? [
                    'category' => $overview->me->category?->value,
                    'long_words' => $overview->me->longWords,
                    'next_gap' => $overview->neighbours['next_gap'] ?? null,
                ] : []),
            ],
            'last_month' => $this->lastMonth($category),
        ];

        if ($category === null || $month === null) {
            return $payload;
        }

        $neighbours = $overview->neighbours;

        return [
            ...$payload,
            'rules_version' => $overview->rulesVersion,
            'category' => $category->value,
            'my_category' => $overview->myCategory?->value,
            'read_only' => $category !== $overview->myCategory,
            'next_cursor' => $overview->nextCursor === null ? null : BoardPageCursor::encode($month->id, $category, $overview->nextCursor),
            'neighbours' => $neighbours === null ? null : [
                'above' => array_map(static fn (BoardRow $row): array => LeagueBoardResource::row($row, $myId), $neighbours['above']),
                'below' => array_map(static fn (BoardRow $row): array => LeagueBoardResource::row($row, $myId), $neighbours['below']),
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private function lastMonth(?ScoreCategory $shown): ?array
    {
        $last = $this->resource->lastMonth;
        if ($last === null) {
            return null;
        }

        $out = [
            'starts_on' => $last->startsOn,
            'ends_on' => $last->endsOn,
            'status' => $last->status,
            'winners' => $last->winners,
            'me' => $last->me,
        ];
        if ($last->rulesVersion < 2) {
            return $out;
        }

        // A v2 month had two tables: `winners` of the table shown now (the viewer's own by default), every table under its category.
        $byCategory = ['free' => [], 'pro' => []];
        foreach ($last->winners as $winner) {
            $key = ($winner['category'] ?? null) === ScoreCategory::Pro->value ? 'pro' : 'free';
            $byCategory[$key][] = ['place' => $winner['place'], 'name' => $winner['name'], 'points' => $winner['points']];
        }

        return [...$out, 'winners' => $byCategory[($shown ?? ScoreCategory::Free)->value], 'winners_by_category' => $byCategory];
    }

    private function learnerId(Request $request): int
    {
        $learner = $request->attributes->get('learner');

        return $learner instanceof Learner ? $learner->id : 0;
    }
}
