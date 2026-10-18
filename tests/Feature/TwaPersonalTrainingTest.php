<?php

declare(strict_types=1);

/*
 * HTTP layer of personal training sessions (task 1.3a C): POST /training/sessions, /finish,
 * 410 after finish, ownership, validation, throttle, `training:close-expired`.
 * The domain rules themselves are covered by PersonalTrainingSessionTest.
 */

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Twa\Services\JwtService;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\ProductEvent;
use App\Models\Stage;
use App\Models\TelegramGroup;
use App\Models\TrainingSession;
use App\Models\Word;
use App\Models\WordRepetition;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

function ptHeaders(Learner $learner): array
{
    return ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];
}

/** @return Collection<int, Lesson> lessons of a fresh course in study order, each with `$words` words */
function ptCourseLessons(int $lessons = 2, int $words = 2, string $status = 'published'): Collection
{
    $course = Course::factory()->create(['status' => $status, 'published_at' => $status === 'published' ? now() : null]);
    $stage = Stage::factory()->create(['course_id' => $course->id, 'number' => 1]);

    return collect(range(1, $lessons))->map(function (int $number) use ($stage, $words): Lesson {
        $lesson = Lesson::factory()->create(['stage_id' => $stage->id, 'number' => $number]);
        Word::factory()->count($words)->for($lesson)->create();

        return $lesson;
    });
}

function ptOpen(object $test, Learner $learner, array $body): TestResponse
{
    return $test->withHeaders(ptHeaders($learner))->postJson('/api/twa/training/sessions', $body);
}

// --- POST /training/sessions ---------------------------------------------------------

it('opens a lesson session and answers like /start, with mode and the first card', function (): void {
    $lesson = ptCourseLessons(1, 3)->first();
    $learner = Learner::factory()->create();

    $response = ptOpen($this, $learner, ['mode' => 'lesson', 'lesson_id' => $lesson->id, 'source' => 'picker'])
        ->assertOk()
        ->assertJsonPath('mode', 'lesson')
        ->assertJsonPath('lesson.lesson', 1)
        ->assertJsonPath('total_words', 3)
        ->assertJsonPath('card.card_kind', 'new')
        ->assertJsonPath('done_reason', null);

    $session = TrainingSession::query()->findOrFail($response->json('session_id'));
    expect($session->learner_id)->toBe($learner->id)
        ->and($session->mode)->toBe('lesson')
        ->and($session->lesson_id)->toBe($lesson->id)
        ->and($session->source)->toBe('picker')
        ->and($session->status)->toBe('open');
});

it('is idempotent over HTTP: two POSTs give one session and the same first card', function (): void {
    $lesson = ptCourseLessons(1, 2)->first();
    $learner = Learner::factory()->create();
    $body = ['mode' => 'lesson', 'lesson_id' => $lesson->id];

    $opened = ptOpen($this, $learner, $body)->assertOk();
    $first = $opened->json('session_id');
    $second = ptOpen($this, $learner, $body)->assertOk()->json('session_id');
    // The TWA opens, then navigates to the training screen, which calls /start: same card, nothing skipped.
    $started = $this->withHeaders(ptHeaders($learner))->postJson("/api/twa/training/sessions/{$first}/start")->assertOk();
    expect($started->json('card.word_id'))->toBe($opened->json('card.word_id'));

    $daily1 = ptOpen($this, $learner, ['mode' => 'daily_review'])->assertOk()->json('session_id');
    $daily2 = ptOpen($this, $learner, ['mode' => 'daily_review'])->assertOk()->json('session_id');

    expect($second)->toBe($first)
        ->and($daily2)->toBe($daily1)
        ->and($daily1)->not->toBe($first)
        ->and(TrainingSession::query()->where('learner_id', $learner->id)->count())->toBe(2);
});

it('opens a daily review with lesson: null, the goal as total and no source needed', function (): void {
    $lesson = ptCourseLessons(1, 2)->first();
    $learner = Learner::factory()->create(['active_course_id' => $lesson->stage?->course_id, 'daily_goal' => 15]);

    $response = ptOpen($this, $learner, ['mode' => 'daily_review'])
        ->assertOk()
        ->assertJsonPath('mode', 'daily_review')
        ->assertJsonPath('lesson', null)
        ->assertJsonPath('total_words', 15)
        ->assertJsonPath('progress', ['done' => 0, 'total' => 15]);

    expect(TrainingSession::query()->findOrFail($response->json('session_id'))->source)->toBe('self');
});

