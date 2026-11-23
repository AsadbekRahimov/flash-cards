<?php

declare(strict_types=1);

namespace App\Domain\Content\Services;

use App\Domain\Content\Contracts\SpeechSynthesizer;
use App\Domain\Content\Exceptions\SpeechSynthesisException;
use App\Domain\Content\Services\Tts\ConfiguredSpeechSynthesizer;
use App\Domain\Content\Services\Tts\GoogleServiceAccount;
use App\Domain\Content\Services\Tts\GoogleSpeechSynthesizer;
use App\Domain\Content\Services\Tts\TtsCharacterBudget;
use App\Jobs\PregenerateExampleAudioJob;
use App\Jobs\PregenerateWordAudioJob;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Word;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * When the audio of a word and of its anchor example is queued (docs/26 §5.6, §5.7, §9.11) - every automatic point but the edit
 * of the text or of the example ({@see WordAudioService::textChanged()}, {@see WordAudioService::exampleChanged()}). The word and
 * its example are two jobs ({@see PregenerateWordAudioJob}, {@see PregenerateExampleAudioJob}); every point below queues both:
 *  - a word added in the admin panel ({@see wordCreated()}), after the save;
 *  - the new and changed words of a lesson import ({@see wordsImported()}), after the commit, one job per word;
 *  - the nightly reconciliation ({@see reconcile()}): the words whose `audio_path` is not the path of their text, then the examples
 *    whose `example_audio_path` is not the path of theirs, in chunks, at most `$limit` jobs in all per run (the words first) - the
 *    insurance against a lost job;
 *  - `audio:pregenerate` for one course or for all of them ({@see queueCourse()}), the words, the examples or both.
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

    /** Why {@see reconcile()} queued nothing (`audio:reconcile` prints each). */
    public const string SKIP_NOT_CONFIGURED = 'not_configured';

    public const string SKIP_DRIVER_UNKNOWN = 'driver_unknown';

    public const string SKIP_HALTED = 'halted';

    public const string SKIP_CREDENTIALS_MISSING = 'credentials_missing';

    public const string SKIP_CREDENTIALS_INVALID = 'credentials_invalid';

    public const string SKIP_VOICE_UNSUPPORTED = 'voice_unsupported';

    public const string SKIP_BUDGET_EXHAUSTED = 'budget_exhausted';

    public function __construct(
        private readonly WordAudioService $audio,
        private readonly SpeechSynthesizer $tts,
        private readonly TtsCharacterBudget $budget,
        private readonly GoogleServiceAccount $account,
    ) {}

    /** {@see WordAudioService::enabled()}: false while there is nobody to voice a word (`TTS_DRIVER` `null` or unknown). */
    public function enabled(): bool
    {
        return $this->audio->enabled();
    }

    /**
     * A word was added in the admin panel (call it after the save): its audio and the audio of its example are queued after the
     * commit, unless the word is switched off or belongs to a learner's own deck. One query.
     */
    public function wordCreated(Word $word): void
    {
        if (! $this->enabled() || $word->is_active === false) {
            return;
        }

        $voiced = $this->voicedWords()->whereKey($word->id)->exists();
        if (! $voiced) {
            return;
        }

        if (! $this->hasCurrentPath($word)) {
            PregenerateWordAudioJob::dispatch((int) $word->id)->afterCommit();
        }
        if (! $this->hasCurrentExamplePath($word)) {
            PregenerateExampleAudioJob::dispatch((int) $word->id)->afterCommit();
        }
    }

    /**
     * The words a lesson import added or changed (call it inside the import's transaction): one job per word whose audio is not
     * the path of its text and one per word whose example's audio is not the path of the example, after the commit - a
     * rolled-back import queues nothing. Ids repeated in `$words` are queued once.
     *
     * @param  iterable<Word>  $words
     */
    public function wordsImported(Course $course, iterable $words): void
    {
        if (! $this->enabled() || $course->isLearnerOwned()) {
            return;
        }

        /** @var array<int, true> $seen */
        $seen = [];
        foreach ($words as $word) {
            $id = (int) $word->id;
            if (isset($seen[$id]) || $word->is_active === false) {
                continue;
            }
            $seen[$id] = true;

            if (! $this->hasCurrentPath($word)) {
                PregenerateWordAudioJob::dispatch($id)->afterCommit();
            }
            if (! $this->hasCurrentExamplePath($word)) {
                PregenerateExampleAudioJob::dispatch($id)->afterCommit();
            }
        }
    }

    /**
     * The nightly reconciliation (`audio:reconcile`): queues the active words of the courses (not a learner's deck) whose
     * `audio_path` is empty or not the path of their current text, oldest id first, then - with what is left of `$limit` - the
     * examples of those words whose `example_audio_path` is not the path of the current example: at most `$limit` jobs in all,
     * the words first (a word without its sound is worse than an example without its). Nothing while the
     * synthesizer is `null` or unknown, the characters of the month are spent (docs/26 §9.3: next month's run picks the words
     * up), or - with `google` - while every job would fail at once: Google refused the sign-in (the halt marker), the key file is
     * missing or not a service-account key (read here, nothing is sent), or the voice is not one of Google's. Those runs would
     * only fill `failed_jobs`; the job of a word is unique while it waits, so a word is not queued twice either.
     * Idempotent: a voiced word has its path and is not queued again.
     *
     * @return array{queued: int, words: int, examples: int, reason: self::SKIP_*|null} `queued` = `words` + `examples`
     */
    public function reconcile(int $limit = self::RECONCILE_LIMIT): array
    {
        $skip = $this->reconcileBlocker();
        if ($skip !== null) {
            return ['queued' => 0, 'words' => 0, 'examples' => 0, 'reason' => $skip];
        }

        $words = 0;
        if ($limit > 0) {
            $this->voicedWords()
                ->select(['words.id', 'words.word', 'words.audio_path'])
                ->chunkById(self::CHUNK, function (Collection $chunk) use (&$words, $limit): bool {
                    /** @var Word $word */
                    foreach ($chunk as $word) {
                        if ($this->hasCurrentPath($word)) {
                            continue;
                        }

                        PregenerateWordAudioJob::dispatch((int) $word->id);
                        if (++$words >= $limit) {
                            return false;
                        }
                    }

                    return true;
                }, 'words.id', 'id');
        }

        $examples = 0;
        $left = $limit - $words;
        if ($left > 0) {
            $this->voicedWords()
                ->whereNotNull('words.example')
                ->select(['words.id', 'words.example', 'words.example_audio_path'])
                ->chunkById(self::CHUNK, function (Collection $chunk) use (&$examples, $left): bool {
                    /** @var Word $word */
                    foreach ($chunk as $word) {
                        if ($this->hasCurrentExamplePath($word)) {
                            continue;
                        }

                        PregenerateExampleAudioJob::dispatch((int) $word->id);
                        if (++$examples >= $left) {
                            return false;
                        }
                    }

                    return true;
                }, 'words.id', 'id');
        }

        return ['queued' => $words + $examples, 'words' => $words, 'examples' => $examples, 'reason' => null];
    }

    /**
     * `audio:pregenerate`: queues the active words of one course whose audio is not current (the file is checked) and - with
     * `$examples` - the anchor examples whose audio is not current. With `$force` only the first job of each text re-synthesizes;
     * `$forced` carries the paths already forced across several courses (`--all`) and across words and examples (an example
     * that is sent like a word is the same file), so one text is one call. An example with nothing to voice is neither queued
     * nor counted.
     *
     * @param  array<string, true>  $forced  paths already queued for re-synthesis in this run
     * @return array{queued: int, skipped: int, examples_queued: int, examples_skipped: int}
     */
    public function queueCourse(Course $course, bool $force, array &$forced, bool $words = true, bool $examples = true): array
    {
        $counts = ['queued' => 0, 'skipped' => 0, 'examples_queued' => 0, 'examples_skipped' => 0];

        Word::query()
            ->where('is_active', true)
            ->whereHas('lesson.stage', fn (Builder $q) => $q->where('course_id', $course->id))
            ->chunkById(self::CHUNK, function (Collection $chunk) use ($force, $words, $examples, &$counts, &$forced): void {
                /** @var Word $word */
                foreach ($chunk as $word) {
                    if ($words) {
                        $path = $this->audio->pathFor($word->word);
                        if (! $force && $this->audio->isCurrent($word)) {
                            $counts['skipped']++;
                        } else {
                            PregenerateWordAudioJob::dispatch((int) $word->id, self::forceFirst($force, $path, $forced));
                            $counts['queued']++;
                        }
                    }

                    $examplePath = $examples ? $this->audio->examplePathFor($word->example) : null;
                    if ($examplePath === null) {
                        continue;
                    }
                    if (! $force && $this->audio->isExampleCurrent($word)) {
                        $counts['examples_skipped']++;
                    } else {
                        PregenerateExampleAudioJob::dispatch((int) $word->id, self::forceFirst($force, $examplePath, $forced));
                        $counts['examples_queued']++;
                    }
                }
            });

        return $counts;
    }

    /**
     * With `--force`, true for the first job of a path in this run (it re-synthesizes), false for the rest (they point at it).
     *
     * @param  array<string, true>  $forced
     */
    private static function forceFirst(bool $force, ?string $path, array &$forced): bool
    {
        if (! $force || $path === null || isset($forced[$path])) {
            return false;
        }
        $forced[$path] = true;

        return true;
    }

    /** @return self::SKIP_*|null why the reconciliation queues nothing tonight */
    private function reconcileBlocker(): ?string
    {
        if ($this->tts instanceof ConfiguredSpeechSynthesizer && ! ConfiguredSpeechSynthesizer::known()) {
            return self::SKIP_DRIVER_UNKNOWN;
        }
        if (! $this->enabled()) {
            return self::SKIP_NOT_CONFIGURED;
        }

        if ($this->usesGoogle()) {
            if (GoogleSpeechSynthesizer::haltCode() !== null) {
                return self::SKIP_HALTED;
            }

            try {
                $this->account->check();
            } catch (SpeechSynthesisException $e) {
                // A bare code: neither the path nor anything of the file.
                return $e->getMessage() === SpeechSynthesisException::NOT_CONFIGURED ? self::SKIP_CREDENTIALS_MISSING : self::SKIP_CREDENTIALS_INVALID;
            }

            if (! GoogleSpeechSynthesizer::supportsVoice($this->audio->defaultVoice())) {
                return self::SKIP_VOICE_UNSUPPORTED;
            }
        }

        return $this->budget->exhausted() ? self::SKIP_BUDGET_EXHAUSTED : null;
    }

    /** The jobs would go to Google: the bound driver is `google` (or the configured one, read now, is). */
    private function usesGoogle(): bool
    {
        return $this->tts instanceof GoogleSpeechSynthesizer
            || ($this->tts instanceof ConfiguredSpeechSynthesizer && ConfiguredSpeechSynthesizer::driver() === ConfiguredSpeechSynthesizer::GOOGLE);
    }

    /** `audio_path` is the path the current text hashes to (no disk access); a word with nothing to pronounce counts as current. */
    private function hasCurrentPath(Word $word): bool
    {
        $expected = $this->audio->pathFor($word->word);

        return $expected === null || $word->audio_path === $expected;
    }

    /** `example_audio_path` is the path the current example hashes to; an example with nothing to voice counts as current. */
    private function hasCurrentExamplePath(Word $word): bool
    {
        $expected = $this->audio->examplePathFor($word->example);

        return $expected === null || $word->example_audio_path === $expected;
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
