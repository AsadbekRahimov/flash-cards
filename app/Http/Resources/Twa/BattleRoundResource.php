<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Practice\DTO\RoundView;
use App\Domain\Practice\Support\IsoTime;
use Illuminate\Http\Request;

/**
 * One round of a battle (docs/16 §7.4): GET /practice/battles/{id}/round?no=. The element comes from the `project()` of its mode in
 * the language of the viewer - the ONLY way an element reaches a response - and is cleaned once more of the keys that live in the
 * snapshot only ({@see PracticeSessionResource::NEVER_SHOWN}, as in the solo session): should a mode ever put one into its
 * projection, it would not leave the server.
 *
 * `reveal` is the review (the right answer, both players' answers, the explanation) and is NOT cleaned: it exists only for a round
 * that is resolved, and its keys (`pairs`, `expected`, `full_text`) are the answer being shown. Before that it is `null` - the
 * right answer and the opponent's are never on the device earlier.
 *
 * @property RoundView $resource
 */
final class BattleRoundResource extends TwaResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $view = $this->resource;

        return [
            'no' => $view->no,
            'phase' => $view->phase,
            'deadline_at' => $view->deadlineAt === null ? null : IsoTime::format($view->deadlineAt),
            'phase_ends_at' => $view->phaseEndsAt === null ? null : IsoTime::format($view->phaseEndsAt),
            'server_now' => IsoTime::format($view->serverNow),
            'element' => self::clean($view->element),
            'reveal' => $view->reveal,
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
            if (is_string($key) && in_array($key, PracticeSessionResource::NEVER_SHOWN, true)) {
                continue;
            }
            $clean[$key] = is_array($item) ? self::clean($item) : $item;
        }

        return $clean;
    }
}
