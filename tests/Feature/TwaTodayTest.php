<?php

declare(strict_types=1);

/*
 * GET /api/twa/today (task 1.3b C, docs/12 §7.3): values of the "Today" screen and the goal / streak
 * block of the `/finish` summary, driven through the real grading path (HTTP review in a personal session).
 * The JSON shape itself is pinned in TwaContractTest.
 */

use App\Domain\Learners\Services\TodayService;
use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Twa\Services\JwtService;
use App\Models\Course;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\Subscription;
use App\Models\TrainingSession;
use App\Models\Word;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

function todayHeaders(Learner $learner): array
{
    return ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];
}

function todayGet(object $test, Learner $learner): TestResponse
{
    return $test->withHeaders(todayHeaders($learner))->getJson('/api/twa/today');
}

/** @return Collection<int, Lesson> lessons (study order) of a fresh published course, each with `$words` active words */
function todayCourse(int $lessons = 2, int $words = 3, array $course = []): Collection
{
    $course = Course::factory()->published()->create($course);
    $stage = Stage::factory()->create(['course_id' => $course->id, 'number' => 1]);

    return collect(range(1, $lessons))->map(function (int $number) use ($stage, $words): Lesson {
        $lesson = Lesson::factory()->create(['stage_id' => $stage->id, 'number' => $number, 'title' => "Lesson {$number}"]);
        Word::factory()->count($words)->for($lesson)->create();

        return $lesson;
    });
}

/** Opens a daily review for the learner and grades `$count` cards (quality 4) over HTTP. Returns the session id. */
function todayGrade(object $test, Learner $learner, int $count): int
{
    $headers = todayHeaders($learner);
    $id = $test->withHeaders($headers)->postJson('/api/twa/training/sessions', ['mode' => 'daily_review'])
        ->assertOk()->json('session_id');

    for ($i = 0; $i < $count; $i++) {
        $card = $test->withHeaders($headers)->getJson("/api/twa/training/sessions/{$id}/next")->assertOk()->json('card');
        $test->withHeaders($headers)
            ->postJson("/api/twa/training/sessions/{$id}/review", ['word_id' => $card['word_id'], 'quality' => 4, 'time_spent_ms' => 400])
            ->assertOk();
    }

    return $id;
}

function todayLearner(array $attributes = []): Learner
{
    $lesson = todayCourse(1, 6)->firstOrFail();

    return Learner::factory()->onboarded()->create(['active_course_id' => $lesson->stage?->course_id, ...$attributes]);
}

afterEach(fn () => CarbonImmutable::setTestNow());

it('requires authentication', function (): void {
    $this->getJson('/api/twa/today')->assertStatus(401);
});

it('is empty for a fresh learner: zero goal progress, no streak, no course, the full Free quota', function (): void {
    $learner = Learner::factory()->create(['daily_goal' => 10]);

    todayGet($this, $learner)->assertOk()->assertExactJson([
        'due_count' => 0,
        'new_words_remaining' => 20,
        'goal' => ['target' => 10, 'done' => 0, 'met' => false],
        'streak' => ['current' => 0, 'best' => 0, 'met_today' => false, 'at_risk' => false, 'freezes' => 0, 'frozen_yesterday' => false],
        'active_course' => null,
        'open_session_id' => null,
        'entitlement' => ['plan' => 'free', 'source' => 'free', 'until' => null],
        'onboarding_completed' => false,
        'league' => null,
    ]);
});

it('never reports a goal target below 1, like the daily stats that decide when it is met', function (): void {
    $learner = Learner::factory()->create(['daily_goal' => 0]);

    todayGet($this, $learner)->assertOk()->assertJsonPath('goal.target', 1);
});

it('reports onboarding_completed and the Pro entitlement with its end', function (): void {
    $learner = Learner::factory()->onboarded()->create();
    $until = now()->addDays(10)->startOfSecond();
    Subscription::factory()->create(['subscriber_id' => $learner->id, 'provider' => 'promo', 'current_period_ends_at' => $until]);

    todayGet($this, $learner)->assertOk()
        ->assertJsonPath('onboarding_completed', true)
        ->assertJsonPath('entitlement.plan', 'pro')
        ->assertJsonPath('entitlement.until', $until->toIso8601String());
});