it('serves due cards from different lessons in a daily review opened over HTTP', function (): void {
    $lessons = ptCourseLessons(2, 1);
    $learner = Learner::factory()->create(['active_course_id' => null]);
    $wordIds = [];
    foreach ($lessons as $lesson) {
        $word = $lesson->words()->firstOrFail();
        $wordIds[] = $word->id;
        WordRepetition::factory()->create([
            'learner_id' => $learner->id,
            'word_id' => $word->id,
            'state' => RepetitionState::Review->value,
            'repetitions' => 2,
            'interval_days' => 6,
            'is_hard' => false,
            'next_review_at' => now()->subDay(),
            'served_ahead_token' => null,
        ]);
    }

    $start = ptOpen($this, $learner, ['mode' => 'daily_review'])->assertOk();
    $sessionId = $start->json('session_id');
    $served = [$start->json('card.word_id')];
    expect($start->json('card.card_kind'))->toBe('due');

    $review = $this->withHeaders(ptHeaders($learner))
        ->postJson("/api/twa/training/sessions/{$sessionId}/review", ['word_id' => $served[0], 'quality' => 5, 'time_spent_ms' => 800])
        ->assertOk();
    $served[] = $review->json('card.word_id');

    expect(collect($served)->sort()->values()->all())->toBe(collect($wordIds)->sort()->values()->all());
});

it('rejects a malformed body with 422', function (array $body, string $field): void {
    $lesson = ptCourseLessons(1, 1)->first();
    $body = array_map(fn ($v) => $v === '@lesson' ? $lesson->id : $v, $body);

    ptOpen($this, Learner::factory()->create(), $body)->assertStatus(422)->assertJsonValidationErrors($field);
})->with([
    'no mode' => [[], 'mode'],
    'group mode' => [['mode' => 'group_lesson', 'lesson_id' => '@lesson'], 'mode'],
    'unknown mode' => [['mode' => 'exam'], 'mode'],
    'array mode' => [['mode' => ['lesson']], 'mode'],
    'lesson_id not an integer' => [['mode' => 'lesson', 'lesson_id' => 'abc'], 'lesson_id'],
    'lesson_id zero' => [['mode' => 'lesson', 'lesson_id' => 0], 'lesson_id'],
    'unknown source' => [['mode' => 'daily_review', 'source' => 'command'], 'source'],
]);

it('answers 422 lesson_mismatch for a lesson mode without a lesson and for a daily review with one', function (): void {
    $lesson = ptCourseLessons(1, 1)->first();
    $learner = Learner::factory()->create();

    ptOpen($this, $learner, ['mode' => 'lesson'])
        ->assertStatus(422)->assertExactJsonStructure(['error' => ['code', 'message']])
        ->assertJsonPath('error.code', 'lesson_mismatch');
    ptOpen($this, $learner, ['mode' => 'daily_review', 'lesson_id' => $lesson->id])
        ->assertStatus(422)->assertJsonPath('error.code', 'lesson_mismatch');

    expect(TrainingSession::query()->count())->toBe(0);
});

it('answers 404 lesson_not_found for a missing lesson and for a lesson of an unpublished course', function (string $status): void {
    $lesson = ptCourseLessons(1, 1, $status)->first();
    $learner = Learner::factory()->create();

    ptOpen($this, $learner, ['mode' => 'lesson', 'lesson_id' => $lesson->id])
        ->assertStatus(404)->assertJsonPath('error.code', 'lesson_not_found');
    ptOpen($this, $learner, ['mode' => 'lesson', 'lesson_id' => 999999])
        ->assertStatus(404)->assertJsonPath('error.code', 'lesson_not_found');

    expect(TrainingSession::query()->count())->toBe(0);
})->with(['draft', 'review', 'archived']);

it('requires a token', function (): void {
    $this->postJson('/api/twa/training/sessions', ['mode' => 'daily_review'])->assertStatus(401);
    $this->postJson('/api/twa/training/sessions/1/finish')->assertStatus(401);
});

it('records training.session_opened with mode, source and the reused flag', function (): void {
    $learner = Learner::factory()->create();

    $id = ptOpen($this, $learner, ['mode' => 'daily_review', 'source' => 'reminder'])->assertOk()->json('session_id');
    ptOpen($this, $learner, ['mode' => 'daily_review', 'source' => 'self'])->assertOk();

    $events = ProductEvent::query()->where('name', ProductEventName::TrainingSessionOpened->value)->orderBy('id')->get();
    expect($events)->toHaveCount(2)
        ->and($events[0]->learner_id)->toBe($learner->id)
        ->and($events[0]->props)->toMatchArray(['training_session_id' => $id, 'mode' => 'daily_review', 'source' => 'reminder', 'reused' => false])
        ->and($events[1]->props)->toMatchArray(['mode' => 'daily_review', 'source' => 'self', 'reused' => true]);
});

