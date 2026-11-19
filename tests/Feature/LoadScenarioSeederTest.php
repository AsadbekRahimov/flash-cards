<?php

declare(strict_types=1);

use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Operations\Services\LoadTestClassroom;
use App\Domain\Operations\Services\LoadTestTokenIssuer;
use App\Domain\Organizations\Events\OrganizationMemberJoined;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Twa\Services\JwtService;
use App\Jobs\AnnounceAssignmentJob;
use App\Models\Assignment;
use App\Models\AssignmentResult;
use App\Models\Course;
use App\Models\GroupMember;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Stage;
use App\Models\TelegramGroup;
use App\Models\Word;
use App\Models\WordRepetition;
use Database\Seeders\LoadScenarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

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

// --- the classes of the burst (LoadTestClassroom) ----------------------------------------------------------------------

/**
 * A published course of two lessons of four active words; with LOAD_SCENARIO_WORDS=4 the mature cards cover the first lesson and the
 * classes get the second.
 *
 * @return array{0: Course, 1: Lesson, 2: Lesson}
 */
function loadClassCourse(): array
{
    $course = Course::factory()->published()->create();
    $stage = Stage::factory()->create(['course_id' => $course->id, 'number' => 1]);
    $first = Lesson::factory()->create(['stage_id' => $stage->id, 'number' => 1]);
    $second = Lesson::factory()->create(['stage_id' => $stage->id, 'number' => 2]);
    foreach ([$first, $second] as $lesson) {
        Word::factory()->count(4)->create(['lesson_id' => $lesson->id, 'is_active' => true]);
    }

    return [$course, $first, $second];
}

/** Runs the seeder with the process variables `$env` set for the run only. */
function seedLoadScenario(object $test, array $env = []): void
{
    $env = ['LOAD_SCENARIO_WORDS' => '4', 'LOAD_SCENARIO_CLASS_SIZE' => '3', ...$env];
    foreach ($env as $name => $value) {
        putenv($name.'='.$value);
    }

    try {
        $test->seed(LoadScenarioSeeder::class);
    } finally {
        foreach (array_keys($env) as $name) {
            putenv($name);
        }
    }
}

/** The test learner number N (1-based, as load:tokens names them). */
function loadLearner(int $n): Learner
{
    return Learner::query()->where('telegram_user_id', LoadTestTokenIssuer::TELEGRAM_USER_BASE + $n)->firstOrFail();
}

function loadHeaders(Learner $learner): array
{
    return ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];
}

/** @return array<string, int> */
function loadClassCounts(): array
{
    return [
        'organizations' => Organization::query()->count(),
        'groups' => TelegramGroup::query()->withoutGlobalScopes()->count(),
        'group_members' => GroupMember::query()->count(),
        'organization_members' => OrganizationMember::query()->count(),
        'assignments' => Assignment::query()->count(),
        'assignment_results' => AssignmentResult::query()->count(),
        'word_repetitions' => WordRepetition::query()->count(),
    ];
}

beforeEach(function (): void {
    // Nothing of the classes may reach Telegram: a mock without expectations throws on any call.
    $this->app->instance(TelegramClient::class, Mockery::mock(TelegramClient::class));
});

it('picks the class learners by token index with the rule of plan.js isClassLearner', function (): void {
    $members = array_values(array_filter(range(0, 29), static fn (int $i): bool => LoadTestClassroom::isMember($i, 0.2)));
    expect($members)->toBe([4, 9, 14, 19, 24, 29]) // the same list plan.test.js checks
        ->and(array_filter(range(0, 99), static fn (int $i): bool => LoadTestClassroom::isMember($i, 0.0)))->toBe([])
        ->and(count(array_filter(range(0, 99), static fn (int $i): bool => LoadTestClassroom::isMember($i, 1.0))))->toBe(100)
        ->and(count(array_filter(range(0, 1249), static fn (int $i): bool => LoadTestClassroom::isMember($i, 0.2))))->toBe(250)
        ->and(LoadTestClassroom::progressWords(4))->toBe(2)
        ->and(LoadTestClassroom::progressWords(1))->toBe(0)
        ->and(LoadTestClassroom::progressWords(2))->toBe(1);
});