// --- goal and streak through the real grading path ---------------------------------------

it('counts graded cards towards the goal and credits the streak when the goal is met', function (): void {
    $learner = todayLearner(['daily_goal' => 2]);

    todayGrade($this, $learner, 1);
    todayGet($this, $learner)->assertOk()
        ->assertJsonPath('goal', ['target' => 2, 'done' => 1, 'met' => false])
        ->assertJsonPath('streak', ['current' => 0, 'best' => 0, 'met_today' => false, 'at_risk' => false, 'freezes' => 0, 'frozen_yesterday' => false]);

    todayGrade($this, $learner, 1);
    todayGet($this, $learner)->assertOk()
        ->assertJsonPath('goal', ['target' => 2, 'done' => 2, 'met' => true])
        ->assertJsonPath('streak', ['current' => 1, 'best' => 1, 'met_today' => true, 'at_risk' => false, 'freezes' => 0, 'frozen_yesterday' => false]);

    // Grading beyond the goal grows `done` but neither re-credits the day nor un-meets the goal.
    todayGrade($this, $learner, 1);
    todayGet($this, $learner)->assertOk()
        ->assertJsonPath('goal', ['target' => 2, 'done' => 3, 'met' => true])
        ->assertJsonPath('streak.current', 1);
});

it('shows a series that ended yesterday as at risk and one that ended earlier as zero', function (): void {
    CarbonImmutable::setTestNow('2026-10-19 08:00:00'); // Tashkent: 13:00 on the 19th
    $atRisk = Learner::factory()->create(['streak_current' => 4, 'streak_best' => 9, 'streak_last_date' => '2026-10-18']);
    $broken = Learner::factory()->create(['streak_current' => 4, 'streak_best' => 9, 'streak_last_date' => '2026-10-17']);

    todayGet($this, $atRisk)->assertOk()
        ->assertJsonPath('streak', ['current' => 4, 'best' => 9, 'met_today' => false, 'at_risk' => true, 'freezes' => 0, 'frozen_yesterday' => false]);
    todayGet($this, $broken)->assertOk()
        ->assertJsonPath('streak', ['current' => 0, 'best' => 9, 'met_today' => false, 'at_risk' => false, 'freezes' => 0, 'frozen_yesterday' => false]);
});

it('splits the day at the learner\'s local midnight (Asia/Tashkent), not at UTC midnight', function (): void {
    $learner = todayLearner(['daily_goal' => 3, 'timezone' => 'Asia/Tashkent']);

    // 23:59 on the 19th in Tashkent is 18:59 UTC; 00:01 on the 20th is 19:01 UTC - the same UTC date.
    CarbonImmutable::setTestNow('2026-10-19 18:59:00');
    todayGrade($this, $learner, 1);
    todayGet($this, $learner)->assertOk()->assertJsonPath('goal.done', 1);

    CarbonImmutable::setTestNow('2026-10-19 19:01:00');
    todayGet($this, $learner)->assertOk()->assertJsonPath('goal.done', 0);
    todayGrade($this, $learner, 2);
    todayGet($this, $learner)->assertOk()->assertJsonPath('goal.done', 2);

    expect(LearnerDailyStats::query()->where('learner_id', $learner->id)->orderBy('date')->pluck('reviews_count')->all())
        ->toBe([1, 2]);
});

it('reads "today" in the learner\'s own zone: the same instant is two different days for two learners', function (): void {
    CarbonImmutable::setTestNow('2026-10-19 19:30:00'); // 00:30 on the 20th in Tashkent, 14:30 on the 19th in New York
    $tashkent = Learner::factory()->create(['timezone' => 'Asia/Tashkent', 'streak_current' => 2, 'streak_best' => 2, 'streak_last_date' => '2026-10-19']);
    $newYork = Learner::factory()->create(['timezone' => 'America/New_York', 'streak_current' => 2, 'streak_best' => 2, 'streak_last_date' => '2026-10-19']);

    todayGet($this, $tashkent)->assertOk()->assertJsonPath('streak.at_risk', true)->assertJsonPath('streak.met_today', false);
    todayGet($this, $newYork)->assertOk()->assertJsonPath('streak.at_risk', false)->assertJsonPath('streak.met_today', true);
});

// --- due_count ------------------------------------------------------------------------

