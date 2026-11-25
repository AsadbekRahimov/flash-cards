<?php

declare(strict_types=1);

use App\Domain\Learning\Enums\ExerciseType;
use App\Domain\Twa\Services\JwtService;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\TrainingSession;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CardAnswers;
use Tests\Support\HotPathFixtures as Fx;

/*
 * The review of a lesson just studied (`lesson_review`, docs/04 A.3, docs/06 §15) over HTTP: opened with POST /training/sessions
 * {mode: lesson_review, lesson_id}, played with the ordinary /next, /review, /finish. Its words are the words of the lesson met TODAY;
 * it never moves the schedule, writes no grade and no day statistics, spends no quota - so a second round gives nothing either.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // 2026-11-25 10:00 in Tashkent; the learner's day began 2026-11-24 19:00 UTC and ends 2026-11-25 19:00 UTC.
    $this->travelTo(CarbonImmutable::parse('2026-11-25 05:00:00', 'UTC'));
    config([
        'learning.lesson_review' => ['enabled' => true, 'max_words' => 20, 'max_serves_per_word' => 3],
        'billing.plans.free.limits.new_words_per_day' => 10,
        'practice.access' => 'all', 'practice.plan' => 'all', 'game.access' => 'off',
    ]);
});

/** @return array<string, string> */
function lessonReviewHeaders(Learner $learner): array
{
    return ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];
}

/**
 * A Free learner on a course of two lessons of 5 words (1.1, 1.2) and an old lesson 0.1 learned days ago.
 *
 * @return array{learner: Learner, course: Course, lessons: array<string, Lesson>, words: array<string, array{active: list<int>, inactive: list<int>}>, headers: array<string, string>}
 */
function lessonReviewWorld(): array
{
    ['course' => $course, 'lessons' => $lessons, 'words' => $words] = Fx::course([1 => [1 => 5, 2 => 5]]);
    $learner = Learner::factory()->onboarded()->create(['timezone' => 'Asia/Tashkent', 'active_course_id' => $course->id, 'daily_goal' => 10, 'interface_locale' => 'ru']);

    return ['learner' => $learner, 'course' => $course, 'lessons' => $lessons, 'words' => $words, 'headers' => lessonReviewHeaders($learner)];
}

/** Plays a `lesson` session to its end (right answers) and finishes it; returns the session id. */
function lessonReviewPlayLesson(object $test, array $headers, int $lessonId): int
{
    $opened = $test->withHeaders($headers)->postJson('/api/twa/training/sessions', ['mode' => 'lesson', 'lesson_id' => $lessonId])->assertOk()->json();
    $card = $opened['card'];
    for ($guard = 0; $card !== null && $guard < 100; $guard++) {
        $card = $test->withHeaders($headers)->postJson("/api/twa/training/sessions/{$opened['session_id']}/review", CardAnswers::body($card))->assertOk()->json('card');
    }
    expect($card)->toBeNull();
    $test->withHeaders($headers)->postJson("/api/twa/training/sessions/{$opened['session_id']}/finish")->assertOk();

    return (int) $opened['session_id'];
}

/**
 * Plays an open review to its end; `$wrongFirst` word ids are answered wrong on their first serve.
 *
 * @param  list<int>  $wrongFirst
 * @return list<array<string, mixed>> the cards served, in order
 */
function lessonReviewPlay(object $test, array $headers, array $start, array $wrongFirst = []): array
{
    $served = [];
    $seen = [];
    $card = $start['card'];
    for ($guard = 0; $card !== null && $guard < 100; $guard++) {
        $served[] = $card;
        $id = (int) $card['word_id'];
        $right = ! (in_array($id, $wrongFirst, true) && ! isset($seen[$id]));
        $seen[$id] = true;
        $card = $test->withHeaders($headers)->postJson("/api/twa/training/sessions/{$start['session_id']}/review", CardAnswers::body($card, $right ? 4 : 2))
            ->assertOk()->json('card');
    }

    return $served;
}

/** @return list<array<string, mixed>> the learner's rows of word_repetitions, every column */
function lessonReviewRows(Learner $learner): array
{
    return DB::table('word_repetitions')->where('learner_id', $learner->id)->orderBy('id')->get()->map(fn (object $r): array => (array) $r)->all();
}

