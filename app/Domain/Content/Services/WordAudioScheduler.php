<?php

declare(strict_types=1);

namespace App\Domain\Content\Services;

use App\Domain\Content\Contracts\SpeechSynthesizer;
use App\Domain\Content\Exceptions\SpeechSynthesisException;
use App\Domain\Content\Services\Tts\ConfiguredSpeechSynthesizer;
use App\Domain\Content\Services\Tts\GoogleServiceAccount;
use App\Domain\Content\Services\Tts\GoogleSpeechSynthesizer;
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

    /**
     * False while `TTS_DRIVER` is `null` (or unset without a service URL) or a value nobody knows (a typo): there is nobody to
     * voice a word.
     */
    public function enabled(): bool
    {
        if ($this->tts instanceof ConfiguredSpeechSynthesizer) {
            return in_array(ConfiguredSpeechSynthesizer::driver(), [ConfiguredSpeechSynthesizer::GOOGLE, ConfiguredSpeechSynthesizer::HTTP], true);
        }

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
     * synthesizer is `null` or unknown, the characters of the month are spent (docs/26 §9.3: next month's run picks the words
     * up), or - with `google` - while every job would fail at once: Google refused the sign-in (the halt marker), the key file is
     * missing or not a service-account key (read here, nothing is sent), or the voice is not one of Google's. Those runs would
     * only fill `failed_jobs`; the job of a word is unique while it waits, so a word is not queued twice either.
     * Idempotent: a voiced word has its path and is not queued again.
     *
     * @return array{queued: int, reason: self::SKIP_*|null}
     */
    public function reconcile(int $limit = self::RECONCILE_LIMIT): array
    {
        $skip = $this->reconcileBlocker();
        if ($skip !== null) {
            return ['queued' => 0, 'reason' => $skip];
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
