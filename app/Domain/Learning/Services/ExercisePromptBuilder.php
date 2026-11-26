<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Content\DTO\ResolvedTranslation;
use App\Domain\Content\Services\PlatformDistractorPool;
use App\Domain\Content\Services\WordTranslationResolver;
use App\Domain\Content\Support\Phrase;
use App\Domain\Content\Support\TranslationParts;
use App\Domain\Learning\DTO\ExercisePrompt;
use App\Domain\Learning\Enums\ExerciseType;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\Word;
use Illuminate\Database\Eloquent\Builder;

/**
 * Turns the selected {@see ExerciseType} into what the client shows (docs/13 §2.5, docs/04 A.3).
 *
 * Meaning / reverse / audio / pretest need {@see OPTIONS} choices: the right one plus real distractors — active words
 * of the same lesson, then of the same course, then of published public platform courses
 * ({@see PlatformDistractorPool}: `Course::scopePublicPlatform()`, the same whitelist as the exam builder; private courses
 * of centers and learners never leak in).
 * The platform layer keeps to the kind of the card (docs/35 §3.3 c, docs/06 §16): a phrase takes the phrase courses (`exam_tag = phrases`)
 * only, a word every other course - a phrase never stands among single words. The lesson and the course are of one kind already (the import
 * keeps phrases to phrase courses).
 * Distractors and their order are chosen by a hash of (seed, candidate), so two `/next` calls for
 * the same serve show the same screen. When there are not enough distractors the card falls back
 * to a flash prompt — the type in the prompt is what the client must follow.
 *
 * A distractor is never a second right answer (docs/06 §12.2): a word whose translation in the session's language shares a
 * sense with the card's ({@see TranslationParts}: he / she / it are all "u" in uz, watch / look are "смотреть") is left out of
 * the reverse options, and a translation sharing a sense with the right one — or with a distractor already picked — is left
 * out of the meaning / audio / pretest options. {@see acceptsPick()} grades by the same rule, so a card built before this
 * rule (an old serve) still cannot fail a learner for a right pick.
 *
 * Spelling and gap-fill prompts never depend on the database beyond the word and the example the card shows
 * (docs/06 §12.4); their type is what the server recomputes on `POST /review` (ExerciseSelector).
 */
final class ExercisePromptBuilder
{
    public const OPTIONS = 4;

    public const POOL = 40;

    /** Words of the course with the picked English text looked at by {@see acceptsPick()} (a stale option, at most a few). */
    private const PICK_LOOKUP = 5;

    public function __construct(
        private readonly WordTranslationResolver $translations,
        private readonly GapFillBuilder $gapFill,
        private readonly LearningCache $cache,
        private readonly PlatformDistractorPool $platform,
    ) {}

    /**
     * The type the card is actually shown with: a four-option card without enough distractors (and a gap-fill
     * whose example lost the word) falls back to flash. `POST /review` records this, not the selector's pick.
     */
    public function effectiveType(ExerciseType $type, Word $word, string $locale, int $seed, ?string $example = null): ExerciseType
    {
        return match ($type) {
            ExerciseType::Meaning, ExerciseType::Reverse, ExerciseType::Audio, ExerciseType::Pretest => count($this->distractors(
                $type,
                $word,
                $this->translations->resolve($word, $locale),
                $locale,
                $seed,
            )) < self::OPTIONS - 1 ? ExerciseType::Flash : $type,
            ExerciseType::GapFill => $this->gapFill->forCard($word, $example) === null ? ExerciseType::Flash : $type,
            default => $type,
        };
    }

    /** The text the server compares a pick with (docs/06 §12.2): the English word for reverse, the translation otherwise. */
    public function correctOption(ExerciseType $type, Word $word, ResolvedTranslation $translation): string
    {
        return $type === ExerciseType::Reverse ? trim($word->word) : trim($translation->text);
    }

    /**
     * Whether a picked option of a four-option card is right by its SENSE, when it is not the right option itself: for reverse,
     * an English word of the card's lesson or course whose translation in the session's language shares a sense with the card's
     * (`she` for "u" when the card is `he`); for meaning / audio / pretest, a translation sharing a sense with the right one
     * ("u" for `it` = "u, bu"). The options built by {@see build()} never contain such a pick any more; this is for a card
     * built before the rule, or by an older serve, and for the second word of a pair that slipped in from a course the
     * distractors do not read (docs/06 §12.2). Costs nothing for a right pick and for the translations; for a stale reverse
     * pick, the cached lesson pool and at most one query over the course of the lesson.
     */
    public function acceptsPick(ExerciseType $type, Word $word, ResolvedTranslation $translation, string $locale, string $picked): bool
    {
        if (! $type->isChoice()) {
            return false;
        }

        $senses = TranslationParts::keys($translation->text);
        if ($senses === []) {
            return false;
        }

        if ($type !== ExerciseType::Reverse) {
            return TranslationParts::share($senses, $picked);
        }

        $key = self::key($picked);
        if ($key === '' || $key === self::key($word->word)) {
            return false;
        }

        $matches = [];
        foreach ($this->lessonPool($word) as $other) {
            if (self::key($other->word) === $key) {
                $matches[] = $other;
            }
        }
        if ($matches === []) {
            $courseId = $this->courseIdOf($word);
            $matches = $courseId === null ? [] : Word::query()
                ->where('is_active', true)
                ->whereKeyNot($word->id)
                ->whereIn('lesson_id', Lesson::query()->select('id')->whereIn('stage_id', Stage::query()->select('id')->where('course_id', $courseId)))
                ->whereRaw('lower(trim(word)) = ?', [$key])
                ->limit(self::PICK_LOOKUP)
                ->get()
                ->all();
        }
        if ($matches === []) {
            return false;
        }

        foreach ($this->translations->resolveMany($matches, $locale) as $resolved) {
            if (TranslationParts::share($senses, $resolved->text)) {
                return true;
            }
        }

        return false;
    }

