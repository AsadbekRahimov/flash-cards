<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Game\Exceptions\GameException;
use App\Models\GameCityProgress;
use App\Models\Learner;
use Carbon\CarbonImmutable;

/**
 * The mark "the learner opened the share dialog of the card of a city" (docs/18 §12.2, docs/04 A.18): `game_city_progress.shared_at`, set
 * once, with the event `game.city_shared`; a repeat changes nothing. Only a city whose boss the learner has beaten has a card: any other
 * stage (no row, a fight that was never won, someone else's row) is 404 `stage_not_found` - nothing says which of them it is.
 */
final class CityShareService
{
    public function __construct(
        private readonly GameAccess $access,
        private readonly ProductEventRecorder $events,
    ) {}

    /**
     * @throws GameException game_unavailable | stage_not_found
     */
    public function markShared(Learner $learner, int $stageId, CarbonImmutable $now): GameCityProgress
    {
        $this->access->assertAvailable($learner);

        $progress = GameCityProgress::query()
            ->where('learner_id', $learner->id)
            ->where('stage_id', $stageId)
            ->whereNotNull('boss_defeated_at')
            ->first();
        if ($progress === null) {
            throw GameException::stageNotFound();
        }

        // The conditional update decides a race of two taps: only the one that sets the mark writes the event.
        $set = GameCityProgress::query()->whereKey($progress->id)->whereNull('shared_at')->update(['shared_at' => $now, 'updated_at' => $now]);
        if ($set > 0) {
            $progress->shared_at = $now;
            $this->events->record(ProductEventName::GameCityShared, ['stage_id' => $stageId], learner: $learner);
        } else {
            $progress->refresh();
        }

        return $progress;
    }
}
