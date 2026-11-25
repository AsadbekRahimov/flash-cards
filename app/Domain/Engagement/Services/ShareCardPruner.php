<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Services;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Clears the cards disk (docs/36 §2б, `achievements:prune-cards`, weekly). A card is cached by what it shows, so every change of a
 * text, the brand, an illustration or the template leaves the old file behind. The renderer moves the time of a file it serves
 * again (once a day), so what is older than `$days` nobody needed for that long: it goes, and is drawn anew if it is ever asked
 * for. Removed as well: the PNG cards of template 1 in the old directory and temporary files a killed render left (older than a
 * day: a render in progress is never touched).
 *
 * A renderer serving an old card at the moment this deletes it is not stopped by a lock: it checks the file again after moving its
 * time and draws the card anew when it is gone or empty ({@see AchievementCardRenderer::renderSpec()}).
 */
final class ShareCardPruner
{
    public function __construct(private readonly AchievementCardRenderer $cards) {}

    /**
     * @return array{deleted: int, kept: int, failed: int}
     */
    public function prune(int $days): array
    {
        $disk = $this->cards->disk();
        $cutoff = now()->subDays(max(1, $days))->getTimestamp();
        $dayAgo = now()->subDay()->getTimestamp();
        $report = ['deleted' => 0, 'kept' => 0, 'failed' => 0];

        $directories = [$this->cards->directory() => $cutoff];
        $legacy = trim((string) config('achievements.legacy_directory', ''), '/');
        if ($legacy !== '' && $legacy !== $this->cards->directory()) {
            // Template 1 is never served again: a day of grace for a job that was about to send one at the deploy.
            $directories[$legacy] = $dayAgo;
        }

        foreach ($directories as $directory => $before) {
            foreach ($disk->files($directory) as $file) {
                try {
                    $limit = str_ends_with($file, '.tmp') ? $dayAgo : $before;
                    if ($disk->lastModified($file) >= $limit) {
                        $report['kept']++;

                        continue;
                    }

                    $disk->delete($file) ? $report['deleted']++ : $report['failed']++;
                } catch (Throwable $e) {
                    $report['failed']++;
                    Log::warning('achievements.card_prune_failed', ['file' => basename($file), 'exception' => $e::class]);
                }
            }
        }

        return $report;
    }
}
