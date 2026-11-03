<?php

declare(strict_types=1);

use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Game\Enums\ClassCaravanStatus;
use App\Domain\Game\Enums\RunFinishReason;
use App\Domain\Game\Enums\RunKind;
use App\Domain\Game\Enums\RunStatus;
use App\Domain\Game\Exceptions\GameException;
use App\Domain\Game\Services\Class\ClassCaravanService;
use App\Domain\Game\Services\Class\ClassCaravanSummary;
use App\Domain\Game\Services\Runs\DailyCaravanService;
use App\Domain\Game\Services\Runs\RunService;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Operations\Support\PureCache;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Jobs\PostClassCaravanSummaryJob;
use App\Models\ClassCaravan;
use App\Models\ClassCaravanMember;
use App\Models\Course;
use App\Models\GameRun;
use App\Models\GameWordMiss;
use App\Models\GroupMember;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Organization;
use App\Models\ProductEvent;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use App\Models\User;
use App\Models\Word;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Spatie\Permission\Models\Role;
use Tests\Support\FakeTelegramClient;
use Tests\Support\Game\GameKit;
use Tests\Support\Practice\PracticeKit;
use Tests\Support\QueryLog;

/*
 * docs/18 §2.8, §12.11, §12.5.3 step 6 (G2.9a): the caravan of a class as the services see it. The start (the rights, the center with a
 * live subscription, one active caravan per group, the model at the start), the contribution of every finished run of a member inside
 * the transaction of the finish, the boss of the class (the gate; the fight itself waits for G2.4), the close by the clock and the
 * summary posted exactly once, the cancel, and what a learner may see. The real races are ClassCaravanConcurrencyTest's (PostgreSQL).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-05 10:00:00');
    GameKit::openAccess();
    GameKit::noEvents();
    config(['game.class.centers_only' => true, 'cache.pure_store' => 'array']);
});

afterEach(fn () => CarbonImmutable::setTestNow());

// ---------------------------------------------------------------------- the world

/** An active center with a live subscription (docs/18 §12.16 В3). */
function classCenter(bool $live = true): Organization
{
    $organization = Organization::factory()->create();
    if ($live) {
        Subscription::factory()->forOrganization($organization)->create();
    }

    return $organization;
}

