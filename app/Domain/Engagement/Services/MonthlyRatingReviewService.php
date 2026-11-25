<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Services;

use App\Domain\Administration\Services\PendingLeagueMonthsBadge;
use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Engagement\DTO\MonthCandidateReview;
use App\Domain\Engagement\DTO\MonthReview;
use App\Domain\Engagement\Enums\LeagueMonthCandidateStatus;
use App\Domain\Engagement\Enums\LeagueMonthStatus;
use App\Domain\Engagement\Enums\ScoreCategory;
use App\Domain\Engagement\Events\LeagueMonthConfirmed;
use App\Domain\Engagement\Exceptions\LeagueMonthException;
use App\Domain\Operations\Support\PureCache;
use App\Models\AuditLog;
use App\Models\LeagueMonth;
use App\Models\LeagueMonthCandidate;
use App\Models\Learner;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * The administrator's part of the monthly rating (docs/13 §7.6): the review of a closed month and the decisions —
 * confirm the winners, disqualify a candidate (the next one moves up), take a disqualification back, mark a prize as
 * delivered, edit the prizes. Every decision runs under `FOR UPDATE` of the month row (two administrators clicking at
 * once are serialised; the second meets the new state and is refused), checks the state it needs, and writes an
 * `audit_logs` row in the same transaction. A refused action throws {@see LeagueMonthException} with a code the panel
 * can show.
 *
 * The places are given at the confirmation only (the candidates not disqualified, by rank, `leagues.monthly.places` of
 * them), so a disqualification before it shifts the places by itself; after it the places are history: no
 * disqualification, no reinstatement, no change of prizes — only "prize delivered".
 */
final class MonthlyRatingReviewService
{
    public const ACTION_CONFIRMED = 'league_month.confirmed';

    public const ACTION_DISQUALIFIED = 'league_month.candidate_disqualified';

    public const ACTION_REINSTATED = 'league_month.candidate_reinstated';

    public const ACTION_PRIZE_DELIVERED = 'league_month.prize_delivered';

    public const ACTION_PRIZES_CHANGED = 'league_month.prizes_changed';

    public const ACTION_CONGRATS_RESENT = 'league_month.congratulations_resent';

    public const MAX_REASON_LENGTH = 500;

    public const MAX_PRIZE_LENGTH = 200;

    public function __construct(
        private readonly MonthlyRatingService $rating,
        private readonly ProductEventRecorder $events,
    ) {}

    /**
     * The months, newest first, with the number of candidates (`candidates_count`) — the list page.
     *
     * @return EloquentCollection<int, LeagueMonth>
     */
    public function months(int $limit = 24): EloquentCollection
    {
        /** @var EloquentCollection<int, LeagueMonth> $months */
        $months = LeagueMonth::query()->withCount('candidates')->orderByDesc('starts_on')->limit(max(1, $limit))->get();

        return $months;
    }

    /** The review page of one month: its prizes and candidates by rank, with the provisional (or final) places. */
    public function review(LeagueMonth $month): MonthReview
    {
        /** @var EloquentCollection<int, LeagueMonthCandidate> $candidates */
        $candidates = LeagueMonthCandidate::query()
            // Soft-deleted learners too: the page marks them (the winner's DM cannot reach them).
            ->with(['learner' => fn ($q) => $q->withTrashed()])
            ->where('league_month_id', $month->id)
            ->orderBy('rank')
            ->get();

        $places = $this->provisionalPlaces($month, $candidates);
        $rows = [];
        foreach ($candidates as $candidate) {
            $learner = $candidate->learner;
            $rows[] = new MonthCandidateReview($candidate, $learner instanceof Learner ? $learner : null, $places[$candidate->id] ?? null);
        }

        return new MonthReview($month, $this->rating->prizes($month), $rows);
    }

