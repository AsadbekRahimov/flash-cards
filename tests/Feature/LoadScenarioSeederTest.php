<?php

declare(strict_types=1);

use App\Domain\Operations\Services\LoadTestTokenIssuer;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\Word;
use App\Models\WordRepetition;
use Database\Seeders\LoadScenarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** A published course with `$count` active words in one lesson. */
function loadScenarioCourse(int $count): Course
{
    $course = Course::factory()->published()->create();
    $stage = Stage::factory()->create(['course_id' => $course->id]);
    $lesson = Lesson::factory()->create(['stage_id' => $stage->id]);
    for ($i = 0; $i < $count; $i++) {
        Word::factory()->create(['lesson_id' => $lesson->id]);
    }

    return $course;
}

it('gives the test learners due mature cards, the pilot and a friend each, and is safe to repeat', function (): void {
    loadScenarioCourse(8);
    $this->artisan('load:tokens', ['--count' => 4, '--output' => '-'])->assertSuccessful();

    $this->seed(LoadScenarioSeeder::class);

    $learners = Learner::query()->orderBy('telegram_user_id')->get();
    expect($learners)->toHaveCount(4)
        ->and(WordRepetition::query()->count())->toBe(4 * 8)
        ->and(WordRepetition::query()->where('next_review_at', '>', now())->count())->toBe(0)
        ->and(WordRepetition::query()->where('state', 'review')->where('repetitions', '>=', 2)->count())->toBe(4 * 8)
        ->and($learners->every(fn (Learner $learner): bool => $learner->practice_pilot_at !== null))->toBeTrue();

    // repetitions 3 and 6 are the server-graded passes of ExerciseSelector (typed answers), the rest rotate flash / reverse / audio
    $reps = WordRepetition::query()->where('learner_id', $learners[0]->id)->pluck('repetitions')->unique()->sort()->values()->all();
    expect($reps)->toBe([2, 3, 4, 5, 6, 7]);

    // 1 with 2, 3 with 4
    $pairs = DB::table('friendships')->where('status', 'accepted')->count();
    expect($pairs)->toBe(2);

    // a repeat: the cards are due again (a long run spent them), no second row, no second friendship
    WordRepetition::query()->update(['next_review_at' => now()->addDays(10)]);
    $this->seed(LoadScenarioSeeder::class);

    expect(WordRepetition::query()->count())->toBe(4 * 8)
        ->and(WordRepetition::query()->where('next_review_at', '>', now())->count())->toBe(0)
        ->and(DB::table('friendships')->count())->toBe(2);
});

it('limits the cards to LOAD_SCENARIO_WORDS', function (): void {
    loadScenarioCourse(6);
    $this->artisan('load:tokens', ['--count' => 2, '--output' => '-'])->assertSuccessful();
    putenv('LOAD_SCENARIO_WORDS=3');
    $_ENV['LOAD_SCENARIO_WORDS'] = '3';

    try {
        $this->seed(LoadScenarioSeeder::class);
    } finally {
        putenv('LOAD_SCENARIO_WORDS');
        unset($_ENV['LOAD_SCENARIO_WORDS']);
    }

    expect(WordRepetition::query()->count())->toBe(2 * 3);
});

it('never touches a learner without the load-test mark', function (): void {
    loadScenarioCourse(3);
    $real = Learner::factory()->create(['telegram_user_id' => LoadTestTokenIssuer::TELEGRAM_USER_BASE + 1, 'meta' => []]);

    expect(fn () => $this->seed(LoadScenarioSeeder::class))->toThrow(RuntimeException::class, 'No test learners');
    expect(WordRepetition::query()->where('learner_id', $real->id)->count())->toBe(0)
        ->and($real->fresh()?->practice_pilot_at)->toBeNull();
});

it('refuses to run in production and without a course', function (): void {
    $this->artisan('load:tokens', ['--count' => 1, '--output' => '-'])->assertSuccessful();

    expect(fn () => $this->seed(LoadScenarioSeeder::class))->toThrow(RuntimeException::class, 'No published course');

    app()->detectEnvironment(fn (): string => 'production');

    expect(fn () => app(LoadScenarioSeeder::class)->run())->toThrow(RuntimeException::class, 'only in the');
});