/** A teacher of the group (`teacher_groups`, the rule of TeacherAuthority). */
function classTeacher(TelegramGroup $group): User
{
    $user = User::factory()->create(['is_active' => true]);
    DB::table('teacher_groups')->insert([
        'user_id' => $user->id,
        'telegram_group_id' => $group->id,
        'is_primary' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $user;
}

function classAdmin(): User
{
    Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');

    return $admin;
}

/**
 * A stop with words, `$members` learners introduced to all of them, the active group of a live center on that course, its teacher.
 *
 * @return array{group: TelegramGroup, teacher: User, course: Course, lesson: Lesson, learners: list<Learner>, words: list<Word>}
 */
function classWorld(int $members = 3, ?Organization $organization = null, int $words = 8): array
{
    static $worlds = 0;
    [$course, $lesson] = PracticeKit::course('class-course-'.(++$worlds));
    $learners = [];
    for ($i = 0; $i < $members; $i++) {
        $learners[] = Learner::factory()->create(['first_name' => "Ученик {$i}"]);
    }
    $list = [];
    for ($n = 1; $n <= $words; $n++) {
        $list[] = $word = GameKit::word($lesson, $learners[0], "classword{$n}", "перевод {$n}");
        foreach (array_slice($learners, 1) as $learner) {
            PracticeKit::introduce($learner, $word, 'review');
        }
    }

    $organization ??= classCenter();
    $group = TelegramGroup::factory()->create(['organization_id' => $organization->id, 'course_id' => $course->id, 'title' => 'Группа A1']);
    foreach ($learners as $learner) {
        GroupMember::factory()->create(['telegram_group_id' => $group->id, 'learner_id' => $learner->id]);
    }

    return ['group' => $group, 'teacher' => classTeacher($group), 'course' => $course, 'lesson' => $lesson, 'learners' => $learners, 'words' => $list];
}

/** The repetitions of the class made plain (the factory draws `is_hard` and the easiness at random): nothing is hard, no lapses, one easiness. @param list<Learner> $learners */
function classFlat(array $learners): void
{
    WordRepetition::query()->whereIn('learner_id', array_map(fn (Learner $l): int => $l->id, $learners))->update(['is_hard' => false, 'lapses' => 0, 'easiness_factor' => 2.5]);
}

function classService(): ClassCaravanService
{
    return app(ClassCaravanService::class);
}

/** Plays a stop run of the learner to its end: all right (3 stars) unless `$wrongAt` says which elements go wrong. @param list<int> $wrongAt */
function classPlay(Learner $learner, Course $course, Lesson $lesson, array $wrongAt = []): GameRun
{
    $runs = app(RunService::class);
    $run = $runs->start($learner, $course->slug, $lesson->id);

    return GameKit::play($runs, $run, $learner, $wrongAt);
}

/** @return list<array<string, mixed>> */
function classEvents(string $name): array
{
    return ProductEvent::query()->where('name', $name)->orderBy('id')->get()->map(fn (ProductEvent $e): array => ['props' => (array) $e->props, 'group' => $e->telegram_group_id, 'user' => $e->user_id, 'learner' => $e->learner_id])->all();
}

function classTelegram(): FakeTelegramClient
{
    $telegram = new FakeTelegramClient;
    app()->instance(TelegramClient::class, $telegram);

    return $telegram;
}

function classRunJob(int $id): void
{
    (new PostClassCaravanSummaryJob($id))->handle(app(TelegramClient::class), classService(), app(ClassCaravanSummary::class), app(ProductEventRecorder::class), app(LocaleResolver::class));
}

// ---------------------------------------------------------------------- the start

it('starts the caravan of the group for a week: the course of the group, the members at the start, the target, the boss words of the class, the event', function (): void {
    $w = classWorld(3);
    classFlat($w['learners']);
    WordRepetition::query()->where('word_id', $w['words'][4]->id)->update(['is_hard' => true]);

    $caravan = classService()->start($w['group'], $w['teacher']);

    expect($caravan->status)->toBe(ClassCaravanStatus::Active)
        ->and($caravan->telegram_group_id)->toBe($w['group']->id)
        ->and($caravan->active_group_id)->toBe($w['group']->id)
        ->and($caravan->organization_id)->toBe($w['group']->organization_id)
        ->and($caravan->course_id)->toBe($w['course']->id)
        ->and($caravan->started_by_user_id)->toBe($w['teacher']->id)
        ->and($caravan->starts_at->toDateTimeString())->toBe('2026-11-05 10:00:00')
        ->and($caravan->ends_at->toDateTimeString())->toBe('2026-11-12 10:00:00')
        ->and($caravan->target_stars)->toBe(18) // 3 members x 6
        ->and($caravan->stars_total)->toBe(0)
        ->and($caravan->runs_total)->toBe(0)
        ->and($caravan->meta)->toBe(['members_at_start' => 3])
        ->and($caravan->boss_snapshot_words)->toHaveCount(8)
        ->and($caravan->boss_snapshot_words[0])->toBe($w['words'][4]->id) // hard for the whole class: first
        ->and($caravan->boss_unlocked_at)->toBeNull()
        ->and($caravan->summary_posted_at)->toBeNull();

    $events = classEvents('game.class_started');
    expect($events)->toHaveCount(1)
        ->and($events[0]['props'])->toBe(['class_caravan_id' => $caravan->id, 'group_id' => $w['group']->id, 'members' => 3, 'target' => 18])
        ->and($events[0]['group'])->toBe($w['group']->id)
        ->and($events[0]['user'])->toBe($w['teacher']->id);
});

it('never sets the target below the minimum: one member gets 12, not 6; a left member is not counted', function (): void {
    $w = classWorld(2);
    GroupMember::query()->where('learner_id', $w['learners'][1]->id)->update(['status' => 'left']);

    $caravan = classService()->start($w['group'], $w['teacher']);

    expect($caravan->target_stars)->toBe(12)->and($caravan->meta['members_at_start'])->toBe(1);
});

it('takes the default course for a group without one', function (): void {
    $w = classWorld(1);
    $w['group']->update(['course_id' => null]);

    $caravan = classService()->start($w['group'], $w['teacher']);

    expect($caravan->course_id)->toBe(Course::query()->where('slug', config('content.default_course_slug'))->value('id'));
});

it('picks the boss words of the class by the hardest for the class: hard for more learners first, then lapses, then misses, then easiness; only this course, only active members, up to boss.size', function (): void {
    $w = classWorld(3, words: 14);
    [$a, $b, $c] = $w['learners'];
    classFlat($w['learners']);
    $words = $w['words'];
    // word 0: hard for two learners; word 1: hard for one; word 2: lapses for all three; word 3: misses in the game; word 4: the lowest easiness.
    WordRepetition::query()->where('word_id', $words[0]->id)->whereIn('learner_id', [$a->id, $b->id])->update(['is_hard' => true]);
    WordRepetition::query()->where('word_id', $words[1]->id)->where('learner_id', $c->id)->update(['is_hard' => true]);
    WordRepetition::query()->where('word_id', $words[2]->id)->update(['lapses' => 2]);
    GameWordMiss::query()->create(['learner_id' => $a->id, 'word_id' => $words[3]->id, 'misses' => 4, 'last_missed_at' => now()]);
    WordRepetition::query()->where('word_id', $words[4]->id)->update(['easiness_factor' => 1.3]);
    // a word hard for a learner who LEFT the group, and a word hard for a stranger: neither counts
    $left = Learner::factory()->create();
    GroupMember::factory()->left()->create(['telegram_group_id' => $w['group']->id, 'learner_id' => $left->id]);
    WordRepetition::factory()->create(['learner_id' => $left->id, 'word_id' => $words[9]->id, 'is_hard' => true, 'lapses' => 9]);
    $stranger = Learner::factory()->create();
    WordRepetition::factory()->create(['learner_id' => $stranger->id, 'word_id' => $words[10]->id, 'is_hard' => true, 'lapses' => 9]);
    // a word of another course the members know and find hard: not in the set
    [$otherLearner, , $otherLesson] = GameKit::stop(1);
    $foreign = GameKit::word($otherLesson, $a, 'foreignword', 'чужое');
    WordRepetition::query()->where('word_id', $foreign->id)->update(['is_hard' => true, 'lapses' => 9]);

    $caravan = classService()->start($w['group'], $w['teacher']);

    $ids = $caravan->boss_snapshot_words;
    expect($ids)->toHaveCount(12)
        ->and(array_slice($ids, 0, 5))->toBe([$words[0]->id, $words[1]->id, $words[2]->id, $words[3]->id, $words[4]->id])
        ->and($ids)->not->toContain($foreign->id)
        ->and(array_slice($ids, 5))->toBe(array_values(array_diff(array_map(fn (Word $wd): int => $wd->id, array_slice($words, 5, 7)), [])))
        ->and($otherLearner->id)->not->toBe($a->id);
});

it('refuses a user who is not a teacher of the group (403 not_teacher), lets an admin and the teacher start, and refuses an inactive teacher', function (): void {
    $w = classWorld(1);
    $stranger = classTeacher(TelegramGroup::factory()->create());
    $inactive = classTeacher($w['group']);
    $inactive->update(['is_active' => false]);

    foreach ([$stranger, $inactive] as $user) {
        try {
            classService()->start($w['group'], $user);
            $this->fail('No exception');
        } catch (GameException $e) {
            expect($e->errorCode)->toBe('class_caravan_forbidden')->and($e->details)->toBe(['reason' => 'not_teacher'])->and($e->httpStatus())->toBe(403);
        }
    }
    expect(ClassCaravan::query()->count())->toBe(0);

    $caravan = classService()->start($w['group'], classAdmin());
    expect($caravan->isActive())->toBeTrue();
});

it('refuses a group that is not active (403 group_inactive)', function (): void {
    $w = classWorld(1);
    $w['group']->update(['status' => 'disabled']);

    expect(fn () => classService()->start($w['group'], $w['teacher']))
        ->toThrow(fn (GameException $e) => $e->errorCode === 'class_caravan_forbidden' && $e->details === ['reason' => 'group_inactive']);
});

it('is for the centers with a live subscription while centers_only is on: no center, a center without a live subscription or a suspended one - 403 centers_only; off - any group', function (): void {
    $noCenter = classWorld(1, organization: Organization::factory()->create()); // the org has no subscription
    $noCenter['group']->update(['organization_id' => null]);
    $expired = classWorld(1, organization: classCenter(live: false));
    Subscription::factory()->forOrganization($expired['group']->organization)->expired()->create();
    $suspended = classWorld(1, organization: classCenter());
    $suspended['group']->organization?->update(['status' => 'suspended']);

    foreach ([$noCenter, $expired, $suspended] as $w) {
        expect(fn () => classService()->start($w['group'], $w['teacher']))
            ->toThrow(fn (GameException $e) => $e->errorCode === 'class_caravan_forbidden' && $e->details === ['reason' => 'centers_only']);
    }
    expect(ClassCaravan::query()->count())->toBe(0);

    config(['game.class.centers_only' => false]);
    foreach ([$noCenter, $expired, $suspended] as $w) {
        expect(classService()->start($w['group'], $w['teacher'])->isActive())->toBeTrue();
    }
    expect(ClassCaravan::query()->count())->toBe(3);
});

it('keeps one active caravan per group: a second start is 409 class_caravan_active, and a new one may start once the first is canceled or finished', function (): void {
    $w = classWorld(1);
    $first = classService()->start($w['group'], $w['teacher']);

    expect(fn () => classService()->start($w['group'], $w['teacher']))->toThrow(fn (GameException $e) => $e->errorCode === 'class_caravan_active' && $e->httpStatus() === 409);
    expect(classEvents('game.class_started'))->toHaveCount(1);

    classService()->cancel($first, $w['teacher']);
    $second = classService()->start($w['group'], $w['teacher']);
    expect($second->id)->not->toBe($first->id)->and($second->active_group_id)->toBe($w['group']->id)
        ->and(classService()->activeOfGroup($w['group'])?->id)->toBe($second->id);

    // Another group of the same center is its own caravan.
    $other = TelegramGroup::factory()->create(['organization_id' => $w['group']->organization_id]);
    expect(classService()->start($other, classTeacher($other))->isActive())->toBeTrue();
});

it('is told by the unique index when the courtesy check missed the other start (a row made under its feet)', function (): void {
    $w = classWorld(1);
    ClassCaravan::factory()->create(['telegram_group_id' => $w['group']->id]);

    expect(fn () => classService()->start($w['group'], $w['teacher']))->toThrow(fn (GameException $e) => $e->errorCode === 'class_caravan_active');
});

// ---------------------------------------------------------------------- the runs join the caravan

it('puts the caravan on the runs of its members at the start - a stop and the daily caravan - and not on the runs of a stranger, a member who left, or when the group is disabled', function (): void {
    $w = classWorld(2);
    $caravan = classService()->start($w['group'], $w['teacher']);
    [$a, $b] = $w['learners'];
    GroupMember::query()->where('learner_id', $b->id)->update(['status' => 'left']);
    [$stranger, $course, $lesson] = GameKit::stop(8);
    WordRepetition::query()->where('learner_id', $a->id)->update(['next_review_at' => now()->subHour()]);

    $stop = app(RunService::class)->start($a, $w['course']->slug, $w['lesson']->id);
    app(RunService::class)->abandon($stop, $a);
    $daily = app(DailyCaravanService::class)->start($a);
    $left = app(RunService::class)->start($b, $w['course']->slug, $w['lesson']->id);
    $foreign = app(RunService::class)->start($stranger, $course->slug, $lesson->id);

    expect($stop->class_caravan_id)->toBe($caravan->id)
        ->and($daily->class_caravan_id)->toBe($caravan->id)
        ->and($left->class_caravan_id)->toBeNull()
        ->and($foreign->class_caravan_id)->toBeNull();

    app(RunService::class)->abandon($daily, $a);
    $w['group']->update(['status' => 'disabled']);
    expect(app(RunService::class)->start($a, $w['course']->slug, $w['lesson']->id)->class_caravan_id)->toBeNull();
});

it('joins no caravan outside its window or after its end, and - in two groups with caravans - the one that started first', function (): void {
    $w = classWorld(1);
    $a = $w['learners'][0];
    $late = ClassCaravan::factory()->create(['telegram_group_id' => $w['group']->id, 'starts_at' => '2026-11-05 09:00:00', 'ends_at' => '2026-11-05 10:00:00']); // ends now: over
    expect(app(RunService::class)->start($a, $w['course']->slug, $w['lesson']->id)->class_caravan_id)->toBeNull();
    $late->update(['status' => ClassCaravanStatus::Finished, 'active_group_id' => null]);

    $second = TelegramGroup::factory()->create(['organization_id' => $w['group']->organization_id]);
    GroupMember::factory()->create(['telegram_group_id' => $second->id, 'learner_id' => $a->id]);
    $older = ClassCaravan::factory()->create(['telegram_group_id' => $second->id, 'starts_at' => '2026-11-04 10:00:00', 'ends_at' => '2026-11-11 10:00:00']);
    classService()->start($w['group'], $w['teacher']);

    expect(classService()->activeIdFor($a, CarbonImmutable::now()))->toBe($older->id);
});

// ---------------------------------------------------------------------- the contribution

it('adds every finished run of a member to the caravan inside the transaction of the finish: the member row, the totals, the best score; a failed run counts too', function (): void {
    $w = classWorld(2);
    $caravan = classService()->start($w['group'], $w['teacher']);
    [$a, $b] = $w['learners'];

    $first = classPlay($a, $w['course'], $w['lesson']);
    $second = classPlay($a, $w['course'], $w['lesson'], wrongAt: [0]);
    $third = classPlay($b, $w['course'], $w['lesson'], wrongAt: [0, 1, 2]);

    expect($first->stars)->toBe(3)->and($second->stars)->toBe(2)->and($third->finish_reason)->toBe(RunFinishReason::Failed)->and($third->stars)->toBe(0);

    $caravan->refresh();
    $rowA = ClassCaravanMember::query()->where('class_caravan_id', $caravan->id)->where('learner_id', $a->id)->firstOrFail();
    $rowB = ClassCaravanMember::query()->where('class_caravan_id', $caravan->id)->where('learner_id', $b->id)->firstOrFail();
    expect($caravan->stars_total)->toBe(5)
        ->and($caravan->runs_total)->toBe(3)
        ->and($caravan->boss_unlocked_at)->toBeNull()
        ->and($rowA->runs)->toBe(2)
        ->and($rowA->stars)->toBe(5)
        ->and($rowA->best_score)->toBe(max($first->score, $second->score))
        ->and($rowA->last_run_at?->toDateTimeString())->toBe('2026-11-05 10:00:00')
        ->and($rowA->boss_won_at)->toBeNull()
        ->and($rowB->runs)->toBe(1)
        ->and($rowB->stars)->toBe(0)
        ->and($rowB->best_score)->toBe($third->score)
        ->and(ClassCaravanMember::query()->count())->toBe(2);
});

it('gives nothing to a caravan that was canceled, closed or ran out between the start of the run and its finish, and an abandoned run gives nothing either', function (): void {
    $w = classWorld(1);
    $a = $w['learners'][0];
    $runs = app(RunService::class);

    $caravan = classService()->start($w['group'], $w['teacher']);
    $run = $runs->start($a, $w['course']->slug, $w['lesson']->id);
    classService()->cancel($caravan, $w['teacher']);
    GameKit::play($runs, $run, $a);
    expect($run->refresh()->class_caravan_id)->toBe($caravan->id)->and($caravan->refresh()->runs_total)->toBe(0)->and(ClassCaravanMember::query()->count())->toBe(0);

    $caravan = classService()->start($w['group'], $w['teacher']);
    CarbonImmutable::setTestNow('2026-11-12 09:50:00');
    $run = $runs->start($a, $w['course']->slug, $w['lesson']->id);
    expect($run->class_caravan_id)->toBe($caravan->id);
    CarbonImmutable::setTestNow('2026-11-12 10:00:00'); // ends_at reached, the scheduler not yet there
    GameKit::play($runs, $run, $a, now: CarbonImmutable::now());
    expect($caravan->refresh()->status)->toBe(ClassCaravanStatus::Active)->and($caravan->runs_total)->toBe(0);

    CarbonImmutable::setTestNow('2026-11-12 10:01:00');
    classService()->closeDue();
    $run = $runs->start($a, $w['course']->slug, $w['lesson']->id);
    expect($run->class_caravan_id)->toBeNull();

    CarbonImmutable::setTestNow('2026-11-13 10:00:00');
    $caravan = classService()->start($w['group'], $w['teacher']);
    $run = $runs->start($a, $w['course']->slug, $w['lesson']->id);
    $runs->abandon($run, $a);
    expect($run->class_caravan_id)->toBe($caravan->id)->and($caravan->refresh()->runs_total)->toBe(0)->and(ClassCaravanMember::query()->where('class_caravan_id', $caravan->id)->count())->toBe(0);
});

it('opens the boss of the class once, with the run that reaches the target', function (): void {
    $w = classWorld(1);
    $a = $w['learners'][0];
    $caravan = classService()->start($w['group'], $w['teacher']);
    expect($caravan->target_stars)->toBe(12);

    classPlay($a, $w['course'], $w['lesson']);
    classPlay($a, $w['course'], $w['lesson']);
    classPlay($a, $w['course'], $w['lesson']);
    expect($caravan->refresh()->stars_total)->toBe(9)->and($caravan->boss_unlocked_at)->toBeNull();

    CarbonImmutable::setTestNow('2026-11-05 11:00:00');
    classPlay($a, $w['course'], $w['lesson']);
    expect($caravan->refresh()->stars_total)->toBe(12)->and($caravan->boss_unlocked_at?->toDateTimeString())->toBe('2026-11-05 11:00:00');

    CarbonImmutable::setTestNow('2026-11-05 12:00:00');
    classPlay($a, $w['course'], $w['lesson']);
    expect($caravan->refresh()->stars_total)->toBe(15)->and($caravan->boss_unlocked_at?->toDateTimeString())->toBe('2026-11-05 11:00:00');
});

it('counts the stars of a run that belongs to the caravan in the budget of the finish: the caravan and the member cost 3 statements, the first run of a member 4', function (): void {
    $w = classWorld(1);
    $a = $w['learners'][0];
    classService()->start($w['group'], $w['teacher']);
    $runs = app(RunService::class);
    $measure = function () use ($runs, $a, $w): int {
        $run = $runs->start($a, $w['course']->slug, $w['lesson']->id);
        for ($i = 0; $i < $run->total - 1; $i++) {
            $element = GameKit::current($run);
            foreach (GameKit::rightAnswers($element) as $answer) {
                $runs->answer($run, $a, (string) Str::uuid(), $element['id'], $answer);
            }
        }
        $last = GameKit::current($run);
        $answers = GameKit::rightAnswers($last);
        $final = array_pop($answers);
        foreach ($answers as $answer) {
            $runs->answer($run, $a, (string) Str::uuid(), $last['id'], $answer);
        }

        return count(array_filter(QueryLog::during(fn () => $runs->answer($run, $a, (string) Str::uuid(), $last['id'], $final)), fn (string $sql): bool => str_contains($sql, 'class_caravan')));
    };

    expect($measure())->toBe(4)->and($measure())->toBe(3);
});

// ---------------------------------------------------------------------- the boss of the class (the gate; the fight is G2.4)

it('gates the boss of the class: locked until the target, one fight per member, enough words, members only, inside the window', function (): void {
    $w = classWorld(2);
    [$a, $b] = $w['learners'];
    $caravan = classService()->start($w['group'], $w['teacher']);
    $now = CarbonImmutable::now();
    $stranger = Learner::factory()->create();

    expect(fn () => classService()->assertClassBossAllowed($caravan, $a, $now))->toThrow(fn (GameException $e) => $e->errorCode === 'class_boss_locked');
    expect(fn () => classService()->assertClassBossAllowed($caravan, $stranger, $now))->toThrow(fn (GameException $e) => $e->errorCode === 'class_caravan_not_found');

    $caravan->update(['boss_unlocked_at' => $now]);
    classService()->assertClassBossAllowed($caravan, $a, $now);
    expect(ClassCaravanService::classBossKey($caravan, $a))->toBe("{$caravan->id}:{$a->id}");

    GameRun::factory()->finished(RunFinishReason::Completed)->create(['learner_id' => $a->id, 'kind' => RunKind::ClassBoss, 'class_caravan_id' => $caravan->id, 'class_boss_key' => ClassCaravanService::classBossKey($caravan, $a)]);
    expect(fn () => classService()->assertClassBossAllowed($caravan, $a, $now))->toThrow(fn (GameException $e) => $e->errorCode === 'class_boss_done');
    classService()->assertClassBossAllowed($caravan, $b, $now);

    $caravan->update(['boss_snapshot_words' => array_slice($caravan->boss_snapshot_words, 0, 7)]);
    expect(fn () => classService()->assertClassBossAllowed($caravan->refresh(), $b, $now))->toThrow(fn (GameException $e) => $e->errorCode === 'game_not_enough_material' && $e->details === ['available' => 7, 'required' => 8, 'reason' => 'no_words']);

    $caravan->update(['boss_snapshot_words' => $w['words'] ? array_map(fn (Word $wd): int => $wd->id, $w['words']) : []]);
    expect(fn () => classService()->assertClassBossAllowed($caravan->refresh(), $b, $now->addDays(7)))->toThrow(fn (GameException $e) => $e->errorCode === 'class_caravan_not_found');
});

it('marks the victory of a member over the boss of the class on the finish, and the caravan beats the boss when the wins reach half of the participants', function (): void {
    $w = classWorld(3);
    [$a, $b, $c] = $w['learners'];
    $caravan = classService()->start($w['group'], $w['teacher']);
    classPlay($a, $w['course'], $w['lesson']);
    classPlay($b, $w['course'], $w['lesson']);
    classPlay($c, $w['course'], $w['lesson']);
    $caravan->update(['boss_unlocked_at' => now()]);

    // The fight of G2.4 is a run `class_boss` finished by RunFinisher; here the contribution is called the way the finisher calls it.
    $fight = function (Learner $learner, bool $won) use ($caravan): void {
        $run = GameRun::factory()->create(['learner_id' => $learner->id, 'kind' => RunKind::ClassBoss, 'class_caravan_id' => $caravan->id, 'class_boss_key' => ClassCaravanService::classBossKey($caravan, $learner), 'total' => 12, 'correct' => $won ? 11 : 6, 'score' => 110]);
        DB::transaction(fn () => classService()->contribute($run, $won ? 3 : 0, $won, CarbonImmutable::now()));
    };

    $fight($a, false);
    expect($caravan->refresh()->boss_defeated_at)->toBeNull()->and(ClassCaravanMember::query()->where('learner_id', $a->id)->value('boss_won_at'))->toBeNull();

    CarbonImmutable::setTestNow('2026-11-05 11:00:00');
    $fight($b, true);
    $caravan->refresh();
    expect($caravan->boss_defeated_at)->toBeNull() // 1 win of 3 participants: ceil(3 / 2) = 2 needed
        ->and(ClassCaravanMember::query()->where('learner_id', $b->id)->firstOrFail()->boss_won_at?->toDateTimeString())->toBe('2026-11-05 11:00:00')
        ->and($caravan->stars_total)->toBe(12)->and($caravan->runs_total)->toBe(5);

    CarbonImmutable::setTestNow('2026-11-05 12:00:00');
    $fight($c, true);
    expect($caravan->refresh()->boss_defeated_at?->toDateTimeString())->toBe('2026-11-05 12:00:00');
});

// ---------------------------------------------------------------------- the close and the summary

it('is scheduled every 15 minutes, without overlapping', function (): void {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'game:class-caravans-close'));

    expect($event)->not->toBeNull()->and($event->expression)->toBe('*/15 * * * *')->and($event->withoutOverlapping)->toBeTrue();
});

