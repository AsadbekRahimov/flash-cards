<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Engagement\DTO\FriendEntry;
use App\Domain\Engagement\DTO\FriendRequestEntry;
use App\Domain\Engagement\DTO\FriendsOverview;
use App\Domain\Engagement\Enums\ChallengeStatus;
use App\Domain\Engagement\Enums\FriendDmKind;
use App\Domain\Engagement\Enums\FriendshipStatus;
use App\Domain\Engagement\Exceptions\FriendshipException;
use App\Domain\Learners\Services\LearnerClock;
use App\Domain\Learning\Services\LearnerStatsService;
use App\Jobs\SendFriendDmJob;
use App\Models\Challenge;
use App\Models\Friendship;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Friends (docs/13 §1.5, §2.4, §7.5).
 *
 * One `friendships` row per PAIR: `learner_id` < `friend_id` ({@see pair()}), so A→B and B→A are the same
 * row and the unique index makes a double request impossible. The states:
 *
 *   pending   — `requested_by_learner_id` asked, the other side has not answered;
 *   accepted  — friends;
 *   blocked   — `requested_by_learner_id` (here: the blocker) blocked the other side.
 *
 * A declined / withdrawn / removed friendship has NO row (the pair may ask again), and a removal closes
 * the open challenges of the pair (`canceled`). Every call is idempotent: a repeated request, accept or
 * remove changes nothing and raises no second event or DM.
 *
 * Locks (docs/13 §3 rule 4): none on learners — every write is one statement on the pair's row (a
 * conditional UPDATE, an INSERT guarded by the unique index, a DELETE). The friend limit is checked before
 * the write without a lock: two parallel requests may overshoot `friends.max` by a few, which is harmless
 * (it is a spam guard, not a quota) and cheaper than a lock on `learners`.
 *
 * What a friend sees of a learner: the first name, the streak and the number of learned words. The streak
 * is read-only here (the friend's `streak_current`, zero once the last held day is older than the friend's
 * yesterday) — never StreakService::current(), which may write to somebody else's learner row. The number of
 * learned words is a few minutes old (a per-learner cache, {@see LearnerStatsService::cachedWordsLearnedFor()}).
 */
final class FriendshipService
{
    public function __construct(
        private readonly ProductEventRecorder $events,
        private readonly LearnerClock $clock,
        private readonly LearnerStatsService $stats,
        private readonly ReferralService $referrals,
        private readonly AchievementService $achievements,
    ) {}

    /**
     * The normalised pair.
     *
     * @return array{0: int, 1: int}
     */
    public static function pair(int $a, int $b): array
    {
        return $a < $b ? [$a, $b] : [$b, $a];
    }

    /** `t.me/<bot>?start=f_<referral_code>`; null while `TELEGRAM_BOT_USERNAME` is not configured. */
    public function inviteLink(Learner $learner): ?string
    {
        $username = ltrim(trim((string) config('telegram.bot_username')), '@');

        return $username === '' ? null : "https://t.me/{$username}?start=f_".$this->referrals->codeFor($learner);
    }

    /** The learner who owns an invite code (`f_<code>` / `ref_<code>`); null for an unknown or blocked one. */
    public function ownerOfCode(string $code): ?Learner
    {
        /** @var Learner|null $owner */
        $owner = Learner::query()->where('referral_code', $code)->whereNull('blocked_at')->first();

        return $owner;
    }

    public function between(Learner|int $a, Learner|int $b): ?Friendship
    {
        [$low, $high] = self::pair($a instanceof Learner ? $a->id : $a, $b instanceof Learner ? $b->id : $b);

        /** @var Friendship|null $friendship */
        $friendship = Friendship::query()->where('learner_id', $low)->where('friend_id', $high)->first();

        return $friendship;
    }

    public function areFriends(Learner|int $a, Learner|int $b): bool
    {
        return $this->between($a, $b)?->status === FriendshipStatus::Accepted->value;
    }

    /**
     * The friendship `$id` if `$by` is one of its two sides; anything else — a stranger's pair, a missing
     * id — is the same "not found" (the API never confirms that somebody else's row exists).
     */
    public function find(Learner $by, int $id): Friendship
    {
        /** @var Friendship|null $friendship */
        $friendship = $this->involving($by->id)->whereKey($id)->first();

        return $friendship ?? throw FriendshipException::notFound();
    }

