<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Content\Services\LessonContentRefresher;
use Illuminate\Console\Command;

/**
 * `courses:recount-words` (docs/03 §6): recomputes `courses.words_count` of every course - the decks of learners
 * included - from its active words and drops the cached word counts of the lessons. The counter is kept up to
 * date by every path that changes words; this is the repair for a figure that drifted anyway, and `deploy.sh`
 * runs it after the migrations because `words_count` is the `total` of `GET /courses`. Not scheduled. Fast
 * (one COUNT per course) and idempotent: a second run reports 0 changed.
 */
final class RecountCourseWords extends Command
{
    /** How many corrected courses the output lists one by one. */
    private const LISTED = 20;

    /** @var string */
    protected $signature = 'courses:recount-words';

    /** @var string */
    protected $description = 'Recompute courses.words_count from the active words and drop the cached word counts of the lessons.';

    public function handle(LessonContentRefresher $refresher): int
    {
        $report = $refresher->refreshAll();

        foreach (array_slice($report->changes, 0, self::LISTED) as $change) {
            $this->line("  course #{$change['id']} ({$change['slug']}): {$change['from']} -> {$change['to']}");
        }
        if ($report->changed() > self::LISTED) {
            $this->line('  ... and '.($report->changed() - self::LISTED).' more');
        }

        $this->info("Courses checked: {$report->courses}, changed: {$report->changed()}; lesson caches dropped: {$report->lessons}");

        return self::SUCCESS;
    }
}