it('asks exactly the words of the lesson met today, objective exercises only, and tells their number', function (): void {
    ['learner' => $learner, 'lessons' => $lessons, 'words' => $words, 'headers' => $h] = lessonReviewWorld();
    // The first word of the lesson was met yesterday (due in three days): not "met today", the review is about today's new words.
    $this->travel(-1)->days();
    Fx::learn($learner, [$words['1.1']['active'][0]]);
    $this->travelTo(CarbonImmutable::parse('2026-11-25 05:00:00', 'UTC'));
    $today = array_slice($words['1.1']['active'], 1);
    lessonReviewPlayLesson($this, $h, $lessons['1.1']->id);

    $start = $this->withHeaders($h)->postJson('/api/twa/training/sessions', ['mode' => 'lesson_review', 'lesson_id' => $lessons['1.1']->id, 'source' => 'plan'])
        ->assertOk()
        ->assertJsonPath('mode', 'lesson_review')
        ->assertJsonPath('total_words', 4)
        ->assertJsonPath('progress', ['done' => 0, 'total' => 4])
        ->assertJsonPath('quota.new_words_remaining', null)
        ->assertJsonPath('lesson.lesson', 1)
        ->json();

    $session = TrainingSession::query()->findOrFail($start['session_id']);
    expect($session->meta['lesson_words'])->toBe($today)
        ->and($session->source)->toBe('plan');

    $served = lessonReviewPlay($this, $h, $start);
    expect(array_map(fn (array $c): int => (int) $c['word_id'], $served))->toBe($today);
    foreach ($served as $card) {
        expect(CardAnswers::typeOf($card))->toBeIn([ExerciseType::Meaning, ExerciseType::Reverse])
            ->and($card['serve_token'])->toBeString()->not->toBe('');
    }
});

it('leaves the schedule, the grades, the day and the Free quota exactly as they were', function (): void {
    ['learner' => $learner, 'lessons' => $lessons, 'headers' => $h] = lessonReviewWorld();
    lessonReviewPlayLesson($this, $h, $lessons['1.1']->id);

    $rows = lessonReviewRows($learner);
    $reviews = DB::table('training_reviews')->count();
    $day = DB::table('learner_daily_stats')->where('learner_id', $learner->id)->get()->map(fn (object $r): array => (array) $r)->all();
    $today = $this->withHeaders($h)->getJson('/api/twa/today')->assertOk()->json();
    // Every word "graduated" to day 1 in the lesson: the review is a repetition AHEAD of the schedule.
    expect(collect($rows)->pluck('state')->unique()->values()->all())->toBe(['review']);

    $this->travel(2)->minutes();
    $start = $this->withHeaders($h)->postJson('/api/twa/training/sessions', ['mode' => 'lesson_review', 'lesson_id' => $lessons['1.1']->id])->assertOk()->json();
    // Right and wrong answers both: neither a pass (it would grow the interval) nor a fail (a lapse) may reach the row.
    lessonReviewPlay($this, $h, $start, wrongFirst: [(int) $start['card']['word_id']]);
    $this->withHeaders($h)->postJson("/api/twa/training/sessions/{$start['session_id']}/finish")->assertOk();

    $after = $this->withHeaders($h)->getJson('/api/twa/today')->assertOk()->json();
    expect(lessonReviewRows($learner))->toBe($rows)
        ->and(DB::table('training_reviews')->count())->toBe($reviews)
        ->and(DB::table('learner_daily_stats')->where('learner_id', $learner->id)->get()->map(fn (object $r): array => (array) $r)->all())->toBe($day)
        ->and($after['goal'])->toBe($today['goal'])
        ->and($after['streak'])->toBe($today['streak'])
        ->and($after['new_words_remaining'])->toBe($today['new_words_remaining'])
        ->and($after['due_count'])->toBe($today['due_count']);
});

