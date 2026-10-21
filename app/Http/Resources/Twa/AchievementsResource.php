<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Engagement\DTO\AchievementEntry;
use App\Domain\Engagement\DTO\AchievementsOverview;
use Illuminate\Http\Request;

/**
 * GET /api/twa/achievements (docs/04 A.13): the whole catalog in display order — unlocked ones with their
 * dates and a prefilled share text, locked ones with the progress towards the threshold. Titles are in the
 * request locale. `share_link` is the learner's invite link (null while the bot username is not configured:
 * the client then hides the share button).
 *
 * @property AchievementsOverview $resource
 */
final class AchievementsResource extends TwaResource
{
    /**
     * @return array{
     *   unlocked_count: int,
     *   total: int,
     *   share_link: string|null,
     *   items: list<array{
     *     key: string, title: string, description: string,
     *     unlocked_at: string|null, shared_at: string|null,
     *     progress: array{current: int, target: int}|null,
     *     share_text: string|null
     *   }>
     * }
     */
    public function toArray(Request $request): array
    {
        $locale = app()->getLocale();
        $entries = $this->resource->entries;

        return [
            'unlocked_count' => count(array_filter($entries, static fn (AchievementEntry $e): bool => $e->unlocked())),
            'total' => count($entries),
            'share_link' => $this->resource->shareLink,
            'items' => array_map(static fn (AchievementEntry $e): array => [
                'key' => $e->key->value,
                'title' => $e->key->title($locale),
                'description' => $e->key->description($locale),
                'unlocked_at' => $e->unlockedAt?->toIso8601String(),
                'shared_at' => $e->sharedAt?->toIso8601String(),
                'progress' => $e->progressCurrent === null || $e->progressTarget === null
                    ? null
                    : ['current' => $e->progressCurrent, 'target' => $e->progressTarget],
                'share_text' => $e->unlocked() ? self::shareText($e->key->title($locale), $locale) : null,
            ], $entries),
        ];
    }

    private static function shareText(string $title, string $locale): string
    {
        $text = __('achievements.share_text', ['title' => $title], $locale);

        return is_string($text) ? $text : '';
    }
}
