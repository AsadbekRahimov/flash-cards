<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

/**
 * GET /api/twa/achievements: the whole catalog in display order with the learner's state, and the
 * invite link a share carries (null while `TELEGRAM_BOT_USERNAME` is not configured).
 */
final readonly class AchievementsOverview
{
    /** @param  list<AchievementEntry>  $entries */
    public function __construct(
        public array $entries,
        public ?string $shareLink,
    ) {}
}
