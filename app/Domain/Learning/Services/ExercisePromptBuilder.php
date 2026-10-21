<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Content\DTO\ResolvedTranslation;
use App\Domain\Content\Services\WordTranslationResolver;
use App\Domain\Learning\DTO\ExercisePrompt;
use App\Domain\Learning\Enums\ExerciseType;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\Word;
use Illuminate\Database\Eloquent\Builder;

/**
 * Turns the selected {@see ExerciseType} into what the client shows (docs/13 §2.5, docs/04 A.3).
 *
 * Reverse / audio need {@see OPTIONS} choices: the right one plus real distractors — active words
 * of the same lesson, then of the same course, then of published public platform courses (the
 * same whitelist as the exam builder; private courses of centers and learners never leak in).
 * Distractors and their order are chosen by a hash of (seed, candidate), so two `/next` calls for
 * the same serve show the same screen. When there are not enough distractors the card falls back
 * to a flash prompt — the type in the prompt is what the client must follow.
 *
 * Spelling and gap-fill prompts never depend on the database beyond the word itself; their type
 * is what the server recomputes on `POST /review` (ExerciseSelector).
 */
final class ExercisePromptBuilder
{
    public const OPTIONS = 4;

    private const POOL = 40;

    public function __construct(
        private readonly WordTranslationResolver $translations,
        private readonly GapFillBuilder $gapFill,
    ) {}

    /**
     * The type the card is actually shown with: reverse / audio without enough distractors (and a gap-fill
     * whose example lost the word) fall back to flash. `POST /review` records this, not the selector's pick.
     */
    public function effectiveType(ExerciseType $type, Word $word, string $locale, int $seed): ExerciseType
    {
        return match ($type) {
            ExerciseType::Reverse, ExerciseType::Audio => count($this->distractors(
                $type,
                $word,
                $type === ExerciseType::Reverse ? trim($word->word) : trim($this->translations->resolve($word, $locale)->text),
                $locale,
                $seed,
            )) < self::OPTIONS - 1 ? ExerciseType::Flash : $type,
            ExerciseType::GapFill => $this->gapFill->build($word) === null ? ExerciseType::Flash : $type,
            default => $type,
        };
    }

    public function build(
        ExerciseType $type,
        Word $word,
        ResolvedTranslation $translation,
        string $locale,
        ?string $audioUrl,
        int $seed,
    ): ExercisePrompt {
        return match ($type) {
            ExerciseType::Flash => ExercisePrompt::flash($word->word, $audioUrl),
            ExerciseType::Reverse => $this->choice($type, $word, $translation, $locale, $audioUrl, $seed),
            ExerciseType::Audio => $this->choice($type, $word, $translation, $locale, $audioUrl, $seed),
            ExerciseType::Spelling => new ExercisePrompt(
                $type,
                $translation->text,
                $audioUrl,
                answerLength: mb_strlen(trim($word->word)),
            ),
            ExerciseType::GapFill => $this->gapFillPrompt($word, $translation, $audioUrl),
        };
    }

    private function gapFillPrompt(Word $word, ResolvedTranslation $translation, ?string $audioUrl): ExercisePrompt
    {
        $gap = $this->gapFill->build($word);
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
        $correct = $type === ExerciseType::Reverse ? trim($word->word) : trim($translation->text);
        $distractors = $this->distractors($type, $word, $correct, $locale, $seed);

        if (count($distractors) < self::OPTIONS - 1) {
            return ExercisePrompt::flash($word->word, $audioUrl);
        }

        $options = [$correct, ...$distractors];
        usort($options, static fn (string $a, string $b): int => crc32($seed.'|'.$a) <=> crc32($seed.'|'.$b));

        return new ExercisePrompt(
            $type,
            $type === ExerciseType::Reverse ? $translation->text : '',
            $audioUrl,
            options: $options,
        );
    }

    /**
     * Up to {@see OPTIONS} - 1 texts (English words for reverse, translations for audio), distinct from the
     * right answer and from each other case-insensitively, from the nearest layer that has enough of them.
     *
     * @return list<string>
     */
    private function distractors(ExerciseType $type, Word $word, string $correct, string $locale, int $seed): array
    {
        $need = self::OPTIONS - 1;
        $taken = [self::key($correct) => true];
        $picked = [];

        foreach ($this->layers($word) as $layer) {
            $candidates = $this->texts($type, $layer->get(), $locale);
            usort($candidates, static fn (array $a, array $b): int => crc32($seed.':'.$a['id']) <=> crc32($seed.':'.$b['id']));

            foreach ($candidates as $candidate) {
                $key = self::key($candidate['text']);
                if ($key === '' || isset($taken[$key])) {
                    continue;
                }
                $taken[$key] = true;
                $picked[] = $candidate['text'];
                if (count($picked) >= $need) {
                    return $picked;
                }
            }
        }

        return $picked;
    }

    /**
     * @param  iterable<Word>  $words
     * @return list<array{id: int, text: string}>
     */
    private function texts(ExerciseType $type, iterable $words, string $locale): array
    {
        $list = [];
        foreach ($words as $w) {
            $list[] = $w;
        }

        if ($type === ExerciseType::Reverse) {
            return array_map(static fn (Word $w): array => ['id' => (int) $w->id, 'text' => trim($w->word)], $list);
        }

        $resolved = $this->translations->resolveMany($list, $locale);
        $texts = [];
        foreach ($list as $w) {
            $translation = $resolved[(int) $w->id] ?? null;
            if ($translation !== null) {
                $texts[] = ['id' => (int) $w->id, 'text' => trim($translation->text)];
            }
        }

        return $texts;
    }

    /**
     * Candidate queries, nearest first; each is evaluated only when the previous ones ran short.
     *
     * @return iterable<Builder<Word>>
     */
    private function layers(Word $word): iterable
    {
        yield $this->pool($word)->where('lesson_id', $word->lesson_id);

        $stageId = Lesson::query()->whereKey($word->lesson_id)->value('stage_id');
        $courseId = $stageId === null ? null : Stage::query()->whereKey($stageId)->value('course_id');
        if ($courseId !== null) {
            yield $this->pool($word)
                ->where('lesson_id', '<>', $word->lesson_id)
                ->whereIn('lesson_id', Lesson::query()->select('id')->whereIn('stage_id', Stage::query()->select('id')->where('course_id', $courseId)));
        }

        yield $this->pool($word)
            ->whereIn('lesson_id', Lesson::query()->select('lessons.id')
                ->join('stages', 'stages.id', '=', 'lessons.stage_id')
                ->join('courses', 'courses.id', '=', 'stages.course_id')
                ->where('courses.status', 'published')
                ->where('courses.visibility', 'public')
                ->where('courses.owner_type', 'platform'));
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