    /**
     * `$from` asks `$to` to be friends. Repeating the request changes nothing; if `$to` had already asked
     * `$from`, the two requests meet and the friendship is accepted. Whoever was blocked by the other side
     * gets the (blocked) row back without any signal; the blocker has to unblock first.
     *
     * @throws FriendshipException
     */
    public function request(Learner $from, Learner $to): Friendship
    {
        if ($from->id === $to->id) {
            throw FriendshipException::self();
        }
        if ($from->blocked_at !== null || $to->blocked_at !== null) {
            throw FriendshipException::unavailable();
        }

        $existing = $this->between($from, $to);
        if ($existing !== null) {
            return $this->requestAgain($existing, $from);
        }

        if ($this->occupied($from->id) >= $this->limit()) {
            throw FriendshipException::limit();
        }

        [$low, $high] = self::pair($from->id, $to->id);

        try {
            // Savepoint: the unique violation of a parallel request must not poison an outer (PostgreSQL) transaction.
            /** @var Friendship $friendship */
            $friendship = DB::transaction(static fn (): Friendship => Friendship::query()->create([
                'learner_id' => $low,
                'friend_id' => $high,
                'status' => FriendshipStatus::Pending->value,
                'requested_by_learner_id' => $from->id,
            ]));
        } catch (UniqueConstraintViolationException) {
            $raced = $this->between($from, $to);

            return $raced === null ? throw FriendshipException::notFound() : $this->requestAgain($raced, $from);
        }

        $this->events->record(ProductEventName::FriendRequested, ['friendship_id' => $friendship->id], learner: $from);
        SendFriendDmJob::send(FriendDmKind::FriendRequested, $friendship->id);

        return $friendship;
    }

    /**
     * The asked side accepts. Already accepted → returned as is. Both sides must have room for a friend.
     *
     * @throws FriendshipException
     */
    public function accept(Learner $by, int $friendshipId): Friendship
    {
        $friendship = $this->find($by, $friendshipId);

        if ($friendship->status === FriendshipStatus::Accepted->value) {
            return $friendship;
        }
        if ($friendship->status !== FriendshipStatus::Pending->value) {
            throw FriendshipException::notFound(); // blocked: invisible to the other side
        }
        if ($friendship->requested_by_learner_id === $by->id) {
            throw FriendshipException::notRecipient();
        }

        if ($this->acceptedCount($by->id) >= $this->limit()) {
            throw FriendshipException::limit();
        }
        if ($this->acceptedCount($friendship->requested_by_learner_id) >= $this->limit()) {
            throw FriendshipException::limitOther();
        }

        $now = CarbonImmutable::now();
        $updated = Friendship::query()
            ->whereKey($friendship->id)
            ->where('status', FriendshipStatus::Pending->value)
            ->update(['status' => FriendshipStatus::Accepted->value, 'accepted_at' => $now, 'updated_at' => $now]);

        if ($updated === 0) {
            // A parallel accept / decline got there first: the row is accepted (fine) or gone.
            return Friendship::query()->find($friendship->id) ?? throw FriendshipException::notFound();
        }

        $friendship->refresh();

        $this->events->record(ProductEventName::FriendAccepted, ['friendship_id' => $friendship->id], learner: $by);
        SendFriendDmJob::send(FriendDmKind::FriendAccepted, $friendship->id);

        // Both sides get `first_friend` (docs/13 §7.4); a failure never undoes the friendship.
        try {
            $this->achievements->onFriendshipAccepted($friendship, $now);
        } catch (Throwable $e) {
            report($e);
        }

        return $friendship;
    }

    /**
     * The asked side declines: the pending row is deleted (the pair may ask again). Repeating it, or
     * declining a row that is gone, is a no-op.
     *
     * @throws FriendshipException
     */
    public function decline(Learner $by, int $friendshipId): void
    {
        $friendship = $this->find($by, $friendshipId);

        if ($friendship->status !== FriendshipStatus::Pending->value) {
            throw FriendshipException::notFound();
        }
        if ($friendship->requested_by_learner_id === $by->id) {
            throw FriendshipException::notRecipient();
        }

        $this->delete($friendship, $by, 'declined');
    }

