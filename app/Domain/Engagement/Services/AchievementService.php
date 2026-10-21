<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Engagement\DTO\AchievementEntry;
use App\Domain\Engagement\DTO\AchievementsOverview;
use App\Domain\Engagement\Enums\AchievementKey;
use App\Domain\Learning\Services\LearnerStatsService;
use App\Jobs\SendAchievementJob;
use App\Models\Achievement;
use App\Models\Friendship;
use App\Models\LeagueMember;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Achievements (docs/13 §1.5, §2.4, §7.4).
 *
 *   evaluate   — looks at the committed facts of one learner (best streak, learned words) and unlocks
 *                every counting key whose threshold is reached; keys already held cost nothing;
 *   on…        — the single-event keys: the first graded card, an accepted friendship, a league win;
 *   unlock     — `INSERT … ON CONFLICT DO NOTHING` on the unique `(learner_id, key)`: of two parallel
 *                callers exactly one inserts (affected rows = 1) and only that one records
 *                `achievement.unlocked` and queues the card DM, so a key is unlocked and announced once;
 *   markShared — the learner shared the card from the TWA: `shared_at` and `achievement.shared`, once.
 *
 * Nothing here runs inside the review transaction: callers hook in `DB::afterCommit` (docs/13 §3 rule 5)
 * and no `learners` row is locked. When one evaluation unlocks several keys of a family at once (a
 * long-time learner meeting the feature), all are stored but only the highest gets a DM.
 *
 * Deliberately not final: tests of the callers replace it with a double to fault it.
 */
class AchievementService
{
    public function __construct(
        private readonly ProductEventRecorder $events,
        private readonly LearnerStatsService $stats,
        private readonly ReferralService $referrals,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('achievements.enabled', true);
    }

    /**
     * Unlocks the counting keys the learner has reached. Reads fresh values from the database, not
     * the (possibly stale) model.
     *
     * @return list<Achievement> the keys unlocked by THIS call
     */
    public function evaluate(Learner $learner, CarbonImmutable $now): array
    {
        if (! $this->enabled() || $learner->blocked_at !== null) {
            return [];
        }

        $held = $this->heldKeys($learner);
        $streak = (int) Learner::query()->whereKey($learner->id)->value('streak_best');
        $words = $this->stats->wordsLearnedFor([$learner->id])[$learner->id] ?? 0;

        /** @var list<array{AchievementKey, array<string, int>}> $due */
        $due = [];
        foreach (AchievementKey::cases() as $key) {
            $threshold = $key->threshold();
            $value = match ($key->family()) {
                'streak' => $streak,
                'words' => $words,
                default => null,
            };
            if ($threshold === null || $value === null || $value < $threshold || isset($held[$key->value])) {
                continue;
            }
            $due[] = [$key, ['value' => $value]];
        }

        $unlocked = [];
        foreach ($due as [$key, $meta]) {
            // Only the highest threshold of a family announces itself when several come at once.
            $notify = true;
            foreach ($due as [$other]) {
                if ($other->family() === $key->family() && ($other->threshold() ?? 0) > ($key->threshold() ?? 0)) {
                    $notify = false;
                }
            }

            $row = $this->unlock($learner, $key, $now, $meta, $notify);
            if ($row !== null) {
                $unlocked[] = $row;
            }
        }

        return $unlocked;
    }

    /** The first graded card ever (a plain conditional insert: nothing is read beforehand). */
    public function onFirstReview(Learner $learner, CarbonImmutable $now): ?Achievement
    {
        return $this->enabled() && $learner->blocked_at === null
            ? $this->unlock($learner, AchievementKey::FirstSession, $now)
            : null;
    }

    /** A friendship became accepted: both sides get their first friend. */
    public function onFriendshipAccepted(Friendship $friendship, CarbonImmutable $now): void
    {
        if (! $this->enabled()) {
            return;
        }

        foreach ([$friendship->learner_id, $friendship->friend_id] as $learnerId) {
            $learner = Learner::query()->find($learnerId);
            if ($learner instanceof Learner && $learner->blocked_at === null) {
                $this->unlock($learner, AchievementKey::FirstFriend, $now);
            }
        }
    }

