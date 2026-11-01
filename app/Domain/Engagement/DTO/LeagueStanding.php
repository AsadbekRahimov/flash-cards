<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

/**
 * One row of a group's standings (docs/13 §2.4): points summed from `learner_daily_stats` for the
 * week (or the final columns once the week is closed), ranked by points, ties by the earlier
 * placement (`league_members.id`). `name` is the learner's first name — nothing else of other
 * people leaves the server.
 *
 * The open standings are cached as arrays ({@see toArray()}): a cache holds scalars only, so the cache never
 * unserializes an object (`cache.serializable_classes` is `false`) and a deploy never meets a stale one.
 *
 * @phpstan-type LeagueStandingArray array{member_id:int, learner_id:int, name:string|null, points:int, rank:int}
 */
final readonly class LeagueStanding
{
    public function __construct(
        public int $memberId,
        public int $learnerId,
        public ?string $name,
        public int $points,
        public int $rank,
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
        ];
    }

    /** @param  LeagueStandingArray  $data */
    public static function fromArray(array $data): self
    {
        return new self($data['member_id'], $data['learner_id'], $data['name'], $data['points'], $data['rank']);
    }
}