    /**
     * Ends the pair from either side: unfriend (accepted), withdraw (own pending request), unblock (own
     * block). The open challenges of the pair are canceled. Repeating it finds no row → not found.
     *
     * @throws FriendshipException
     */
    public function remove(Learner $by, int $friendshipId): void
    {
        $friendship = $this->find($by, $friendshipId);

        $how = match ($friendship->status) {
            FriendshipStatus::Accepted->value => 'removed',
            FriendshipStatus::Pending->value => $friendship->requested_by_learner_id === $by->id ? 'canceled' : null,
            FriendshipStatus::Blocked->value => $friendship->requested_by_learner_id === $by->id ? 'unblocked' : null,
            default => null,
        };
        if ($how === null) {
            // A pending request of the other side is answered with accept / decline; a block against you is invisible.
            throw $friendship->status === FriendshipStatus::Pending->value ? FriendshipException::notRecipient() : FriendshipException::notFound();
        }

        $this->delete($friendship, $by, $how);
    }

    /**
     * `$by` blocks `$other`: the pair becomes `blocked` (whatever it was), open challenges are canceled and
     * `$other` can neither ask again nor tell that they are blocked. Already blocked by `$by` → unchanged.
     * (The blocker's own undo is {@see remove()}.)
     *
     * @throws FriendshipException
     */
    public function block(Learner $by, Learner $other): Friendship
    {
        if ($by->id === $other->id) {
            throw FriendshipException::self();
        }

        [$low, $high] = self::pair($by->id, $other->id);
        $now = CarbonImmutable::now();

        $existing = $this->between($by, $other);
        if ($existing !== null && $existing->status === FriendshipStatus::Blocked->value) {
            return $existing; // by us: idempotent; by them: the first block stands
        }

        try {
            /** @var Friendship $friendship */
            $friendship = DB::transaction(function () use ($existing, $low, $high, $by, $now): Friendship {
                if ($existing === null) {
                    return Friendship::query()->create([
                        'learner_id' => $low,
                        'friend_id' => $high,
                        'status' => FriendshipStatus::Blocked->value,
                        'requested_by_learner_id' => $by->id,
                    ]);
                }

                $existing->forceFill([
                    'status' => FriendshipStatus::Blocked->value,
                    'requested_by_learner_id' => $by->id,
                    'accepted_at' => null,
                    'updated_at' => $now,
                ])->save();

                return $existing;
            });
        } catch (UniqueConstraintViolationException) {
            $raced = $this->between($by, $other);

            return $raced ?? throw FriendshipException::notFound();
        }

        $this->cancelOpenChallenges($low, $high, $now);
        $this->events->record(ProductEventName::FriendRemoved, ['friendship_id' => $friendship->id, 'how' => 'blocked'], learner: $by);

        return $friendship;
    }

    /**
     * The friends screen: friends (newest first) with the visible streak and learned words, and the open
     * requests in both directions. A friend who deleted their profile is not listed.
     */
    public function overview(Learner $learner, ?CarbonImmutable $now = null): FriendsOverview
    {
        $now ??= CarbonImmutable::now();

        /** @var list<Friendship> $rows */
        $rows = $this->involving($learner->id)
            ->whereIn('status', [FriendshipStatus::Accepted->value, FriendshipStatus::Pending->value])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get()
            ->values()
            ->all();

        $otherIds = array_values(array_unique(array_map(static fn (Friendship $f): int => $f->otherId($learner->id), $rows)));
        $others = Learner::query()->whereIn('id', $otherIds)->get()->keyBy('id');

        // Learned words are shown for friends only (not for the people who merely asked), and a few minutes
        // old is fine: they come from the per-learner cache, the database counts only the learners that missed it.
        $friendIds = [];
        foreach ($rows as $row) {
            $otherId = $row->otherId($learner->id);
            if ($row->status === FriendshipStatus::Accepted->value && $others->has($otherId)) {
                $friendIds[] = $otherId;
            }
        }
        $words = $this->stats->cachedWordsLearnedFor($friendIds);

        $friends = [];
        $incoming = [];
        $outgoing = [];
        foreach ($rows as $row) {
            $other = $others->get($row->otherId($learner->id));
            if (! $other instanceof Learner) {
                continue;
            }

            if ($row->status === FriendshipStatus::Accepted->value) {
                $friends[] = new FriendEntry($row->id, $other->first_name, $this->visibleStreak($other, $now), $words[$other->id] ?? 0, $row->accepted_at);
            } elseif ($row->requested_by_learner_id === $learner->id) {
                $outgoing[] = new FriendRequestEntry($row->id, $other->first_name, $row->created_at);
            } else {
                $incoming[] = new FriendRequestEntry($row->id, $other->first_name, $row->created_at);
            }
        }

        return new FriendsOverview(
            inviteLink: $this->inviteLink($learner),
            limit: $this->limit(),
            friends: $friends,
            incoming: $incoming,
            outgoing: $outgoing,
        );
    }

