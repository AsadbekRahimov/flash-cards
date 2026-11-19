<?php

declare(strict_types=1);

/*
 * `GET /today.assignments_possible` (docs/04 A.2a, docs/AUDIT_2026-11-18 «Рабочая спецификация первого входа»): false means
 * `GET /assignments` is surely empty, so the TWA does not ask it on the first open of a self-study learner. The flag comes from the
 * Today snapshot (`twa:today:v2`, docs/19 K10) and must be a SUPERSET of what `AssignmentService::forLearner` can return: every
 * situation in which the list has something says true, and every write that can turn "none" into "some" moves the learner's version,
 * so a warm snapshot never hides an assignment. Each write path is checked as "read (warm) -> write -> read sees the new value".
 */

use App\Domain\Administration\Services\OrganizationGroupLinker;
use App\Domain\Learners\Services\GroupMembershipService;
use App\Domain\Organizations\Services\AssignmentService;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Domain\Twa\Services\JwtService;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\GroupMember;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Organization;
use App\Models\Stage;
use App\Models\TelegramGroup;
use App\Models\User;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\QueryLog;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->app->instance(TelegramClient::class, Mockery::mock(TelegramClient::class)->shouldIgnoreMissing());
    $this->course = Course::factory()->published()->create();
    $this->lesson = Lesson::factory()->create(['stage_id' => Stage::factory()->create(['course_id' => $this->course->id, 'number' => 1])->id, 'number' => 1]);
    Word::factory()->count(3)->for($this->lesson)->create(['is_active' => true]);
    $this->org = Organization::factory()->create();
    $this->group = TelegramGroup::factory()->create(['chat_id' => -9001, 'organization_id' => $this->org->id, 'course_id' => $this->course->id, 'status' => 'active']);
});

function possibleHeaders(Learner $learner): array
{
    return ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];
}

function possibleToday(object $test, Learner $learner): TestResponse
{
    return $test->withHeaders(possibleHeaders($learner))->getJson('/api/twa/today')->assertOk();
}

/** The flag of `/today` and the size of the list the TWA would ask for next. */
function possibleBoth(object $test, Learner $learner): array
{
    return [
        possibleToday($test, $learner)->json('assignments_possible'),
        count($test->withHeaders(possibleHeaders($learner))->getJson('/api/twa/assignments')->assertOk()->json('assignments')),
    ];
}

function possibleAssignment(object $test, ?TelegramGroup $group = null, array $attributes = []): Assignment
{
    $group ??= $test->group;

    return Assignment::factory()->create(['organization_id' => $group->organization_id, 'telegram_group_id' => $group->id, 'lesson_id' => $test->lesson->id, ...$attributes]);
}

// --- the four situations of the first open ---------------------------------------------------------------------------------

it('says false for a learner in no group, and reads no table of the groups once the snapshot is warm', function (): void {
    $learner = Learner::factory()->onboarded()->create();

    expect(possibleBoth($this, $learner))->toBe([false, 0]);

    $queries = QueryLog::during(fn () => possibleToday($this, $learner)->assertJsonPath('assignments_possible', false));
    expect(QueryLog::matching($queries, '/from ["`](group_members|telegram_groups|assignments)["`]/'))->toBe([]);
});

it('says true for a member of a center\'s group with an assignment', function (): void {
    $learner = Learner::factory()->onboarded()->memberOf($this->group)->create();
    possibleAssignment($this);

    expect(possibleBoth($this, $learner))->toBe([true, 1]);
});

it('says true ("maybe") for a member of a center\'s group without an assignment', function (): void {
    $learner = Learner::factory()->onboarded()->memberOf($this->group)->create();

    expect(possibleBoth($this, $learner))->toBe([true, 0]);
});

it('says true at once for a learner who joins the group right after the snapshot was warmed, and the list then has the assignment', function (): void {
    $learner = Learner::factory()->onboarded()->create();
    possibleAssignment($this);
    expect(possibleBoth($this, $learner))->toBe([false, 0]);

    app(GroupMembershipService::class)->join($learner, $this->group, 'test');

    expect(possibleBoth($this, $learner))->toBe([true, 1]);
});