it('seeds centers, groups, memberships and one assignment per group for the class share, without a message', function (): void {
    Queue::fake();
    Event::fake([OrganizationMemberJoined::class]);
    [$course, $first, $second] = loadClassCourse();
    $this->artisan('load:tokens', ['--count' => 20, '--output' => '-'])->assertSuccessful();

    seedLoadScenario($this);

    // 0.2 of 20: Load5, Load10, Load15, Load20; classes of 3 -> two groups (3 + 1), one center.
    $memberIds = collect([5, 10, 15, 20])->map(fn (int $n): int => loadLearner($n)->id)->all();
    $center = Organization::query()->sole();
    $groups = TelegramGroup::query()->withoutGlobalScopes()->orderBy('chat_id', 'desc')->get();

    expect($center->slug)->toBe('load-test-center-1')
        ->and($center->status)->toBe('active')
        ->and($center->settings)->toBe(['load_test' => true])
        ->and($groups)->toHaveCount(2)
        ->and($groups->pluck('chat_id')->all())->toBe([LoadTestClassroom::CHAT_ID_BASE - 1, LoadTestClassroom::CHAT_ID_BASE - 2])
        ->and($groups->every(fn (TelegramGroup $g): bool => $g->status === 'active' && $g->organization_id === $center->id
            && $g->course_id === $course->id && $g->meta === ['load_test' => true]))->toBeTrue()
        ->and(GroupMember::query()->where('telegram_group_id', $groups[0]->id)->orderBy('learner_id')->pluck('learner_id')->all())->toBe(array_slice($memberIds, 0, 3))
        ->and(GroupMember::query()->where('telegram_group_id', $groups[1]->id)->pluck('learner_id')->all())->toBe([$memberIds[3]])
        ->and(GroupMember::query()->where('status', 'active')->count())->toBe(4)
        ->and(OrganizationMember::query()->where('organization_id', $center->id)->where('status', 'active')->where('seat_state', 'waitlisted')->where('joined_via', 'group')->count())->toBe(4);

    $assignments = Assignment::query()->orderBy('telegram_group_id')->get();
    expect($assignments)->toHaveCount(2)
        ->and($assignments->every(fn (Assignment $a): bool => $a->status === 'active' && $a->lesson_id === $second->id
            && $a->due_at->isAfter(now()->addDays(29)) && $a->announced_at !== null && $a->reminded_at !== null
            && $a->meta === ['load_test' => true]))->toBeTrue();

    // Ranks 0 and 2 (Load5, Load15) have repeated two of the four words of the class lesson; Load10 and Load20 none of it.
    $secondWords = Word::query()->where('lesson_id', $second->id)->pluck('id');
    $onLesson = fn (int $n): int => WordRepetition::query()->where('learner_id', loadLearner($n)->id)->whereIn('word_id', $secondWords)->count();
    expect([$onLesson(5), $onLesson(10), $onLesson(15), $onLesson(20), $onLesson(1)])->toBe([2, 0, 2, 0, 0])
        ->and(AssignmentResult::query()->orderBy('learner_id')->pluck('progress_pct', 'learner_id')->all())
        ->toBe([$memberIds[0] => 50, $memberIds[1] => 0, $memberIds[2] => 50, $memberIds[3] => 0])
        ->and(AssignmentResult::query()->whereNotNull('completed_at')->count())->toBe(0)
        // the mature cards of the first lesson for everybody, members included
        ->and(WordRepetition::query()->whereIn('word_id', Word::query()->where('lesson_id', $first->id)->pluck('id'))->count())->toBe(20 * 4);

    // (the friendships of the seed queue their own jobs; the classes queue none)
    Queue::assertNotPushed(AnnounceAssignmentJob::class);
    Event::assertNotDispatched(OrganizationMemberJoined::class);
});