it('closes the caravans past their end: finished, the group released, the event, the summary job queued once; the others untouched; a second sweep does nothing', function (): void {
    Bus::fake();
    $w = classWorld(1);
    $due = classService()->start($w['group'], $w['teacher']);
    classPlay($w['learners'][0], $w['course'], $w['lesson']);
    $other = TelegramGroup::factory()->create();
    $fresh = ClassCaravan::factory()->create(['telegram_group_id' => $other->id, 'ends_at' => '2026-11-12 10:00:01']);
    $canceled = ClassCaravan::factory()->canceled()->create(['ends_at' => '2026-11-01 10:00:00']);

    CarbonImmutable::setTestNow('2026-11-12 10:00:00');
    $this->artisan('game:class-caravans-close')->expectsOutput('Class caravans closed: 1')->assertSuccessful();

    $due->refresh();
    expect($due->status)->toBe(ClassCaravanStatus::Finished)
        ->and($due->active_group_id)->toBeNull()
        ->and($due->finished_at?->toDateTimeString())->toBe('2026-11-12 10:00:00')
        ->and($due->summary_posted_at)->toBeNull()
        ->and($fresh->fresh()?->status)->toBe(ClassCaravanStatus::Active)
        ->and($canceled->fresh()?->status)->toBe(ClassCaravanStatus::Canceled);
    Bus::assertDispatchedTimes(PostClassCaravanSummaryJob::class, 1);
    Bus::assertDispatched(PostClassCaravanSummaryJob::class, fn (PostClassCaravanSummaryJob $job): bool => $job->classCaravanId === $due->id);
    $events = classEvents('game.class_finished');
    expect($events)->toHaveCount(1)
        ->and($events[0]['props'])->toBe(['class_caravan_id' => $due->id, 'group_id' => $w['group']->id, 'stars_total' => 3, 'runs_total' => 1, 'boss_defeated' => false])
        ->and($events[0]['group'])->toBe($w['group']->id);

    // The group is free for a new caravan; the sweep again finds nothing.
    expect(classService()->start($w['group'], $w['teacher'])->isActive())->toBeTrue();
    expect(classService()->closeDue())->toBe(0);
    Bus::assertDispatchedTimes(PostClassCaravanSummaryJob::class, 1);
});