it('counts only due cards of active words', function (): void {
    $learner = Learner::factory()->create();
    $lesson = Lesson::factory()->for(Stage::factory()->create())->create();
    [$due, $future, $inactive] = [
        Word::factory()->for($lesson)->create(),
        Word::factory()->for($lesson)->create(),
        Word::factory()->for($lesson)->create(['is_active' => false]),
    ];
    $make = fn (Word $word, array $state) => WordRepetition::factory()->create([
        'learner_id' => $learner->id, 'word_id' => $word->id, 'state' => RepetitionState::Review->value, 'repetitions' => 2, ...$state,
    ]);
    $make($due, ['next_review_at' => now()->subHour()]);
    $make($future, ['next_review_at' => now()->addDays(3)]);
    $make($inactive, ['next_review_at' => now()->subDay()]);

    todayGet($this, $learner)->assertOk()->assertJsonPath('due_count', 1);
});

// --- open_session_id ------------------------------------------------------------------

it('exposes the open, unexpired daily review only', function (): void {
    $learner = Learner::factory()->create();
    $headers = todayHeaders($learner);

    // A lesson session and somebody else's daily review are not "continue".
    TrainingSession::factory()->personal($learner, 'lesson')->create();
    TrainingSession::factory()->personal(Learner::factory()->create(), 'daily_review')->create();
    todayGet($this, $learner)->assertOk()->assertJsonPath('open_session_id', null);

    $daily = TrainingSession::factory()->personal($learner, 'daily_review')->create();
    todayGet($this, $learner)->assertOk()->assertJsonPath('open_session_id', $daily->id);

    // The scheduler has not closed it yet, but it has expired: nothing to continue.
    $daily->update(['expires_at' => now()->subMinute()]);
    todayGet($this, $learner)->assertOk()->assertJsonPath('open_session_id', null);

    $daily->update(['expires_at' => now()->addHour(), 'status' => 'closed', 'ended_at' => now()]);
    todayGet($this, $learner)->assertOk()->assertJsonPath('open_session_id', null);

    // Opened over HTTP, it is what the screen points to.
    $id = $this->withHeaders($headers)->postJson('/api/twa/training/sessions', ['mode' => 'daily_review'])->assertOk()->json('session_id');
    todayGet($this, $learner)->assertOk()->assertJsonPath('open_session_id', $id);
});

// --- active course --------------------------------------------------------------------

it('describes the active course with its progress and the next lesson with new words', function (): void {
    $lessons = todayCourse(2, 2, ['title' => ['ru' => 'Английский A1', 'uz' => 'Ingliz tili A1']]);
    $learner = Learner::factory()->create(['active_course_id' => $lessons[0]->stage?->course_id]);

    todayGet($this, $learner)->assertOk()->assertJsonPath('active_course', [
        'id' => $lessons[0]->stage?->course_id,
        'slug' => Course::query()->findOrFail($lessons[0]->stage?->course_id)->slug,
        'title' => 'Английский A1',
        'progress' => ['learned' => 0, 'total' => 4],
        'next_lesson' => ['id' => $lessons[0]->id, 'stage' => 1, 'lesson' => 1, 'title' => 'Lesson 1'],
    ]);

    // Lesson 1 fully served (one word learned, one only shown): the next lesson with new words is 2.
    [$a, $b] = $lessons[0]->words()->orderBy('id')->get()->all();
    WordRepetition::factory()->create(['learner_id' => $learner->id, 'word_id' => $a->id, 'repetitions' => 1, 'state' => RepetitionState::Review->value, 'next_review_at' => now()->addDay()]);
    WordRepetition::factory()->create(['learner_id' => $learner->id, 'word_id' => $b->id, 'repetitions' => 0, 'state' => RepetitionState::New->value]);

    todayGet($this, $learner)->assertOk()
        ->assertJsonPath('active_course.progress', ['learned' => 1, 'total' => 4])
        ->assertJsonPath('active_course.next_lesson.id', $lessons[1]->id)
        ->assertJsonPath('active_course.next_lesson.lesson', 2);
});

