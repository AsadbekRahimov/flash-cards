<?php

declare(strict_types=1);

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Organizations\Exceptions\AssignmentException;
use App\Domain\Organizations\Services\AssignmentService;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Services\TelegramMessageRateLimiter;
use App\Jobs\AnnounceAssignmentJob;
use App\Models\Assignment;
use App\Models\AssignmentResult;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Organization;
use App\Models\ProductEvent;
use App\Models\Stage;
use App\Models\TelegramGroup;
use App\Models\TrainingReview;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\Word;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeTelegramClient;

/*
 * AssignmentService (docs/13 §1.4, §2.3 "Реализация 2B.4"): creation rules, the one-time group posts
 * (Telegram mocked), and the progress snapshot — only ACTIVE words of the lesson, `completed_at`
 * once, late completions marked.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);
    $this->service = app(AssignmentService::class);

    $this->org = Organization::factory()->create(['timezone' => 'Asia/Tashkent']);
    $this->course = Course::factory()->published()->create();
    $this->stage = Stage::factory()->create(['course_id' => $this->course->id, 'number' => 2]);
    $this->lesson = Lesson::factory()->create(['stage_id' => $this->stage->id, 'number' => 3, 'title' => 'Food']);
    $this->words = Word::factory()->count(4)->for($this->lesson)->create(['is_active' => true]);
    $this->group = TelegramGroup::factory()->create(['organization_id' => $this->org->id, 'course_id' => $this->course->id, 'status' => 'active']);
    $this->learner = Learner::factory()->memberOf($this->group)->create();
});

function asgGrade(Learner $learner, Word $word, int $repetitions = 2, ?CarbonImmutable $at = null): WordRepetition
{
    return WordRepetition::factory()->create([
        'learner_id' => $learner->id,
        'word_id' => $word->id,
        'repetitions' => $repetitions,
        'last_reviewed_at' => $at ?? now(),
    ]);
}

function asgMake(object $test, array $attrs = []): Assignment
{
    return Assignment::factory()->create(array_merge([
        'organization_id' => $test->org->id,
        'telegram_group_id' => $test->group->id,
        'lesson_id' => $test->lesson->id,
        'due_at' => now()->addDays(3),
    ], $attrs));
}

it('creates an assignment with the lesson title by default, records the event and queues the announcement after commit', function (): void {
    Queue::fake();
    $user = User::factory()->create();

    $assignment = $this->service->create($this->org, $this->group, $this->lesson, now()->addDays(2), null, 80, $user);

    expect($assignment->title)->toBe('Food')
        ->and($assignment->status)->toBe(Assignment::STATUS_ACTIVE)
        ->and($assignment->completion_threshold)->toBe(80)
        ->and($assignment->created_by_user_id)->toBe($user->id)
        ->and($assignment->announced_at)->toBeNull();
    Queue::assertPushed(AnnounceAssignmentJob::class, fn (AnnounceAssignmentJob $job): bool => $job->assignmentId === $assignment->id);

    $event = ProductEvent::query()->where('name', ProductEventName::AssignmentCreated->value)->sole();
    expect($event->props)->toMatchArray(['assignment_id' => $assignment->id, 'lesson_id' => $this->lesson->id, 'organization_id' => $this->org->id]);
});

it('keeps a custom title and falls back to no stored title for a lesson without one', function (): void {
    Queue::fake();
    $untitled = Lesson::factory()->create(['stage_id' => $this->stage->id, 'number' => 4, 'title' => null]);

    expect($this->service->create($this->org, $this->group, $this->lesson, now()->addDay(), '  Homework  ')->title)->toBe('Homework');

    $a = $this->service->create($this->org, $this->group, $untitled, now()->addDay());
    expect($a->title)->toBeNull()
        ->and($a->displayTitle('ru'))->toBe('Урок 4');
});

it('refuses a lesson that is not in the course of the group', function (): void {
    Queue::fake();
    $otherLesson = Lesson::factory()->create();

    expect(fn () => $this->service->create($this->org, $this->group, $otherLesson, now()->addDay()))
        ->toThrow(AssignmentException::class);

    try {
        $this->service->create($this->org, $this->group, $otherLesson, now()->addDay());
    } catch (AssignmentException $e) {
        expect($e->errorCode)->toBe(AssignmentException::LESSON_NOT_IN_COURSE)->and($e->httpStatus())->toBe(422);
    }
    expect(Assignment::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('refuses a group of another organization, a group without a course, a past due date and a bad threshold', function (): void {
    Queue::fake();
    $foreign = TelegramGroup::factory()->create(['organization_id' => Organization::factory()->create()->id, 'course_id' => $this->course->id]);
    $noCourse = TelegramGroup::factory()->create(['organization_id' => $this->org->id, 'course_id' => null]);

    $code = function (Closure $call): string {
        try {
            $call();
        } catch (AssignmentException $e) {
            return $e->errorCode;
        }

        return 'none';
    };

    expect($code(fn () => $this->service->create($this->org, $foreign, $this->lesson, now()->addDay())))->toBe(AssignmentException::FOREIGN_GROUP)
        ->and($code(fn () => $this->service->create($this->org, $noCourse, $this->lesson, now()->addDay())))->toBe(AssignmentException::GROUP_WITHOUT_COURSE)
        ->and($code(fn () => $this->service->create($this->org, $this->group, $this->lesson, now()->subMinute())))->toBe(AssignmentException::INVALID_DUE)
        ->and($code(fn () => $this->service->create($this->org, $this->group, $this->lesson, now()->addDay(), null, 0)))->toBe(AssignmentException::INVALID_THRESHOLD)
        ->and($code(fn () => $this->service->create($this->org, $this->group, $this->lesson, now()->addDay(), null, 101)))->toBe(AssignmentException::INVALID_THRESHOLD)
        ->and(Assignment::query()->count())->toBe(0);
});

it('announces once to the group chat with a web_app button into the lesson', function (): void {
    config(['twa.base_url' => 'https://app.example.test']);
    $assignment = asgMake($this);

    expect($this->service->announce($assignment))->toBeTrue()
        ->and($this->service->announce($assignment))->toBeFalse()
        ->and($this->service->announce($assignment->fresh()))->toBeFalse();

    expect($this->tg->sent)->toHaveCount(1);
    $sent = $this->tg->sent[0];
    expect($sent['chat_id'])->toBe($this->group->chat_id)
        ->and($sent['url'])->toBe('https://app.example.test/twa/?from=assignment&lesson='.$this->lesson->id)
        ->and($sent['text'])->toContain('Food')
        ->and($assignment->fresh()->announced_at)->not->toBeNull();
});

it('does not announce a closed assignment', function (): void {
    $assignment = asgMake($this, ['status' => Assignment::STATUS_CLOSED]);

    expect($this->service->announce($assignment))->toBeFalse()
        ->and($this->tg->sent)->toBe([]);
});

it('releases the claim when Telegram refuses, retries it later, and gives up after the attempt limit', function (): void {
    config(['organizations.assignments.announce_attempts' => 2]);
    $assignment = asgMake($this);
    $this->tg->failSends = 5;

    expect($this->service->announce($assignment))->toBeFalse();
    expect($assignment->fresh()->announced_at)->toBeNull()
        ->and($assignment->fresh()->meta['announced_at_failures'])->toBe(1);

    expect($this->service->announcePending())->toBe(0); // fails again: 2 failures
    expect($this->service->announcePending())->toBe(0); // limit reached, not even tried
    expect($this->tg->failSends)->toBe(3);

    $this->tg->failSends = 0;
    $other = asgMake($this);
    expect($this->service->announcePending())->toBe(1)
        ->and($other->fresh()->announced_at)->not->toBeNull();
});

it('reminds once, 24 hours before the deadline, with one message to the group', function (): void {
    $now = CarbonImmutable::parse('2026-11-01 10:00:00');
    $soon = asgMake($this, ['due_at' => $now->addHours(20), 'announced_at' => $now->subDays(3)]);
    asgMake($this, ['due_at' => $now->addHours(40), 'announced_at' => $now->subDays(1)]); // not yet
    asgMake($this, ['due_at' => $now->addHours(5), 'status' => Assignment::STATUS_CLOSED]);
    asgMake($this, ['due_at' => $now->subHour()]); // already past

    expect($this->service->remindDue($now))->toBe(1)
        ->and($this->service->remindDue($now))->toBe(0)
        ->and($this->service->remindDue($now->addHour()))->toBe(0);

    expect($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['chat_id'])->toBe($this->group->chat_id)
        ->and($this->tg->sent[0]['text'])->toContain('Напоминание')
        ->and($soon->fresh()->reminded_at)->not->toBeNull();
});

it('does not send a second message when the assignment was announced less than 24 hours before the deadline', function (): void {
    $now = CarbonImmutable::parse('2026-11-01 10:00:00');
    $assignment = asgMake($this, ['due_at' => $now->addHours(10), 'announced_at' => $now->subHours(2)]);

    expect($this->service->remindDue($now))->toBe(0)
        ->and($this->tg->sent)->toBe([])
        ->and($assignment->fresh()->reminded_at)->not->toBeNull();
});

it('counts progress only over the ACTIVE words of the lesson and only repetitions >= 1', function (): void {
    $assignment = asgMake($this);
    $inactive = Word::factory()->for($this->lesson)->create(['is_active' => false]);
    $elsewhere = Word::factory()->create();

    asgGrade($this->learner, $this->words[0]);
    asgGrade($this->learner, $this->words[1]);
    asgGrade($this->learner, $this->words[2], repetitions: 0); // seen but never answered right
    asgGrade($this->learner, $inactive);
    asgGrade($this->learner, $elsewhere);

    $this->service->refreshResults($assignment);

    $row = AssignmentResult::query()->where('learner_id', $this->learner->id)->sole();
    expect($row->progress_pct)->toBe(50)
        ->and($row->completed_at)->toBeNull();
});

it('writes a zero row for a member who has not started and ignores members who left', function (): void {
    $assignment = asgMake($this);
    $left = Learner::factory()->memberOf($this->group, 'left')->create();

    $this->service->refreshResults($assignment);

    expect(AssignmentResult::query()->where('assignment_id', $assignment->id)->pluck('progress_pct', 'learner_id')->all())
        ->toBe([$this->learner->id => 0])
        ->and(AssignmentResult::query()->where('learner_id', $left->id)->exists())->toBeFalse();
});

it('sets completed_at once at the threshold and raises assignment.completed once', function (): void {
    $assignment = asgMake($this, ['completion_threshold' => 50]);
    asgGrade($this->learner, $this->words[0], at: CarbonImmutable::now()->addSeconds(0));

    expect($this->service->refreshResults($assignment))->toBe(0);

    asgGrade($this->learner, $this->words[1]);
    expect($this->service->refreshResults($assignment))->toBe(1);

    $first = AssignmentResult::query()->where('learner_id', $this->learner->id)->sole();
    expect($first->completed_at)->not->toBeNull()->and($first->progress_pct)->toBe(50);
    $stamp = $first->completed_at->toIso8601String();

    // More progress and repeated refreshes change the percent, not the moment, and raise no second event.
    asgGrade($this->learner, $this->words[2]);
    expect($this->service->refreshResults($assignment->fresh(), CarbonImmutable::now()->addHour()))->toBe(0)
        ->and($this->service->refreshResults($assignment->fresh()))->toBe(0);

    $again = AssignmentResult::query()->where('learner_id', $this->learner->id)->sole();
    expect($again->completed_at->toIso8601String())->toBe($stamp)
        ->and($again->progress_pct)->toBe(75);
    expect(ProductEvent::query()->where('name', ProductEventName::AssignmentCompleted->value)->count())->toBe(1);
});

it('never resets completed_at when a word later leaves the lesson', function (): void {
    $assignment = asgMake($this);
    foreach ($this->words as $word) {
        asgGrade($this->learner, $word);
    }
    $this->service->refreshResults($assignment);

    $this->words[0]->update(['is_active' => false]);
    Word::factory()->for($this->lesson)->create(['is_active' => true]); // a new unseen word: 3 of 4
    $this->service->refreshResults($assignment->fresh());

    $row = AssignmentResult::query()->sole();
    expect($row->completed_at)->not->toBeNull()
        ->and($row->progress_pct)->toBe(75);
});

it('marks a completion after the due date as late', function (): void {
    $assignment = asgMake($this, ['due_at' => now()->subDay()]);
    foreach ($this->words as $word) {
        asgGrade($this->learner, $word);
    }

    $this->service->refreshResults($assignment);

    $row = AssignmentResult::query()->sole();
    expect($row->completed_at)->not->toBeNull()
        ->and($row->isLate())->toBeTrue();
    $event = ProductEvent::query()->where('name', ProductEventName::AssignmentCompleted->value)->sole();
    expect($event->props['late'])->toBeTrue();
});

it('does not mark a completion before the due date as late', function (): void {
    $assignment = asgMake($this);
    foreach ($this->words as $word) {
        asgGrade($this->learner, $word);
    }

    $this->service->refreshResults($assignment);

    expect(AssignmentResult::query()->sole()->isLate())->toBeFalse();
});

it('a lesson with no active words never completes', function (): void {
    $empty = Lesson::factory()->create(['stage_id' => $this->stage->id, 'number' => 9]);
    $assignment = asgMake($this, ['lesson_id' => $empty->id]);

    $this->service->refreshResults($assignment);

    $row = AssignmentResult::query()->sole();
    expect($row->progress_pct)->toBe(0)->and($row->completed_at)->toBeNull();
});

it('closes with a final snapshot, and a second close is a no-op', function (): void {
    $assignment = asgMake($this);
    foreach ($this->words as $word) {
        asgGrade($this->learner, $word);
    }

    expect($this->service->close($assignment))->toBeTrue()
        ->and($this->service->close($assignment))->toBeFalse();

    expect($assignment->fresh()->status)->toBe(Assignment::STATUS_CLOSED)
        ->and(AssignmentResult::query()->sole()->completed_at)->not->toBeNull();
    // A closed assignment is no longer recounted.
    expect($this->service->refreshActive())->toBe(0);
});

it('closes assignments overdue by more than the grace period only', function (): void {
    config(['organizations.assignments.overdue_grace_days' => 7]);
    $old = asgMake($this, ['due_at' => now()->subDays(10)]);
    $recent = asgMake($this, ['due_at' => now()->subDays(3)]);

    expect($this->service->closeExpired())->toBe(1)
        ->and($old->fresh()->status)->toBe(Assignment::STATUS_CLOSED)
        ->and($recent->fresh()->status)->toBe(Assignment::STATUS_ACTIVE);
});

it('shows a learner only the active assignments of the groups they are in, with their own fresh progress', function (): void {
    $mine = asgMake($this);
    $closed = asgMake($this, ['status' => Assignment::STATUS_CLOSED]);
    $otherGroup = TelegramGroup::factory()->create(['organization_id' => $this->org->id, 'course_id' => $this->course->id]);
    $notMine = asgMake($this, ['telegram_group_id' => $otherGroup->id]);
    asgGrade($this->learner, $this->words[0]);
    asgGrade($this->learner, $this->words[1]);

    $items = $this->service->forLearner($this->learner);

    expect($items)->toHaveCount(1)
        ->and($items[0]->assignment->id)->toBe($mine->id)
        ->and($items[0]->progressPct)->toBe(50)
        ->and($items[0]->completed())->toBeFalse();
    // Only the asking learner's row is written by this path.
    expect(AssignmentResult::query()->where('assignment_id', $notMine->id)->count())->toBe(0)
        ->and(AssignmentResult::query()->where('assignment_id', $closed->id)->count())->toBe(0);
});

it('hides assignments of a learner who left the group or of a suspended organization', function (): void {
    asgMake($this);
    $left = Learner::factory()->memberOf($this->group, 'left')->create();

    expect($this->service->forLearner($left))->toBe([]);

    $this->org->update(['status' => 'suspended']);
    expect($this->service->forLearner($this->learner))->toBe([]);
});

it('assignments:refresh recounts, reminds and announces in one idempotent run', function (): void {
    $assignment = asgMake($this, ['due_at' => now()->addHours(10)]);
    foreach ($this->words as $word) {
        asgGrade($this->learner, $word);
    }

    $this->artisan('assignments:refresh')->assertSuccessful();
    $this->artisan('assignments:refresh')->assertSuccessful();

    // announced (never announced before) + reminded: announcement already said the deadline,
    // so the second message is skipped -> exactly one message in total.
    expect($this->tg->sent)->toHaveCount(1)
        ->and($assignment->fresh()->announced_at)->not->toBeNull()
        ->and($assignment->fresh()->reminded_at)->not->toBeNull()
        ->and(AssignmentResult::query()->sole()->completed_at)->not->toBeNull();
});

it('is scheduled every 30 minutes', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e): bool => str_contains((string) $e->command, 'assignments:refresh'));

    expect($event)->not->toBeNull()->and($event->expression)->toBe('*/30 * * * *');
});