it('goes on with the next caravan when one fails to close: the failure is reported, the others are closed', function (): void {
    Exceptions::fake();
    $groups = TelegramGroup::factory()->count(3)->create();
    $caravans = $groups->map(fn (TelegramGroup $g): ClassCaravan => ClassCaravan::factory()->create(['telegram_group_id' => $g->id, 'ends_at' => '2026-11-05 09:00:00']));
    $calls = 0;
    $this->app->instance(Dispatcher::class, Mockery::mock(Dispatcher::class, function (MockInterface $mock) use (&$calls): void {
        $mock->shouldReceive('dispatch')->andReturnUsing(function () use (&$calls): void {
            if (++$calls === 1) {
                throw new RuntimeException('queue down');
            }
        });
    }));

    expect(classService()->closeDue())->toBe(2)
        ->and($caravans->map(fn (ClassCaravan $c): string => (string) $c->fresh()?->status->value)->all())->toBe(['finished', 'finished', 'finished'])
        ->and($calls)->toBe(3);
    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'queue down');
});

it('posts the summary of the week to the group once: the stars, the runs, the boss, the top five by stars then runs; the marker, the event; a second delivery posts nothing', function (): void {
    $telegram = classTelegram();
    $w = classWorld(3);
    [$a, $b, $c] = $w['learners'];
    $caravan = classService()->start($w['group'], $w['teacher']);
    classPlay($a, $w['course'], $w['lesson'], wrongAt: [0]);
    classPlay($b, $w['course'], $w['lesson']);
    classPlay($b, $w['course'], $w['lesson'], wrongAt: [0]);
    classPlay($c, $w['course'], $w['lesson'], wrongAt: [0]);
    classPlay($c, $w['course'], $w['lesson'], wrongAt: [0]);
    // a sixth and seventh member with less, to be cut by the top five
    foreach ([1, 2, 3] as $i) {
        $extra = Learner::factory()->create(['first_name' => "Экстра {$i}"]);
        GroupMember::factory()->create(['telegram_group_id' => $w['group']->id, 'learner_id' => $extra->id]);
        ClassCaravanMember::factory()->create(['class_caravan_id' => $caravan->id, 'learner_id' => $extra->id, 'runs' => 1, 'stars' => $i === 3 ? 0 : 1]);
    }
    $w['group']->update(['title' => 'Группа <A1>']);
    CarbonImmutable::setTestNow('2026-11-12 10:00:00');
    classService()->closeDue();

    classRunJob($caravan->id);
    classRunJob($caravan->id);

    expect($telegram->sent)->toHaveCount(1)
        ->and($telegram->sent[0]['chat_id'])->toBe($w['group']->chat_id)
        ->and($telegram->sent[0]['text'])->toBe(implode("\n", [
            '🏁 Караван класса «Группа &lt;A1&gt;» завершил неделю!',
            '',
            '⭐ Звёзд собрано: <b>11</b> из 18',
            '🐪 Забегов: <b>5</b>',
            '👹 До босса класса не дошли — цель недели не взята.',
            '',
            'Лучшие караванщики:',
            '🥇 Ученик 1 — <b>5</b> ⭐ (забегов: 2)',
            '🥈 Ученик 2 — <b>4</b> ⭐ (забегов: 2)',
            '🥉 Ученик 0 — <b>2</b> ⭐ (забегов: 1)',
            '4. Экстра 1 — <b>1</b> ⭐ (забегов: 1)',
            '5. Экстра 2 — <b>1</b> ⭐ (забегов: 1)',
        ]))
        ->and($caravan->fresh()?->summary_posted_at?->toDateTimeString())->toBe('2026-11-12 10:00:00')
        ->and($caravan->fresh()?->meta)->toBe(['members_at_start' => 3]);
    $events = classEvents('game.class_summary_posted');
    expect($events)->toHaveCount(1)->and($events[0]['props'])->toBe(['class_caravan_id' => $caravan->id])->and($events[0]['group'])->toBe($w['group']->id);
});