it('throttles session creation: the 11th POST in a minute is 429, per learner', function (): void {
    $learner = Learner::factory()->create();

    foreach (range(1, 10) as $_) {
        ptOpen($this, $learner, ['mode' => 'daily_review'])->assertOk();
    }
    ptOpen($this, $learner, ['mode' => 'daily_review'])->assertStatus(429);

    ptOpen($this, Learner::factory()->create(), ['mode' => 'daily_review'])->assertOk();
});

// --- POST /training/sessions/{id}/finish ---------------------------------------------

it('finishes a personal session with a summary, repeats with the same numbers and then answers 410', function (): void {
    $lesson = ptCourseLessons(1, 3)->first();
    $learner = Learner::factory()->create();
    $headers = ptHeaders($learner);
    $id = ptOpen($this, $learner, ['mode' => 'lesson', 'lesson_id' => $lesson->id])->assertOk()->json('session_id');

    $grade = function (int $quality) use ($headers, $id): void {
        $card = $this->withHeaders($headers)->getJson("/api/twa/training/sessions/{$id}/next")->assertOk()->json('card');
        $this->withHeaders($headers)
            ->postJson("/api/twa/training/sessions/{$id}/review", ['word_id' => $card['word_id'], 'quality' => $quality, 'time_spent_ms' => 500])
            ->assertOk();
    };
    $grade(5);
    $grade(2);

    $this->withHeaders($headers)->postJson("/api/twa/training/sessions/{$id}/finish")
        ->assertOk()
        ->assertExactJson(['reviewed' => 2, 'correct' => 1, 'new_words' => 2]);

    $session = TrainingSession::query()->findOrFail($id);
    expect($session->status)->toBe('closed')
        ->and($session->done_reason)->toBe('finished')
        ->and($session->ended_at)->not->toBeNull();
    $endedAt = $session->ended_at?->toIso8601String();

    $this->withHeaders($headers)->postJson("/api/twa/training/sessions/{$id}/finish")
        ->assertOk()
        ->assertExactJson(['reviewed' => 2, 'correct' => 1, 'new_words' => 2]);
    expect(TrainingSession::query()->findOrFail($id)->ended_at?->toIso8601String())->toBe($endedAt);

    $this->withHeaders($headers)->postJson("/api/twa/training/sessions/{$id}/start")
        ->assertStatus(410)->assertJsonPath('error.code', 'session_closed');
    $this->withHeaders($headers)->getJson("/api/twa/training/sessions/{$id}/next")
        ->assertStatus(410)->assertJsonPath('error.code', 'session_closed');
    $this->withHeaders($headers)
        ->postJson("/api/twa/training/sessions/{$id}/review", ['word_id' => 1, 'quality' => 4, 'time_spent_ms' => 100])
        ->assertStatus(410)->assertJsonPath('error.code', 'session_closed');
});

it('finishes an empty session with zeroes', function (): void {
    $learner = Learner::factory()->create();
    $id = ptOpen($this, $learner, ['mode' => 'daily_review'])->assertOk()->json('session_id');

    $this->withHeaders(ptHeaders($learner))->postJson("/api/twa/training/sessions/{$id}/finish")
        ->assertOk()->assertExactJson(['reviewed' => 0, 'correct' => 0, 'new_words' => 0]);
});

it('answers 404 for a missing session and 403 forbidden for somebody elses personal session on every route', function (): void {
    $owner = Learner::factory()->create();
    $stranger = Learner::factory()->create();
    $id = ptOpen($this, $owner, ['mode' => 'daily_review'])->assertOk()->json('session_id');
    $headers = ptHeaders($stranger);

    $this->withHeaders($headers)->postJson('/api/twa/training/sessions/999999/finish')
        ->assertStatus(404)->assertJsonPath('error.code', 'session_not_found');

    foreach ([
        $this->withHeaders($headers)->postJson("/api/twa/training/sessions/{$id}/finish"),
        $this->withHeaders($headers)->getJson("/api/twa/training/sessions/{$id}/next"),
        $this->withHeaders($headers)->postJson("/api/twa/training/sessions/{$id}/start"),
        $this->withHeaders($headers)->postJson("/api/twa/training/sessions/{$id}/review", ['word_id' => 1, 'quality' => 4, 'time_spent_ms' => 100]),
    ] as $response) {
        $response->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
    }

    expect(TrainingSession::query()->findOrFail($id)->status)->toBe('open');

    // A stranger gets 403 (not 410) even once the owner has finished.
    $this->withHeaders(ptHeaders($owner))->postJson("/api/twa/training/sessions/{$id}/finish")->assertOk();
    $this->withHeaders($headers)->postJson("/api/twa/training/sessions/{$id}/finish")
        ->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
});

