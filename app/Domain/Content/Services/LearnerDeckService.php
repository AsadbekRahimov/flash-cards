<?php

declare(strict_types=1);

namespace App\Domain\Content\Services;

use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Content\DTO\DeckImportReport;
use App\Domain\Content\DTO\DeckLimits;
use App\Domain\Content\DTO\DeckRow;
use App\Domain\Content\Exceptions\DeckException;
use App\Domain\Learning\Services\LearningCache;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\Word;
use App\Models\WordTranslation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A learner's own decks (docs/13 §8.1, docs/04 A.10). A deck is a course with `owner_type = learner`,
 * `visibility = private`, published at once (nobody but the owner can see it, so there is no moderation).
 * It lives in the ordinary content tables: one stage "Моя колода", lessons "Часть N" of
 * `config('decks.lesson_size')` words, so `daily_review`, the Free new-word quota, progress and
 * the catalog treat it like any other course (access: CourseCatalogService::isAvailable with the learner).
 *
 * Limits by plan (config/decks.php, plan from EntitlementResolver): Free 1 deck × 50 words, Pro 20 × 2000.
 * A deck counts while it is not archived; a word while it is active. Creating a deck is serialised on the
 * learner row, adding words on the deck row, so parallel requests cannot overshoot a limit. An import is
 * all or nothing: when the new words do not fit, nothing is written.
 *
 * Words are never deleted (docs/03 §8): removing a word or the whole deck sets `words.is_active = false`;
 * `word_repetitions` stay, so the progress survives.
 */
final class LearnerDeckService
{
    public const STAGE_TITLE = 'Моя колода';

    public function __construct(
        private readonly EntitlementResolver $entitlements,
        private readonly CourseCatalogService $catalog,
        private readonly CoursePublisher $publisher,
        private readonly DeckImportParser $parser,
        private readonly LearningCache $cache,
    ) {}

    public function limits(Learner $learner): DeckLimits
    {
        $plan = $this->entitlements->for($learner)->plan->value;
        /** @var array{decks?: mixed, deck_words?: mixed} $limits */
        $limits = config("decks.limits.{$plan}") ?? config('decks.limits.free');

        return new DeckLimits(
            max(0, (int) ($limits['decks'] ?? 0)),
            max(0, (int) ($limits['deck_words'] ?? 0)),
            $this->decksQuery($learner)->count(),
        );
    }

    /** @return Collection<int, Course> the learner's non-archived decks, oldest first */
    public function decks(Learner $learner): Collection
    {
        return $this->decksQuery($learner)->orderBy('id')->get();
    }

    /** The learner's own, non-archived deck by slug; null for a missing, archived or somebody else's one. */
    public function find(Learner $learner, string $slug): ?Course
    {
        return $this->decksQuery($learner)->where('slug', $slug)->first();
    }

    /** @throws DeckException deck_limit_reached | deck_title_invalid */
    public function create(Learner $learner, string $title): Course
    {
        $title = $this->title($title);

        return DB::transaction(function () use ($learner, $title): Course {
            // Serialises parallel creates of one learner: the count below cannot be overtaken.
            Learner::query()->whereKey($learner->id)->lockForUpdate()->firstOrFail();

            $limits = $this->limits($learner);
            if ($limits->usedDecks >= $limits->decks) {
                throw DeckException::decksLimit($limits->decks);
            }

            $course = Course::query()->create([
                'slug' => 'deck-'.$learner->id.'-'.Str::lower(Str::random(8)),
                'owner_type' => 'learner',
                'owner_id' => $learner->id,
                'source' => 'learner',
                'title' => ['ru' => $title, 'uz' => $title],
                'description' => [],
                'target_language' => 'en',
                'visibility' => 'private',
                'status' => 'published',
                'published_at' => now(),
            ]);

            Stage::query()->create(['course_id' => $course->id, 'number' => 1, 'title' => self::STAGE_TITLE]);

            // Column defaults (words_count, is_featured, …) are not on the fresh model.
            return $course->refresh();
        });
    }

    /** @throws DeckException deck_title_invalid | deck_not_found */
    public function rename(Learner $learner, Course $deck, string $title): Course
    {
        $title = $this->title($title);
        $this->assertOwned($learner, $deck);

        $deck->forceFill(['title' => ['ru' => $title, 'uz' => $title]])->save();

        return $deck;
    }