    public function build(
        ExerciseType $type,
        Word $word,
        ResolvedTranslation $translation,
        string $locale,
        ?string $audioUrl,
        int $seed,
        ?string $example = null,
    ): ExercisePrompt {
        return match ($type) {
            ExerciseType::Flash => ExercisePrompt::flash($word->word, $audioUrl),
            ExerciseType::Meaning, ExerciseType::Reverse, ExerciseType::Audio, ExerciseType::Pretest => $this->choice($type, $word, $translation, $locale, $audioUrl, $seed),
            ExerciseType::Spelling => new ExercisePrompt(
                $type,
                $translation->text,
                $audioUrl,
                answerLength: mb_strlen(trim($word->word)),
            ),
            ExerciseType::GapFill => $this->gapFillPrompt($word, $translation, $audioUrl, $example),
        };
    }

    private function gapFillPrompt(Word $word, ResolvedTranslation $translation, ?string $audioUrl, ?string $example): ExercisePrompt
    {
        $gap = $this->gapFill->forCard($word, $example);
        if ($gap === null) {
            // The selector only picks gap_fill when the example carries the word; a stale example is a flash card.
            return ExercisePrompt::flash($word->word, $audioUrl);
        }

        return new ExercisePrompt(
            ExerciseType::GapFill,
            $translation->text,
            $audioUrl,
            maskedExample: $gap->masked,
            answerLength: mb_strlen($gap->expected),
        );
    }

    private function choice(
        ExerciseType $type,
        Word $word,
        ResolvedTranslation $translation,
        string $locale,
        ?string $audioUrl,
        int $seed,
    ): ExercisePrompt {
        $correct = $this->correctOption($type, $word, $translation);
        $distractors = $this->distractors($type, $word, $translation, $locale, $seed);

        if (count($distractors) < self::OPTIONS - 1) {
            return ExercisePrompt::flash($word->word, $audioUrl);
        }

        $options = [$correct, ...$distractors];
        usort($options, static fn (string $a, string $b): int => crc32($seed.'|'.$a) <=> crc32($seed.'|'.$b));

        // reverse: the translation is the question; meaning / pretest: the word is; audio: nothing — the word is only heard.
        return new ExercisePrompt(
            $type,
            match ($type) {
                ExerciseType::Reverse => $translation->text,
                ExerciseType::Meaning, ExerciseType::Pretest => $word->word,
                default => '',
            },
            $audioUrl,
            options: $options,
        );
    }

    /**
     * Up to {@see OPTIONS} - 1 texts (English words for reverse, translations for meaning / audio / pretest), from the nearest
     * layer that has enough of them: distinct from the right answer and from each other case-insensitively, and never a second
     * right answer — a reverse option whose translation shares a sense with the card's is left out; a meaning / audio / pretest
     * option is left out when it shares a sense with the right translation or with an option picked before it (so the four
     * never read "u" and "u, bu" side by side). The translations of the candidates come from the cached rows of the resolver,
     * one `many` per layer.
     *
     * @return list<string>
     */
    private function distractors(ExerciseType $type, Word $word, ResolvedTranslation $translation, string $locale, int $seed): array
    {
        $need = self::OPTIONS - 1;
        $reverse = $type === ExerciseType::Reverse;
        $taken = [self::key($this->correctOption($type, $word, $translation)) => true];
        // The senses no option may name: the card's own, and (for translation options) those of the options picked so far.
        $senses = array_fill_keys(TranslationParts::keys($translation->text), true);
        $picked = [];

        foreach ($this->layers($word) as $layer) {
            $candidates = $this->texts($type, $layer instanceof Builder ? $layer->get() : $layer, $locale);
            usort($candidates, static fn (array $a, array $b): int => crc32($seed.':'.$a['id']) <=> crc32($seed.':'.$b['id']));

            foreach ($candidates as $candidate) {
                $key = self::key($candidate['text']);
                if ($key === '' || isset($taken[$key])) {
                    continue;
                }
                $shared = false;
                foreach ($candidate['senses'] as $sense) {
                    if (isset($senses[$sense])) {
                        $shared = true;
                        break;
                    }
                }
                if ($shared) {
                    continue;
                }

                $taken[$key] = true;
                if (! $reverse) {
                    foreach ($candidate['senses'] as $sense) {
                        $senses[$sense] = true;
                    }
                }
                $picked[] = $candidate['text'];
                if (count($picked) >= $need) {
                    return $picked;
                }
            }
        }

        return $picked;
    }

