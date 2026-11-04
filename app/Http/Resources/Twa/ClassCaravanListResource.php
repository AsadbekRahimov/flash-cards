<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Game\DTO\ClassCaravanView;
use Illuminate\Http\Request;

/**
 * GET /api/twa/game/class (docs/18 §12.8, docs/04 A.18): the caravans of the learner's classes, the newest first, as short rows (the
 * table of contributions is in `GET /game/class/{id}`).
 *
 * @property list<ClassCaravanView> $resource
 *
 * @phpstan-import-type Boss from ClassCaravanView
 */
final class ClassCaravanListResource extends TwaResource
{
    /**
     * @return array{caravans: list<array{
     *   id: int, group_title: string, course: array{slug: string, title: string}, status: string,
     *   starts_at: string, ends_at: string, stars_total: int, target_stars: int, runs_total: int, boss: Boss
     * }>}
     */
    public function toArray(Request $request): array
    {
        return ['caravans' => array_map(self::row(...), $this->resource)];
    }

    /**
     * @return array{
     *   id: int, group_title: string, course: array{slug: string, title: string}, status: string,
     *   starts_at: string, ends_at: string, stars_total: int, target_stars: int, runs_total: int, boss: Boss
     * }
     */
    public static function row(ClassCaravanView $view): array
    {
        return [
            'id' => $view->id,
            'group_title' => $view->groupTitle,
            'course' => $view->course,
            'status' => $view->status,
            'starts_at' => $view->startsAt,
            'ends_at' => $view->endsAt,
            'stars_total' => $view->starsTotal,
            'target_stars' => $view->targetStars,
            'runs_total' => $view->runsTotal,
            'boss' => $view->boss,
        ];
    }
}