it('speaks the language of the group, says so when nobody ran, and tells a beaten boss from one that stood', function (): void {
    $telegram = classTelegram();
    $w = classWorld(1);
    $w['group']->update(['meta' => ['locale' => 'uz'], 'title' => 'Guruh']);
    $caravan = ClassCaravan::factory()->finished()->create(['telegram_group_id' => $w['group']->id, 'target_stars' => 12]);
    $beaten = ClassCaravan::factory()->finished()->create(['telegram_group_id' => TelegramGroup::factory()->create(['title' => 'B'])->id, 'target_stars' => 12, 'stars_total' => 14, 'runs_total' => 4, 'boss_unlocked_at' => now(), 'boss_defeated_at' => now()]);
    $stood = ClassCaravan::factory()->finished()->create(['telegram_group_id' => TelegramGroup::factory()->create(['title' => 'C'])->id, 'target_stars' => 12, 'stars_total' => 12, 'runs_total' => 4, 'boss_unlocked_at' => now()]);

    classRunJob($caravan->id);
    classRunJob($beaten->id);
    classRunJob($stood->id);

    expect($telegram->sent)->toHaveCount(3)
        ->and($telegram->sent[0]['text'])->toBe("🏁 «Guruh» sinf karvoni haftani yakunladi!\n\n⭐ Yig'ilgan yulduzlar: <b>0</b> / 12\n🐪 Yurishlar: <b>0</b>\n👹 Sinf bossigacha yetib bormadik — hafta maqsadi olinmadi.\n\nBu hafta hech kim yo'lga chiqmadi. Yana urinib ko'ramiz!")
        ->and($telegram->sent[1]['text'])->toContain('👹 Босс класса побеждён!')->and($telegram->sent[1]['text'])->toContain('<b>14</b> из 12')
        ->and($telegram->sent[2]['text'])->toContain('👹 Босс класса устоял');
});

