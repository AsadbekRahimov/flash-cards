<?php

declare(strict_types=1);

namespace App\Domain\Learners\Services;

use App\Domain\Billing\Services\CheckoutService;
use App\Domain\Billing\Services\SubscriptionService;
use App\Models\AuditLog;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * "Welcome back, the account is restored" (R11b): a learner who deleted the account and came back within
 * `learners.purge.after_days` days is told so once - by the bot's `/start` or by the Mini App, whichever is first.
 *
 * The state is one key of `learners.meta`, {@see KEY} (ISO time of the return): {@see LearnerRegistrar} sets it when it
 * revives a soft-deleted row ({@see markPending}); the two channels only read it ({@see isPending}, no query) and the one
 * that shows the notice takes it away ({@see consume}) - a short transaction under the row lock, so exactly one channel
 * gets `true`. Plain JSON keys, no database-specific operators.
 */
final class AccountRestoreNotice
{
    public const KEY = 'restored_notice_at';

    public function __construct(private readonly SubscriptionService $subscriptions) {}

    /** The learner was just revived (`deleted_at` is already null): leave the marker and the audit record. */
    public function markPending(Learner $learner): void
    {
        $now = CarbonImmutable::now();

        /** @var array<string, mixed>|null $meta */
        $meta = DB::transaction(function () use ($learner, $now): ?array {
            /** @var Learner|null $current */
            $current = Learner::query()->whereKey($learner->id)->lockForUpdate()->first();

            if ($current === null) {
                return null;
            }

            $meta = [...$current->meta, self::KEY => $now->toIso8601String()];
            $current->meta = $meta;
            $current->save();

            AuditLog::create([
                'user_id' => null,
                'action' => LearnerDeletionService::ACTION_RESTORED,
                'entity_type' => Learner::class,
                'entity_id' => $current->id,
                'ip' => null,
                // The id is in `entity_id`; nothing personal here (docs/07 §13).
                'meta' => ['by' => LearnerDeletionService::BY_SELF],
            ]);

            return $meta;
        });

        if ($meta !== null) {
            $this->remember($learner, $meta);
        }
    }

    /** Reads the loaded `meta`, no query. */
    public function isPending(Learner $learner): bool
    {
        return array_key_exists(self::KEY, $learner->meta);
    }

    /** @return bool whether this call took the marker (false: nothing to show, or the other channel was first) */
    public function consume(Learner $learner): bool
    {
        /** @var array<string, mixed>|null $meta */
        $meta = DB::transaction(function () use ($learner): ?array {
            /** @var Learner|null $current */
            $current = Learner::query()->whereKey($learner->id)->lockForUpdate()->first();

            if ($current === null || ! array_key_exists(self::KEY, $current->meta)) {
                return null;
            }

            $meta = $current->meta;
            unset($meta[self::KEY]);
            // An object, like the column default `{}`: the `array` cast would store an empty PHP array as `[]`.
            $current->setAttribute('meta', $meta === [] ? new stdClass : $meta);
            $current->save();

            return $meta;
        });

        // Whoever got here, the marker is gone now: the in-memory copy follows.
        $this->remember($learner, $meta ?? array_diff_key($learner->meta, [self::KEY => true]));

        return $meta !== null;
    }

    /** Whether the learner's own auto-renewal can be switched on again (the same summary as the subscription screen). */
    public function proCanResume(Learner $learner): bool
    {
        $live = $this->subscriptions->liveFor($learner);

        return $live !== null && CheckoutService::summarize($live, CarbonImmutable::now())['can_resume'];
    }

    /** @param  array<string, mixed>  $meta */
    private function remember(Learner $learner, array $meta): void
    {
        $learner->setAttribute('meta', $meta);
        $learner->syncOriginalAttribute('meta');
    }
}