    /**
     * `pending_review → confirmed`; the places are final. After the commit: {@see LeagueMonthConfirmed} (the bot's congratulations),
     * `league.month_confirmed`.
     *
     *   v1 month: the candidates not disqualified get the places 1..`places` by rank;
     *   v2 month (docs/32 §7, owner's decision В5): each category on its own - the place of a candidate not disqualified is its
     *             competition rank among the candidates of the category not disqualified (equal tuples, equal `rank` → one place);
     *             everybody with a place within `places(category)` wins, a shared prize place included. When that gives more winners
     *             than `places + tie_reserve`, the confirmation is refused ({@see LeagueMonthException::TIE_BEYOND_RESERVE}) unless
     *             the administrator confirms with `$acceptTieOverflow` (the published rule: decided by hand).
     */
    public function confirm(LeagueMonth $month, User $by, ?string $ip = null, ?CarbonImmutable $now = null, bool $acceptTieOverflow = false): LeagueMonth
    {
        $now ??= CarbonImmutable::now();

        /** @var list<array{learner_id: int, place: int, points: int, category?: string}> $winners */
        $winners = [];

        DB::transaction(function () use ($month, $by, $ip, $now, $acceptTieOverflow, &$winners): void {
            $locked = $this->lockPending($month);

            $winners = MonthlyRatingService::isV2($locked) ? $this->confirmCategories($locked, $acceptTieOverflow) : $this->confirmPlaces($locked);

            $locked->forceFill([
                'status' => LeagueMonthStatus::Confirmed->value,
                'confirmed_at' => $now,
                'confirmed_by_user_id' => $by->id,
            ])->save();
            $month->setRawAttributes($locked->getAttributes(), true);

            $this->audit(self::ACTION_CONFIRMED, $locked, $by, $ip, ['winners' => $winners, ...($acceptTieOverflow ? ['tie_overflow_accepted' => true] : [])]);
        });

        PureCache::forgetMany([MonthlyRatingService::topKey($month->id)]);
        PendingLeagueMonthsBadge::forget();
        $this->events->record(ProductEventName::LeagueMonthConfirmed, [
            'month_id' => $month->id,
            'starts_on' => $month->starts_on->toDateString(),
            'winners' => count($winners),
        ], user: $by);
        LeagueMonthConfirmed::dispatch($month->id, $month->starts_on->toDateString(), $winners);

        return $month;
    }

    /**
     * Raises {@see LeagueMonthConfirmed} again for a confirmed month (the first one may have been lost after the commit: the
     * queue or the dispatch failed). The DM of {@see MonthWinnerSender} is once per (winner, month), so those already
     * congratulated get nothing and the rest are served. Audit row in the same transaction; the event goes after the commit.
     */
    public function resendCongratulations(LeagueMonth $month, User $by, ?string $ip = null): int
    {
        /** @var list<array{learner_id: int, place: int, points: int, category?: string}> $winners */
        $winners = [];

        DB::transaction(function () use ($month, $by, $ip, &$winners): void {
            $locked = $this->lockMonth($month);
            if (! $locked->isConfirmed()) {
                throw LeagueMonthException::monthNotConfirmed();
            }

            $candidates = LeagueMonthCandidate::query()
                ->where('league_month_id', $locked->id)
                ->where('status', LeagueMonthCandidateStatus::Winner->value)
                ->whereNotNull('place')
                ->orderBy('place')
                ->get();
            foreach ($candidates as $candidate) {
                $winner = ['learner_id' => $candidate->learner_id, 'place' => (int) $candidate->place, 'points' => $candidate->points];
                if ($candidate->category !== null) {
                    $winner['category'] = $candidate->category;
                }
                $winners[] = $winner;
            }

            $this->audit(self::ACTION_CONGRATS_RESENT, $locked, $by, $ip, ['winners' => count($winners)]);
        });

        LeagueMonthConfirmed::dispatch($month->id, $month->starts_on->toDateString(), $winners);

        return count($winners);
    }

    /** Disqualifies a candidate of a `pending_review` month with a reason (kept with the row and in the audit log). */
    public function disqualify(LeagueMonth $month, int $candidateId, string $reason, User $by, ?string $ip = null, ?CarbonImmutable $now = null): LeagueMonthCandidate
    {
        $now ??= CarbonImmutable::now();
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > self::MAX_REASON_LENGTH) {
            throw LeagueMonthException::reasonInvalid(self::MAX_REASON_LENGTH);
        }

        /** @var LeagueMonthCandidate $candidate */
        $candidate = DB::transaction(function () use ($month, $candidateId, $reason, $by, $ip, $now): LeagueMonthCandidate {
            $locked = $this->lockPending($month);
            $candidate = $this->candidateOf($locked, $candidateId);
            if ($candidate->isDisqualified()) {
                throw LeagueMonthException::candidateAlreadyDisqualified();
            }

            $candidate->forceFill([
                'status' => LeagueMonthCandidateStatus::Disqualified->value,
                'place' => null,
                'disqualified_at' => $now,
                'disqualified_by_user_id' => $by->id,
                'disqualify_reason' => $reason,
            ])->save();

            $this->audit(self::ACTION_DISQUALIFIED, $locked, $by, $ip, [
                'candidate_id' => $candidate->id,
                'learner_id' => $candidate->learner_id,
                'rank' => $candidate->rank,
                'points' => $candidate->points,
                'reason' => $reason,
            ]);

            return $candidate;
        });

