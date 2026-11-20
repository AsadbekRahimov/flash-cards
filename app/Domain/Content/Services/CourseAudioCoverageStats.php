<?php

declare(strict_types=1);

namespace App\Domain\Content\Services;

use App\Domain\Administration\Services\WidgetCache;
use App\Domain\Content\DTO\AudioCoverageReport;
use Illuminate\Support\Facades\DB;

/**
 * The audio coverage of the courses that are published or on review, for the block «Требуют внимания» of the dashboard of
 * `/admin`: the same count as `audio:coverage <course>` without `--verify` ({@see WordAudioCoverageService}: active words with
 * `audio_path` recorded), for every such course in ONE grouped statement, and the courses below {@see THRESHOLD} percent — the
 * threshold of the check before publishing (docs/ADMIN_GUIDE.md, `audio:coverage --min=95`). The files on the disk are not
 * checked here (that is a walk over every word; the command does it).
 *
 * An array of scalars in the pure cache ({@see WidgetCache}, `admin:widget:audio_coverage:v1:all`, fresh {@see FRESH_SECONDS}, the top
 * of the K1 window of docs/19 §5): no reset on a write of `words.audio_path` — the voicing queue writes it word by word, a course
 * changes its status, words are imported and switched off, and the statement is cheap (≈ 30 ms on 100 000 words, docs/20 §1.13), so
 * the timer alone keeps a voiced course on the list no longer than the other K1 widgets. Numbers are cast back on read.
 */
final class CourseAudioCoverageStats
{
    public const WIDGET = 'audio_coverage';

    public const FRESH_SECONDS = 300;

    /** Percent: below it a course is not ready to publish (docs/ADMIN_GUIDE.md «Check the coverage before publishing»). */
    public const THRESHOLD = 95.0;

    /** The statuses of a course that is, or is about to be, in front of learners. */
    public const STATUSES = ['review', 'published'];

    public function __construct(private readonly WidgetCache $cache) {}

    /**
     * The courses below the threshold, the lowest coverage first.
     *
     * @return list<array{id: int, slug: string, status: string, words: int, with_audio: int, percent: float, missing: int}>
     */
    public function belowThreshold(): array
    {
        $rows = [];
        foreach ($this->cache->remember(self::WIDGET, 'all', self::FRESH_SECONDS, fn (): array => $this->compute()) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $words = is_numeric($row['words'] ?? null) ? (int) $row['words'] : 0;
            $withAudio = is_numeric($row['with_audio'] ?? null) ? (int) $row['with_audio'] : 0;
            $report = new AudioCoverageReport(stages: [], words: $words, withAudio: $withAudio, verified: null, problems: [], problemCount: 0);

            if ($words === 0 || $report->meets(self::THRESHOLD)) {
                continue;
            }

            $rows[] = [
                'id' => is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
                'slug' => is_scalar($row['slug'] ?? null) ? (string) $row['slug'] : '',
                'status' => is_scalar($row['status'] ?? null) ? (string) $row['status'] : '',
                'words' => $words,
                'with_audio' => $withAudio,
                'percent' => $report->percent(),
                'missing' => $words - $withAudio,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => [$a['percent'], $a['id']] <=> [$b['percent'], $b['id']]);

        return $rows;
    }

    /**
     * Active words and words with audio recorded per course of {@see STATUSES}, from the database (no cache).
     *
     * @return list<array{id: int, slug: string, status: string, words: int, with_audio: int}>
     */
    public function compute(): array
    {
        return array_values(DB::table('courses')
            ->join('stages', 'stages.course_id', '=', 'courses.id')
            ->join('lessons', 'lessons.stage_id', '=', 'stages.id')
            ->join('words', 'words.lesson_id', '=', 'lessons.id')
            ->whereIn('courses.status', self::STATUSES)
            ->where('words.is_active', true)
            ->groupBy('courses.id', 'courses.slug', 'courses.status')
            ->orderBy('courses.id')
            ->selectRaw(
                'courses.id AS id, courses.slug AS slug, courses.status AS status, COUNT(words.id) AS words, '
                .'SUM(CASE WHEN words.audio_path IS NOT NULL AND words.audio_path <> ? THEN 1 ELSE 0 END) AS with_audio',
                [''],
            )
            ->get()
            ->map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'slug' => (string) $row->slug,
                'status' => (string) $row->status,
                'words' => (int) $row->words,
                'with_audio' => (int) $row->with_audio,
            ])
            ->all());
    }
}