    /**
     * Accepted friendships of the learner and the pending requests THEY sent: what counts against `friends.max`.
     * Requests addressed TO the learner do not count: anybody can send one, so they must not be able to fill
     * the recipient's limit (the recipient's limit is checked when they accept).
     */
    private function occupied(int $learnerId): int
    {
        return $this->involving($learnerId)
            ->where(function (Builder $q) use ($learnerId): void {
                $q->where('status', FriendshipStatus::Accepted->value)
                    ->orWhere(fn (Builder $p) => $p->where('status', FriendshipStatus::Pending->value)->where('requested_by_learner_id', $learnerId));
            })
            ->count();
    }

    private function acceptedCount(int $learnerId): int
    {
        return $this->involving($learnerId)
            ->where('status', FriendshipStatus::Accepted->value)
            ->count();
    }

    private function limit(): int
    {
        return max(1, (int) config('friends.max'));
    }

    /** @return Builder<Friendship> the pairs one learner is a side of */
    private function involving(int $learnerId): Builder
    {
        return Friendship::query()->where(fn (Builder $q) => $q->where('learner_id', $learnerId)->orWhere('friend_id', $learnerId));
    }

    private function requestAgain(Friendship $existing, Learner $from): Friendship
    {
        switch ($existing->status) {
            case FriendshipStatus::Pending->value:
                // Our own request again: nothing to do. The other side's: the requests meet.
                return $existing->requested_by_learner_id === $from->id ? $existing : $this->accept($from, $existing->id);
            case FriendshipStatus::Blocked->value:
                if ($existing->requested_by_learner_id === $from->id) {
                    throw FriendshipException::blocked();
                }

                return $existing;
            default:
                return $existing;
        }
    }

    private function delete(Friendship $friendship, Learner $by, string $how): void
    {
        $deleted = Friendship::query()->whereKey($friendship->id)->where('status', $friendship->status)->delete();
        if ($deleted === 0) {
            return; // a parallel call ended it first: one event, one cancellation
        }

        $this->cancelOpenChallenges($friendship->learner_id, $friendship->friend_id, CarbonImmutable::now());
        $this->events->record(ProductEventName::FriendRemoved, ['friendship_id' => $friendship->id, 'how' => $how], learner: $by);
    }

    /** Open (pending / active) challenges of the pair end as `canceled`; the pair's "open" marker is released. */
    private function cancelOpenChallenges(int $low, int $high, CarbonImmutable $now): void
    {
        Challenge::query()
            ->whereIn('status', ChallengeStatus::open())
            ->where(function (Builder $q) use ($low, $high): void {
                $q->where(fn (Builder $p) => $p->where('challenger_id', $low)->where('opponent_id', $high))
                    ->orWhere(fn (Builder $p) => $p->where('challenger_id', $high)->where('opponent_id', $low));
            })
            ->update([
                'status' => ChallengeStatus::Canceled->value,
                'open_pair' => null,
                'finished_at' => $now,
                'updated_at' => $now,
            ]);
    }

    private function visibleStreak(Learner $learner, CarbonImmutable $now): int
    {
        $last = $learner->streak_last_date;
        if ($last === null) {
            return 0;
        }

        $yesterday = $this->clock->today($learner, $now)->subDay()->toDateString();

        return $last->toDateString() >= $yesterday ? max(0, $learner->streak_current) : 0;
    }
}