    /**
     * The candidates of a layer: the option text (the English word for reverse, the translation otherwise) and the senses of
     * the candidate's translation in `$locale` (what the learner would read for it, after the fallback).
     *
     * @param  iterable<Word>  $words
     * @return list<array{id: int, text: string, senses: list<string>}>
     */
    private function texts(ExerciseType $type, iterable $words, string $locale): array
    {
        $list = [];
        foreach ($words as $w) {
            $list[] = $w;
        }

        $resolved = $this->translations->resolveMany($list, $locale);
        $texts = [];
        foreach ($list as $w) {
            $translation = $resolved[(int) $w->id] ?? null;
            if ($translation === null) {
                continue;
            }
            $texts[] = [
                'id' => (int) $w->id,
                'text' => $type === ExerciseType::Reverse ? trim($w->word) : trim($translation->text),
                'senses' => TranslationParts::keys($translation->text),
            ];
        }

        return $texts;
    }

    /**
     * The candidates, nearest first; each layer is evaluated only when the previous ones ran short: the lesson and the platform
     * layers are cached pools (no statement on a hit), the course layer is a query.
     *
     * @return iterable<Builder<Word>|list<Word>>
     */
    private function layers(Word $word): iterable
    {
        yield $this->lessonPool($word);

        $courseId = $this->courseIdOf($word);
        if ($courseId !== null) {
            // `ORDER BY id LIMIT` makes the planner walk the words primary key and filter, which reads
            // almost the whole table for a course with high ids (107 ms at 100k words vs 0.1 ms for
            // course 1). `id + 0` cannot use that index: the course lessons are read by lesson_id and
            // the same 40 lowest ids are taken (0.4-2 ms for any course at 270k words).
            yield $this->pool($word)
                ->reorder()
                ->orderByRaw('words.id + 0')
                ->where('lesson_id', '<>', $word->lesson_id)
                ->whereIn('lesson_id', Lesson::query()->select('id')->whereIn('stage_id', Stage::query()->select('id')->where('course_id', $courseId)));
        }

        // The platform layer is no query of its own: the shared pool of the kind (plain rows of the pure cache, no statement on
        // a hit, one bounded statement of 1-2 ms on a miss). As a query - the lowest 40 ids among ALL the words of the public
        // courses - it read every one of them on each call: 36-43 ms at 60k public words, and growing with them (docs/38).
        yield $this->platformPool($word);
    }

    /**
     * The third layer: the {@see POOL} lowest ids of the public courses of the platform of the card's kind, other than the card.
     *
     * @return list<Word>
     */
    private function platformPool(Word $word): array
    {
        $words = [];
        foreach ($this->platform->nearest(Phrase::isPhraseWord($word), self::POOL, exceptWordId: (int) $word->id) as $row) {
            $words[] = (new Word)->forceFill(['id' => $row['id'], 'lesson_id' => $row['lesson_id'], 'word' => $row['word'], 'translation' => $row['translation'], 'part_of_speech' => $row['part_of_speech']]);
        }

        return $words;
    }

    /** The course of the word's lesson (two key lookups), null when the lesson or its stage is gone. */
    private function courseIdOf(Word $word): ?int
    {
        $stageId = Lesson::query()->whereKey($word->lesson_id)->value('stage_id');
        $courseId = $stageId === null ? null : Stage::query()->whereKey($stageId)->value('course_id');

        return $courseId === null ? null : (int) $courseId;
    }

    /**
     * The first layer from the cached pool of the lesson (plain rows, no query on a hit): the same words as
     * `pool()->where('lesson_id', ...)` - active, other than the card, by `id`, {@see POOL} of them.
     *
     * @return list<Word>
     */
    private function lessonPool(Word $word): array
    {
        $words = [];
        foreach ($this->cache->lessonDistractorPool((int) $word->lesson_id) as $row) {
            if ($row['id'] === (int) $word->id) {
                continue;
            }
            $words[] = (new Word)->forceFill(['id' => $row['id'], 'word' => $row['word'], 'translation' => $row['translation']]);
            if (count($words) >= self::POOL) {
                break;
            }
        }

        return $words;
    }

    /**
     * Active words other than the one on the card, in a stable order.
     *
     * @return Builder<Word>
     */
    private function pool(Word $word): Builder
    {
        return Word::query()
            ->where('is_active', true)
            ->whereKeyNot($word->id)
            ->orderBy('id')
            ->limit(self::POOL);
    }

    private static function key(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }
}