it('has no next lesson once every word of the course was served, and localizes the title', function (): void {
    $lessons = todayCourse(1, 1, ['title' => ['ru' => 'Английский A1', 'uz' => 'Ingliz tili A1']]);
    $learner = Learner::factory()->create(['active_course_id' => $lessons[0]->stage?->course_id, 'interface_locale' => 'uz']);
    WordRepetition::factory()->create(['learner_id' => $learner->id, 'word_id' => $lessons[0]->words()->firstOrFail()->id, 'repetitions' => 1, 'next_review_at' => now()->addDay()]);

    todayGet($this, $learner)->assertOk()
        ->assertJsonPath('active_course.title', 'Ingliz tili A1')
        ->assertJsonPath('active_course.next_lesson', null)
        ->assertJsonPath('active_course.progress', ['learned' => 1, 'total' => 1]);
});

it('treats an active course that is no longer published as absent', function (): void {
    $lessons = todayCourse(1, 1);
    $learner = Learner::factory()->create(['active_course_id' => $lessons[0]->stage?->course_id]);
    Course::query()->whereKey($learner->active_course_id)->update(['status' => 'archived']);

    todayGet($this, $learner)->assertOk()->assertJsonPath('active_course', null);
});

it('treats a published but private active course as absent (the single availability rule)', function (): void {
    $lessons = todayCourse(1, 1);
    $learner = Learner::factory()->create(['active_course_id' => $lessons[0]->stage?->course_id]);
    Course::query()->whereKey($learner->active_course_id)->update(['visibility' => 'private']);

    todayGet($this, $learner)->assertOk()->assertJsonPath('active_course', null);

    // `unlisted` is "by link" and stays available.
    Course::query()->whereKey($learner->active_course_id)->update(['visibility' => 'unlisted']);
    todayGet($this, $learner)->assertOk()->assertJsonPath('active_course.id', $learner->active_course_id);
});

it('runs a constant number of queries however many lessons the course has', function (): void {
    $count = function (int $lessons): int {
        $course = todayCourse($lessons, 2)->first();
        $learner = Learner::factory()->create(['active_course_id' => $course?->stage?->course_id]);
        $learner->load('activeCourse');
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(TodayService::class)->summary($learner);

        return count(DB::getQueryLog());
    };

    expect($count(8))->toBe($count(2));
});

// --- /finish summary ------------------------------------------------------------------

it('adds the day\'s goal and streak to the /finish summary and keeps them on a repeat', function (): void {
    $learner = todayLearner(['daily_goal' => 2]);
    $id = todayGrade($this, $learner, 2);

    $expected = [
        'reviewed' => 2, 'correct' => 2, 'new_words' => 2,
        'goal' => ['target' => 2, 'done' => 2, 'met' => true],
        'streak' => ['current' => 1, 'best' => 1, 'met_today' => true, 'at_risk' => false, 'freezes' => 0, 'frozen_yesterday' => false],
    ];
    $this->withHeaders(todayHeaders($learner))->postJson("/api/twa/training/sessions/{$id}/finish")
        ->assertOk()->assertExactJson($expected);
    $this->withHeaders(todayHeaders($learner))->postJson("/api/twa/training/sessions/{$id}/finish")
        ->assertOk()->assertExactJson($expected);
});

it('shows the goal still open in /finish when the session fell short of it', function (): void {
    $learner = todayLearner(['daily_goal' => 5]);
    $id = todayGrade($this, $learner, 1);

    $this->withHeaders(todayHeaders($learner))->postJson("/api/twa/training/sessions/{$id}/finish")
        ->assertOk()
        ->assertJsonPath('goal', ['target' => 5, 'done' => 1, 'met' => false])
        ->assertJsonPath('streak.met_today', false);
});

it('counts the whole day in /finish, not only the session', function (): void {
    $learner = todayLearner(['daily_goal' => 4]);
    todayGrade($this, $learner, 2);
    $headers = todayHeaders($learner);
    $first = TrainingSession::query()->where('learner_id', $learner->id)->firstOrFail();
    $this->withHeaders($headers)->postJson("/api/twa/training/sessions/{$first->id}/finish")->assertOk();

    $second = todayGrade($this, $learner, 1);
    $this->withHeaders($headers)->postJson("/api/twa/training/sessions/{$second}/finish")
        ->assertOk()
        ->assertJsonPath('reviewed', 1)
        ->assertJsonPath('goal.done', 3);
});
