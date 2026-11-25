<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Engagement\DTO\MonthlyMeView;
use Illuminate\Http\Request;

/**
 * GET /api/twa/league/month/me (docs/04 A.19): the viewer's own line of the current month, nothing of anybody else.
 *
 * @property MonthlyMeView $resource
 */
final class MonthlyRatingMeResource extends TwaResource
{
    /**
     * @return array{
     *   enabled: bool,
     *   month: array{starts_on: string, ends_on: string, days_left: int},
     *   me: array{rank: int|null, points: int, active_days: int, participant: bool, eligible: bool, missing: array{active_days: int, account_days: int}, not_counted_reason: 'opted_out'|'blocked'|null}|null
     * }
     */
    public function toArray(Request $request): array
    {
        $view = $this->resource;

        return [
            'enabled' => $view->enabled,
            'month' => ['starts_on' => $view->startsOn, 'ends_on' => $view->endsOn, 'days_left' => $view->daysLeft],
            'me' => $view->me?->toArray(),
        ];
    }
}
