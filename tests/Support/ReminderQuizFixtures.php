<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\Word;
use App\Models\WordRepetition;
use App\Models\WordTranslation;
use Illuminate\Support\Facades\DB;

/**
 * Data of the reminder-question tests: a lesson of words with distinct translations (so four options always exist) and
 * the learner's cards on them.
 */
final class ReminderQuizFixtures
{
    /**
     * A lesson of a public course with `$count` words `quizword1 … ` (ru: `перевод1 …`, uz: `tarjima1 …`).
     *
     * @return list<Word> in id order
     */
    public static function lesson(int $count = 6, ?Course $course = null, string $prefix = 'quizword'): array
    {
        $course ??= Course::factory()->published()->create(['visibility' => 'public']);
        $stage = Stage::query()->where('course_id', $course->id)->first() ?? Stage::factory()->create(['course_id' => $course->id, 'number' => 1]);
        $lesson = Lesson::factory()->create(['stage_id' => $stage->id, 'number' => (int) Lesson::query()->where('stage_id', $stage->id)->count() + 1]);

        $words = [];
        for ($i = 1; $i <= $count; $i++) {
            $word = Word::factory()->for($lesson)->create(['word' => $prefix.$i, 'translation' => "перевод{$prefix}{$i}"]);
            WordTranslation::factory()->uz()->create(['word_id' => $word->id, 'translation' => "tarjima{$prefix}{$i}"]);
            $words[] = $word;
        }

        return $words;
    }

    /** @param array<string, mixed> $attributes */
    public static function learner(array $attributes = []): Learner
    {
        return Learner::factory()->create([
            'telegram_user_id' => 777001,
            'interface_locale' => 'ru',
            'reminder_time' => '19:00',
            ...$attributes,
        ]);
    }

    /** A card that is due (graduated, date passed). @param array<string, mixed> $attributes */
    public static function due(Learner $learner, Word $word, array $attributes = []): WordRepetition
    {
        return WordRepetition::factory()->due()->create(['learner_id' => $learner->id, 'word_id' => $word->id, ...$attributes]);
    }

    /** A card the learner answered that is not due yet. @param array<string, mixed> $attributes */
    public static function scheduled(Learner $learner, Word $word, int $inDays, array $attributes = []): WordRepetition
    {
        return WordRepetition::factory()->review()->create([
            'learner_id' => $learner->id,
            'word_id' => $word->id,
            'next_review_at' => now()->addDays($inDays),
            ...$attributes,
        ]);
    }

    /**
     * What a chat answer must never change: the row counts of the tables of learning and a hash of every card (SM-2 fields included).
     *
     * @return array<string, int|string>
     */
    public static function learningWrites(): array
    {
        return [
            'word_repetitions' => DB::table('word_repetitions')->count(),
            'training_sessions' => DB::table('training_sessions')->count(),
            'training_reviews' => DB::table('training_reviews')->count(),
            'learner_daily_stats' => DB::table('learner_daily_stats')->count(),
            'cards' => md5((string) DB::table('word_repetitions')->orderBy('id')->get()->toJson()),
        ];
    }
}
