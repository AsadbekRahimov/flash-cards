<?php

declare(strict_types=1);

use App\Domain\Learners\DTO\ReminderContent;
use App\Domain\Learners\DTO\ReminderQuiz;
use App\Domain\Learners\Enums\ReminderKind;
use App\Domain\Learners\Services\ReminderQuizBuilder;
use App\Domain\Learning\Enums\ExerciseType;
use App\Domain\Learning\Enums\RepetitionState;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Word;
use App\Models\WordRepetition;
use App\Models\WordTranslation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Tests\Support\ReminderQuizFixtures as Fx;

uses(RefreshDatabase::class);

/*
 * The question of a reminder (docs/12 §7.5): which word, which exercise, which options — and when there is none.
 */

beforeEach(function (): void {
    $this->now = CarbonImmutable::parse('2026-10-18 14:05:00', 'UTC');
    $this->travelTo($this->now);
});

function reminderQuizBuild(Learner $learner, ReminderKind $kind = ReminderKind::Review, int $idle = 0, string $locale = 'ru'): ?ReminderQuiz
{
    return app(ReminderQuizBuilder::class)->tryBuild($learner, new ReminderContent($kind, 1, 0, $idle), $locale, CarbonImmutable::now());
}

it('asks about the due word, with four different options of which exactly one is right', function (): void {
    $words = Fx::lesson(6);
    $learner = Fx::learner();
    Fx::due($learner, $words[2]);

    $quiz = reminderQuizBuild($learner);

    expect($quiz)->not->toBeNull()
        ->and($quiz->wordId)->toBe($words[2]->id)
        ->and($quiz->word)->toBe('quizword3')
        ->and($quiz->exercise)->toBe(ExerciseType::Meaning)
        ->and($quiz->options)->toHaveCount(4)
        ->and(array_unique($quiz->options))->toHaveCount(4)
        ->and($quiz->options[$quiz->correctIndex])->toBe('переводquizword3')
        ->and($quiz->answer)->toBe('переводquizword3')
        ->and($quiz->token)->toMatch('/^[A-Za-z0-9]{10}$/');
});

it('takes the due card before one that is not due, and a hard one before an older one', function (): void {
    $words = Fx::lesson(8);
    $learner = Fx::learner();
    Fx::scheduled($learner, $words[0], 0, ['next_review_at' => now()->addHours(1)]); // not due yet, the nearest
    Fx::due($learner, $words[1], ['next_review_at' => now()->subDays(5), 'is_hard' => false]);
    Fx::due($learner, $words[2], ['next_review_at' => now()->subHours(2), 'is_hard' => true]);

    expect(reminderQuizBuild($learner)?->wordId)->toBe($words[2]->id);

    WordRepetition::query()->where('word_id', $words[2]->id)->update(['is_hard' => false]);

    expect(reminderQuizBuild($learner)?->wordId)->toBe($words[1]->id);
});

it('takes the card nearest to its date when nothing is due', function (): void {
    $words = Fx::lesson(6);
    $learner = Fx::learner();
    Fx::scheduled($learner, $words[0], 9);
    Fx::scheduled($learner, $words[1], 2);
    Fx::scheduled($learner, $words[2], 5);

    expect(reminderQuizBuild($learner, ReminderKind::StreakAtRisk)?->wordId)->toBe($words[1]->id);
});

it('never asks about a word the learner has not answered yet, nor about an inactive word', function (): void {
    $words = Fx::lesson(6);
    $learner = Fx::learner();
    WordRepetition::factory()->create(['learner_id' => $learner->id, 'word_id' => $words[0]->id, 'repetitions' => 0, 'state' => RepetitionState::New->value, 'next_review_at' => now()->subDay()]);
    Fx::scheduled($learner, $words[1], 1);
    Word::query()->whereKey($words[1]->id)->update(['is_active' => false]);

    // A new card is not due and not "answered"; the inactive word is excluded from both queries.
    expect(reminderQuizBuild($learner, ReminderKind::NewWords))->toBeNull();

    Fx::scheduled($learner, $words[2], 3);

    expect(reminderQuizBuild($learner, ReminderKind::NewWords)?->wordId)->toBe($words[2]->id);
});

it('keeps a pair with one translation out of the options together, so the right option is the only right one', function (): void {
    // he / she / it are all "u" in uz (docs/06 §12.2); the lesson has three more words to fill the options with.
    $course = Course::factory()->published()->create(['visibility' => 'public']);
    $words = Fx::lesson(3, $course, 'extra');
    $lesson = $words[0]->lesson;
    $make = function (string $english, string $ru, string $uz) use ($lesson): Word {
        $word = Word::factory()->for($lesson)->create(['word' => $english, 'translation' => $ru]);
        WordTranslation::factory()->uz()->create(['word_id' => $word->id, 'translation' => $uz]);

        return $word;
    };
    $he = $make('he', 'он', 'u');
    $make('she', 'она', 'u');
    $make('it', 'оно', 'u, bu');
    $learner = Fx::learner(['interface_locale' => 'uz']);
    Fx::due($learner, $he);

    for ($i = 0; $i < 25; $i++) {
        $quiz = reminderQuizBuild($learner, locale: 'uz');

        expect($quiz)->not->toBeNull()
            ->and($quiz->word)->toBe('he')
            ->and($quiz->options[$quiz->correctIndex])->toBe('u')
            ->and(array_values(array_filter($quiz->options, static fn (string $o): bool => str_starts_with($o, 'u'))))->toBe(['u'])
            ->and(array_keys($quiz->options, 'u', true))->toBe([$quiz->correctIndex]);
    }
});