it('brings a word answered wrong back after the others, at most three serves, and sums the round on /finish without points', function (): void {
    ['learner' => $learner, 'course' => $course, 'lessons' => $lessons, 'words' => $words, 'headers' => $h] = lessonReviewWorld();
    lessonReviewPlayLesson($this, $h, $lessons['1.1']->id);
    [$w1, $w2] = $words['1.1']['active'];

    $start = $this->withHeaders($h)->postJson('/api/twa/training/sessions', ['mode' => 'lesson_review', 'lesson_id' => $lessons['1.1']->id])->assertOk()->json();
    // $w1: wrong, then right; $w2: wrong on every serve.
    $served = [];
    $seen = [];
    $card = $start['card'];
    $last = null;
    for ($guard = 0; $card !== null && $guard < 100; $guard++) {
        $id = (int) $card['word_id'];
        $served[] = $id;
        $right = ! ($id === $w2 || ($id === $w1 && ! isset($seen[$id])));
        $seen[$id] = true;
        $last = $this->withHeaders($h)->postJson("/api/twa/training/sessions/{$start['session_id']}/review", CardAnswers::body($card, $right ? 4 : 2))->assertOk()->json();
        $card = $last['card'];
    }

    // The first round in order, then the two failed ones, then $w2 a third time - and no more.
    expect($served)->toBe([...$words['1.1']['active'], $w1, $w2, $w2])
        ->and($last['done_reason'])->toBe('lesson_review_complete')
        ->and($last['progress'])->toBe(['done' => 4, 'total' => 5])
        ->and(TrainingSession::query()->findOrFail($start['session_id'])->completed_at)->not->toBeNull();

    $this->withHeaders($h)->postJson("/api/twa/training/sessions/{$start['session_id']}/finish")->assertOk()
        ->assertJsonPath('reviewed', 8)
        ->assertJsonPath('correct', 4)
        ->assertJsonPath('new_words', 0)
        ->assertJsonPath('league_points', 0)
        ->assertJsonPath('lesson_review', ['total' => 5, 'won' => 4, 'first_try' => 3])
        ->assertJsonPath('hard_round_offer', null)
        ->assertJsonPath('practice_entry', ['course' => $course->slug, 'lesson_id' => $lessons['1.1']->id]);
});

it('may be played again the same day as a new round that gives nothing either', function (): void {
    ['learner' => $learner, 'lessons' => $lessons, 'headers' => $h] = lessonReviewWorld();
    lessonReviewPlayLesson($this, $h, $lessons['1.1']->id);
    $rows = lessonReviewRows($learner);
    $day = DB::table('learner_daily_stats')->where('learner_id', $learner->id)->value('reviews_count');

    $first = $this->withHeaders($h)->postJson('/api/twa/training/sessions', ['mode' => 'lesson_review', 'lesson_id' => $lessons['1.1']->id])->assertOk()->json();
    lessonReviewPlay($this, $h, $first);
    // Not finished: the open of the next round closes the completed one as /finish would.
    $second = $this->withHeaders($h)->postJson('/api/twa/training/sessions', ['mode' => 'lesson_review', 'lesson_id' => $lessons['1.1']->id])->assertOk()->json();
    lessonReviewPlay($this, $h, $second);

    expect($second['session_id'])->not->toBe($first['session_id'])
        ->and(TrainingSession::query()->findOrFail($first['session_id'])->only(['status', 'done_reason']))->toBe(['status' => 'closed', 'done_reason' => 'finished'])
        ->and(lessonReviewRows($learner))->toBe($rows)
        ->and(DB::table('learner_daily_stats')->where('learner_id', $learner->id)->value('reviews_count'))->toBe($day);
});

it('returns the same live round on a second open and closes the round of another lesson', function (): void {
    ['lessons' => $lessons, 'headers' => $h] = lessonReviewWorld();
    lessonReviewPlayLesson($this, $h, $lessons['1.1']->id);
    lessonReviewPlayLesson($this, $h, $lessons['1.2']->id);

    $a = $this->withHeaders($h)->postJson('/api/twa/training/sessions', ['mode' => 'lesson_review', 'lesson_id' => $lessons['1.1']->id])->assertOk()->json();
    $again = $this->withHeaders($h)->postJson('/api/twa/training/sessions', ['mode' => 'lesson_review', 'lesson_id' => $lessons['1.1']->id])->assertOk()->json();
    // The card on screen is handed back as it was: same word, same token.
    expect($again['session_id'])->toBe($a['session_id'])
        ->and($again['card']['word_id'])->toBe($a['card']['word_id'])
        ->and($again['card']['serve_token'])->toBe($a['card']['serve_token']);
    $this->withHeaders($h)->getJson("/api/twa/training/sessions/{$a['session_id']}/next")->assertOk()->assertJsonPath('card.serve_token', $a['card']['serve_token']);

    $b = $this->withHeaders($h)->postJson('/api/twa/training/sessions', ['mode' => 'lesson_review', 'lesson_id' => $lessons['1.2']->id])->assertOk()->json();
    expect($b['session_id'])->not->toBe($a['session_id'])
        ->and(TrainingSession::query()->findOrFail($a['session_id'])->only(['status', 'done_reason']))->toBe(['status' => 'closed', 'done_reason' => 'expired']);
});

