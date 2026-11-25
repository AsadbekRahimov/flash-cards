<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa\Cup;

use App\Domain\Cup\DTO\CupRoundView;
use App\Domain\Game\Enums\TaskKind;
use App\Http\Resources\Twa\TwaResource;
use Illuminate\Http\Request;

/**
 * The attempt of the day (docs/04 A.23 `CupRoundResource`): `POST /cups/{id}/rounds/today` (201 new / 200 the one already open) and
 * `GET /cup-rounds/{attempt}`. It is {@see CupRoundView} as the core built it - the current task is the projection of the game (no
 * right answer, no word, no id of the database, the options in the participant's own order); `review` (the right answers) only once
 * the attempt is over. A `listen` task gets `audio_url`: the clip of its element through the route of the attempt, never a path.
 *
 * @property CupRoundView $resource
 */
final class CupRoundResource extends TwaResource
{
    public const AUDIO_ROUTE = 'twa.cup-rounds.audio';

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return self::shape($this->resource);
    }

    /** @return array<string, mixed> */
    public static function shape(CupRoundView $view): array
    {
        $round = $view->toArray();
        if (is_array($round['current'])) {
            $round['current'] = self::withAudio($view->attemptId, $round['current']);
        }
        if (is_array($round['review'])) {
            $review = [];
            foreach ($round['review'] as $entry) {
                $review[] = is_array($entry) ? self::withAudio($view->attemptId, $entry, 'element_id') : $entry;
            }
            $round['review'] = $review;
        }

        return $round;
    }

    /**
     * @param  array<string, mixed>  $task
     * @return array<string, mixed>
     */
    private static function withAudio(int $attemptId, array $task, string $idKey = 'id'): array
    {
        $id = $task[$idKey] ?? null;
        if (($task['kind'] ?? null) === TaskKind::Listen->value && is_string($id)) {
            $task['audio_url'] = route(self::AUDIO_ROUTE, ['attempt' => $attemptId, 'element' => $id], false);
        }

        return $task;
    }
}
