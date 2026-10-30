<?php

declare(strict_types=1);

namespace App\Domain\Practice\Services\Battle;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Practice\Enums\BattleEndReason;
use App\Domain\Practice\Enums\BattleStatus;
use App\Domain\Practice\Services\PracticeAccess;
use App\Models\Battle;
use App\Models\BattleParticipant;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The sweeper of the battles (`practice:battles-sweep`, every minute; docs/16 §8.6):
 *
 *  1. the invitations past `invite_expires_at` -> closed / invite_expired, both players let go, one `battle.closed` each.
 *     Each row is claimed by a conditional UPDATE (`status = invited` still), the way `practice:close-expired` claims a
 *     session: an invitation accepted a moment earlier is left alone and raises nothing; the participants of the claimed
 *     rows are released by one statement;
 *  2. every match that is on (room, countdown, playing) is advanced under `FOR UPDATE` of its own row, one transaction
 *     each (`BattleClock::advance`: absence, deadlines, the end) - nothing waits under more than one row;
 *  3. with `PRACTICE_ACCESS=off` the matches that are on are voided instead (`closed / voided`, `meta.voided_reason = practice_off`).
 *
 * Idempotent: a repeated or an overlapping run finds nothing to do. Returns how many battles this run changed.
 */
final class BattleJanitor
{
    public function __construct(
        private readonly BattleClock $clock,
        private readonly PracticeAccess $access,
        private readonly ProductEventRecorder $events,
    ) {}

    public function sweep(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();

        return $this->expireInvitations($now) + $this->advanceLive($now);
    }

    private function expireInvitations(CarbonImmutable $now): int
    {
        /** @var list<array{id: int, challenger_id: int}> $expired */
        $expired = Battle::query()
            ->where('status', BattleStatus::Invited->value)
            ->where('invite_expires_at', '<=', $now)
            ->orderBy('id')
            ->get(['id', 'challenger_id'])
            ->map(fn (Battle $battle): array => ['id' => $battle->id, 'challenger_id' => $battle->challenger_id])
            ->all();
        if ($expired === []) {
            return 0;
        }

        return DB::transaction(function () use ($expired, $now): int {
            $closed = [];
            foreach ($expired as $row) {
                $claimed = Battle::query()
                    ->whereKey($row['id'])
                    ->where('status', BattleStatus::Invited->value)
                    ->increment('state_version', 1, [
                        'status' => BattleStatus::Closed->value,
                        'end_reason' => BattleEndReason::InviteExpired->value,
                        'finished_at' => $now,
                        'updated_at' => $now,
                    ]) === 1;
                if ($claimed) {
                    $closed[] = $row;
                }
            }
            if ($closed === []) {
                return 0;
            }

            BattleParticipant::query()->whereIn('battle_id', array_column($closed, 'id'))->update(['active_learner_id' => null, 'updated_at' => $now]);

            DB::afterCommit(function () use ($closed): void {
                $challengers = Learner::query()->whereIn('id', array_unique(array_column($closed, 'challenger_id')))->get()->keyBy('id');
                foreach ($closed as $row) {
                    $this->events->record(
                        ProductEventName::BattleClosed,
                        ['battle_id' => $row['id'], 'end_reason' => BattleEndReason::InviteExpired->value],
                        learner: $challengers->get($row['challenger_id']),
                    );
                }
            });

            return count($closed);
        });
    }

    private function advanceLive(CarbonImmutable $now): int
    {
        $off = $this->access->isOff();
        $changed = 0;

        Battle::query()
            ->whereIn('status', [BattleStatus::Room->value, BattleStatus::Countdown->value, BattleStatus::Playing->value])
            ->lazyById(100, 'id')
            ->each(function (Battle $row) use ($now, $off, &$changed): void {
                $changed += DB::transaction(function () use ($row, $now, $off): int {
                    /** @var Battle|null $battle */
                    $battle = Battle::query()->whereKey($row->id)->lockForUpdate()->first();
                    if ($battle === null || ! $battle->status->isLive()) {
                        return 0;
                    }
                    $battle->load('participants');

                    if ($off) {
                        $this->clock->close($battle, BattleEndReason::Voided, $now, [BattleClock::META_VOIDED_REASON => BattleClock::VOIDED_PRACTICE_OFF]);

                        return 1;
                    }

                    return $this->clock->advance($battle, $now)->changed ? 1 : 0;
                });
            });

        return $changed;
    }
}
