<?php

declare(strict_types=1);

namespace App\Domain\Practice\Services;

use App\Models\AuditLog;
use App\Models\Learner;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The pilot of the practice (docs/16 §2.1, §3.2): with `PRACTICE_ACCESS=pilot` only the learners with
 * `learners.practice_pilot_at` get the practice. An admin puts a learner into the pilot or takes the learner out on the
 * learner's page; one audit record per real change (`learner.practice_pilot_changed`, meta `{from, to}` - the value of the
 * column before and after: an ISO 8601 moment or null). Who may do it is decided by `LearnerPolicy::update` at the call site.
 *
 * Both moves are idempotent. The row of the learner is read under a lock that does not conflict with the foreign keys other
 * transactions check on it (`FOR NO KEY UPDATE` on PostgreSQL, as SchedulerSwitchService), so two clicks cannot both log a
 * change and the learner's own requests are not held up.
 */
final class PracticePilotService
{
    public const ACTION_CHANGED = 'learner.practice_pilot_changed';

    /** @return bool whether the learner was changed by this call (false: already so) */
    public function include(Learner $learner, User $actor, ?string $ip = null): bool
    {
        return $this->set($learner, true, $actor, $ip);
    }

    /** @return bool whether the learner was changed by this call (false: was not in the pilot) */
    public function exclude(Learner $learner, User $actor, ?string $ip = null): bool
    {
        return $this->set($learner, false, $actor, $ip);
    }

    private function set(Learner $learner, bool $inPilot, User $actor, ?string $ip): bool
    {
        return DB::transaction(function () use ($learner, $inPilot, $actor, $ip): bool {
            $lock = Learner::query()->whereKey($learner->id);
            /** @var Learner|null $current */
            $current = (DB::connection()->getDriverName() === 'pgsql' ? $lock->lock('for no key update') : $lock->lockForUpdate())->first();

            if ($current === null) {
                return false;
            }

            if (($current->practice_pilot_at !== null) === $inPilot) {
                $learner->setRawAttributes($current->getAttributes(), true);

                return false;
            }

            $from = $current->practice_pilot_at?->toIso8601String();
            $current->forceFill(['practice_pilot_at' => $inPilot ? now() : null])->save();

            AuditLog::query()->create([
                'user_id' => $actor->id,
                'action' => self::ACTION_CHANGED,
                'entity_type' => Learner::class,
                'entity_id' => $current->id,
                'ip' => $ip,
                'meta' => ['from' => $from, 'to' => $current->practice_pilot_at?->toIso8601String()],
            ]);

            $learner->setRawAttributes($current->getAttributes(), true);

            return true;
        });
    }
}