it('does not let a learner finish a group session, not even a member', function (): void {
    $group = TelegramGroup::factory()->create(['status' => 'active']);
    $member = Learner::factory()->memberOf($group)->create();
    $outsider = Learner::factory()->create();
    $session = TrainingSession::factory()->create(['telegram_group_id' => $group->id]);

    $this->withHeaders(ptHeaders($member))->postJson("/api/twa/training/sessions/{$session->id}/finish")
        ->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
    $this->withHeaders(ptHeaders($outsider))->postJson("/api/twa/training/sessions/{$session->id}/finish")
        ->assertStatus(403)->assertJsonPath('error.code', 'not_a_member');

    expect($session->fresh()?->status)->toBe('open');
});

it('rejects a blocked learner at the door (401 learner_blocked) and leaves the session open', function (): void {
    $learner = Learner::factory()->create();
    $session = TrainingSession::factory()->personal($learner, 'daily_review')->create();
    $learner->update(['blocked_at' => now()]);

    $res = $this->withHeaders(ptHeaders($learner))->postJson("/api/twa/training/sessions/{$session->id}/finish");

    $res->assertStatus(401)->assertJsonPath('error.code', 'learner_blocked');
    expect($session->fresh()?->status)->toBe('open');
});

it('reports done_reason when a daily review has nothing to show, and null while there is a card', function (): void {
    $learner = Learner::factory()->create(['active_course_id' => null]);
    $id = ptOpen($this, $learner, ['mode' => 'daily_review'])
        ->assertOk()
        ->assertJsonPath('card', null)
        ->assertJsonPath('done_reason', 'nothing_due')
        ->json('session_id');

    $this->withHeaders(ptHeaders($learner))->getJson("/api/twa/training/sessions/{$id}/next")
        ->assertOk()->assertJsonPath('done_reason', 'nothing_due');
});

it('reports lesson_complete for a finished lesson', function (): void {
    $lesson = ptCourseLessons(1, 1)->first();
    $learner = Learner::factory()->create();
    WordRepetition::factory()->create([
        'learner_id' => $learner->id,
        'word_id' => $lesson->words()->firstOrFail()->id,
        'state' => RepetitionState::Review->value,
        'repetitions' => 3,
        'interval_days' => 10,
        'next_review_at' => now()->addDays(10),
        'served_ahead_token' => null,
    ]);

    ptOpen($this, $learner, ['mode' => 'lesson', 'lesson_id' => $lesson->id])
        ->assertOk()->assertJsonPath('card', null)->assertJsonPath('done_reason', 'lesson_complete');
});

// --- training:close-expired ---------------------------------------------------------

it('training:close-expired closes expired sessions and leaves live ones', function (): void {
    $learner = Learner::factory()->create();
    $expired = TrainingSession::factory()->personal($learner, 'daily_review')->create(['expires_at' => now()->subMinute()]);
    $alive = TrainingSession::factory()->personal($learner)->create(['expires_at' => now()->addHour()]);
    $oldGroup = TrainingSession::factory()->create(['started_at' => now()->subHours(30)]);

    $this->artisan('training:close-expired')->expectsOutputToContain('Training sessions closed: 2')->assertSuccessful();

    expect($expired->fresh()?->status)->toBe('closed')
        ->and($expired->fresh()?->done_reason)->toBe('expired')
        ->and($oldGroup->fresh()?->done_reason)->toBe('expired')
        ->and($alive->fresh()?->status)->toBe('open');

    $this->artisan('training:close-expired')->expectsOutputToContain('Training sessions closed: 0')->assertSuccessful();

    // The closed session answers 410 and can still be "finished" (idempotent, keeps `expired`).
    $this->withHeaders(ptHeaders($learner))->getJson("/api/twa/training/sessions/{$expired->id}/next")->assertStatus(410);
    $this->withHeaders(ptHeaders($learner))->postJson("/api/twa/training/sessions/{$expired->id}/finish")->assertOk();
    expect($expired->fresh()?->done_reason)->toBe('expired');
});

it('schedules training:close-expired every 15 minutes without overlapping', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains((string) $e->command, 'training:close-expired'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/15 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});
