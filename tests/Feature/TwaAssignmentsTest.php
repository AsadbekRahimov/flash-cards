<?php

declare(strict_types=1);

/*
 * GET /api/twa/assignments (docs/04 A.9, task 2B.4): the learner sees the active assignments of THEIR
 * groups with THEIR progress. The JSON shape itself is pinned in TwaContractTest.
 */

use App\Domain\Twa\Services\JwtService;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Organization;
use App\Models\Stage;
use App\Models\TelegramGroup;
use App\Models\Word;
use App\Models\WordRepetition;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function asgApiHeaders(Learner $learner): array
{
    return ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];
}

beforeEach(function (): void {
    $this->course = Course::factory()->published()->create();
    $this->stage = Stage::factory()->create(['course_id' => $this->course->id, 'number' => 1]);
    $this->lesson = Lesson::factory()->create(['stage_id' => $this->stage->id, 'number' => 1, 'title' => 'Food']);
    $this->words = Word::factory()->count(4)->for($this->lesson)->create(['is_active' => true]);
    $this->org = Organization::factory()->create();
    $this->group = TelegramGroup::factory()->create(['organization_id' => $this->org->id, 'course_id' => $this->course->id, 'title' => 'Group A']);
    $this->learner = Learner::factory()->memberOf($this->group)->create();
});

it('requires authentication', function (): void {
    $this->getJson('/api/twa/assignments')->assertUnauthorized();
});

it('returns the learner\'s active assignments with their own progress, soonest due first', function (): void {
    $later = Assignment::factory()->create(['organization_id' => $this->org->id, 'telegram_group_id' => $this->group->id, 'lesson_id' => $this->lesson->id, 'title' => 'Later', 'due_at' => now()->addDays(5)]);
    $sooner = Assignment::factory()->create(['organization_id' => $this->org->id, 'telegram_group_id' => $this->group->id, 'lesson_id' => $this->lesson->id, 'title' => 'Sooner', 'due_at' => now()->addDay()]);
    foreach ($this->words->take(3) as $word) {
        WordRepetition::factory()->create(['learner_id' => $this->learner->id, 'word_id' => $word->id, 'repetitions' => 1]);
    }

    $response = $this->withHeaders(asgApiHeaders($this->learner))->getJson('/api/twa/assignments')->assertOk();

    expect(array_column($response->json('assignments'), 'title'))->toBe(['Sooner', 'Later'])
        ->and($response->json('assignments.0.progress_pct'))->toBe(75)
        ->and($response->json('assignments.0.group'))->toBe('Group A')
        ->and($response->json('assignments.0.id'))->toBe($sooner->id)
        ->and($later->id)->not->toBe($sooner->id);
});

it('does not show assignments of other groups, closed ones, or other learners\' progress', function (): void {
    $otherOrg = Organization::factory()->create();
    $otherGroup = TelegramGroup::factory()->create(['organization_id' => $otherOrg->id, 'course_id' => $this->course->id]);
    $other = Learner::factory()->memberOf($otherGroup)->create();
    Assignment::factory()->create(['organization_id' => $otherOrg->id, 'telegram_group_id' => $otherGroup->id, 'lesson_id' => $this->lesson->id, 'title' => 'Foreign']);
    Assignment::factory()->closed()->create(['organization_id' => $this->org->id, 'telegram_group_id' => $this->group->id, 'lesson_id' => $this->lesson->id, 'title' => 'Closed']);
    $mine = Assignment::factory()->create(['organization_id' => $this->org->id, 'telegram_group_id' => $this->group->id, 'lesson_id' => $this->lesson->id, 'title' => 'Mine']);
    // A groupmate finished it; this learner did not.
    $mate = Learner::factory()->memberOf($this->group)->create();
    foreach ($this->words as $word) {
        WordRepetition::factory()->create(['learner_id' => $mate->id, 'word_id' => $word->id, 'repetitions' => 3]);
    }

    $json = $this->withHeaders(asgApiHeaders($this->learner))->getJson('/api/twa/assignments')->assertOk()->json('assignments');

    expect($json)->toHaveCount(1)
        ->and($json[0]['id'])->toBe($mine->id)
        ->and($json[0]['progress_pct'])->toBe(0)
        ->and($json[0]['completed'])->toBeFalse();

    $theirs = $this->withHeaders(asgApiHeaders($other))->getJson('/api/twa/assignments')->assertOk()->json('assignments');
    expect(array_column($theirs, 'title'))->toBe(['Foreign']);
});

it('marks a completed and a late assignment', function (): void {
    Assignment::factory()->create(['organization_id' => $this->org->id, 'telegram_group_id' => $this->group->id, 'lesson_id' => $this->lesson->id, 'due_at' => now()->subDay()]);
    foreach ($this->words as $word) {
        WordRepetition::factory()->create(['learner_id' => $this->learner->id, 'word_id' => $word->id, 'repetitions' => 1]);
    }

    $row = $this->withHeaders(asgApiHeaders($this->learner))->getJson('/api/twa/assignments')->assertOk()->json('assignments.0');

    expect($row['completed'])->toBeTrue()
        ->and($row['completed_at'])->not->toBeNull()
        ->and($row['late'])->toBeTrue()
        ->and($row['overdue'])->toBeTrue()
        ->and($row['progress_pct'])->toBe(100);
});

it('accepts source=assignment when the lesson session is opened from the assignment', function (): void {
    $this->learner->update(['active_course_id' => $this->course->id]);

    $this->withHeaders(asgApiHeaders($this->learner))
        ->postJson('/api/twa/training/sessions', ['mode' => 'lesson', 'lesson_id' => $this->lesson->id, 'source' => 'assignment'])
        ->assertOk()
        ->assertJsonStructure(['session_id']);
});
