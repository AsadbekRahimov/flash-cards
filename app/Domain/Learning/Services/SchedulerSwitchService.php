<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Learning\Enums\SchedulerKind;
use App\Models\AuditLog;
use App\Models\Learner;
use App\Models\User;
use App\Models\WordRepetition;
use Illuminate\Support\Facades\DB;

/**
 * Admin switch of one learner between the arms of the SRS A/B (docs/13 §8.3): writes
 * `learners.scheduler`, one audit record per real change (`learner.scheduler_changed`,
 * meta `{from, to, initialized_cards}`) and, when the target is FSRS, seeds the FSRS memory of the
 * learner's graduated cards that never had one (docs/06 §9.4): stability = max(1, interval_days),
 * difficulty = clamp(1..10, 5 + (2.5 − EF) · 4 / 1.2). Cards still in minute steps get their memory
 * when they graduate; cards already carrying FSRS memory (an earlier FSRS period) keep it.
 *
 * Switching back to SM-2 touches no card: FSRS never changes the SM-2 columns.
 *
 * Lock order: `learners` FOR UPDATE, then `word_repetitions` — the same order as CardPicker
 * (docs/06 §4.2); the review transaction never waits on `learners`, so no cycle is possible.
 */
final class SchedulerSwitchService
{
    public const ACTION_CHANGED = 'learner.scheduler_changed';

    private const CHUNK = 500;

    public function __construct(private readonly SchedulerResolver $resolver) {}

    /** @return bool whether the learner was switched by this call (false: already on that scheduler) */
    public function switchTo(Learner $learner, SchedulerKind $to, User $actor, ?string $ip = null): bool
    {
        return DB::transaction(function () use ($learner, $to, $actor, $ip): bool {
            $lock = Learner::query()->whereKey($learner->id);
            // `FOR NO KEY UPDATE` on PG: the FK checks of concurrent inserts (reviews, repetitions) only take
            // `FOR KEY SHARE`, which does not conflict with it (a plain FOR UPDATE would deadlock with them).
            /** @var Learner|null $current */
            $current = (DB::connection()->getDriverName() === 'pgsql' ? $lock->lock('for no key update') : $lock->lockForUpdate())->first();

            if ($current === null) {
                return false;
            }

            $from = $this->resolver->kindFor($current);
            if ($from === $to) {
                $learner->setRawAttributes($current->getAttributes(), true);

                return false;
            }

            $current->update(['scheduler' => $to]);

            $initialized = $to === SchedulerKind::Fsrs ? $this->seedFsrsMemory($current) : 0;

            AuditLog::create([
                'user_id' => $actor->id,
                'action' => self::ACTION_CHANGED,
                'entity_type' => Learner::class,
                'entity_id' => $current->id,
                'ip' => $ip,
                'meta' => ['from' => $from->value, 'to' => $to->value, 'initialized_cards' => $initialized],
            ]);

            $learner->setRawAttributes($current->getAttributes(), true);

            return true;
        });
    }

    /** @return int number of cards that received an initial stability / difficulty */
    private function seedFsrsMemory(Learner $learner): int
    {
        $count = 0;

        WordRepetition::query()
            ->where('learner_id', $learner->id)
            ->where('state', RepetitionState::Review->value)
            ->where(fn ($q) => $q->whereNull('stability')->orWhereNull('difficulty'))
            ->select(['id', 'interval_days', 'easiness_factor', 'stability', 'difficulty'])
            ->chunkById(self::CHUNK, function ($rows) use (&$count): void {
                /** @var WordRepetition $row */
                foreach ($rows as $row) {
                    WordRepetition::query()->whereKey($row->id)->update([
                        'stability' => $row->stability ?? FsrsScheduler::stabilityFromSm2((int) $row->interval_days),
                        'difficulty' => $row->difficulty ?? FsrsScheduler::difficultyFromEasinessFactor((float) $row->easiness_factor),
                    ]);
                    $count++;
                }
            });

        return $count;
    }
}