it('the announce job posts once even when delivered twice', function (): void {
    $assignment = asgMake($this);

    (new AnnounceAssignmentJob($assignment->id))->handle($this->service);
    (new AnnounceAssignmentJob($assignment->id))->handle($this->service);

    expect($this->tg->sent)->toHaveCount(1);
});

it('leaves the announcement unclaimed when the bot-wide send budget has no free slot, and posts it on the next run', function (): void {
    config(['telegram.outbound.sync_wait_ms' => 100]); // the clock is frozen: waiting longer only lets a real Redis TTL expire
    $this->app->instance(TelegramMessageRateLimiter::class, new TelegramMessageRateLimiter(globalPerSecond: 1));
    $service = $this->app->make(AssignmentService::class);
    $this->travelTo(now()->startOfSecond()->addMilliseconds(1)); // frozen: the whole test is one wall-clock second

    $first = asgMake($this);
    $second = asgMake($this);

    expect($service->announcePending())->toBe(1)
        ->and($this->tg->sent)->toHaveCount(1);

    // The second assignment found no slot: not claimed, no failure counted.
    $left = Assignment::query()->whereNull('announced_at')->sole();
    expect($left->meta['announced_at_failures'] ?? 0)->toBe(0);

    $this->travel(2)->seconds();

    expect($service->announcePending())->toBe(1)
        ->and($this->tg->sent)->toHaveCount(2)
        ->and($first->fresh()->announced_at)->not->toBeNull()
        ->and($second->fresh()->announced_at)->not->toBeNull();
});