    /**
     * A league week was closed: whoever took the first place of their group with at least one point
     * has won a league. Called after the closing transaction committed; a failure for one member does
     * not stop the others.
     *
     * @param  list<int>  $memberIds  `league_members.id` of the closed week
     */
    public function onLeagueWeekClosed(array $memberIds, CarbonImmutable $now): void
    {
        if (! $this->enabled() || $memberIds === []) {
            return;
        }

        foreach (array_chunk($memberIds, 500) as $chunk) {
            $winners = LeagueMember::query()
                ->with('learner')
                ->whereIn('id', $chunk)
                ->where('final_rank', 1)
                ->where('final_points', '>', 0)
                ->get();

            foreach ($winners as $member) {
                $learner = $member->learner;
                if ($learner === null || $learner->blocked_at !== null) {
                    continue;
                }

                try {
                    $this->unlock($learner, AchievementKey::LeagueTop1, $now, ['league_week_id' => $member->league_week_id]);
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }
    }

    /**
     * Stores the key for the learner once. Returns the new row, or null when the learner already had it
     * (also when a parallel caller got there first).
     *
     * @param  array<string, int|string>  $meta
     */
    public function unlock(Learner $learner, AchievementKey $key, CarbonImmutable $now, array $meta = [], bool $notify = true): ?Achievement
    {
        $inserted = Achievement::query()->insertOrIgnore([
            'learner_id' => $learner->id,
            'key' => $key->value,
            'unlocked_at' => $now,
            'shared_at' => null,
            'meta' => $meta === [] ? null : json_encode($meta, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($inserted === 0) {
            return null;
        }

        /** @var Achievement|null $row */
        $row = Achievement::query()->where('learner_id', $learner->id)->where('key', $key->value)->first();
        if ($row === null) {
            return null;
        }

        $this->events->record(ProductEventName::AchievementUnlocked, ['key' => $key->value], learner: $learner);

        if ($notify) {
            SendAchievementJob::dispatch($row->id)->afterCommit();
        }

        return $row;
    }

    /**
     * The learner shared the card. Idempotent: only the call that sets `shared_at` records the event.
     * Returns false when the learner has not unlocked the key.
     */
    public function markShared(Learner $learner, AchievementKey $key, CarbonImmutable $now): bool
    {
        $held = Achievement::query()->where('learner_id', $learner->id)->where('key', $key->value);
        if (! (clone $held)->exists()) {
            return false;
        }

        $updated = $held->whereNull('shared_at')->update(['shared_at' => $now, 'updated_at' => $now]);
        if ($updated > 0) {
            $this->events->record(ProductEventName::AchievementShared, ['key' => $key->value], learner: $learner);
        }

        return true;
    }

    /** The whole catalog with the learner's state, for the TWA list. */
    public function overview(Learner $learner): AchievementsOverview
    {
        /** @var array<string, Achievement> $rows */
        $rows = Achievement::query()->where('learner_id', $learner->id)->get()->keyBy('key')->all();
        $streak = max((int) $learner->streak_current, (int) $learner->streak_best);
        $words = $this->stats->wordsLearnedFor([$learner->id])[$learner->id] ?? 0;

        $entries = [];
        foreach (AchievementKey::cases() as $key) {
            $row = $rows[$key->value] ?? null;
            $target = $key->threshold();
            $current = match ($key->family()) {
                'streak' => $streak,
                'words' => $words,
                default => null,
            };

            $entries[] = new AchievementEntry(
                $key,
                $row?->unlocked_at->toImmutable(),
                $row?->shared_at?->toImmutable(),
                $current === null || $target === null ? null : min($current, $target),
                $target,
            );
        }

        try {
            $link = $this->referrals->inviteLink($this->referrals->codeFor($learner));
        } catch (Throwable $e) {
            Log::warning('achievements.share_link_failed', ['learner_id' => $learner->id, 'error' => $e->getMessage()]);
            $link = null;
        }

        return new AchievementsOverview($entries, $link);
    }

    /** @return array<string, true> keys the learner already holds */
    private function heldKeys(Learner $learner): array
    {
        $held = [];
        foreach (Achievement::query()->where('learner_id', $learner->id)->pluck('key') as $key) {
            $held[(string) $key] = true;
        }

        return $held;
    }
}
