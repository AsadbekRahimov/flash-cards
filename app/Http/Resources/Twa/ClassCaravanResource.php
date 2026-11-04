<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Game\DTO\ClassCaravanView;
use Illuminate\Http\Request;

/**
 * GET /api/twa/game/class/{id} (docs/18 §12.8, docs/04 A.18): one caravan of the learner's class with the table of contributions, the
 * learner's own row and, once the week is over, the summary. The classmates have a first name and the `is_me` mark - no ids.
 *
 * @property ClassCaravanView $resource
 *
 * @phpstan-import-type Boss from ClassCaravanView
 * @phpstan-import-type Member from ClassCaravanView
 * @phpstan-import-type My from ClassCaravanView
 * @phpstan-import-type Summary from ClassCaravanView
 */
final class ClassCaravanResource extends TwaResource
{
    /**
     * @return array{
     *   id: int, group_title: string, course: array{slug: string, title: string}, status: string,
     *   starts_at: string, ends_at: string, stars_total: int, target_stars: int, runs_total: int,
     *   boss: Boss, members: list<Member>, my: My|null, summary: Summary|null
     * }
     */
    public function toArray(Request $request): array
    {
        $view = $this->resource;

        return [
            ...ClassCaravanListResource::row($view),
            'members' => $view->members ?? [],
            'my' => $view->my,
            'summary' => $view->summary,
        ];
    }
}
