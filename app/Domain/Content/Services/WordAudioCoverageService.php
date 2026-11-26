<?php

declare(strict_types=1);

namespace App\Domain\Content\Services;

use App\Domain\Content\DTO\AudioCoverageReport;
use App\Domain\Content\Services\Tts\ConfiguredSpeechSynthesizer;
use App\Domain\Content\Services\Tts\GoogleSpeechSynthesizer;
use App\Models\Course;
use App\Models\Word;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Coverage of the pre-generated word audio of a course (docs/12 task 1.2c), for the check before publishing
 * (`audio:coverage`). The count by module is one grouped statement over the active words: how many have `audio_path`
 * recorded. The file check (optional) walks the same words in chunks and reads the disk once per unique path (the files
 * are content-addressed, one text in several words is one file); a word counts as voiced only when
 * {@see WordAudioService::isCurrent()} would say so. The anchor examples are counted in the same statement and checked in the same
 * walk ({@see WordAudioService::isExampleCurrent()}), as totals only (docs/26 §9.11): the gate of the publication is the words.
 */
final class WordAudioCoverageService
{
    private const int CHUNK = 500;

    /** What `\s` of PCRE with `/u` matches ({@see WordAudioService::exampleText()}): the blanks trimmed off an example in SQL. */
    private const string BLANKS = " \t\n\v\f\r\u{85}\u{A0}\u{1680}\u{180E}\u{2000}\u{2001}\u{2002}\u{2003}\u{2004}\u{2005}"
        ."\u{2006}\u{2007}\u{2008}\u{2009}\u{200A}\u{2028}\u{2029}\u{202F}\u{205F}\u{3000}";

    public function __construct(private readonly WordAudioService $audio) {}

    /** @param  int  $limit  how many broken words to list (all of them are counted) */
    public function report(Course $course, bool $verify = false, int $limit = 20): AudioCoverageReport
    {
        [$stages, $examples, $examplesWithAudio] = $this->countByStage($course);

        $verified = null;
        $examplesVerified = null;
        $problems = [];
        $problemCount = 0;
        if ($verify) {
            $byStage = [];
            $verified = 0;
            $examplesVerified = 0;
            /** @var array<string, 'ready'|'empty'|'missing'> $files the disk state per path, read once in this run */
            $files = [];

            $this->activeWords($course)
                ->select(['words.id', 'words.word', 'words.audio_path', 'words.example', 'words.example_audio_path', 'stages.number as stage_number'])
                ->chunkById(self::CHUNK, function (Collection $words) use (&$byStage, &$verified, &$examplesVerified, &$problems, &$problemCount, &$files, $limit): void {
                    /** @var Word $word */
                    foreach ($words as $word) {
                        $example = $word->example_audio_path;
                        if ($example !== null && $example === $this->audio->examplePathFor($word->example)
                            && ($files[$example] ??= $this->audio->fileState($example)) === 'ready') {
                            $examplesVerified++;
                        }

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
            examples: $examples,
            examplesWithAudio: $examplesWithAudio,
            examplesVerified: $examplesVerified,
        );
    }

    /**
     * Active words and those with `audio_path` recorded, by module, and the totals of the examples (an example that is voiced at all;
     * of them with `example_audio_path` recorded): one statement.
     *
     * @return array{0: list<array{number: int, title: string, words: int, with_audio: int, verified: int|null}>, 1: int, 2: int}
     */
    private function countByStage(Course $course): array
    {
        // Only the examples that are voiced at all ({@see WordAudioService::exampleText()}): not blanks alone, and not longer than
        // one request of Google (5 000 bytes) nor than the characters of one request of the driver in effect
        // ({@see ConfiguredSpeechSynthesizer::maxTextChars()}: 2 000 for `deepgram`). The length is of the trimmed text: the inner
        // runs of blanks, collapsed for the synthesizer, are not collapsed here, so a sentence over the limit only by its inner
        // blanks would be left out (the longest example is 74 bytes). The SQL fragments are fixed text; the blanks and the limits
        // are bindings.
        $pgsql = Word::query()->getModel()->getConnection()->getDriverName() === 'pgsql';
        $trimmed = $pgsql ? 'BTRIM(words.example, ?)' : 'TRIM(words.example, ?)';
        $bytes = $pgsql ? "OCTET_LENGTH({$trimmed})" : "LENGTH(CAST({$trimmed} AS BLOB))";
        $chars = $pgsql ? "CHAR_LENGTH({$trimmed})" : "LENGTH({$trimmed})";
        $voiced = "words.example IS NOT NULL AND {$trimmed} <> ? AND {$bytes} <= ? AND {$chars} <= ?";
        $voicedBindings = [self::BLANKS, '', self::BLANKS, GoogleSpeechSynthesizer::MAX_TEXT_BYTES, self::BLANKS, ConfiguredSpeechSynthesizer::maxTextChars()];

        $rows = $this->activeWords($course)
            ->groupBy('stages.id', 'stages.number', 'stages.title')
            ->orderBy('stages.number')
            ->selectRaw(
                'stages.number as stage_number, stages.title as stage_title, COUNT(words.id) as words_total, '
                .'SUM(CASE WHEN words.audio_path IS NOT NULL AND words.audio_path <> ? THEN 1 ELSE 0 END) as with_audio, '
                ."SUM(CASE WHEN {$voiced} THEN 1 ELSE 0 END) as examples_total, "
                ."SUM(CASE WHEN {$voiced} AND words.example_audio_path IS NOT NULL AND words.example_audio_path <> ? THEN 1 ELSE 0 END) as examples_with_audio",
                ['', ...$voicedBindings, ...$voicedBindings, ''],
            )
            ->toBase()
            ->get();

        $stages = array_values($rows->map(fn (object $row): array => [
            'number' => (int) $row->stage_number,
            'title' => (string) $row->stage_title,
            'words' => (int) $row->words_total,
            'with_audio' => (int) $row->with_audio,
            'verified' => null,
        ])->all());

        return [
            $stages,
            (int) $rows->sum(fn (object $row): int => (int) $row->examples_total),
            (int) $rows->sum(fn (object $row): int => (int) $row->examples_with_audio),
        ];
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