it('refuses a replayed answer, an answer without the card on screen and a word outside the set', function (): void {
    ['lessons' => $lessons, 'words' => $words, 'headers' => $h] = lessonReviewWorld();
    lessonReviewPlayLesson($this, $h, $lessons['1.1']->id);
    $start = $this->withHeaders($h)->postJson('/api/twa/training/sessions', ['mode' => 'lesson_review', 'lesson_id' => $lessons['1.1']->id])->assertOk()->json();
    $url = "/api/twa/training/sessions/{$start['session_id']}/review";
    $body = CardAnswers::body($start['card']);

    $this->withHeaders($h)->postJson($url, [...$body, 'serve_token' => 'forged'])->assertStatus(409)->assertJsonPath('error.code', 'card_not_due');
    $this->withHeaders($h)->postJson($url, [...$body, 'word_id' => $words['1.2']['active'][0]])->assertStatus(422)->assertJsonPath('error.code', 'out_of_scope');
    $this->withHeaders($h)->postJson($url, $body)->assertOk()->assertJsonPath('progress.done', 1);
    // The same request again: the serve is spent, nothing is counted twice.
    $this->withHeaders($h)->postJson($url, $body)->assertStatus(409)->assertJsonPath('error.code', 'card_not_due');

    expect(TrainingSession::query()->findOrFail($start['session_id'])->meta['answers'])->toBe(1);
});

it('refuses a lesson not studied today, an unknown or closed lesson, a missing lesson and a switched-off review', function (): void {
    ['learner' => $learner, 'lessons' => $lessons, 'words' => $words, 'headers' => $h] = lessonReviewWorld();
    // Lesson 1.2 was studied - but yesterday: none of its words is "met today".
    $this->travel(-1)->days();
    Fx::learn($learner, $words['1.2']['active']);
    $this->travelTo(CarbonImmutable::parse('2026-11-25 05:00:00', 'UTC'));

    $open = fn (array $body) => $this->withHeaders($h)->postJson('/api/twa/training/sessions', ['mode' => 'lesson_review', ...$body]);

    $open(['lesson_id' => $lessons['1.1']->id])->assertStatus(409)->assertJsonPath('error.code', 'lesson_review_unavailable')->assertJsonPath('error.details.reason', 'no_new_words');
    $open(['lesson_id' => $lessons['1.2']->id])->assertStatus(409)->assertJsonPath('error.details.reason', 'no_new_words');
    $open([])->assertStatus(422)->assertJsonPath('error.code', 'lesson_mismatch');
    $open(['lesson_id' => 999999])->assertStatus(404)->assertJsonPath('error.code', 'lesson_not_found');

    // Someone else's private deck: not found, whatever was met there.
    ['course' => $private, 'lessons' => $privateLessons, 'words' => $privateWords] = Fx::course([1 => [1 => 3]], ['visibility' => 'private', 'owner_type' => 'organization']);
    Fx::learn($learner, $privateWords['1.1']['active']);
    $open(['lesson_id' => $privateLessons['1.1']->id])->assertStatus(404)->assertJsonPath('error.code', 'lesson_not_found');

    lessonReviewPlayLesson($this, $h, $lessons['1.1']->id);
    config(['learning.lesson_review.enabled' => false]);
    $open(['lesson_id' => $lessons['1.1']->id])->assertStatus(409)->assertJsonPath('error.details.reason', 'disabled');

    expect(TrainingSession::query()->where('mode', 'lesson_review')->count())->toBe(0)
        ->and($private->id)->toBeInt();
});

