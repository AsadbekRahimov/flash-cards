<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

use App\Domain\Learners\Support\Regions;

/**
 * A table of a region of one category (docs/35 §2.4, R7): its code, the number of participants and whether it is shown - from
 * `leagues.regions.min_participants` (5) on; below it the table is hidden and the screen explains why. As the viewer's own line
 * (`me.region`) it adds the viewer's place in it (null when hidden, or when the viewer has no place). Nothing of the region of another
 * learner leaves the server: only the tables a viewer opens and their own region.
 */
final readonly class RegionStanding
{
    public function __construct(
        public string $code,
        public int $participants,
        public bool $shown,
        public int $minParticipants,
        public ?int $rank = null,
    ) {}

    /** @return array{code: string, name: string, participants: int, shown: bool, min_participants: int} */
    public function toArray(string $locale): array
    {
        return [
            'code' => $this->code,
            'name' => Regions::name($this->code, $locale),
            'participants' => $this->participants,
            'shown' => $this->shown,
            'min_participants' => $this->minParticipants,
        ];
    }

    /** @return array{code: string, name: string, participants: int, shown: bool, min_participants: int, rank: int|null} */
    public function toMeArray(string $locale): array
    {
        return [...$this->toArray($locale), 'rank' => $this->rank];
    }
}
