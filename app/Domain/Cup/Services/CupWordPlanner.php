<?php

declare(strict_types=1);

namespace App\Domain\Cup\Services;

use App\Domain\Content\Support\Phrase;
use App\Domain\Cup\Exceptions\CupException;
use App\Domain\Cup\Support\CupSeeds;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Practice\Support\SeededShuffle;
use App\Models\Course;
use App\Models\Cup;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The words of a cup (docs/33 §5.1): the course of the level, the words with a translation in EVERY supported locale (the snapshot of
 * a day is shown to each participant in his language), the plan of the whole cup chosen at the start by the secret seed - no word
 * twice in one cup.
 *
 * A day gets `round_tasks` words plus up to `round.spare_words` spares (a word the composer cannot turn into a task is replaced by the
 * next one, the same for everybody since the day is built once); a course with fewer than `days × round_tasks` such words cannot hold
 * a cup (422 `cup_material_unavailable` at the publication).
 */
final class CupWordPlanner
{
    public function __construct(private readonly LocaleResolver $locales) {}

    /** The course of the level: `cup.level_courses` by slug, else the first published public course of the platform of the level. */
    public function courseForLevel(string $level): ?Course
    {
        $slug = config("cup.level_courses.{$level}");
        // Words only (docs/35 §3.3 c): a course of phrases never plays a cup - the round and the duel are built for single words
        // (a `spell` task, options of one kind), even when `cup.level_courses` names one.
        $query = Course::query()->publicPlatform()->ofCardKind(false);

        if (is_string($slug) && $slug !== '') {
            return $query->where('courses.slug', $slug)->first();
        }

        return $query->where('courses.level', $level)->orderBy('courses.position')->orderBy('courses.id')->first();
    }

    public function eligibleCount(Course $course): int
    {
        return $this->eligible($course)->count();
    }

    /**
     * The plan of the words of the cup: day => word ids (the order of a day is the seed's).
     *
     * @return array<int, list<int>>
     *
     * @throws CupException cup_material_unavailable
     */
    public function plan(Cup $cup, Course $course): array
    {
        /** @var list<int> $ids */
        $ids = array_map('intval', $this->eligible($course)->orderBy('words.id')->pluck('words.id')->all());
        $days = max(1, $cup->days);
        $tasks = max(1, $cup->round_tasks);
        $perDay = min($tasks + max(0, (int) config('cup.round.spare_words', 2)), intdiv(count($ids), $days));
        if ($perDay < $tasks) {
            throw CupException::materialUnavailable();
        }

        $seed = CupSeeds::plan($cup->seed_secret);
        $picked = [];
        foreach (SeededShuffle::sample($seed, 'cup-words', count($ids), $perDay * $days) as $index) {
            $picked[] = $ids[$index];
        }
        $picked = SeededShuffle::apply($picked, $seed, 'cup-words:order');

        $plan = [];
        foreach (array_chunk($picked, $perDay) as $i => $chunk) {
            $plan[$i + 1] = $chunk;
        }

        return $plan;
    }

    private function eligible(Course $course): Builder
    {
        $query = DB::table('words')
            ->join('lessons', 'lessons.id', '=', 'words.lesson_id')
            ->join('stages', 'stages.id', '=', 'lessons.stage_id')
            ->where('stages.course_id', $course->id)
            ->where('words.is_active', true)
            ->where('words.word', '<>', '')
            ->where('words.translation', '<>', '')
            ->where(fn (Builder $q) => $q->whereNull('words.part_of_speech')->orWhere('words.part_of_speech', '<>', Phrase::PART_OF_SPEECH));

        $fallback = $this->locales->fallback();
        foreach ($this->locales->supported() as $locale) {
            if ($locale === $fallback) {
                continue; // `words.translation` is the text of the fallback locale
            }
            $query->whereExists(fn (Builder $q) => $q->selectRaw('1')
                ->from('word_translations')
                ->whereColumn('word_translations.word_id', 'words.id')
                ->where('word_translations.locale', $locale)
                ->where('word_translations.translation', '<>', ''));
        }

        return $query;
    }
}
