<?php

declare(strict_types=1);

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Organizations\Services\AssignmentService;
use App\Domain\Twa\Services\JwtService;
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
use App\Models\Word;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
 * The snapshot of GET /api/twa/assignments (docs/13 §1.4, "Реализация P1-1"): the learner's progress
 * is counted live by grouped queries and written by ONE upsert of the changed rows only, so the
 * number of queries does not grow with the assignments and a warm call writes nothing; the
 * threshold is claimed once (conditional UPDATE), the moment comes from the journal (F08), and the
 * group refresh writes a whole chunk in one statement.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->course = Course::factory()->published()->create();
    $this->stage = Stage::factory()->create(['course_id' => $this->course->id, 'number' => 1]);
    $this->org = Organization::factory()->create();
    $this->group = TelegramGroup::factory()->create(['organization_id' => $this->org->id, 'course_id' => $this->course->id]);
    $this->learner = Learner::factory()->memberOf($this->group)->create();
    $this->headers = ['Authorization' => 'Bearer '.app(JwtService::class)->issue($this->learner->id)['token']];
    $this->service = app(AssignmentService::class);
});

/** A lesson of `$words` active words; returns [Lesson, Collection<Word>]. */
function snapLesson(object $t, int $number, int $words = 4): array
{
    $lesson = Lesson::factory()->create(['stage_id' => $t->stage->id, 'number' => $number]);

    return [$lesson, Word::factory()->count($words)->for($lesson)->create(['is_active' => true])];
}

function snapAssignment(object $t, Lesson $lesson, array $attrs = []): Assignment
{
    return Assignment::factory()->create(array_merge([
        'organization_id' => $t->org->id,
        'telegram_group_id' => $t->group->id,
        'lesson_id' => $lesson->id,
        'due_at' => now()->addDays(3),
    ], $attrs));
}

function snapGrade(Learner $learner, Word $word, ?CarbonImmutable $at = null): WordRepetition
{
    return WordRepetition::factory()->create([
        'learner_id' => $learner->id,
        'word_id' => $word->id,
        'repetitions' => 1,
        'last_reviewed_at' => $at ?? now(),
        'updated_at' => $at ?? now(),
    ]);
}

/** Runs the GET, returns [queries, writes] of the request. */
function snapMeasure(object $t): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $t->withHeaders($t->headers)->getJson('/api/twa/assignments')->assertOk();
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    $writes = array_values(array_filter($log, static fn (array $entry): bool => str_starts_with($entry['query'], 'insert') || str_starts_with($entry['query'], 'update')));

    return [count($log), count($writes)];
}

it('costs the same number of queries for 1 and 20 assignments — cold, after a session and warm — with at most one write', function (): void {
    $lessons = array_map(fn (int $i): array => snapLesson($this, $i, 5), range(1, 20));
    $measured = [];

    foreach ([1, 20] as $n) {
        Assignment::query()->delete();
        AssignmentResult::query()->delete();
        WordRepetition::query()->delete();

        foreach (range(1, $n) as $i) {
            snapAssignment($this, $lessons[$i - 1][0], ['due_at' => now()->addDays($i)]);
            snapGrade($this->learner, $lessons[$i - 1][1][0]);
        }

        $measured[$n]['cold'] = snapMeasure($this);
        $measured[$n]['warm'] = snapMeasure($this);

        foreach (range(1, $n) as $i) {
            snapGrade($this->learner, $lessons[$i - 1][1][1]);
        }

        $measured[$n]['after_session'] = snapMeasure($this);
        $measured[$n]['warm_again'] = snapMeasure($this);
    }

    // The same for 1 and for 20 assignments: nothing here is per assignment.
    expect($measured[20])->toBe($measured[1]);

    // Upper bounds (SQLite and PostgreSQL alike): auth + assignments (with lesson, stage, group) + totals
    // + repeated + snapshot rows = 8 on a warm call; a cold call or one after a session adds the single
    // upsert and one re-read.
    [$coldQueries, $coldWrites] = $measured[20]['cold'];
    [$warmQueries, $warmWrites] = $measured[20]['warm'];
    [$afterQueries, $afterWrites] = $measured[20]['after_session'];

    expect($coldQueries)->toBeLessThanOrEqual(10)->and($coldWrites)->toBe(1)
        ->and($afterQueries)->toBeLessThanOrEqual(10)->and($afterWrites)->toBe(1)
        ->and($warmQueries)->toBeLessThanOrEqual(8)->and($warmWrites)->toBe(0)
        ->and($measured[20]['warm_again'])->toBe($measured[20]['warm']);
});

