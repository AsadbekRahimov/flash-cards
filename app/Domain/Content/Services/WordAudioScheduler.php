<?php

declare(strict_types=1);

namespace App\Domain\Content\Services;

use App\Domain\Content\Contracts\SpeechSynthesizer;
use App\Domain\Content\Services\Tts\NullSpeechSynthesizer;
use App\Domain\Content\Services\Tts\TtsCharacterBudget;
use App\Jobs\PregenerateWordAudioJob;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Word;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * When the audio of a word is queued (docs/26 §5.6) - every automatic point but the edit of the text
 * ({@see WordAudioService::textChanged()}):
 *  - a word added in the admin panel ({@see wordCreated()}), after the save;
 *  - the new and changed words of a lesson import ({@see wordsImported()}), after the commit, one job per word;
 *  - the nightly reconciliation ({@see reconcile()}): the words whose `audio_path` is not the path of their text, in chunks, at
 *    most `$limit` per run - the insurance against a lost job;
 *  - `audio:pregenerate` for one course or for all of them ({@see queueCourse()}).
 *
 * Only the active words of the courses that are not a learner's own deck (docs/26 §9.4: the decks keep the phone voice; the job
 * checks it again). The automatic points queue nothing while the synthesizer is `null` (docs/26 §7 step 2: the code ships with
 * `TTS_DRIVER=null` and nothing changes; the reconciliation voices the words once a driver is set). Readiness is the path alone,
 * the disk is not asked (docs/26 §9.5) - except for `audio:pregenerate`, which has always checked the file.
 */
final class WordAudioScheduler
{
    /** Words read per chunk by the reconciliation and `audio:pregenerate`. */
    public const int CHUNK = 500;

    /** The default ceiling of jobs one reconciliation queues (`audio:reconcile --limit`). */
    public const int RECONCILE_LIMIT = 2000;

    public function __construct(
        private readonly WordAudioService $audio,
        private readonly SpeechSynthesizer $tts,
        private readonly TtsCharacterBudget $budget,
    ) {}

    /** False while `TTS_DRIVER` is `null` (or unset without a service URL): there is nobody to voice a word. */
    public function enabled(): bool
    {
        return ! $this->tts instanceof NullSpeechSynthesizer;
    }

    /**
     * A word was added in the admin panel (call it after the save): its audio is queued after the commit, unless the word is
     * switched off or belongs to a learner's own deck. One query.
     */
    public function wordCreated(Word $word): void
    {
        if (! $this->enabled() || $word->is_active === false) {
            return;
        }

        $voiced = $this->voicedWords()->whereKey($word->id)->exists();
        if ($voiced) {
            PregenerateWordAudioJob::dispatch((int) $word->id)->afterCommit();
        }
    }

    /**
     * The words a lesson import added or changed (call it inside the import's transaction): one job per word whose audio is not
     * the path of its text, after the commit - a rolled-back import queues nothing. Ids repeated in `$words` are queued once.
     *
     * @param  iterable<Word>  $words
     */
    public function wordsImported(Course $course, iterable $words): void
    {
        if (! $this->enabled() || $course->isLearnerOwned()) {
            return;
        }

        /** @var array<int, true> $queued */
        $queued = [];
        foreach ($words as $word) {
            $id = (int) $word->id;
            if (isset($queued[$id]) || $word->is_active === false || $this->hasCurrentPath($word)) {
                continue;
            }

            $queued[$id] = true;
            PregenerateWordAudioJob::dispatch($id)->afterCommit();
        }
    }

    /**
     * The nightly reconciliation (`audio:reconcile`): queues the active words of the courses (not a learner's deck) whose
     * `audio_path` is empty or not the path of their current text, oldest id first, at most `$limit`. Nothing while the
     * synthesizer is `null` or the characters of the month are spent (docs/26 §9.3: next month's run picks the words up).
     * Idempotent: a voiced word has its path and is not queued again.
     *
     * @return array{queued: int, reason: 'not_configured'|'budget_exhausted'|null}
     */
    public function reconcile(int $limit = self::RECONCILE_LIMIT): array
    {
        if (! $this->enabled()) {
            return ['queued' => 0, 'reason' => 'not_configured'];
        }
        if ($this->budget->exhausted()) {
            return ['queued' => 0, 'reason' => 'budget_exhausted'];
        }

        $queued = 0;
        if ($limit <= 0) {
            return ['queued' => $queued, 'reason' => null];
        }

        $this->voicedWords()
            ->select(['words.id', 'words.word', 'words.audio_path'])
            ->chunkById(self::CHUNK, function (Collection $words) use (&$queued, $limit): bool {
                /** @var Word $word */
                foreach ($words as $word) {
                    if ($this->hasCurrentPath($word)) {
                        continue;
                    }

                    PregenerateWordAudioJob::dispatch((int) $word->id);
                    if (++$queued >= $limit) {
                        return false;
                    }
                }

                return true;
            }, 'words.id', 'id');

        return ['queued' => $queued, 'reason' => null];
    }

    /**
     * `audio:pregenerate`: queues the active words of one course whose audio is not current (the file is checked). With `$force`
     * only the first word of each text re-synthesizes; `$forced` carries the texts already forced across several courses
     * (`--all`), so one text is one call.
     *
     * @param  array<string, true>  $forced  paths already queued for re-synthesis in this run
     * @return array{queued: int, skipped: int}
     */
    public function queueCourse(Course $course, bool $force, array &$forced): array
    {
        $queued = 0;
        $skipped = 0;

        Word::query()
            ->where('is_active', true)
            ->whereHas('lesson.stage', fn (Builder $q) => $q->where('course_id', $course->id))
            ->chunkById(self::CHUNK, function (Collection $words) use ($force, &$queued, &$skipped, &$forced): void {
                /** @var Word $word */
                foreach ($words as $word) {
                    if (! $force && $this->audio->isCurrent($word)) {
                        $skipped++;

                        continue;
                    }

                    $path = $this->audio->pathFor($word->word);
                    $first = $force && $path !== null && ! isset($forced[$path]);
                    if ($first) {
                        $forced[$path] = true;
                    }

                    PregenerateWordAudioJob::dispatch((int) $word->id, $first);
                    $queued++;
                }
            });

        return ['queued' => $queued, 'skipped' => $skipped];
    }

    /** `audio_path` is the path the current text hashes to (no disk access); a word with nothing to pronounce counts as current. */
    private function hasCurrentPath(Word $word): bool
    {
        $expected = $this->audio->pathFor($word->word);

        return $expected === null || $word->audio_path === $expected;
    }

    /** @return Builder<Word> the active words of the courses that are not a learner's own deck */
    private function voicedWords(): Builder
    {
        return Word::query()
            ->where('words.is_active', true)
            ->whereIn('words.lesson_id', Lesson::query()
                ->select('lessons.id')
                ->join('stages', 'stages.id', '=', 'lessons.stage_id')
                ->join('courses', 'courses.id', '=', 'stages.course_id')
                ->where('courses.owner_type', '<>', 'learner'));
    }
}