it('is idempotent: a second run adds, removes and invalidates nothing', function (): void {
    loadClassCourse();
    $this->artisan('load:tokens', ['--count' => 20, '--output' => '-'])->assertSuccessful();
    seedLoadScenario($this);

    $counts = loadClassCounts();
    $ids = [
        Organization::query()->pluck('id')->all(),
        TelegramGroup::query()->withoutGlobalScopes()->pluck('id')->all(),
        GroupMember::query()->orderBy('id')->pluck('id')->all(),
        OrganizationMember::query()->orderBy('id')->pluck('id')->all(),
        Assignment::query()->orderBy('id')->pluck('id')->all(),
    ];
    $versions = Learner::query()->pluck('id')->mapWithKeys(fn (int $id): array => [$id => EntitlementResolver::version($id)])->all();

    seedLoadScenario($this);

    expect(loadClassCounts())->toBe($counts)
        ->and([
            Organization::query()->pluck('id')->all(),
            TelegramGroup::query()->withoutGlobalScopes()->pluck('id')->all(),
            GroupMember::query()->orderBy('id')->pluck('id')->all(),
            OrganizationMember::query()->orderBy('id')->pluck('id')->all(),
            Assignment::query()->orderBy('id')->pluck('id')->all(),
        ])->toBe($ids)
        ->and(Learner::query()->pluck('id')->mapWithKeys(fn (int $id): array => [$id => EntitlementResolver::version($id)])->all())->toBe($versions);
});

