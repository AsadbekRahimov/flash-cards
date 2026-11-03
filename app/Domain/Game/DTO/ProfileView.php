<?php

declare(strict_types=1);

namespace App\Domain\Game\DTO;

/**
 * The game profile of a learner (docs/18 §12.8, docs/04 A.18): what he wears and his counters.
 *
 * @phpstan-type Worn array{key: string, asset: string|null}
 */
final readonly class ProfileView
{
    /** @param  array<string, Worn>  $equipped  by slot */
    public function __construct(
        public array $equipped,
        public int $stopsCleared,
        public int $citiesCleared,
        public int $coins,
        public int $tickets,
    ) {}
}