it('does not claim the 24-hour reminder without a free slot and stops the run after the first denial', function (): void {
    config(['telegram.outbound.sync_wait_ms' => 100]); // the clock is frozen: waiting longer only lets a real Redis TTL expire
    $this->app->instance(TelegramMessageRateLimiter::class, new TelegramMessageRateLimiter(globalPerSecond: 1));
    $service = $this->app->make(AssignmentService::class);
    $now = CarbonImmutable::parse('2026-11-01 10:00:00');
    $this->travelTo($now);

    $a = asgMake($this, ['due_at' => $now->addHours(20), 'announced_at' => $now->subDays(3)]);
    $b = asgMake($this, ['due_at' => $now->addHours(21), 'announced_at' => $now->subDays(3)]);

    expect($service->remindDue($now))->toBe(1)
        ->and($this->tg->sent)->toHaveCount(1)
        ->and($b->fresh()->reminded_at)->toBeNull();

    $this->travel(2)->seconds();

    expect($service->remindDue($now))->toBe(1)
        ->and($b->fresh()->reminded_at)->not->toBeNull();
});

it('takes no second slot when the queued job announces (its middleware has already reserved one)', function (): void {
    $limiter = new TelegramMessageRateLimiter(globalPerSecond: 1);
    $this->app->instance(TelegramMessageRateLimiter::class, $limiter);
    $assignment = asgMake($this);

    expect($limiter->reserveGlobal())->toBeTrue(); // the middleware's slot: the budget of this second is spent

    (new AnnounceAssignmentJob($assignment->id))->handle($this->app->make(AssignmentService::class));

    expect($this->tg->sent)->toHaveCount(1)
        ->and($assignment->fresh()->announced_at)->not->toBeNull();
});