it('creates nothing with share 0, and a share of 0 after a run removes the classes', function (): void {
    loadClassCourse();
    $this->artisan('load:tokens', ['--count' => 20, '--output' => '-'])->assertSuccessful();

    seedLoadScenario($this, ['LOAD_SCENARIO_CLASS_SHARE' => '0']);

    expect(Organization::query()->count())->toBe(0)
        ->and(TelegramGroup::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(GroupMember::query()->count())->toBe(0)
        ->and(Assignment::query()->count())->toBe(0)
        // without classes every learner has the mature cards of the whole cut
        ->and(WordRepetition::query()->count())->toBe(20 * 4);

    seedLoadScenario($this);
    expect(Assignment::query()->count())->toBe(2);

    $member = loadLearner(5);
    $this->withHeaders(loadHeaders($member))->getJson('/api/twa/today')->assertOk()->assertJsonPath('assignments_possible', true);

    seedLoadScenario($this, ['LOAD_SCENARIO_CLASS_SHARE' => '0']);

    expect(Organization::query()->count())->toBe(0)
        ->and(TelegramGroup::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(GroupMember::query()->count())->toBe(0)
        ->and(OrganizationMember::query()->count())->toBe(0)
        ->and(Assignment::query()->count())->toBe(0)
        ->and(AssignmentResult::query()->count())->toBe(0);
    // the warm `/today` snapshot of the former member is not served any more
    $this->withHeaders(loadHeaders($member))->getJson('/api/twa/today')->assertOk()->assertJsonPath('assignments_possible', false);
});

it('gives a class learner assignments_possible and a non-empty list, a solo learner neither', function (): void {
    [, , $second] = loadClassCourse();
    $this->artisan('load:tokens', ['--count' => 20, '--output' => '-'])->assertSuccessful();
    $member = loadLearner(10);
    $solo = loadLearner(11);

    // Warm snapshots from before the seed must not hide the classes: the seed moves the members' version.
    $this->withHeaders(loadHeaders($member))->getJson('/api/twa/today')->assertOk()->assertJsonPath('assignments_possible', false);

    seedLoadScenario($this);

    $this->withHeaders(loadHeaders($member))->getJson('/api/twa/today')->assertOk()->assertJsonPath('assignments_possible', true);
    $list = $this->withHeaders(loadHeaders($member))->getJson('/api/twa/assignments')->assertOk()->json('assignments');
    expect($list)->toHaveCount(1)
        ->and($list[0]['lesson_id'])->toBe($second->id)
        ->and($list[0]['completed'])->toBeFalse();

    $this->withHeaders(loadHeaders($solo))->getJson('/api/twa/today')->assertOk()->assertJsonPath('assignments_possible', false);
    expect($this->withHeaders(loadHeaders($solo))->getJson('/api/twa/assignments')->assertOk()->json('assignments'))->toBe([]);
});

it('moves members when the share or the class size changes', function (): void {
    loadClassCourse();
    $this->artisan('load:tokens', ['--count' => 20, '--output' => '-'])->assertSuccessful();
    seedLoadScenario($this);

    // 0.5 of 20 = 10 members (Load2, Load4, ...), classes of 4 -> three groups.
    seedLoadScenario($this, ['LOAD_SCENARIO_CLASS_SHARE' => '0.5', 'LOAD_SCENARIO_CLASS_SIZE' => '4']);

    $expected = collect(range(2, 20, 2))->map(fn (int $n): int => loadLearner($n)->id)->sort()->values()->all();
    expect(GroupMember::query()->orderBy('learner_id')->pluck('learner_id')->all())->toBe($expected)
        ->and(TelegramGroup::query()->withoutGlobalScopes()->count())->toBe(3)
        ->and(Assignment::query()->count())->toBe(3)
        ->and(OrganizationMember::query()->count())->toBe(10)
        ->and(AssignmentResult::query()->count())->toBe(10)
        ->and(GroupMember::query()->where('learner_id', loadLearner(5)->id)->exists())->toBeFalse();
});

it('refuses a group or a center in the load-test range without the mark, before writing anything', function (): void {
    loadClassCourse();
    $this->artisan('load:tokens', ['--count' => 20, '--output' => '-'])->assertSuccessful();
    TelegramGroup::factory()->create(['chat_id' => LoadTestClassroom::CHAT_ID_BASE - 7, 'meta' => []]);

    expect(fn () => seedLoadScenario($this))->toThrow(RuntimeException::class, 'without the load-test mark');
    expect(Organization::query()->count())->toBe(0)->and(GroupMember::query()->count())->toBe(0);

    TelegramGroup::query()->withoutGlobalScopes()->delete();
    Organization::factory()->create(['slug' => 'load-test-center-1', 'settings' => []]);

    expect(fn () => seedLoadScenario($this))->toThrow(RuntimeException::class, 'without the load-test mark');
    expect(TelegramGroup::query()->withoutGlobalScopes()->count())->toBe(0)->and(GroupMember::query()->count())->toBe(0);
});

it('refuses a class share outside 0..1 and a bad class size', function (): void {
    loadClassCourse();
    $this->artisan('load:tokens', ['--count' => 5, '--output' => '-'])->assertSuccessful();

    expect(fn () => seedLoadScenario($this, ['LOAD_SCENARIO_CLASS_SHARE' => '1.5']))->toThrow(RuntimeException::class, 'LOAD_SCENARIO_CLASS_SHARE')
        ->and(fn () => seedLoadScenario($this, ['LOAD_SCENARIO_CLASS_SHARE' => 'lots']))->toThrow(RuntimeException::class, 'LOAD_SCENARIO_CLASS_SHARE')
        ->and(fn () => seedLoadScenario($this, ['LOAD_SCENARIO_CLASS_SIZE' => '0']))->toThrow(RuntimeException::class, 'LOAD_SCENARIO_CLASS_SIZE');
    expect(TelegramGroup::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('writes no class in production', function (): void {
    loadClassCourse();
    $this->artisan('load:tokens', ['--count' => 20, '--output' => '-'])->assertSuccessful();
    app()->detectEnvironment(fn (): string => 'production');

    // `db:seed` would ask for a confirmation in production: the seeder itself, as LoadScenarioSeeder::run() is called there.
    expect(fn () => app(LoadScenarioSeeder::class)->run())->toThrow(RuntimeException::class, 'only in the');
    expect(Organization::query()->count())->toBe(0)
        ->and(TelegramGroup::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(WordRepetition::query()->count())->toBe(0);
});
