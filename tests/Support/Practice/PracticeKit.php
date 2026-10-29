<?php

declare(strict_types=1);

namespace Tests\Support\Practice;

use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Services\Modes\CollocationMode;
use App\Domain\Practice\Services\Modes\DictationMode;
use App\Domain\Practice\Services\Modes\PairsMode;
use App\Domain\Practice\Services\Modes\SentenceMode;
use App\Domain\Twa\Services\JwtService;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\Subscription;
use App\Models\Word;
use App\Models\WordRepetition;
use Illuminate\Support\Str;

/**
 * What the tests of the practice share: the access flags, a token, a fake handler in place of a mode, a course with words the
 * learner has been introduced to.
 */
final class PracticeKit
{
    /** The practice is `off` by default (docs/16 §3.2): open it to everyone and drop the plan restriction. */
    public static function openAccess(): void
    {
        config(['practice.access' => 'all', 'practice.plan' => 'all']);
    }

    /** @return array<string, string> */
    public static function headers(Learner $learner): array
    {
        return ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];
    }

    /** Puts a FakeEchoMode in place of the skeleton of the mode (the container hands it out). */
    public static function fake(PracticeMode $mode, int $candidates = 8, int $spares = 0, bool $audio = false): FakeEchoMode
    {
        $fake = new FakeEchoMode($mode, $candidates, $spares, $audio);
        app()->instance(self::handlerClass($mode), $fake);

        return $fake;
    }

    /** @return class-string */
    public static function handlerClass(PracticeMode $mode): string
    {
        return match ($mode) {
            PracticeMode::Pairs => PairsMode::class,
            PracticeMode::Collocation => CollocationMode::class,
            PracticeMode::Sentence => SentenceMode::class,
            PracticeMode::Dictation => DictationMode::class,
        };
    }

    /** A published course with one lesson (module 1, lesson 1). @return array{0: Course, 1: Lesson} */
    public static function course(string $slug = 'english-a1'): array
    {
        $course = Course::factory()->published()->create(['slug' => $slug, 'title' => ['ru' => 'Английский A1', 'uz' => 'Ingliz tili A1']]);
        $lesson = Lesson::factory()->for(Stage::factory()->create(['course_id' => $course->id, 'number' => 1]))->create(['number' => 1, 'title' => 'Еда']);

        return [$course, $lesson];
    }

    /** A learner on Pro (a live subscription), with the cached plan dropped. */
    public static function pro(Learner $learner): Learner
    {
        Subscription::factory()->create(['subscriber_id' => $learner->id]);
        app(EntitlementResolver::class)->forget($learner);

        return $learner;
    }

    /**
     * Words of the lesson; the learner has been introduced to them when `$introducedTo` is given (a `word_repetitions` row in `$state`).
     *
     * @param  array<string, mixed>  $attributes  for every word
     * @return list<Word>
     */
    public static function words(Lesson $lesson, int $count, ?Learner $introducedTo = null, array $attributes = [], string $state = 'new'): array
    {
        $words = [];
        for ($i = 0; $i < $count; $i++) {
            $word = Word::factory()->for($lesson)->create(['word' => 'w'.Str::lower(Str::random(6)), ...$attributes]);
            if ($introducedTo !== null) {
                self::introduce($introducedTo, $word, $state);
            }
            $words[] = $word;
        }

        return $words;
    }

    /** The learner has been served the word (any state counts, `new` included). */
    public static function introduce(Learner $learner, Word $word, string $state = 'new'): WordRepetition
    {
        return WordRepetition::factory()->create([
            'learner_id' => $learner->id,
            'word_id' => $word->id,
            'state' => $state,
            'repetitions' => $state === 'review' ? 2 : 0,
        ]);
    }
}