it('posts nothing for a caravan that is active or canceled, skips a group that is not active with a marker, and keeps no marker when Telegram fails', function (): void {
    $telegram = classTelegram();
    $w = classWorld(1);
    $active = ClassCaravan::factory()->create(['telegram_group_id' => $w['group']->id]);
    $canceled = ClassCaravan::factory()->canceled()->create(['telegram_group_id' => TelegramGroup::factory()->create()->id]);
    $gone = TelegramGroup::factory()->create(['status' => 'disabled']);
    $orphan = ClassCaravan::factory()->finished()->create(['telegram_group_id' => $gone->id, 'meta' => ['members_at_start' => 2]]);
    $failing = ClassCaravan::factory()->finished()->create(['telegram_group_id' => TelegramGroup::factory()->create()->id]);

    classRunJob($active->id);
    classRunJob($canceled->id);
    classRunJob($orphan->id);
    expect($telegram->sent)->toBe([])
        ->and($active->fresh()?->summary_posted_at)->toBeNull()
        ->and($canceled->fresh()?->summary_posted_at)->toBeNull()
        ->and($orphan->fresh()?->summary_posted_at?->toDateTimeString())->toBe('2026-11-05 10:00:00')
        ->and($orphan->fresh()?->meta)->toBe(['members_at_start' => 2, 'summary_skipped' => 'group_inactive'])
        ->and(classEvents('game.class_summary_posted'))->toBe([]);

    $telegram->failSends = 1;
    expect(fn () => classRunJob($failing->id))->toThrow(TelegramApiException::class);
    expect($failing->fresh()?->summary_posted_at)->toBeNull()->and(classEvents('game.class_summary_posted'))->toBe([]);

    classRunJob($failing->id); // the retry
    expect($telegram->sent)->toHaveCount(1)->and($failing->fresh()?->summary_posted_at)->not->toBeNull()->and(classEvents('game.class_summary_posted'))->toHaveCount(1);
});

