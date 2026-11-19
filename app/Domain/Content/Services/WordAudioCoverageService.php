<?php

declare(strict_types=1);

namespace App\Domain\Content\Services;

use App\Domain\Content\DTO\AudioCoverageReport;
use App\Models\Course;
use App\Models\Word;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Coverage of the pre-generated word audio of a course (docs/12 task 1.2c), for the check before publishing
 * (`audio:coverage`). The count by module is one grouped statement over the active words: how many have `audio_path`
 * recorded. The file check (optional) walks the same words in chunks and reads the disk once per unique path (the files
 * are content-addressed, one text in several words is one file); a word counts as voiced only when
 * {@see WordAudioService::isCurrent()} would say so.
 */
final class WordAudioCoverageService
{
    private const int CHUNK = 500;

    public function __construct(private readonly WordAudioService $audio) {}

    /** @param  int  $limit  how many broken words to list (all of them are counted) */
    public function report(Course $course, bool $verify = false, int $limit = 20): AudioCoverageReport
    {
        $stages = $this->countByStage($course);

        $verified = null;
        $problems = [];
        $problemCount = 0;
        if ($verify) {
            $byStage = [];
            $verified = 0;
            /** @var array<string, 'ready'|'empty'|'missing'> $files the disk state per path, read once in this run */
            $files = [];

            $this->activeWords($course)
                ->select(['words.id', 'words.word', 'words.audio_path', 'stages.number as stage_number'])
                ->chunkById(self::CHUNK, function (Collection $words) use (&$byStage, &$verified, &$problems, &$problemCount, &$files, $limit): void {
                    /** @var Word $word */
                    foreach ($words as $word) {
                        $recorded = $word->audio_path;
                        if ($recorded === null || $recorded === '') {
                            continue;
                        }

                        $expected = $this->audio->pathFor($word->word);
                        $reason = 'stale';
                        if ($recorded === $expected) {
                            $state = $files[$recorded] ??= $this->audio->fileState($recorded);
                            if ($state === 'ready') {
                                $stage = (int) $word->getAttribute('stage_number');
                                $byStage[$stage] = ($byStage[$stage] ?? 0) + 1;
                                $verified++;

                                continue;
                            }
                            $reason = $state;
                        }

                        $problemCount++;
                        if (count($problems) < $limit) {
                            $problems[] = ['id' => (int) $word->id, 'word' => $word->word, 'reason' => $reason, 'path' => $recorded];
                        }
                    }
                }, 'words.id', 'id');

            foreach ($stages as $i => $stage) {
                $stages[$i]['verified'] = $byStage[$stage['number']] ?? 0;
            }
        }

        return new AudioCoverageReport(
            stages: $stages,
            words: array_sum(array_column($stages, 'words')),
            withAudio: array_sum(array_column($stages, 'with_audio')),
            verified: $verified,
            problems: $problems,
            problemCount: $problemCount,
        );
    }

    /**
     * Active words and those with `audio_path` recorded, by module: one statement.
     *
     * @return list<array{number: int, title: string, words: int, with_audio: int, verified: int|null}>
     */
    private function countByStage(Course $course): array
    {
        return $this->activeWords($course)
            ->groupBy('stages.id', 'stages.number', 'stages.title')
            ->orderBy('stages.number')
            ->selectRaw(
                'stages.number as stage_number, stages.title as stage_title, COUNT(words.id) as words_total, '
                .'SUM(CASE WHEN words.audio_path IS NOT NULL AND words.audio_path <> ? THEN 1 ELSE 0 END) as with_audio',
                [''],
            )
            ->toBase()
            ->get()
            ->map(fn (object $row): array => [
                'number' => (int) $row->stage_number,
                'title' => (string) $row->stage_title,
                'words' => (int) $row->words_total,
                'with_audio' => (int) $row->with_audio,
                'verified' => null,
            ])
            ->values()
            ->all();
    }

    /** @return Builder<Word> the active words of the course, joined to their lesson and module */
    private function activeWords(Course $course): Builder
    {
        return Word::query()
            ->join('lessons', 'lessons.id', '=', 'words.lesson_id')
            ->join('stages', 'stages.id', '=', 'lessons.stage_id')
            ->where('stages.course_id', $course->id)
            ->where('words.is_active', true);
    }
}
