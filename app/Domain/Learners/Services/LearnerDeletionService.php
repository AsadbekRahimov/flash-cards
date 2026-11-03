<?php

declare(strict_types=1);

namespace App\Domain\Learners\Services;

use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Billing\Services\LearnerBillingService;
use App\Models\AuditLog;
use App\Models\Learner;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Step 1 of the account deletion (docs/03 §8 rule 2, docs/07 §10; owner's decision of 03.11.2026): the learner's own
 * "Delete account" (Mini App settings, `/delete_account` in the bot) and the admin's one (support). It only sets
 * `learners.deleted_at`; the final deletion is {@see LearnerPurgeService} after `learners.purge.after_days` days, and the
 * learner's own `/start` / `/auth` before that brings the account back ({@see LearnerRegistrar}).
 *
 * What stops at once is not done by this service but by the soft delete itself: every read of a learner goes through
 * the `SoftDeletes` scope, so the Mini App token is refused (`TwaAuth`: 401 `learner_not_found`), reminders, battle and
 * friend DMs, offers and the weekly league find nobody, and a battle the learner was in runs out by the clock
 * (presence / invitation TTL, `practice:battles-sweep`). Memberships, progress and sessions are kept untouched until the
 * purge, so a learner who comes back finds everything as it was.
 *
 * Subscription: the Stars auto-renewal is stopped at once (Telegram would otherwise keep charging a person who deleted
 * the account) - `LearnerBillingService::cancelAutoRenew`, best-effort like the purge: a refusal is logged and does not
 * stop the deletion (the purge tries again). The paid period itself is not cut: a learner who returns keeps the Pro they
 * paid for; the purge ends it. Payments are never touched.
 *
 * Idempotent: a second call (double tap, stale admin table row) finds no live row and returns false, no second audit.
 */
final class LearnerDeletionService
{
    public const ACTION_DELETED = 'learner.deleted';

    public const BY_SELF = 'self';

    public const BY_ADMIN = 'admin';

    public function __construct(private readonly LearnerBillingService $billing) {}

    /**
     * @param  User|null  $by  the admin; null = the learner themselves
     * @return bool whether the learner was deleted by this call (false: already deleted)
     */
    public function delete(Learner $learner, ?User $by = null, ?string $ip = null): bool
    {
        $deleted = DB::transaction(function () use ($learner, $by, $ip): bool {
            // Re-read under a row lock: two concurrent taps must not both pass; a soft-deleted row is not found.
            /** @var Learner|null $current */
            $current = Learner::query()->whereKey($learner->id)->lockForUpdate()->first();

            if ($current === null) {
                return false;
            }

            $current->delete();

            AuditLog::create([
                'user_id' => $by?->id,
                'action' => self::ACTION_DELETED,
                'entity_type' => Learner::class,
                'entity_id' => $current->id,
                'ip' => $ip,
                // The id is in `entity_id`; nothing personal here (docs/07 §13).
                'meta' => ['by' => $by === null ? self::BY_SELF : self::BY_ADMIN],
            ]);

            $learner->setRawAttributes($current->getAttributes(), true);

            return true;
        });

        if ($deleted) {
            $this->stopRenewal($learner);
        }

        return $deleted;
    }

    /** Until when the learner can still come back: `deleted_at` + `learners.purge.after_days` (the purge cutoff, mirrored). */
    public function restorableUntil(Learner $learner): CarbonImmutable
    {
        $deletedAt = $learner->deleted_at;

        return ($deletedAt === null ? CarbonImmutable::now() : CarbonImmutable::instance($deletedAt))
            ->addDays(max(1, (int) config('learners.purge.after_days', 30)));
    }

    /** The provider call goes after the commit (docs/13 §3 rule 2); nothing to stop is not an error. */
    private function stopRenewal(Learner $learner): void
    {
        try {
            $this->billing->cancelAutoRenew($learner);
        } catch (BillingException) {
            // No live auto-renewing subscription, or the provider refused (logged by the billing service): the purge ends it anyway.
        } catch (Throwable $e) {
            report($e);
            Log::warning('learners.delete.renewal_cancel_failed', ['learner_id' => $learner->id, 'error' => $e::class]);
        }
    }
}
