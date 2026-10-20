<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Engagement\DTO\ChallengeEntry;
use App\Domain\Engagement\DTO\ChallengesOverview;
use App\Domain\Engagement\Enums\ChallengeMetric;
use App\Domain\Engagement\Enums\ChallengeStatus;
use App\Domain\Engagement\Enums\FriendDmKind;
use App\Domain\Engagement\Enums\FriendshipStatus;
use App\Domain\Engagement\Exceptions\ChallengeException;
use App\Domain\Learners\Services\LearnerClock;
use App\Jobs\SendFriendDmJob;
use App\Models\Challenge;
use App\Models\Friendship;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Friend challenges (docs/13 §1.5, §2.4, §7.5): "who repeats more in N days".
 *
 * Life cycle: `propose` (only to an accepted friend; ONE open challenge per pair — `open_pair` unique)
 * → `pending` → `accept` → `active` → `close` → `finished`; or `declined` / `expired` (nobody answered within
 * `friends.challenges.pending_ttl_hours`) / `canceled` (the friendship ended, FriendshipService).
 *
 * The period. Everything per day lives on the learner's LOCAL date (`learner_daily_stats.date`), so a
 * challenge is a range of dates, fixed once at acceptance and deterministic from then on:
 *   - it starts the local day AFTER the acceptance (in the CHALLENGER's time zone), so nobody starts with
 *     cards already reviewed that day, and lasts `days` dates: `starts_on`..`ends_on`;
 *   - each side is scored by THEIR OWN local dates in that range (`SUM(reviews_count | new_words_count)`);
 *   - it closes once `ends_on` is over in BOTH zones (`ends_at` = the later of the two local midnights,
 *     taken from the zones at acceptance), so nobody loses the tail of the last day to a time-zone offset.
 * A draw has no winner. `close` is idempotent: the claim is a conditional UPDATE `active` → `finished`, and
 * only the call that wins it raises the event and the DMs. No learner locks (docs/13 §3 rule 4).
 */
final class ChallengeService
{
    private const MAX_DAYS = 30;

    private const FINISHED_LIMIT = 20;

    public function __construct(
        private readonly FriendshipService $friendships,
        private readonly LearnerClock $clock,
        private readonly ProductEventRecorder $events,
    ) {}

    /**
     * @throws ChallengeException
     */
    public function propose(Learner $from, Learner $to, string $metric, ?int $days = null, ?CarbonImmutable $now = null): Challenge
    {
        $now ??= CarbonImmutable::now();

        $metricEnum = ChallengeMetric::tryFrom($metric) ?? throw ChallengeException::invalid('metric');
        $days ??= max(1, (int) config('friends.challenges.days'));
        if ($days < 1 || $days > self::MAX_DAYS) {
            throw ChallengeException::invalid('length');
        }

        if ($from->id === $to->id || ! $this->friendships->areFriends($from, $to)) {
            throw ChallengeException::notFriends();
        }

        [$low, $high] = FriendshipService::pair($from->id, $to->id);
        $this->expireStaleBetween($low, $high, $now);

        try {
            // Savepoint: the unique violation of `open_pair` must not poison an outer (PostgreSQL) transaction.
            /** @var Challenge $challenge */
            $challenge = DB::transaction(static fn (): Challenge => Challenge::query()->create([
                'challenger_id' => $from->id,
                'opponent_id' => $to->id,
                'metric' => $metricEnum->value,
                'days' => $days,
                'status' => ChallengeStatus::Pending->value,
                'open_pair' => "{$low}:{$high}",
            ]));
        } catch (UniqueConstraintViolationException) {
            throw ChallengeException::exists();
        }

        $this->events->record(
            ProductEventName::ChallengeProposed,
            ['challenge_id' => $challenge->id, 'metric' => $metricEnum->value, 'days' => $days],
            learner: $from,
        );
        SendFriendDmJob::send(FriendDmKind::ChallengeProposed, $challenge->id);

        return $challenge;
    }

    /**
     * The challenged friend accepts: the period is fixed (see the class docblock). Already active → returned
     * as is; a challenge nobody answered in time becomes `expired`.
     *
     * @throws ChallengeException
     */
    public function accept(Learner $by, int $challengeId, ?CarbonImmutable $now = null): Challenge
    {
        $now ??= CarbonImmutable::now();
        $challenge = $this->find($by, $challengeId);

        if ($challenge->challenger_id === $by->id) {
            throw ChallengeException::notOpponent();
        }
        if ($challenge->status === ChallengeStatus::Active->value) {
            return $challenge;
        }
        if ($challenge->status !== ChallengeStatus::Pending->value) {
            throw ChallengeException::closed();
        }
        if ($this->isStale($challenge, $now)) {
            $this->finalize($challenge->id, ChallengeStatus::Pending, ChallengeStatus::Expired, $now);

            throw ChallengeException::closed();
        }

        $challenger = Learner::query()->find($challenge->challenger_id);
        if ($challenger === null || ! $this->friendships->areFriends($challenger, $by)) {
            // The challenger deleted their profile / the friendship ended without canceling this one.
            $this->finalize($challenge->id, ChallengeStatus::Pending, ChallengeStatus::Canceled, $now);

            throw ChallengeException::closed();
        }

        $startsOn = $this->clock->today($challenger, $now)->addDay();
        $endsOn = $startsOn->addDays($challenge->days - 1);
        $endsAt = max(
            $this->clock->localDateBoundsUtc($challenger, $endsOn->toDateString())->endUtc,
            $this->clock->localDateBoundsUtc($by, $endsOn->toDateString())->endUtc,
        );

        $updated = Challenge::query()
            ->whereKey($challenge->id)
            ->where('status', ChallengeStatus::Pending->value)
            ->update([
                'status' => ChallengeStatus::Active->value,
                'starts_at' => $this->clock->localDateBoundsUtc($challenger, $startsOn->toDateString())->startUtc,
                'ends_at' => $endsAt,
                'starts_on' => self::storedDate($startsOn->toDateString()),
                'ends_on' => self::storedDate($endsOn->toDateString()),
                'updated_at' => $now,
            ]);

        $challenge->refresh();
        if ($updated === 0) {
            return $challenge; // a parallel accept / decline got there first
        }

        $this->events->record(
            ProductEventName::ChallengeAccepted,
            ['challenge_id' => $challenge->id, 'metric' => $challenge->metric, 'starts_on' => $startsOn->toDateString()],
            learner: $by,
        );
        SendFriendDmJob::send(FriendDmKind::ChallengeAccepted, $challenge->id);

        return $challenge;
    }

    /**
     * The challenged friend declines (`declined`; the pair may challenge again). Repeating it is a no-op.
     *
     * @throws ChallengeException
     */
    public function decline(Learner $by, int $challengeId, ?CarbonImmutable $now = null): Challenge
    {
        $challenge = $this->find($by, $challengeId);

        if ($challenge->challenger_id === $by->id) {
            throw ChallengeException::notOpponent();
        }
        if ($challenge->status === ChallengeStatus::Declined->value) {
            return $challenge;
        }
        if ($challenge->status !== ChallengeStatus::Pending->value) {
            throw ChallengeException::closed();
        }

        $this->finalize($challenge->id, ChallengeStatus::Pending, ChallengeStatus::Declined, $now ?? CarbonImmutable::now());

        return $challenge->refresh();
    }

    /**
     * Closes an active challenge whose period is over: scores from `learner_daily_stats`, the winner (none
     * on a draw), the event and the two DMs — once, by whoever wins the conditional UPDATE. Not due yet, or
     * not active → returned unchanged.
     */
    public function close(Challenge $challenge, ?CarbonImmutable $now = null): Challenge
    {
        $now ??= CarbonImmutable::now();

        $startsOn = $challenge->starts_on;
        $endsOn = $challenge->ends_on;
        $endsAt = $challenge->ends_at;
        if ($challenge->status !== ChallengeStatus::Active->value
            || $startsOn === null
            || $endsOn === null
            || $endsAt === null
            || $now->lt($endsAt)
        ) {
            return $challenge;
        }

        $metric = $challenge->metric();
        $from = $startsOn->toDateString();
        $to = $endsOn->toDateString();
        $challengerScore = $this->score($challenge->challenger_id, $metric, $from, $to);
        $opponentScore = $this->score($challenge->opponent_id, $metric, $from, $to);

        $winner = match (true) {
            $challengerScore > $opponentScore => $challenge->challenger_id,
            $opponentScore > $challengerScore => $challenge->opponent_id,
            default => null,
        };

        $updated = Challenge::query()
            ->whereKey($challenge->id)
            ->where('status', ChallengeStatus::Active->value)
            ->update([
                'status' => ChallengeStatus::Finished->value,
                'open_pair' => null,
                'winner_learner_id' => $winner,
                'scores' => json_encode(['challenger' => $challengerScore, 'opponent' => $opponentScore], JSON_THROW_ON_ERROR),
                'finished_at' => $now,
                'updated_at' => $now,
            ]);

        $challenge->refresh();
        if ($updated === 0) {
            return $challenge; // closed by a parallel run: it has raised the event and the DMs
        }

        $this->events->record(
            ProductEventName::ChallengeFinished,
            [
                'challenge_id' => $challenge->id,
                'metric' => $challenge->metric,
                'result' => $winner === null ? 'draw' : ($winner === $challenge->challenger_id ? 'challenger' : 'opponent'),
            ],
            learner: Learner::query()->find($challenge->challenger_id),
        );
        SendFriendDmJob::send(FriendDmKind::ChallengeFinished, $challenge->id, 'challenger');
        SendFriendDmJob::send(FriendDmKind::ChallengeFinished, $challenge->id, 'opponent');

        return $challenge;
    }

    /**
     * Scheduler: closes every active challenge that is due and expires the pending ones nobody answered.
     * Safe to run twice or in parallel.
     *
     * @return int how many challenges changed state
     */
    public function closeDue(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $changed = 0;

        Challenge::query()
            ->where('status', ChallengeStatus::Active->value)
            ->where('ends_at', '<=', $now)
            ->orderBy('id')
            ->each(function (Challenge $challenge) use ($now, &$changed): void {
                if ($this->close($challenge, $now)->status === ChallengeStatus::Finished->value) {
                    $changed++;
                }
            });

        $changed += Challenge::query()
            ->where('status', ChallengeStatus::Pending->value)
            ->where('created_at', '<=', $now->subHours($this->pendingTtlHours()))
            ->update([
                'status' => ChallengeStatus::Expired->value,
                'open_pair' => null,
                'finished_at' => $now,
                'updated_at' => $now,
            ]);

        return $changed;
    }

    /**
     * The challenge `$id` if `$by` is one of its sides; a stranger's id is "not found", like a missing one.
     *
     * @throws ChallengeException
     */
    public function find(Learner $by, int $id): Challenge
    {
        /** @var Challenge|null $challenge */
        $challenge = $this->involving($by->id)->whereKey($id)->first();

        return $challenge ?? throw ChallengeException::notFound();
    }

    /** The challenge as `$viewer` sees it (live score while active). */
    public function entry(Learner $viewer, Challenge $challenge): ChallengeEntry
    {
        return $this->entries($viewer, [$challenge])[0];
    }

    /** The viewer's open challenges (newest first) and the latest finished ones. */
    public function overview(Learner $viewer): ChallengesOverview
    {
        /** @var list<Challenge> $open */
        $open = $this->involving($viewer->id)->whereIn('status', ChallengeStatus::open())->orderByDesc('id')->limit(50)->get()->values()->all();
        /** @var list<Challenge> $finished */
        $finished = $this->involving($viewer->id)->where('status', ChallengeStatus::Finished->value)->orderByDesc('id')->limit(self::FINISHED_LIMIT)->get()->values()->all();

        return new ChallengesOverview(
            open: $this->entries($viewer, $open),
            finished: $this->entries($viewer, $finished),
            days: max(1, (int) config('friends.challenges.days')),
        );
    }

    /**
     * @param  list<Challenge>  $challenges
     * @return list<ChallengeEntry>
     */
    private function entries(Learner $viewer, array $challenges): array
    {
        if ($challenges === []) {
            return [];
        }

        $otherIds = array_values(array_unique(array_map(static fn (Challenge $c): int => $c->otherId($viewer->id), $challenges)));
        $names = Learner::query()->whereIn('id', $otherIds)->pluck('first_name', 'id');

        /** @var array<int, int> $friendshipIds other learner id => friendship id */
        $friendshipIds = [];
        $rows = Friendship::query()
            ->where(fn (Builder $q) => $q->where('learner_id', $viewer->id)->orWhere('friend_id', $viewer->id))
            ->where('status', FriendshipStatus::Accepted->value)
            ->get();
        foreach ($rows as $row) {
            $friendshipIds[$row->otherId($viewer->id)] = $row->id;
        }

        $entries = [];
        foreach ($challenges as $c) {
            $isChallenger = $c->challenger_id === $viewer->id;
            $scores = $this->scoresOf($c);
            $mine = $scores === null ? null : ($isChallenger ? $scores[0] : $scores[1]);
            $theirs = $scores === null ? null : ($isChallenger ? $scores[1] : $scores[0]);

            $other = $c->otherId($viewer->id);
            $name = $names->get($other);

            $entries[] = new ChallengeEntry(
                id: $c->id,
                status: $c->status,
                metric: $c->metric,
                days: $c->days,
                role: $isChallenger ? 'challenger' : 'opponent',
                opponentName: is_string($name) ? $name : null,
                friendshipId: $friendshipIds[$other] ?? null,
                startsOn: $c->starts_on?->toDateString(),
                endsOn: $c->ends_on?->toDateString(),
                createdAt: $c->created_at,
                myScore: $mine,
                theirScore: $theirs,
                result: $c->status === ChallengeStatus::Finished->value
                    ? ($c->winner_learner_id === null ? 'draw' : ($c->winner_learner_id === $viewer->id ? 'won' : 'lost'))
                    : null,
            );
        }

        return $entries;
    }

    /**
     * (challenger, opponent) scores: final for a finished challenge, live for an active one, null otherwise.
     *
     * @return array{0: int, 1: int}|null
     */
    private function scoresOf(Challenge $c): ?array
    {
        $final = $c->scores;
        if ($c->status === ChallengeStatus::Finished->value && is_array($final)) {
            return [(int) ($final['challenger'] ?? 0), (int) ($final['opponent'] ?? 0)];
        }

        $startsOn = $c->starts_on;
        $endsOn = $c->ends_on;
        if ($c->status === ChallengeStatus::Active->value && $startsOn !== null && $endsOn !== null) {
            $from = $startsOn->toDateString();
            $to = $endsOn->toDateString();

            return [
                $this->score($c->challenger_id, $c->metric(), $from, $to),
                $this->score($c->opponent_id, $c->metric(), $from, $to),
            ];
        }

        return null;
    }

    /** `SUM(<metric column>)` of the learner's daily stats over the local dates `$from`..`$to` (inclusive). */
    private function score(int $learnerId, ChallengeMetric $metric, string $from, string $to): int
    {
        return (int) LearnerDailyStats::query()
            ->where('learner_id', $learnerId)
            ->where('date', '>=', self::storedDate($from))
            ->where('date', '<=', self::storedDate($to))
            ->sum($metric->column());
    }

    /** @return Builder<Challenge> the challenges one learner is a side of */
    private function involving(int $learnerId): Builder
    {
        return Challenge::query()->where(fn (Builder $q) => $q->where('challenger_id', $learnerId)->orWhere('opponent_id', $learnerId));
    }

    private function isStale(Challenge $challenge, CarbonImmutable $now): bool
    {
        $created = $challenge->created_at;

        return $created !== null && $created->lte($now->subHours($this->pendingTtlHours()));
    }

    private function pendingTtlHours(): int
    {
        return max(1, (int) config('friends.challenges.pending_ttl_hours'));
    }

    /** A nobody-answered challenge of the pair would block a new one for good: expire it first. */
    private function expireStaleBetween(int $low, int $high, CarbonImmutable $now): void
    {
        Challenge::query()
            ->where('status', ChallengeStatus::Pending->value)
            ->where('open_pair', "{$low}:{$high}")
            ->where('created_at', '<=', $now->subHours($this->pendingTtlHours()))
            ->update([
                'status' => ChallengeStatus::Expired->value,
                'open_pair' => null,
                'finished_at' => $now,
                'updated_at' => $now,
            ]);
    }

    /** A conditional move out of the open states that also releases the pair's `open_pair`. */
    private function finalize(int $id, ChallengeStatus $from, ChallengeStatus $to, CarbonImmutable $now): void
    {
        Challenge::query()
            ->whereKey($id)
            ->where('status', $from->value)
            ->update(['status' => $to->value, 'open_pair' => null, 'finished_at' => $now, 'updated_at' => $now]);
    }

    /** What a `date` column holds for a local date: sqlite keeps `Y-m-d 00:00:00` when written through a model cast, so compare in that form. */
    private static function storedDate(string $localDate): string
    {
        return (string) (new LearnerDailyStats)->fromDateTime($localDate);
    }
}
