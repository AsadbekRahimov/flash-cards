<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Practice\DTO\BattleOptionsView;
use Illuminate\Http\Request;

/**
 * GET /api/twa/practice/battles/options (docs/16 §7.3): what a learner may invite a friend to - the friend (a first name), the
 * course and lesson, each format with its state for the material of BOTH players' languages and the time of a round, and whether
 * the friend can practice at all.
 *
 * @property BattleOptionsView $resource
 */
final class BattleOptionsResource extends TwaResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $options = $this->resource;

        return [
            'friend' => $options->friend,
            'course' => $options->course,
            'lesson' => $options->lesson,
            'modes' => $options->modes,
            'friend_access' => $options->friendAccess,
        ];
    }
}