it('takes the completion moment from the first passing review in the journal, not from later repeats', function (): void {
    $now = CarbonImmutable::now();
    $assignment = asgMake($this, ['due_at' => $now->addDay()]);
    $session = TrainingSession::factory()->create(['learner_id' => $this->learner->id]);

    foreach ($this->words as $word) {
        // Passed 2 hours after the assignment (before the deadline) ...
        TrainingReview::factory()->create([
            'training_session_id' => $session->id, 'learner_id' => $this->learner->id, 'word_id' => $word->id,
            'quality' => 4, 'created_at' => $now->addHours(2),
        ]);
        // ... repeated after the deadline, before the snapshot was recomputed.
        TrainingReview::factory()->create([
            'training_session_id' => $session->id, 'learner_id' => $this->learner->id, 'word_id' => $word->id,
            'quality' => 5, 'created_at' => $now->addDays(2),
        ]);
        asgGrade($this->learner, $word, at: $now->addDays(2));
    }

    $this->service->refreshResults($assignment, $now->addDays(3));

    $row = AssignmentResult::query()->sole();
    expect($row->completed_at->getTimestamp())->toBe($now->addHours(2)->getTimestamp())
        ->and($row->isLate())->toBeFalse();
    expect(ProductEvent::query()->where('name', ProductEventName::AssignmentCompleted->value)->sole()->props['late'])->toBeFalse();
});

it('marks a late completion as late when the journal itself shows the threshold passed after the deadline', function (): void {
    $now = CarbonImmutable::now();
    $assignment = asgMake($this, ['due_at' => $now->addDay()]);
    $session = TrainingSession::factory()->create(['learner_id' => $this->learner->id]);

    foreach ($this->words as $word) {
        // A failed grade before the deadline does not count; the first pass is after it.
        TrainingReview::factory()->create([
            'training_session_id' => $session->id, 'learner_id' => $this->learner->id, 'word_id' => $word->id,
            'quality' => 1, 'created_at' => $now->addHours(2),
        ]);
        TrainingReview::factory()->create([
            'training_session_id' => $session->id, 'learner_id' => $this->learner->id, 'word_id' => $word->id,
            'quality' => 4, 'created_at' => $now->addDays(2),
        ]);
        asgGrade($this->learner, $word, at: $now->addDays(2));
    }

    $this->service->refreshResults($assignment, $now->addDays(3));

    expect(AssignmentResult::query()->sole()->isLate())->toBeTrue();
});