it('falls back to the plain text when no candidate gets three distractors', function (): void {
    // A lesson of three words (the card and two others) in a course nothing else is published in: only two distractors exist.
    $course = Course::factory()->create(['visibility' => 'private']);
    $words = Fx::lesson(3, $course);
    $learner = Fx::learner();
    foreach ($words as $word) {
        Fx::due($learner, $word);
    }

    expect(reminderQuizBuild($learner))->toBeNull();
});

it('skips a candidate that has no translation and asks about the next one', function (): void {
    $lonely = Word::factory()->create(['word' => 'lonelyword', 'translation' => '']); // nothing to ask for: no translation in any language
    $words = Fx::lesson(6);
    $learner = Fx::learner();
    Fx::due($learner, $lonely, ['next_review_at' => now()->subDays(3)]);
    Fx::due($learner, $words[0], ['next_review_at' => now()->subDay()]);

    expect(reminderQuizBuild($learner)?->wordId)->toBe($words[0]->id);
});

it('asks reverse when the meaning exercise is switched off, and nothing when both are', function (): void {
    $words = Fx::lesson(6);
    $learner = Fx::learner(['meta' => ['exercises' => ['meaning' => false]]]);
    Fx::due($learner, $words[1]);

    $quiz = reminderQuizBuild($learner);

    expect($quiz?->exercise)->toBe(ExerciseType::Reverse)
        ->and($quiz?->options[$quiz->correctIndex])->toBe('quizword2')
        ->and($quiz?->answer)->toBe('переводquizword2');

    $learner->forceFill(['meta' => ['exercises' => ['meaning' => false, 'reverse' => false]]])->save();

    expect(reminderQuizBuild($learner->fresh() ?? $learner))->toBeNull();
});

it('asks on the ladder steps of the table and only up to the idle limit', function (ReminderKind $kind, int $idle, bool $asks): void {
    $words = Fx::lesson(6);
    $learner = Fx::learner();
    Fx::due($learner, $words[0]);

    expect(reminderQuizBuild($learner, $kind, $idle) !== null)->toBe($asks);
})->with([
    'review' => [ReminderKind::Review, 0, true],
    'streak at risk' => [ReminderKind::StreakAtRisk, 0, true],
    'new words' => [ReminderKind::NewWords, 1, true],
    'inactive 3 days' => [ReminderKind::Inactive, 3, true],
    'inactive 7 days' => [ReminderKind::Inactive, 7, true],
    'inactive 10 days (a sparse day)' => [ReminderKind::Inactive, 10, false],
    'never started' => [ReminderKind::NeverStarted, 0, false],
    'choose course' => [ReminderKind::ChooseCourse, 0, false],
]);

it('lets the owner raise the idle limit to the sparse days', function (): void {
    config(['learning.reminders.quiz.max_idle_days' => 60]);
    $words = Fx::lesson(6);
    $learner = Fx::learner();
    Fx::due($learner, $words[0]);

    expect(reminderQuizBuild($learner, ReminderKind::Inactive, 30))->not->toBeNull();
});

it('builds nothing when the flag is off', function (): void {
    config(['learning.reminders.quiz.enabled' => false]);
    $words = Fx::lesson(6);
    $learner = Fx::learner();
    Fx::due($learner, $words[0]);

    expect(reminderQuizBuild($learner))->toBeNull()
        ->and(app(ReminderQuizBuilder::class)->eligible(new ReminderContent(ReminderKind::Review, 1, 0)))->toBeFalse();
});

it('reports a failure of the choice and returns null instead of throwing', function (): void {
    Exceptions::fake();
    $words = Fx::lesson(6);
    $learner = Fx::learner();
    Fx::due($learner, $words[0]);
    Event::listen('eloquent.retrieved: '.WordRepetition::class, static function (): void {
        throw new RuntimeException('boom');
    });

    expect(reminderQuizBuild($learner))->toBeNull();

    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'boom');
});

it('does not take the buttons of two options that read the same once cut', function (): void {
    $long = str_repeat('очень длинный перевод ', 3);
    $words = Fx::lesson(6);
    // Four of the six translations share the first 40 characters; only two are different, so no four distinct buttons exist.
    foreach (array_slice($words, 0, 4) as $i => $word) {
        Word::query()->whereKey($word->id)->update(['translation' => $long.$i]);
    }
    $learner = Fx::learner();
    Fx::due($learner, $words[0]);

    expect(reminderQuizBuild($learner))->toBeNull();
});

it('asks only an exercise that is really available: meaning off in the config makes it reverse, nothing when reverse is off too', function (): void {
    config(['learning.ladder.meaning_enabled' => false]);
    $words = Fx::lesson(6);
    $learner = Fx::learner();
    Fx::due($learner, $words[1]);

    expect(reminderQuizBuild($learner)?->exercise)->toBe(ExerciseType::Reverse);

    $learner->forceFill(['meta' => ['exercises' => ['reverse' => false]]])->save();

    expect(reminderQuizBuild($learner->fresh() ?? $learner))->toBeNull();
});