it('says false for a member of a group that belongs to no center: no assignment can come from it', function (): void {
    $plain = TelegramGroup::factory()->create(['chat_id' => -9002, 'organization_id' => null, 'status' => 'active']);
    $learner = Learner::factory()->onboarded()->memberOf($plain)->create();

    expect(possibleBoth($this, $learner))->toBe([false, 0]);
});

// --- the superset: whatever forLearner returns, the flag is true ------------------------------------------------------------

it('covers every situation in which the list of assignments is not empty', function (string $situation): void {
    $learner = Learner::factory()->onboarded()->memberOf($this->group)->create();
    possibleAssignment($this);

    // The list does not look at the status of the group (an assignment stays visible after the bot was removed from the chat):
    // the flag must not either.
    match ($situation) {
        'active group' => null,
        'disabled group' => $this->group->update(['status' => 'disabled']),
        'pending group' => $this->group->update(['status' => 'pending']),
    };

    expect(app(AssignmentService::class)->forLearner($learner))->not->toBe([])
        ->and(possibleToday($this, $learner)->json('assignments_possible'))->toBeTrue();
})->with(['active group', 'disabled group', 'pending group']);

it('may say true while the list is empty: a suspended center, a closed assignment, a group moved to another center ("maybe")', function (): void {
    $learner = Learner::factory()->onboarded()->memberOf($this->group)->create();
    possibleAssignment($this, attributes: ['status' => Assignment::STATUS_CLOSED]);
    expect(possibleBoth($this, $learner))->toBe([true, 0]);

    $this->org->update(['status' => 'suspended']);
    possibleAssignment($this);
    expect(possibleBoth($this, $learner))->toBe([true, 0]);
});

it('agrees with the list over a mix of learners: never false where the list has something', function (): void {
    $plain = TelegramGroup::factory()->create(['chat_id' => -9003, 'organization_id' => null, 'status' => 'active']);
    $disabled = TelegramGroup::factory()->create(['chat_id' => -9004, 'organization_id' => $this->org->id, 'course_id' => $this->course->id, 'status' => 'disabled']);
    possibleAssignment($this);
    possibleAssignment($this, $disabled);

    $learners = [
        Learner::factory()->onboarded()->create(),
        Learner::factory()->onboarded()->memberOf($this->group)->create(),
        Learner::factory()->onboarded()->memberOf($this->group, 'left')->create(),
        Learner::factory()->onboarded()->memberOf($this->group, 'removed')->create(),
        Learner::factory()->onboarded()->memberOf($this->group, 'banned')->create(),
        Learner::factory()->onboarded()->memberOf($plain)->create(),
        Learner::factory()->onboarded()->memberOf($disabled)->create(),
    ];

    $seen = [];
    foreach ($learners as $learner) {
        [$flag, $listed] = possibleBoth($this, $learner);
        if ($listed > 0) {
            expect($flag)->toBeTrue();
        }
        $seen[] = [$flag, $listed];
    }

    expect($seen)->toBe([[false, 0], [true, 1], [false, 0], [false, 0], [false, 0], [false, 0], [true, 1]]);
});

// --- the write paths: read (warm) -> write -> read -----------------------------------------------------------------------

it('follows a leave and a removal through GroupMembershipService at once, and the join back (a ban stays: no join lifts it)', function (string $status, array $afterJoin): void {
    $learner = Learner::factory()->onboarded()->memberOf($this->group)->create();
    possibleAssignment($this);
    expect(possibleBoth($this, $learner))->toBe([true, 1]);

    app(GroupMembershipService::class)->leave($learner, $this->group, $status);

    expect(possibleBoth($this, $learner))->toBe([false, 0]);

    app(GroupMembershipService::class)->join($learner, $this->group, 'test');

    expect(possibleBoth($this, $learner))->toBe($afterJoin);
})->with([
    'left' => [GroupMembershipService::LEFT, [true, 1]],
    'removed' => [GroupMembershipService::REMOVED, [true, 1]],
    'banned' => [GroupMembershipService::BANNED, [false, 0]],
]);