        $this->events->record(ProductEventName::LeagueMonthCandidateDisqualified, [
            'month_id' => $month->id,
            'candidate_id' => $candidate->id,
            'rank' => $candidate->rank,
        ], learner: $candidate->learner, user: $by);

        return $candidate;
    }

    /** Takes a disqualification back (the month still `pending_review`). */
    public function reinstate(LeagueMonth $month, int $candidateId, User $by, ?string $ip = null): LeagueMonthCandidate
    {
        /** @var LeagueMonthCandidate $candidate */
        $candidate = DB::transaction(function () use ($month, $candidateId, $by, $ip): LeagueMonthCandidate {
            $locked = $this->lockPending($month);
            $candidate = $this->candidateOf($locked, $candidateId);
            if (! $candidate->isDisqualified()) {
                throw LeagueMonthException::candidateNotDisqualified();
            }

            $reason = $candidate->disqualify_reason;
            $candidate->forceFill([
                'status' => LeagueMonthCandidateStatus::Candidate->value,
                'disqualified_at' => null,
                'disqualified_by_user_id' => null,
                'disqualify_reason' => null,
            ])->save();

            $this->audit(self::ACTION_REINSTATED, $locked, $by, $ip, [
                'candidate_id' => $candidate->id,
                'learner_id' => $candidate->learner_id,
                'rank' => $candidate->rank,
                'previous_reason' => $reason,
            ]);

            return $candidate;
        });

        $this->events->record(ProductEventName::LeagueMonthCandidateReinstated, [
            'month_id' => $month->id,
            'candidate_id' => $candidate->id,
            'rank' => $candidate->rank,
        ], learner: $candidate->learner, user: $by);

        return $candidate;
    }

    /** Marks the prize of a confirmed winner as handed over (by hand, outside the system). Once. */
    public function markPrizeDelivered(LeagueMonth $month, int $candidateId, User $by, ?string $ip = null, ?CarbonImmutable $now = null): LeagueMonthCandidate
    {
        $now ??= CarbonImmutable::now();

        /** @var LeagueMonthCandidate $candidate */
        $candidate = DB::transaction(function () use ($month, $candidateId, $by, $ip, $now): LeagueMonthCandidate {
            $locked = $this->lockMonth($month);
            if (! $locked->isConfirmed()) {
                throw LeagueMonthException::monthNotConfirmed();
            }

            $candidate = $this->candidateOf($locked, $candidateId);
            if (! $candidate->isWinner() || $candidate->place === null) {
                throw LeagueMonthException::candidateNotWinner();
            }
            if ($candidate->prize_delivered_at !== null) {
                throw LeagueMonthException::prizeAlreadyDelivered();
            }

            $candidate->forceFill(['prize_delivered_at' => $now, 'prize_delivered_by_user_id' => $by->id])->save();

            $this->audit(self::ACTION_PRIZE_DELIVERED, $locked, $by, $ip, [
                'candidate_id' => $candidate->id,
                'learner_id' => $candidate->learner_id,
                'place' => $candidate->place,
            ]);

            return $candidate;
        });

        $this->events->record(ProductEventName::LeagueMonthPrizeDelivered, [
            'month_id' => $month->id,
            'candidate_id' => $candidate->id,
            'place' => (int) $candidate->place,
        ], learner: $candidate->learner, user: $by);

        return $candidate;
    }

    /**
     * Replaces the prizes of a month that is not confirmed yet: a list of `{place, ru, uz}` (places 1..`places`, each once,
     * titles up to 200 characters; a place with empty titles is dropped). An empty list = no prizes this month; `null`
     * = back to the configured defaults.
     *
     * @param  array<array-key, mixed>|null  $prizes  what the form sends: a list of `{place, ru, uz}` items, or for a v2 month `{free: […], pro: […]}`
     */
    public function setPrizes(LeagueMonth $month, ?array $prizes, User $by, ?string $ip = null): LeagueMonth
    {
        $normalised = $prizes === null ? null : (
            MonthlyRatingService::isV2($month) && ! array_is_list($prizes) ? $this->normaliseCategoryPrizes($prizes) : $this->normalisePrizes($prizes)
        );

        DB::transaction(function () use ($month, $normalised, $by, $ip): void {
            $locked = $this->lockMonth($month);
            if ($locked->isConfirmed()) {
                throw LeagueMonthException::prizesLocked();
            }

            $before = $locked->prizes;
            $locked->forceFill(['prizes' => $normalised])->save();
            $month->setRawAttributes($locked->getAttributes(), true);

            $this->audit(self::ACTION_PRIZES_CHANGED, $locked, $by, $ip, ['before' => $before, 'after' => $normalised]);
        });

        return $month;
    }

    /** @return list<array{date: string, reviews: int, correct: int, new_words: int, goal_met: bool, game_points: int, time_spent_ms: int, points: int, counted: int}> */
    public function dailyBreakdown(LeagueMonth $month, int $learnerId): array
    {
        return $this->rating->dailyBreakdown($month, $learnerId);
    }

    /**
     * The place every candidate would get now: confirmed months show the given places; a pending one hands 1..`places`
     * to the candidates not disqualified, by rank.
     *
     * @param  EloquentCollection<int, LeagueMonthCandidate>  $candidates  by rank
     * @return array<int, int> candidate id => place
     */
    private function provisionalPlaces(LeagueMonth $month, EloquentCollection $candidates): array
    {
        $places = [];
        if ($month->isConfirmed()) {
            foreach ($candidates as $candidate) {
                if ($candidate->place !== null) {
                    $places[$candidate->id] = $candidate->place;
                }
            }

            return $places;
        }

        if (MonthlyRatingService::isV2($month)) {
            foreach (ScoreCategory::cases() as $category) {
                foreach ($this->categoryPlaces($candidates, $category) as $id => $place) {
                    if ($place <= $this->rating->places($category)) {
                        $places[$id] = $place;
                    }
                }
            }

            return $places;
        }

        $place = 0;
        foreach ($candidates as $candidate) {
            if ($candidate->isDisqualified() || $place >= $this->rating->places()) {
                continue;
            }
            $place++;
            $places[$candidate->id] = $place;
        }

        return $places;
    }

    /**
     * The winners of a v1 month: the candidates not disqualified get the places 1..`places` by rank (the caller's transaction).
     *
     * @return list<array{learner_id: int, place: int, points: int, category?: string}>
     */
    private function confirmPlaces(LeagueMonth $month): array
    {
        /** @var EloquentCollection<int, LeagueMonthCandidate> $candidates */
        $candidates = LeagueMonthCandidate::query()
            ->where('league_month_id', $month->id)
            ->where('status', '<>', LeagueMonthCandidateStatus::Disqualified->value)
            ->orderBy('rank')
            ->limit($this->rating->places())
            ->get();

        $winners = [];
        $place = 0;
        foreach ($candidates as $candidate) {
            $place++;
            $candidate->forceFill(['status' => LeagueMonthCandidateStatus::Winner->value, 'place' => $place])->save();
            $winners[] = ['learner_id' => $candidate->learner_id, 'place' => $place, 'points' => $candidate->points];
        }

        return $winners;
    }

    /**
     * The winners of a v2 month, category by category, written under the month's lock (the caller's transaction).
     *
     * @return list<array{learner_id: int, place: int, points: int, category?: string}>
     */
    private function confirmCategories(LeagueMonth $month, bool $acceptTieOverflow): array
    {
        /** @var EloquentCollection<int, LeagueMonthCandidate> $candidates */
        $candidates = LeagueMonthCandidate::query()
            ->where('league_month_id', $month->id)
            ->orderBy('rank')
            ->orderBy('id')
            ->get();

        $winners = [];
        foreach (ScoreCategory::cases() as $category) {
            $places = $this->rating->places($category);
            $won = array_filter($this->categoryPlaces($candidates, $category), static fn (int $place): bool => $place <= $places);
            $allowed = $places + $this->rating->tieReserve($category);
            if (count($won) > $allowed && ! $acceptTieOverflow) {
                throw LeagueMonthException::tieBeyondReserve($category->value, count($won), $allowed);
            }

            foreach ($candidates as $candidate) {
                $place = $won[$candidate->id] ?? null;
                if ($place === null) {
                    continue;
                }
                $candidate->forceFill(['status' => LeagueMonthCandidateStatus::Winner->value, 'place' => $place])->save();
                $winners[] = ['learner_id' => $candidate->learner_id, 'place' => $place, 'points' => $candidate->points, 'category' => $category->value];
            }
        }

        return $winners;
    }

    /**
     * The places of the candidates of one category not disqualified: the competition rank among them - a candidate whose `rank` (the
     * competition rank frozen at the close among all the eligible ones) equals the one before shares its place.
     *
     * @param  EloquentCollection<int, LeagueMonthCandidate>  $candidates  by rank
     * @return array<int, int> candidate id => place
     */
    private function categoryPlaces(EloquentCollection $candidates, ScoreCategory $category): array
    {
        $places = [];
        $position = 0;
        $previousRank = null;
        $place = 0;
        foreach ($candidates as $candidate) {
            if ($candidate->category !== $category->value || $candidate->isDisqualified()) {
                continue;
            }
            $position++;
            $place = $previousRank !== null && $candidate->rank === $previousRank ? $place : $position;
            $previousRank = $candidate->rank;
            $places[$candidate->id] = $place;
        }

        return $places;
    }

    /**
     * The prizes of a v2 month: `{free: [{place, ru, uz}], pro: [...]}` (either key may be missing - no prizes in that table), each
     * list checked as a v1 list against the places of its category.
     *
     * @param  array<array-key, mixed>  $prizes
     * @return array<string, list<array{place: int, ru: string, uz: string}>>
     */
    private function normaliseCategoryPrizes(array $prizes): array
    {
        $out = [];
        foreach ($prizes as $key => $list) {
            $category = is_string($key) ? ScoreCategory::tryFrom($key) : null;
            if ($category === null || ! is_array($list) || ! array_is_list($list)) {
                throw LeagueMonthException::prizesInvalid('the prizes of a month with two tables are {free: [...], pro: [...]}');
            }
            $out[$category->value] = $this->normalisePrizes($list, $this->rating->places($category));
        }

        return $out;
    }

    /**
     * @param  array<int, mixed>  $prizes
     * @return list<array{place: int, ru: string, uz: string}>
     */
    private function normalisePrizes(array $prizes, ?int $places = null): array
    {
        $places ??= $this->rating->places();
        $byPlace = [];
        foreach ($prizes as $item) {
            if (! is_array($item)) {
                throw LeagueMonthException::prizesInvalid('each prize is {place, ru, uz}');
            }
            $place = filter_var($item['place'] ?? null, FILTER_VALIDATE_INT);
            if ($place === false || $place < 1 || $place > $places) {
                throw LeagueMonthException::prizesInvalid('place must be 1..'.$places);
            }
            if (isset($byPlace[$place])) {
                throw LeagueMonthException::prizesInvalid("place {$place} is listed twice");
            }
            $ru = trim((string) ($item['ru'] ?? ''));
            $uz = trim((string) ($item['uz'] ?? ''));
            if (mb_strlen($ru) > self::MAX_PRIZE_LENGTH || mb_strlen($uz) > self::MAX_PRIZE_LENGTH) {
                throw LeagueMonthException::prizesInvalid('a title is longer than '.self::MAX_PRIZE_LENGTH.' characters');
            }
            if ($ru === '' && $uz === '') {
                continue;
            }
            $byPlace[$place] = ['place' => $place, 'ru' => $ru, 'uz' => $uz];
        }
        ksort($byPlace);

        return array_values($byPlace);
    }

    private function lockMonth(LeagueMonth $month): LeagueMonth
    {
        /** @var LeagueMonth|null $locked */
        $locked = LeagueMonth::query()->whereKey($month->id)->lockForUpdate()->first();
        if ($locked === null) {
            throw LeagueMonthException::monthNotPending();
        }

        return $locked;
    }

    private function lockPending(LeagueMonth $month): LeagueMonth
    {
        $locked = $this->lockMonth($month);
        if ($locked->status !== LeagueMonthStatus::PendingReview->value) {
            throw LeagueMonthException::monthNotPending();
        }

        return $locked;
    }

    private function candidateOf(LeagueMonth $month, int $candidateId): LeagueMonthCandidate
    {
        /** @var LeagueMonthCandidate|null $candidate */
        $candidate = LeagueMonthCandidate::query()->whereKey($candidateId)->where('league_month_id', $month->id)->first();
        if ($candidate === null) {
            throw LeagueMonthException::candidateNotFound();
        }

        return $candidate;
    }

    /** @param  array<string, mixed>  $meta */
    private function audit(string $action, LeagueMonth $month, User $by, ?string $ip, array $meta): void
    {
        AuditLog::query()->create([
            'user_id' => $by->id,
            'action' => $action,
            'entity_type' => LeagueMonth::class,
            'entity_id' => $month->id,
            'ip' => $ip,
            'meta' => ['starts_on' => $month->starts_on->toDateString(), ...$meta],
        ]);
    }
}