    /**
     * Parses `$text` and adds the words.
     *
     * @throws DeckException deck_not_found | deck_import_empty | deck_words_limit_reached
     */
    public function importText(Learner $learner, Course $deck, string $text, string $format): DeckImportReport
    {
        $this->assertOwned($learner, $deck);

        $parsed = $this->parser->parse($text, $format, $learner->interface_locale);
        if ($parsed->rows === []) {
            throw DeckException::importEmpty();
        }

        return $this->addWords($learner, $deck, $parsed->rows, $parsed->rejected);
    }

    /**
     * Adds rows to the deck. A word already in the deck (any case) is a duplicate and stays as it is; a word the
     * learner removed earlier comes back with the new translation.
     *
     * @param  list<DeckRow>  $rows  distinct by DeckRow::key() (DeckImportParser guarantees it)
     * @param  list<array{line:int, reason:string}>  $rejected  lines the parser dropped, passed through to the report
     *
     * @throws DeckException deck_not_found | deck_words_limit_reached
     */
    public function addWords(Learner $learner, Course $deck, array $rows, array $rejected = []): DeckImportReport
    {
        $this->assertOwned($learner, $deck);
        $limit = $this->limits($learner)->deckWords;

        return DB::transaction(function () use ($deck, $rows, $rejected, $limit): DeckImportReport {
            // Serialises parallel imports into one deck: the active count below cannot be overtaken.
            Course::query()->whereKey($deck->id)->lockForUpdate()->firstOrFail();

            $stage = Stage::query()->where('course_id', $deck->id)->orderBy('number')->firstOrFail();
            /** @var Collection<int, Lesson> $lessons */
            $lessons = Lesson::query()->where('stage_id', $stage->id)->orderBy('number')->get();

            $existing = Word::query()->whereIn('lesson_id', $lessons->modelKeys())->get()
                ->keyBy(fn (Word $word): string => mb_strtolower($word->word));

            $fresh = [];
            $revived = [];
            $duplicates = 0;
            foreach ($rows as $row) {
                $known = $existing->get($row->key());
                if ($known === null) {
                    $fresh[] = $row;
                } elseif (! $known->is_active) {
                    $revived[] = [$known, $row];
                } else {
                    $duplicates++;
                }
            }

            $active = $existing->filter(fn (Word $word): bool => $word->is_active)->count();
            $room = max(0, $limit - $active);
            if (count($fresh) + count($revived) > $room) {
                throw DeckException::wordsLimit($limit, $room);
            }

            foreach ($revived as [$word, $row]) {
                $word->forceFill(['is_active' => true, 'translation' => $row->translation, 'example' => $row->example])->save();
                $this->writeTranslations($word, $row);
            }

            $counts = [];
            foreach ($existing as $word) {
                $counts[$word->lesson_id] = ($counts[$word->lesson_id] ?? 0) + 1;
            }

            $touched = $this->place($stage, $lessons, $counts, $fresh);

            $this->publisher->recount($deck);

            $lessonsToForget = $lessons->merge($touched)->unique('id')->values()->all();
            DB::afterCommit(function () use ($lessonsToForget): void {
                foreach ($lessonsToForget as $lesson) {
                    $this->cache->forgetLesson($lesson);
                }
            });

            return new DeckImportReport(count($fresh), count($revived), $duplicates, $rejected);
        });
    }

    /**
     * Deactivates one word of the deck; its repetitions stay.
     *
     * @throws DeckException deck_not_found | deck_word_not_found
     */
    public function removeWord(Learner $learner, Course $deck, int $wordId): void
    {
        $this->assertOwned($learner, $deck);

        DB::transaction(function () use ($deck, $wordId): void {
            Course::query()->whereKey($deck->id)->lockForUpdate()->firstOrFail();

            /** @var Word|null $word */
            $word = Word::query()
                ->whereKey($wordId)
                ->whereHas('lesson.stage', fn ($q) => $q->where('course_id', $deck->id))
                ->where('is_active', true)
                ->first();
            if ($word === null) {
                throw DeckException::wordNotFound();
            }

            $word->forceFill(['is_active' => false])->save();
            $this->publisher->recount($deck);

            DB::afterCommit(fn () => $this->cache->forgetLesson($word->lesson()->firstOrFail()));
        });
    }