// ---------------------------------------------------------------------- the cancel

it('cancels the caravan by the teacher or an admin with the reason kept, posts no summary, leaves the runs of the members to them; a stranger is refused; a caravan that is over stays as it is', function (): void {
    $telegram = classTelegram();
    $w = classWorld(1);
    $a = $w['learners'][0];
    $caravan = classService()->start($w['group'], $w['teacher']);
    $run = classPlay($a, $w['course'], $w['lesson']);
    $stranger = classTeacher(TelegramGroup::factory()->create());

    expect(fn () => classService()->cancel($caravan, $stranger))->toThrow(fn (GameException $e) => $e->errorCode === 'class_caravan_forbidden' && $e->details === ['reason' => 'not_teacher']);

    CarbonImmutable::setTestNow('2026-11-06 10:00:00');
    $canceled = classService()->cancel($caravan, $w['teacher']);
    expect($canceled->status)->toBe(ClassCaravanStatus::Canceled)
        ->and($canceled->active_group_id)->toBeNull()
        ->and($canceled->finished_at?->toDateTimeString())->toBe('2026-11-06 10:00:00')
        ->and($canceled->meta)->toBe(['members_at_start' => 1, 'canceled_reason' => 'teacher', 'canceled_by_user_id' => $w['teacher']->id])
        ->and($run->fresh()?->status)->toBe(RunStatus::Finished)
        ->and($run->fresh()?->finish_reason)->toBe(RunFinishReason::Completed)
        ->and($run->fresh()?->class_caravan_id)->toBe($caravan->id)
        ->and(ClassCaravanMember::query()->where('class_caravan_id', $caravan->id)->count())->toBe(1);

    classRunJob($caravan->id);
    expect($telegram->sent)->toBe([])->and(classService()->closeDue())->toBe(0);

    // Canceled again, or by an admin after the fact: nothing changes.
    $admin = classAdmin();
    expect(classService()->cancel($caravan->fresh() ?? $caravan, $admin)->meta['canceled_by_user_id'])->toBe($w['teacher']->id);

    $second = classService()->start($w['group'], $w['teacher']);
    expect(classService()->cancel($second, $admin)->meta['canceled_reason'])->toBe('admin');
    $finished = ClassCaravan::factory()->finished()->create(['telegram_group_id' => $w['group']->id]);
    expect(classService()->cancel($finished, $admin)->status)->toBe(ClassCaravanStatus::Finished);
});