it('claims completed_at once through the learner\'s list, raises one event, and the next call writes nothing', function (): void {
    $now = CarbonImmutable::now();
    [$lesson, $words] = snapLesson($this, 1);
    snapAssignment($this, $lesson, ['due_at' => $now->addDay()]);
    $session = TrainingSession::factory()->create(['learner_id' => $this->learner->id]);

    foreach ($words as $i => $word) {
        TrainingReview::factory()->create([
            'training_session_id' => $session->id, 'learner_id' => $this->learner->id, 'word_id' => $word->id,
            'quality' => 4, 'created_at' => $now->addMinutes(10 * ($i + 1)),
        ]);
        snapGrade($this->learner, $word, $now->addMinutes(10 * ($i + 1)));
    }

    $this->travelTo($now->addHours(2));

    [, $writes] = snapMeasure($this);
    $first = $this->withHeaders($this->headers)->getJson('/api/twa/assignments')->json('assignments.0');

    expect($writes)->toBeGreaterThanOrEqual(2) // the upsert and the claim (plus the event)
        ->and($first['completed'])->toBeTrue()
        ->and($first['progress_pct'])->toBe(100)
        ->and($first['late'])->toBeFalse()
        ->and(CarbonImmutable::parse($first['completed_at'])->getTimestamp())->toBe($now->addMinutes(40)->getTimestamp());

    [, $writesAgain] = snapMeasure($this);
    $again = $this->withHeaders($this->headers)->getJson('/api/twa/assignments')->json('assignments.0');

    expect($writesAgain)->toBe(0)
        ->and($again['completed_at'])->toBe($first['completed_at'])
        ->and(ProductEvent::query()->where('name', ProductEventName::AssignmentCompleted->value)->count())->toBe(1)
        ->and(AssignmentResult::query()->count())->toBe(1);
});

it('makes up for a snapshot that reached the threshold without a claim', function (): void {
    [$lesson, $words] = snapLesson($this, 1);
    $assignment = snapAssignment($this, $lesson);
    foreach ($words as $word) {
        snapGrade($this->learner, $word);
    }
    // A writer died between the upsert and the claim: the percent is there, the claim is not.
    $row = AssignmentResult::factory()->create(['assignment_id' => $assignment->id, 'learner_id' => $this->learner->id, 'progress_pct' => 100, 'completed_at' => null]);

    $item = $this->withHeaders($this->headers)->getJson('/api/twa/assignments')->json('assignments.0');

    expect($item['completed'])->toBeTrue()
        ->and($row->fresh()->completed_at)->not->toBeNull()
        ->and(ProductEvent::query()->where('name', ProductEventName::AssignmentCompleted->value)->count())->toBe(1);

    // Once claimed, nothing more happens.
    $this->withHeaders($this->headers)->getJson('/api/twa/assignments')->assertOk();
    expect(ProductEvent::query()->where('name', ProductEventName::AssignmentCompleted->value)->count())->toBe(1)
        ->and(AssignmentResult::query()->count())->toBe(1);
});

it('corrects the row the group refresh wrote earlier, counting only the ACTIVE words of the lesson', function (): void {
    [$lesson, $words] = snapLesson($this, 1);
    $inactive = Word::factory()->for($lesson)->create(['is_active' => false]);
    [, $otherWords] = snapLesson($this, 2);
    $assignment = snapAssignment($this, $lesson);

    snapGrade($this->learner, $words[0]);
    $this->service->refreshResults($assignment);
    $row = AssignmentResult::query()->sole();
    expect($row->progress_pct)->toBe(25);

    snapGrade($this->learner, $words[1]);
    snapGrade($this->learner, $words[2]);
    snapGrade($this->learner, $inactive);
    snapGrade($this->learner, $otherWords[0]);

    $item = $this->withHeaders($this->headers)->getJson('/api/twa/assignments')->json('assignments.0');
    $fresh = AssignmentResult::query()->sole();

    expect($item['progress_pct'])->toBe(75)
        ->and($item['completed'])->toBeFalse()
        ->and($fresh->id)->toBe($row->id) // the upsert updated the row, it did not replace it
        ->and($fresh->progress_pct)->toBe(75)
        ->and($fresh->completed_at)->toBeNull()
        ->and($fresh->created_at?->getTimestamp())->toBe($row->created_at?->getTimestamp());
});

