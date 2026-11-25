<?php

declare(strict_types=1);

namespace App\Domain\Polls\Services;

use App\Domain\Billing\Support\PaidSubscriptionQuery;
use App\Domain\Learners\Services\OnboardingService;
use App\Domain\Learners\Services\ProEmojiService;
use App\Domain\Polls\Support\PollAudience;
use App\Domain\Telegram\Services\DmReachability;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who gets a poll (docs/35 §1.5 "Запуск"): ONE definition of the audience for the preview count of `/admin` (O6) and for the insert
 * of the launch ({@see PollLauncher}), so the number shown is the number sent to (as of that moment).
 *
 * Always: not deleted (the model's scope), not banned (`blocked_at`), the DM open (`dm_blocked_at` and `dm_blocked_reason` empty -
 * {@see DmReachability} sets and clears them together; the load-test learners carry the flag too), a
 * real Telegram id. Then the filters of {@see PollAudience}: onboarded only, plan, interface language, active course, region.
 *
 * The plan is the SQL twin of the entitlement ({@see PaidSubscriptionQuery}: an own live paid subscription or a seat in an active
 * organization with one; the welcome trial is a `trialing` subscription, so it is Pro). Over the whole table (the preview count) the
 * two "who is Pro" subqueries are uncorrelated and hashed once; over a range of ids (the launch inserts by ranges) they are narrowed
 * to that range, as {@see ProEmojiService::visibleForMany()} narrows them to a page: one index range
 * per chunk instead of hashing every live subscription of the platform per chunk.
 */
final class PollAudienceQuery
{
    /**
     * @param  PollAudience|array<array-key, mixed>|null  $audience
     * @return Builder<Learner>
     */
    public function for(PollAudience|array|null $audience, ?CarbonImmutable $now = null, ?int $fromId = null, ?int $toId = null): Builder
    {
        $audience = $audience instanceof PollAudience ? $audience : PollAudience::from($audience);
        $now ??= CarbonImmutable::now();
        $ranged = $fromId !== null && $toId !== null;

        $query = Learner::query()->whereRaw(self::reachableSql('learners'));

        if ($ranged) {
            $query->whereBetween('learners.id', [$fromId, $toId]);
        }

        if ($audience->onboardedOnly) {
            $query->where('learners.onboarding_state', OnboardingService::DONE);
        }

        if ($audience->locales !== []) {
            $query->whereIn('learners.interface_locale', $audience->locales);
        }

        if ($audience->courseIds !== []) {
            $query->whereIn('learners.active_course_id', $audience->courseIds);
        }

        if ($audience->regionCodes !== []) {
            $query->whereIn('learners.region_code', $audience->regionCodes);
        }

        if ($audience->plan !== PollAudience::PLAN_ALL) {
            $personal = PaidSubscriptionQuery::personalLearnerIds($now);
            $sponsored = PaidSubscriptionQuery::sponsoredLearnerIds($now);
            if ($ranged) {
                $personal->whereBetween('subscriptions.subscriber_id', [$fromId, $toId]);
                $sponsored->whereBetween('om.learner_id', [$fromId, $toId]);
            }

            $pro = fn (Builder $q): Builder => $q
                ->whereIn('learners.id', $personal)
                ->orWhereIn('learners.id', $sponsored);

            $audience->plan === PollAudience::PLAN_PRO ? $query->where($pro) : $query->whereNot($pro);
        }

        return $query;
    }

    /**
     * "The learner can be written to", ONE definition for the audience of the launch and the step to `sending` of the broadcast
     * ({@see PollDeliverySender}): not deleted, not banned, the DM open (`dm_blocked_at` AND `dm_blocked_reason` empty), a real
     * Telegram id. Plain SQL over the alias `$alias` of `learners` - column names and constants only, no value from outside.
     *
     * @param  literal-string  $alias
     * @return literal-string
     */
    public static function reachableSql(string $alias): string
    {
        return "{$alias}.deleted_at IS NULL AND {$alias}.blocked_at IS NULL AND {$alias}.dm_blocked_at IS NULL "
            ."AND {$alias}.dm_blocked_reason IS NULL AND {$alias}.telegram_user_id > 0";
    }

    /**
     * The preview number of `/admin`: one `count(*)` of {@see for()} (O6 shows it next to the preview; the launch inserts the same
     * set, minus who changed meanwhile).
     *
     * @param  PollAudience|array<array-key, mixed>|null  $audience
     */
    public function count(PollAudience|array|null $audience, ?CarbonImmutable $now = null): int
    {
        return $this->for($audience, $now)->toBase()->count();
    }
}
