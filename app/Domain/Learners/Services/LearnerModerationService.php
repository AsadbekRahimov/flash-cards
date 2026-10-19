<?php

declare(strict_types=1);

namespace App\Domain\Learners\Services;

use App\Models\AuditLog;
use App\Models\Learner;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Admin moderation of a learner: block / unblock (`learners.blocked_at`).
 * Both are idempotent and write exactly one audit record per real change.
 * Who may do it is decided by `LearnerPolicy::update` at the call site.
 */
final class LearnerModerationService
{
    public const ACTION_BLOCKED = 'learner.blocked';

    public const ACTION_UNBLOCKED = 'learner.unblocked';

    /** @return bool whether the learner was blocked by this call (false: already blocked) */
    public function block(Learner $learner, User $actor, ?string $ip = null): bool
    {
        return $this->change($learner, $actor, blocked: true, ip: $ip);
    }

    /** @return bool whether the learner was unblocked by this call (false: was not blocked) */
    public function unblock(Learner $learner, User $actor, ?string $ip = null): bool
    {
        return $this->change($learner, $actor, blocked: false, ip: $ip);
    }

    private function change(Learner $learner, User $actor, bool $blocked, ?string $ip): bool
    {
        return DB::transaction(function () use ($learner, $actor, $blocked, $ip): bool {
            // Re-read under a row lock: two concurrent clicks must not both pass the check.
            /** @var Learner|null $current */
            $current = Learner::query()->whereKey($learner->id)->lockForUpdate()->first();

            if ($current === null) {
                return false;
            }

            if (($current->blocked_at !== null) === $blocked) {
                $learner->setRawAttributes($current->getAttributes(), true);

                return false;
            }

            $current->update(['blocked_at' => $blocked ? now() : null]);

            AuditLog::create([
                'user_id' => $actor->id,
                'action' => $blocked ? self::ACTION_BLOCKED : self::ACTION_UNBLOCKED,
                'entity_type' => Learner::class,
                'entity_id' => $current->id,
                'ip' => $ip,
                'meta' => [],
            ]);

            $learner->setRawAttributes($current->getAttributes(), true);

            return true;
        });
    }
}
