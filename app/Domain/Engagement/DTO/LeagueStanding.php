<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

/**
 * One row of a group's standings (docs/13 §2.4, docs/32 §5.1): the points of the week (or the final columns once the week is
 * closed) and the place. `name` is the learner's first name — nothing else of other people leaves the server.
 *
 *   v1 week: the points summed from `learner_daily_stats`, ranked by points, ties by the earlier placement (`league_members.id`):
 *            every rank is distinct; `longWords` / `activeDays` are null;
 *   v2 week: the sum of the `best_days` best `week_points` of the rating days (docs/32 §3.5), the tie-break facts `longWords` (the
 *            `week_long` of those days) and `activeDays` (those days with points), the COMPETITION rank of the tuple
 *            (points, longWords, activeDays): 1, 2, 2, 4. The member id only keeps the order of a shared place stable.
 *
 * The open standings are cached as arrays ({@see toArray()}): a cache holds scalars only, so the cache never
 * unserializes an object (`cache.serializable_classes` is `false`) and a deploy never meets a stale one.
 *
 * @phpstan-type LeagueStandingArray array{member_id:int, learner_id:int, name:string|null, points:int, rank:int, long_words?:int|null, active_days?:int|null}
 */
final readonly class LeagueStanding
{
    public function __construct(
        public int $memberId,
        public int $learnerId,
        public ?string $name,
        public int $points,
        public int $rank,
        public ?int $longWords = null,
        public ?int $activeDays = null,
    ) {}

    /** @return LeagueStandingArray */
    public function toArray(): array
    {
        return [
            'member_id' => $this->memberId,
            'learner_id' => $this->learnerId,
            'name' => $this->name,
            'points' => $this->points,
            'rank' => $this->rank,
            'long_words' => $this->longWords,
            'active_days' => $this->activeDays,
        ];
    }

    /** @param  LeagueStandingArray  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['member_id'],
            $data['learner_id'],
            $data['name'],
            $data['points'],
            $data['rank'],
            $data['long_words'] ?? null,
            $data['active_days'] ?? null,
        );
    }

    /** Whether two rows share a place (v2): the same points and tie-break facts. */
    public function tiesWith(self $other): bool
    {
        return $this->points === $other->points && $this->longWords === $other->longWords && $this->activeDays === $other->activeDays;
    }
}