it('refuses when neither pick-the-translation nor pick-the-word is available to the learner', function (): void {
    ['learner' => $learner, 'lessons' => $lessons, 'headers' => $h] = lessonReviewWorld();
    lessonReviewPlayLesson($this, $h, $lessons['1.1']->id);
    $learner->forceFill(['meta' => ['exercises' => ['meaning' => false, 'reverse' => false]]])->save();

    $this->withHeaders($h)->postJson('/api/twa/training/sessions', ['mode' => 'lesson_review', 'lesson_id' => $lessons['1.1']->id])
        ->assertStatus(409)->assertJsonPath('error.details.reason', 'no_objective_exercise');
});

it('keeps the round to its owner', function (): void {
    ['lessons' => $lessons, 'headers' => $h] = lessonReviewWorld();
    lessonReviewPlayLesson($this, $h, $lessons['1.1']->id);
    $start = $this->withHeaders($h)->postJson('/api/twa/training/sessions', ['mode' => 'lesson_review', 'lesson_id' => $lessons['1.1']->id])->assertOk()->json();

    $other = lessonReviewHeaders(Learner::factory()->onboarded()->create());
    $this->withHeaders($other)->getJson("/api/twa/training/sessions/{$start['session_id']}/next")->assertStatus(403);
    $this->withHeaders($other)->postJson("/api/twa/training/sessions/{$start['session_id']}/review", CardAnswers::body($start['card']))->assertStatus(403);
    $this->withHeaders($other)->postJson("/api/twa/training/sessions/{$start['session_id']}/finish")->assertStatus(403);
});

it('does not count the words of today after the local midnight', function (): void {
    ['lessons' => $lessons, 'headers' => $h] = lessonReviewWorld();
    lessonReviewPlayLesson($this, $h, $lessons['1.1']->id);

    // 23:59 in Tashkent: still today.
    $this->travelTo(CarbonImmutable::parse('2026-11-25 18:59:00', 'UTC'));
    $this->withHeaders($h)->postJson('/api/twa/training/sessions', ['mode' => 'lesson_review', 'lesson_id' => $lessons['1.1']->id])->assertOk();

    // 00:00 the next day: the open round is yesterday's, the words are no longer "met today".
    $this->travelTo(CarbonImmutable::parse('2026-11-25 19:00:00', 'UTC'));
    $this->withHeaders($h)->postJson('/api/twa/training/sessions', ['mode' => 'lesson_review', 'lesson_id' => $lessons['1.1']->id])
        ->assertStatus(409)->assertJsonPath('error.details.reason', 'no_new_words');
    expect(TrainingSession::query()->where('mode', 'lesson_review')->where('status', 'open')->count())->toBe(0);
});

it('never lets the card picker serve a review: its scope is refused', function (): void {
    $session = new TrainingSession(['mode' => TrainingSession::MODE_LESSON_REVIEW, 'lesson_id' => 1, 'learner_id' => 1]);

    expect(fn () => App\Domain\Learning\Support\TrainingScope::forSession($session))->toThrow(LogicException::class);
});

it('does not touch an unrelated learner row while a review is played (no write besides the session)', function (): void {
    ['learner' => $learner, 'lessons' => $lessons, 'headers' => $h] = lessonReviewWorld();
    lessonReviewPlayLesson($this, $h, $lessons['1.1']->id);
    $start = $this->withHeaders($h)->postJson('/api/twa/training/sessions', ['mode' => 'lesson_review', 'lesson_id' => $lessons['1.1']->id])->assertOk()->json();

    $writes = [];
    DB::listen(function ($query) use (&$writes): void {
        if (preg_match('/^(insert|update|delete)\s+(into\s+)?["`]?(\w+)/i', $query->sql, $m) === 1) {
            $writes[] = strtolower($m[3]);
        }
    });
    $this->withHeaders($h)->postJson("/api/twa/training/sessions/{$start['session_id']}/review", CardAnswers::body($start['card']))->assertOk();

    expect(array_values(array_unique($writes)))->toBe(['training_sessions'])
        ->and(WordRepetition::query()->where('learner_id', $learner->id)->count())->toBe(5);
});
