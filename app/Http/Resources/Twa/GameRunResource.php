<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Game\DTO\RunView;
use Illuminate\Http\Request;

/**
 * A run (docs/18 §12.8, docs/04 A.18): `GET /game/runs/{id}`, the 201 of `POST /game/runs`, `abandon`, and the `run` of an answer / a hint.
 *
 * A white list. The current element comes from the `project()` of its kind - the ONLY way an element reaches a response - and is
 * cleaned once more of the keys that live in the snapshot only: should a kind ever put one into its projection, it would not leave the
 * server (`GameContractTest` lists the same keys). `boosters` are empty lists until G2.5b: the shape is final, the state is honest.
 *
 * @property RunView $resource
 */
final class GameRunResource extends TwaResource
{
    /** Keys of the snapshot that a client never gets in `current`: the expected answers, the ids of the database, the audio path, the text of a dictation. */
    public const NEVER_SHOWN = [
        'pairs', 'correct_option_id', 'correct_ids', 'accepted', 'accepted_display', 'expected', 'correct_text', 'full_text', 'display',
        'audio_path', 'audio_disk', 'word_id', 'version_id', 'item_id', 'target_word_ids', 'word', 'pool', 'seed',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $view = $this->resource;

        return [
            'id' => $view->id,
            'kind' => $view->kind->value,
            'status' => $view->status->value,
            'finish_reason' => $view->finishReason?->value,
            'course' => $view->courseSlug === null ? null : ['slug' => $view->courseSlug],
            'lesson_id' => $view->lessonId,
            'city' => $view->city,
            'stage_number' => $view->stageNumber,
            'lesson_number' => $view->lessonNumber,
            'lesson_title' => $view->lessonTitle,
            'scope_widened' => $view->scopeWidened,
            'total' => $view->total,
            'position' => $view->position,
            'flasks' => $view->flasks,
            'flasks_max' => $view->flasksMax,
            'combo' => $view->combo,
            'score' => $view->score,
            'current' => $view->current === null ? null : [
                'element' => self::clean($view->current['element']),
                'event' => $view->current['event'],
            ],
            'boosters' => ['available' => [], 'used' => []],
            'hint' => $view->hint,
            'summary' => $view->summary,
            'server_now' => $view->serverNow,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function clean(array $value): array
    {
        $clean = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array($key, self::NEVER_SHOWN, true)) {
                continue;
            }
            $clean[$key] = is_array($item) ? self::clean($item) : $item;
        }

        return $clean;
    }
}
