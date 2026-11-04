<?php

declare(strict_types=1);

namespace App\Domain\Game\Services\Runs;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Game\DTO\RunSnapshot;
use App\Domain\Game\DTO\RunState;
use App\Domain\Game\Enums\AttemptKind;
use App\Domain\Game\Enums\BoosterEffect;
use App\Domain\Game\Enums\ItemKind;
use App\Domain\Game\Exceptions\GameException;
use App\Domain\Game\Services\GameLimits;
use App\Domain\Game\Services\Tasks\TaskRegistry;
use App\Domain\Game\Support\GameWeek;
use App\Domain\Learners\Services\LearnerClock;
use App\Domain\Learners\Support\StoredDate;
use App\Domain\Practice\Support\JsonbKeyOrder;
use App\Models\GameBoosterUse;
use App\Models\GameInventoryItem;
use App\Models\GameItem;
use App\Models\GameRun;
use App\Models\GameRunAttempt;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The boosters of a run (docs/18 §2, §12.5.2, §12.8; G2.5b part 2). {@see use()} is the body of `RunService::booster` under the lock of
 * the run (`game_runs FOR UPDATE`, taken by the caller); the order of the rest is the one of docs/18 §12.5.1:
 *
 *   `game_inventory` (the learner's own row, an atomic `qty - 1 where qty > 0`) -> `game_booster_uses` INSERT -> `game_run_attempts`
 *   INSERT -> `game_runs` UPDATE.
 *
 * The checks come first and write nothing, so a refusal costs nothing: the item (a booster), the state of the run (the element of a
 * hint; a flask / a second chance in a run that has flasks), one booster of a kind per element / per run, `qty > 0`, the limit of the
 * week ({@see GameLimits::assertBoosterAllowed}, decided under the lock of the run: the runs of a learner are serialised). The unique
 * `(run_id, item_id, element_id)` of `game_booster_uses` is the net below: the state of the run says the same, under the same lock.
 *
 * Effects: `hint` - the hint of the element in the form of its kind, as the bazaar gives it but without coins; `extra_flask` - one
 * flask more and the maximum raised to `game.run.flasks_max_with_booster`; `second_chance` - the first mistake of the run costs no flask
 * ({@see RunScoring}). What was used lives in `state.boosters.used`, so the view of a run reads it without a query.
 */
final class BoosterService
{
    public function __construct(
        private readonly TaskRegistry $tasks,
        private readonly GameLimits $limits,
        private readonly LearnerClock $clock,
        private readonly ProductEventRecorder $events,
    ) {}

    /**
     * The booster `$itemKey` for the run (locked, active, not expired). Saves the run; the caller builds the view.
     *
     * @throws GameException item_not_found | unknown_element_id | element_not_current | answer_already_accepted | hint_not_available |
     *                       booster_already_used | booster_not_applicable | booster_not_owned | booster_limit_reached
     */
    public function use(GameRun $run, Learner $learner, string $requestId, string $itemKey, ?string $elementId, CarbonImmutable $now): void
    {
        $item = GameItem::query()->where('key', $itemKey)->where('kind', ItemKind::Booster->value)->first();
        if ($item === null || $item->effect === null) {
            throw GameException::itemNotFound();
        }
        $effect = $item->effect;

        $snapshot = RunSnapshot::fromArray($run->snapshot);
        $state = RunState::fromArray($run->state);
        $current = $snapshot->at($state->current) ?? throw GameException::runClosed('');

        $target = null; // the element of a hint
        $hint = null;
        if ($effect === BoosterEffect::Hint) {
            $target = $elementId === null ? $current : ($snapshot->element($elementId) ?? throw GameException::unknownElementId());
            if ($target->id !== $current->id) {
                throw GameException::elementNotCurrent($current->id);
            }
            if ($state->isAnswered($target->id)) {
                throw GameException::answerAlreadyAccepted((string) $state->verdictOf($target->id)?->value);
            }
            if ($state->hasUsedBooster($effect->value, $target->id)) {
                throw GameException::boosterAlreadyUsed();
            }
            $hint = $state->hintOf($target->id) !== null ? null : $this->tasks->forElement($target)->hint($target, $state);
            if ($hint === null) {
                throw GameException::hintNotAvailable();
            }
        } else {
            if ($state->hasUsedBooster($effect->value, null)) {
                throw GameException::boosterAlreadyUsed();
            }
            if ($run->flasks_max <= 0 || ($effect === BoosterEffect::ExtraFlask && $run->flasks_max >= $this->maxWithBooster())) {
                throw GameException::boosterNotApplicable();
            }
        }

        $owned = GameInventoryItem::query()->where('learner_id', $learner->id)->where('item_id', $item->id)->where('qty', '>', 0)->exists();
        if (! $owned) {
            throw GameException::boosterNotOwned();
        }
        $this->limits->assertBoosterAllowed($learner, $now);

        // The atomic spend: the guard `qty > 0` leaves 0 rows to a last one that went elsewhere in the meantime.
        $spent = GameInventoryItem::query()->where('learner_id', $learner->id)->where('item_id', $item->id)->where('qty', '>', 0)->decrement('qty');
        if ($spent === 0) {
            throw GameException::boosterNotOwned();
        }

        GameBoosterUse::query()->create([
            'learner_id' => $learner->id,
            'run_id' => $run->id,
            'item_id' => $item->id,
            'element_id' => $target?->id,
            'used_at' => $now,
            'week_start' => StoredDate::of(GameWeek::start($this->clock->today($learner, $now))->toDateString()),
        ]);

        $updates = [];
        if ($target !== null) {
            $state->markHinted($target->id, $hint);
        } elseif ($effect === BoosterEffect::ExtraFlask) {
            $state->extraFlask = true;
            $state->flasks = min($state->flasks + 1, $this->maxWithBooster());
            $updates = ['flasks' => $state->flasks, 'flasks_max' => $this->maxWithBooster()];
        } else {
            $state->secondChanceLeft = 1;
        }
        $state->boostersUsed[] = ['key' => $item->key, 'effect' => $effect->value, 'element_id' => $target?->id];

        GameRunAttempt::query()->create([
            'run_id' => $run->id,
            'learner_id' => $learner->id,
            'request_id' => $requestId,
            'element_id' => $current->id,
            'kind' => AttemptKind::Booster,
            'payload' => ['item_key' => $item->key, 'effect' => $effect->value],
            'verdict' => null,
            'is_first' => false,
            'result' => JsonbKeyOrder::object(['effect' => $effect->value, 'hint' => $hint]),
            'time_spent_ms' => null,
            'accepted_at' => $now,
        ]);

        $run->forceFill([...$updates, 'state' => $state->toArray()])->save();

        DB::afterCommit(fn () => $this->events->record(ProductEventName::GameBoosterUsed, ['run_id' => $run->id, 'effect' => $effect->value], learner: $learner));
    }

    /**
     * The boosters of the learner's inventory (`qty > 0`) in the order of the shop - one statement.
     *
     * @return list<array{key: string, effect: string, qty: int}>
     */
    public function available(int $learnerId): array
    {
        $rows = DB::table('game_inventory')
            ->join('game_items', 'game_items.id', '=', 'game_inventory.item_id')
            ->where('game_inventory.learner_id', $learnerId)
            ->where('game_items.kind', ItemKind::Booster->value)
            ->where('game_inventory.qty', '>', 0)
            ->orderBy('game_items.sort')
            ->orderBy('game_items.id')
            ->get(['game_items.key', 'game_items.effect', 'game_inventory.qty']);

        $available = [];
        foreach ($rows as $row) {
            $available[] = ['key' => (string) $row->key, 'effect' => (string) $row->effect, 'qty' => (int) $row->qty];
        }

        return $available;
    }

    private function maxWithBooster(): int
    {
        return max(1, (int) config('game.run.flasks_max_with_booster', 4));
    }
}