    /**
     * "Deleting" a deck: archived, its words deactivated, no longer the learner's active course.
     * Repetitions are untouched (the progress is kept); idempotent for an already archived deck.
     *
     * @throws DeckException deck_not_found
     */
    public function archive(Learner $learner, Course $deck): void
    {
        if (! $this->catalog->isOwnedBy($deck, $learner)) {
            throw DeckException::notFound();
        }

        $lessons = DB::transaction(function () use ($learner, $deck): Collection {
            $locked = Course::query()->whereKey($deck->id)->lockForUpdate()->firstOrFail();

            /** @var Collection<int, Lesson> $lessons */
            $lessons = Lesson::query()
                ->whereIn('stage_id', Stage::query()->where('course_id', $locked->id)->select('id'))
                ->get();

            Word::query()->whereIn('lesson_id', $lessons->modelKeys())->update(['is_active' => false]);
            Learner::query()->whereKey($learner->id)->where('active_course_id', $locked->id)->update(['active_course_id' => null]);

            $locked->forceFill(['status' => CoursePublisher::ARCHIVED])->save();
            $this->publisher->recount($locked);

            return $lessons;
        });

        $learner->refresh();
        $deck->refresh();

        DB::afterCommit(function () use ($lessons): void {
            foreach ($lessons as $lesson) {
                $this->cache->forgetLesson($lesson);
            }
        });
    }

    /**
     * Active words of the deck in study order, with their translations.
     *
     * @return Collection<int, Word>
     */
    public function words(Course $deck): Collection
    {
        return Word::query()
            ->select('words.*')
            ->join('lessons', 'lessons.id', '=', 'words.lesson_id')
            ->join('stages', 'stages.id', '=', 'lessons.stage_id')
            ->where('stages.course_id', $deck->id)
            ->where('words.is_active', true)
            ->orderBy('stages.number')
            ->orderBy('lessons.number')
            ->orderBy('words.id')
            ->with('translations')
            ->get();
    }

    /**
     * Writes new words into the lessons, opening a new "Часть N" when the last one is full.
     *
     * @param  Collection<int, Lesson>  $lessons  existing lessons in order
     * @param  array<int, int>  $counts  lesson id => number of words (active or not) already in it
     * @param  list<DeckRow>  $rows
     * @return list<Lesson> the lessons that received words
     */
    private function place(Stage $stage, Collection $lessons, array $counts, array $rows): array
    {
        $size = max(1, (int) config('decks.lesson_size', 50));

        /** @var array<int, Lesson> $byId */
        $byId = [];
        $currentId = null;
        $number = 0;
        foreach ($lessons as $lesson) {
            $byId[$lesson->id] = $lesson;
            $currentId = $lesson->id;
            $number = $lesson->number;
        }

        $touched = [];
        foreach ($rows as $row) {
            if ($currentId === null || ($counts[$currentId] ?? 0) >= $size) {
                $number++;
                $created = Lesson::query()->create(['stage_id' => $stage->id, 'number' => $number, 'title' => 'Часть '.$number]);
                $byId[$created->id] = $created;
                $currentId = $created->id;
            }

            $word = Word::query()->create([
                'lesson_id' => $currentId,
                'word' => $row->word,
                'translation' => $row->translation,
                'example' => $row->example,
            ]);
            $this->writeTranslations($word, $row);

            $counts[$currentId] = ($counts[$currentId] ?? 0) + 1;
            $touched[$currentId] = $byId[$currentId];
        }

        return array_values($touched);
    }

    private function writeTranslations(Word $word, DeckRow $row): void
    {
        $texts = array_filter(['ru' => $row->translation, 'uz' => $row->translationUz]);

        foreach ($texts as $locale => $text) {
            WordTranslation::query()->updateOrCreate(
                ['word_id' => $word->id, 'locale' => $locale],
                ['translation' => $text, 'source' => 'learner', 'reviewed_at' => null],
            );
        }
    }

    private function title(string $title): string
    {
        $title = mb_substr($this->parser->sanitize($title), 0, max(1, (int) config('decks.title_max_length', 60)));
        if ($title === '' || preg_match('/\p{L}/u', $title) !== 1) {
            throw DeckException::titleInvalid();
        }

        return $title;
    }

    private function assertOwned(Learner $learner, Course $deck): void
    {
        if (! $this->catalog->isOwnedBy($deck, $learner) || $deck->status !== 'published') {
            throw DeckException::notFound();
        }
    }

    /** @return Builder<Course> */
    private function decksQuery(Learner $learner): Builder
    {
        return Course::query()
            ->where('owner_type', 'learner')
            ->where('owner_id', $learner->id)
            ->where('status', '!=', CoursePublisher::ARCHIVED);
    }
}