it('takes each completion moment from the journal (F08): a repeat after the deadline is not late, a first pass after it is', function (): void {
    $now = CarbonImmutable::now();
    [$early, $earlyWords] = snapLesson($this, 1);
    [$late, $lateWords] = snapLesson($this, 2);
    snapAssignment($this, $early, ['due_at' => $now->addDay()]);
    snapAssignment($this, $late, ['due_at' => $now->addDay()->addMinute()]);
    $session = TrainingSession::factory()->create(['learner_id' => $this->learner->id]);

    foreach ($earlyWords as $word) {
        // Passed before the deadline, repeated after it (before any snapshot was taken).
        TrainingReview::factory()->create(['training_session_id' => $session->id, 'learner_id' => $this->learner->id, 'word_id' => $word->id, 'quality' => 4, 'created_at' => $now->addHours(2)]);
        TrainingReview::factory()->create(['training_session_id' => $session->id, 'learner_id' => $this->learner->id, 'word_id' => $word->id, 'quality' => 5, 'created_at' => $now->addDays(2)]);
        snapGrade($this->learner, $word, $now->addDays(2));
    }
    foreach ($lateWords as $word) {
        // Failed before the deadline, first passed after it.
        TrainingReview::factory()->create(['training_session_id' => $session->id, 'learner_id' => $this->learner->id, 'word_id' => $word->id, 'quality' => 1, 'created_at' => $now->addHours(2)]);
        TrainingReview::factory()->create(['training_session_id' => $session->id, 'learner_id' => $this->learner->id, 'word_id' => $word->id, 'quality' => 4, 'created_at' => $now->addDays(2)]);
        snapGrade($this->learner, $word, $now->addDays(2));
    }

    $this->travelTo($now->addDays(3));
    $items = $this->withHeaders($this->headers)->getJson('/api/twa/assignments')->json('assignments');

    expect($items)->toHaveCount(2)
        ->and($items[0]['completed'])->toBeTrue()
        ->and($items[0]['late'])->toBeFalse()
        ->and(CarbonImmutable::parse($items[0]['completed_at'])->getTimestamp())->toBe($now->addHours(2)->getTimestamp())
        ->and($items[1]['completed'])->toBeTrue()
        ->and($items[1]['late'])->toBeTrue()
        ->and(CarbonImmutable::parse($items[1]['completed_at'])->getTimestamp())->toBe($now->addDays(2)->getTimestamp());

    $late = ProductEvent::query()->where('name', ProductEventName::AssignmentCompleted->value)->orderBy('id')->pluck('props')->map(static fn (array $props): bool => (bool) $props['late'])->all();
    expect($late)->toBe([false, true]);
});

it('writes a whole chunk of the group in one upsert and claims only the thresholds reached', function (): void {
    [$lesson, $words] = snapLesson($this, 1);
    $assignment = snapAssignment($this, $lesson);
    $half = Learner::factory()->memberOf($this->group)->create();
    $idle = Learner::factory()->memberOf($this->group)->create();
    foreach ($words as $word) {
        snapGrade($this->learner, $word);
    }
    snapGrade($half, $words[0]);
    snapGrade($half, $words[1]);

    $statements = function (callable $run): array {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $result = $run();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $on = static fn (string $prefix): int => count(array_filter($log, static fn (array $e): bool => str_starts_with($e['query'], $prefix) && str_contains($e['query'], 'assignment_results')));

        return ['result' => $result, 'inserts' => $on('insert'), 'updates' => $on('update'), 'total' => count($log)];
    };

    $first = $statements(fn (): int => $this->service->refreshResults($assignment));
    expect($first['result'])->toBe(1)
        ->and($first['inserts'])->toBe(1) // the upsert of all three rows
        ->and($first['updates'])->toBe(1) // the one claim
        ->and(AssignmentResult::query()->where('assignment_id', $assignment->id)->orderBy('learner_id')->pluck('progress_pct', 'learner_id')->all())
        ->toBe([$this->learner->id => 100, $half->id => 50, $idle->id => 0]);

    $reloaded = $assignment->fresh();
    $again = $statements(fn (): int => $this->service->refreshResults($reloaded, CarbonImmutable::now()->addMinute()));
    expect($again['result'])->toBe(0)
        ->and($again['inserts'])->toBe(1)
        ->and($again['updates'])->toBe(0)
        ->and($again['total'])->toBeLessThanOrEqual(6) // group check, member ids, totals, repeated, rows, upsert
        ->and(ProductEvent::query()->where('name', ProductEventName::AssignmentCompleted->value)->count())->toBe(1);

    // Every row carries the time of the last refresh (the report's "as of").
    expect(AssignmentResult::query()->where('assignment_id', $assignment->id)->pluck('computed_at')->map(static fn ($at): int => $at->getTimestamp())->unique()->all())
        ->toBe([CarbonImmutable::now()->addMinute()->getTimestamp()]);
});

it('answers a learner without assignments from one read and writes nothing', function (): void {
    [$queries, $writes] = snapMeasure($this);

    expect($writes)->toBe(0)
        ->and($queries)->toBeLessThanOrEqual(3)
        ->and($this->withHeaders($this->headers)->getJson('/api/twa/assignments')->json('assignments'))->toBe([]);
});
