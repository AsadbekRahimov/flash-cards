<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

use App\Domain\Engagement\Enums\AchievementKey;
use Carbon\CarbonInterface;

/** One line of the achievements list: the key, whether it is unlocked / shared, the progress of a counting key. */
final readonly class AchievementEntry
{
    public function __construct(
        public AchievementKey $key,
        public ?CarbonInterface $unlockedAt,
        public ?CarbonInterface $sharedAt,
        public ?int $progressCurrent,
        public ?int $progressTarget,
    ) {}

    public function unlocked(): bool
    {
        return $this->unlockedAt !== null;
    }
}