// ---------------------------------------------------------------------- what a learner sees

it('shows a learner the active and finished caravans of the active groups they are an active member of, newest first, and nothing else; a stranger\'s or a canceled one is 404', function (): void {
    $w = classWorld(2);
    [$a, $b] = $w['learners'];
    $mine = classService()->start($w['group'], $w['teacher']);
    $old = ClassCaravan::factory()->finished()->create(['telegram_group_id' => $w['group']->id, 'starts_at' => '2026-10-20 10:00:00']);
    $canceled = ClassCaravan::factory()->canceled()->create(['telegram_group_id' => $w['group']->id, 'starts_at' => '2026-10-27 10:00:00']);
    $leftGroup = TelegramGroup::factory()->create();
    GroupMember::factory()->left()->create(['telegram_group_id' => $leftGroup->id, 'learner_id' => $a->id]);
    $ofLeft = ClassCaravan::factory()->create(['telegram_group_id' => $leftGroup->id]);
    $disabled = TelegramGroup::factory()->create(['status' => 'disabled']);
    GroupMember::factory()->create(['telegram_group_id' => $disabled->id, 'learner_id' => $a->id]);
    $ofDisabled = ClassCaravan::factory()->create(['telegram_group_id' => $disabled->id]);
    $stranger = Learner::factory()->create();

    expect(classService()->visibleFor($a)->pluck('id')->all())->toBe([$mine->id, $old->id])
        ->and(classService()->visibleFor($b)->pluck('id')->all())->toBe([$mine->id, $old->id])
        ->and(classService()->visibleFor($stranger)->all())->toBe([])
        ->and(classService()->find($a, $mine->id)->id)->toBe($mine->id)
        ->and(classService()->find($a, $old->id)->id)->toBe($old->id);
    foreach ([$canceled->id, $ofLeft->id, $ofDisabled->id, 999_999] as $id) {
        expect(fn () => classService()->find($a, $id))->toThrow(fn (GameException $e) => $e->errorCode === 'class_caravan_not_found' && $e->httpStatus() === 404);
    }
    expect(fn () => classService()->find($stranger, $mine->id))->toThrow(fn (GameException $e) => $e->errorCode === 'class_caravan_not_found');

    config(['game.access' => 'off']);
    expect(fn () => classService()->visibleFor($a))->toThrow(fn (GameException $e) => $e->errorCode === 'game_unavailable');
    expect(fn () => classService()->find($a, $mine->id))->toThrow(fn (GameException $e) => $e->errorCode === 'game_unavailable');
});

it('gives the standings best first as arrays, from the cache while the caravan is active (60 s) and from the database once it is over', function (): void {
    $w = classWorld(2);
    [$a, $b] = $w['learners'];
    $caravan = classService()->start($w['group'], $w['teacher']);
    classPlay($a, $w['course'], $w['lesson'], wrongAt: [0]);
    $run = classPlay($b, $w['course'], $w['lesson']);

    $standings = classService()->standings($caravan);
    expect($standings)->toBe([
        ['learner_id' => $b->id, 'name' => 'Ученик 1', 'stars' => 3, 'runs' => 1, 'best_score' => $run->score, 'boss_won' => false],
        ['learner_id' => $a->id, 'name' => 'Ученик 0', 'stars' => 2, 'runs' => 1, 'best_score' => ClassCaravanMember::query()->where('learner_id', $a->id)->value('best_score'), 'boss_won' => false],
    ])
        ->and(ClassCaravanService::standingsKey($caravan->id))->toBe("game:class:v1:{$caravan->id}:standings")
        ->and(PureCache::store()->get(ClassCaravanService::standingsKey($caravan->id)))->toBe($standings)
        ->and(classService()->myStanding($caravan, $a)['stars'] ?? null)->toBe(2)
        ->and(classService()->myStanding($caravan, Learner::factory()->create()))->toBeNull();

    // Warm: a second read touches no table; a new run shows after the TTL.
    expect(QueryLog::during(fn () => classService()->standings($caravan)))->toBe([]);
    classPlay($a, $w['course'], $w['lesson']);
    expect(classService()->standings($caravan)[0]['learner_id'])->toBe($b->id);
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(ClassCaravanService::STANDINGS_TTL + 1));
    expect(classService()->standings($caravan)[0])->toMatchArray(['learner_id' => $a->id, 'stars' => 5, 'runs' => 2]);

    // Over: the cache is dropped on the close and the rows are read as they are.
    CarbonImmutable::setTestNow('2026-11-12 10:00:00');
    classService()->closeDue();
    $over = $caravan->fresh() ?? $caravan;
    expect(PureCache::store()->has(ClassCaravanService::standingsKey($caravan->id)))->toBeFalse()
        ->and(count(QueryLog::during(fn () => classService()->standings($over))))->toBe(1)
        ->and(PureCache::store()->has(ClassCaravanService::standingsKey($caravan->id)))->toBeFalse();
});