it('follows the Telegram updates: new_chat_members joins, chat_member left and kicked leave', function (): void {
    possibleAssignment($this);
    $learner = Learner::factory()->onboarded()->create(['telegram_user_id' => 5551]);
    expect(possibleBoth($this, $learner))->toBe([false, 0]);
    $user = ['id' => 5551, 'first_name' => 'Ali', 'is_bot' => false];
    $chat = ['id' => -9001, 'type' => 'supergroup'];

    app(TelegramDispatcher::class)->dispatch(['message' => ['chat' => $chat, 'new_chat_members' => [$user]]]);
    expect(possibleBoth($this, $learner))->toBe([true, 1]);

    app(TelegramDispatcher::class)->dispatch(['chat_member' => ['chat' => $chat, 'new_chat_member' => ['status' => 'left', 'user' => $user]]]);
    expect(possibleBoth($this, $learner))->toBe([false, 0]);

    app(TelegramDispatcher::class)->dispatch(['chat_member' => ['chat' => $chat, 'new_chat_member' => ['status' => 'member', 'user' => $user]]]);
    expect(possibleBoth($this, $learner))->toBe([true, 1]);

    app(TelegramDispatcher::class)->dispatch(['chat_member' => ['chat' => $chat, 'new_chat_member' => ['status' => 'kicked', 'user' => $user]]]);
    expect(possibleBoth($this, $learner))->toBe([false, 0]);
});

it('follows the link of the group to a center and its unlink (OrganizationGroupLinker: the admin panel and the bot\'s link)', function (): void {
    $plain = TelegramGroup::factory()->create(['chat_id' => -9005, 'organization_id' => null, 'course_id' => $this->course->id, 'status' => 'active']);
    $learner = Learner::factory()->onboarded()->memberOf($plain)->create();
    expect(possibleBoth($this, $learner))->toBe([false, 0]);

    app(OrganizationGroupLinker::class)->link($this->org, $plain, User::factory()->create());
    possibleAssignment($this, $plain->refresh());

    expect(possibleBoth($this, $learner))->toBe([true, 1]);

    app(OrganizationGroupLinker::class)->unlink($this->org, $plain, User::factory()->create());

    expect(possibleBoth($this, $learner))->toBe([false, 0]);
});

it('follows the bot being removed from the chat and the group switched off and on: the assignment stays listed, the flag stays true', function (): void {
    $learner = Learner::factory()->onboarded()->memberOf($this->group)->create();
    possibleAssignment($this);
    expect(possibleBoth($this, $learner))->toBe([true, 1]);

    // MyChatMemberHandler: the bot was kicked -> `disabled` (the admin panel's "disable" writes the same through Eloquent).
    app(TelegramDispatcher::class)->dispatch(['my_chat_member' => [
        'chat' => ['id' => -9001, 'type' => 'supergroup', 'title' => 'G'],
        'old_chat_member' => ['status' => 'administrator'],
        'new_chat_member' => ['status' => 'kicked'],
    ]]);
    expect($this->group->refresh()->status)->toBe('disabled')
        ->and(possibleBoth($this, $learner))->toBe([true, 1]);

    $this->group->update(['status' => 'active']);
    expect(possibleBoth($this, $learner))->toBe([true, 1]);
});

it('follows the delete of the group: its memberships are gone, the flag is false at once', function (): void {
    $learner = Learner::factory()->onboarded()->memberOf($this->group)->create();
    expect(possibleToday($this, $learner)->json('assignments_possible'))->toBeTrue();

    $this->group->delete();

    expect(GroupMember::query()->where('learner_id', $learner->id)->exists())->toBeFalse()
        ->and(possibleBoth($this, $learner))->toBe([false, 0]);
});
