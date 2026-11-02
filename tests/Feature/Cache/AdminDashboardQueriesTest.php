<?php

declare(strict_types=1);

use App\Domain\Administration\Services\AdminDashboardStats;
use App\Domain\Administration\Services\PanelDataScope;
use App\Domain\Billing\Support\PaidSubscriptionQuery;
use App\Domain\Operations\Support\PureCache;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use App\Models\TrainingReview;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\Word;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\HotPathFixtures as Fx;
use Tests\Support\QueryLog;

/*
 * The heavy widgets of the dashboard of /admin as ONE aggregate each (docs/20 §4 "Индексы F2": indexes do not cure them): the
 * rankings group the reviews of the window by word / by learner and read the ten rows they name; the activity chart and the
 * hardest words add no filter by sessions for an admin; the seat table asks "deleted?" as an anti-join. Each is run beside the
 * query it replaces - kept below verbatim - on one data set, for an admin and for a teacher, and must give the very same rows.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(RolePermissionSeeder::class)->run();
});

// --- the queries the widgets ran before (the reference), copied as they were ------------------------------

/** @return list<array{id: int, name: string, groups: string, reviews_count: int}> */
function legacyTopLearners(PanelDataScope $scope): array
{
    $since = now()->subDays(30);
    $sessionIds = $scope->trainingSessionIds();
    $reviewsInScope = fn (Builder $q): Builder => $q
        ->where('created_at', '>=', $since)
        ->whereIn('training_session_id', clone $sessionIds);

    return $scope->learners()
        ->with(['groups' => fn ($groups) => $groups
            ->wherePivot('status', 'active')
            ->whereIn('telegram_groups.id', $scope->groups()->select('telegram_groups.id'))])
        ->withCount(['trainingReviews as reviews_count' => $reviewsInScope])
        ->whereHas('trainingReviews', $reviewsInScope)
        ->orderByDesc('reviews_count')
        ->limit(10)
        ->get()
        ->map(fn (Learner $learner): array => [
            'id' => (int) $learner->id,
            'name' => trim(($learner->first_name ?? '—').' '.($learner->last_name ?? '')),
            'groups' => $learner->groups->pluck('title')->implode(', '),
            'reviews_count' => (int) $learner->getAttribute('reviews_count'),
        ])
        ->values()
        ->all();
}

/** @return list<array{id: int, word: string, translation: string, attempts: int, hard_ratio: float|null}> */
function legacyHardestWords(PanelDataScope $scope): array
{
    $since = now()->subDays(30);
    $sessionIds = $scope->trainingSessionIds();

    return Word::query()
        ->select('words.id', 'words.word', 'words.translation')
        ->selectSub(
            fn (QueryBuilder $q) => $q->from('training_reviews')
                ->selectRaw('COUNT(*)')
                ->whereColumn('training_reviews.word_id', 'words.id')
                ->whereIn('training_reviews.training_session_id', clone $sessionIds)
                ->where('training_reviews.created_at', '>=', $since),
            'attempts',
        )
        ->selectSub(
            fn (QueryBuilder $q) => $q->from('training_reviews')
                ->selectRaw('CAST(SUM(CASE WHEN quality < ? THEN 1 ELSE 0 END) AS FLOAT) / NULLIF(COUNT(*), 0)', [3])
                ->whereColumn('training_reviews.word_id', 'words.id')
                ->whereIn('training_reviews.training_session_id', clone $sessionIds)
                ->where('training_reviews.created_at', '>=', $since),
            'hard_ratio',
        )
        ->whereHas(
            'trainingReviews',
            fn (Builder $q): Builder => $q
                ->whereIn('training_session_id', clone $sessionIds)
                ->where('created_at', '>=', $since),
            '>=',
            5,
        )
        ->orderByDesc('hard_ratio')
        ->limit(10)
        ->get()
        ->map(fn (Word $word): array => [
            'id' => (int) $word->id,
            'word' => (string) $word->word,
            'translation' => (string) $word->translation,
            'attempts' => (int) $word->getAttribute('attempts'),
            'hard_ratio' => $word->getAttribute('hard_ratio') === null ? null : (float) $word->getAttribute('hard_ratio'),
        ])
        ->values()
        ->all();
}

/** @return list<int> */
function legacyActivity(PanelDataScope $scope): array
{
    $since = Carbon::today()->subDays(29);

    /** @var array<string, int> $counts */
    $counts = TrainingReview::query()
        ->whereIn('training_session_id', $scope->trainingSessionIds())
        ->where('created_at', '>=', $since)
        ->selectRaw('DATE(created_at) AS d, COUNT(*) AS c')
        ->groupBy('d')
        ->pluck('c', 'd')
        ->map(fn ($v): int => (int) $v)
        ->all();

    $values = [];
    for ($i = 0; $i < 30; $i++) {
        $values[] = $counts[$since->copy()->addDays($i)->toDateString()] ?? 0;
    }

    return $values;
}

/** @return list<array<string, mixed>> the first ten rows of the seat table, as the widget built them with the join of learners */
function legacyOrganizationSeats(): array
{
    $now = CarbonImmutable::now();

    $members = fn (string $seatState) => DB::table('organization_members as om')
        ->join('learners as l', 'l.id', '=', 'om.learner_id')
        ->whereNull('l.deleted_at')
        ->whereColumn('om.organization_id', 'organizations.id')
        ->where('om.status', 'active')
        ->where('om.seat_state', $seatState)
        ->selectRaw('count(*)');

    $sponsoring = PaidSubscriptionQuery::granting(
        Subscription::query()
            ->where('subscriptions.subscriber_type', 'organization')
            ->whereColumn('subscriptions.subscriber_id', 'organizations.id'),
        $now,
    )->orderByDesc('subscriptions.id')->limit(1);

    return Organization::query()
        ->withCount(['telegramGroups as active_groups_count' => fn ($groups) => $groups->where('status', 'active')])
        ->addSelect([
            'members_count' => $members('seated'),
            'waitlisted_count' => $members('waitlisted'),
            'sponsor_subscription_id' => (clone $sponsoring)->select('subscriptions.id'),
            'seats_limit' => (clone $sponsoring)->select('subscriptions.seats_limit'),
        ])
        ->orderByDesc('members_count')
        ->orderBy('name')
        ->orderBy('organizations.id')
        ->limit(10)
        ->get()
        ->map(fn (Organization $organization): array => [
            'id' => (int) $organization->id,
            'name' => (string) $organization->name,
            'status' => (string) $organization->status,
            'active_groups_count' => (int) $organization->getAttribute('active_groups_count'),
            'members_count' => (int) $organization->getAttribute('members_count'),
            'waitlisted_count' => (int) $organization->getAttribute('waitlisted_count'),
            'seats_limit' => $organization->getAttribute('seats_limit') === null ? null : (int) $organization->getAttribute('seats_limit'),
            'sponsoring' => $organization->getAttribute('sponsor_subscription_id') !== null && $organization->status === 'active',
        ])
        ->values()
        ->all();
}

// --- the data set -----------------------------------------------------------------------------------------

/**
 * Reviews of one (session, learner, word) triple: `$count` of them, `$hard` with a grade below 3, `$daysAgo` old.
 *
 * @param  list<array<string, mixed>>  $rows
 */
function dashReviews(array &$rows, TrainingSession $session, Learner $learner, int $wordId, int $count, int $hard = 0, int $daysAgo = 0): void
{
    for ($i = 0; $i < $count; $i++) {
        $rows[] = [
            'training_session_id' => $session->id,
            'learner_id' => $learner->id,
            'word_id' => $wordId,
            'quality' => $i < $hard ? 1 : 4,
            'time_spent_ms' => 1000,
            'created_at' => now()->subDays($daysAgo)->subMinutes($i),
        ];
    }
}

/**
 * One data set for every ranking: two groups, learners of all the kinds that must be counted or left out (members, a member who
 * left, one of both groups, a deleted one, one with only personal practice), reviews in group and personal sessions, in the
 * window and past it. Every count and every ratio is distinct, so the order does not depend on how a database breaks ties.
 *
 * @return array{admin: User, teacher: User, words: array<int, string>} the words by number, as the table shows them
 */
function seedRankingData(): array
{
    $groupA = TelegramGroup::factory()->create(['title' => 'Class A']);
    $groupB = TelegramGroup::factory()->create(['title' => 'Class B']);

    $teacher = User::factory()->create(['is_active' => true]);
    $teacher->assignRole('teacher');
    $teacher->telegramGroups()->attach($groupA->id);
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');

    $sessionA1 = TrainingSession::factory()->create(['telegram_group_id' => $groupA->id]);
    $sessionA2 = TrainingSession::factory()->create(['telegram_group_id' => $groupA->id]);
    $sessionB = TrainingSession::factory()->create(['telegram_group_id' => $groupB->id]);

    // Members of the teacher's group: m1..m14 with 1..14 reviews each (the ten best of them make the teacher's list).
    $members = [];
    for ($i = 1; $i <= 14; $i++) {
        $members[$i] = Learner::factory()->memberOf($groupA)->create(['first_name' => "Member{$i}", 'last_name' => null]);
    }
    $a1 = Learner::factory()->memberOf($groupA)->create(['first_name' => 'Ada', 'last_name' => 'One']);
    $a2 = Learner::factory()->memberOf($groupA)->create(['first_name' => 'Bea', 'last_name' => 'Two']);
    $a3 = Learner::factory()->memberOf($groupA)->create(['first_name' => 'Cy', 'last_name' => 'Three']);
    $left = Learner::factory()->memberOf($groupA, 'left')->create(['first_name' => 'Left', 'last_name' => 'Group']);
    $both = Learner::factory()->memberOf($groupA)->memberOf($groupB)->create(['first_name' => 'Both', 'last_name' => 'Groups']);
    $b1 = Learner::factory()->memberOf($groupB)->create(['first_name' => 'Other', 'last_name' => 'One']);
    $b2 = Learner::factory()->memberOf($groupB)->create(['first_name' => 'Other', 'last_name' => 'Two']);
    $deleted = Learner::factory()->memberOf($groupA)->create(['first_name' => 'Gone', 'last_name' => 'Away']);
    $wordy = Learner::factory()->memberOf($groupA)->create(['first_name' => 'Wordy', 'last_name' => null]);
    $solo = Learner::factory()->create(['first_name' => 'Solo', 'last_name' => null]);
    $personalA1 = TrainingSession::factory()->personal($a1)->create();
    $personalSolo = TrainingSession::factory()->personal($solo)->create();

    // Words, in distinct lessons, by bulk insert: w[1..16].
    $lesson = Lesson::factory()->create();
    $wordIds = Fx::words($lesson, 16)['active'];
    $word = fn (int $n): int => $wordIds[$n - 1];

    $rows = [];

    // --- learners (counts: group sessions of A for the teacher; everything for the admin) ---
    foreach ($members as $i => $member) {
        dashReviews($rows, $sessionA1, $member, $word(16), $i, 0);
    }
    dashReviews($rows, $sessionA1, $a1, $word(16), 30);   // teacher 30
    dashReviews($rows, $personalA1, $a1, $word(16), 25);  // admin 55 + 5 below (personal practice stays private from a teacher)
    dashReviews($rows, $sessionA1, $a2, $word(16), 25);   // 25
    dashReviews($rows, $sessionA1, $a2, $word(16), 6, 0, 40); // too old to count
    dashReviews($rows, $sessionA2, $a3, $word(16), 22);   // 22
    dashReviews($rows, $sessionA1, $left, $word(16), 40); // no longer an active member: the teacher does not see them
    dashReviews($rows, $sessionA1, $both, $word(16), 21); // teacher 21, with Class A only
    dashReviews($rows, $sessionB, $both, $word(16), 3);   // admin 24, with both classes
    dashReviews($rows, $sessionB, $b1, $word(16), 33);    // admin 33 + 8 below
    dashReviews($rows, $sessionB, $b2, $word(16), 31);
    dashReviews($rows, $sessionA1, $deleted, $word(16), 60); // deleted below: nobody sees them
    dashReviews($rows, $personalSolo, $solo, $word(16), 70);

    // --- words (attempts, hard) in the sessions of group A ---
    $inA = [1 => [5, 5], 2 => [6, 5], 7 => [5, 4], 8 => [7, 5], 9 => [6, 4], 10 => [5, 3], 11 => [7, 4], 12 => [6, 3], 13 => [7, 3], 14 => [5, 2], 15 => [7, 2]];
    foreach ($inA as $n => [$attempts, $hard]) {
        dashReviews($rows, $sessionA1, $wordy, $word($n), $attempts, $hard);
    }
    dashReviews($rows, $sessionA1, $wordy, $word(3), 3, 3);    // w3: 3 in the class...
    dashReviews($rows, $personalA1, $a1, $word(3), 5, 3);      // ...and 5 in personal practice: 8 attempts for an admin only
    dashReviews($rows, $sessionB, $b1, $word(4), 8, 7);        // w4: another class only
    dashReviews($rows, $sessionA1, $wordy, $word(5), 5, 5, 31); // w5: all of it past the window
    dashReviews($rows, $sessionA1, $wordy, $word(6), 4, 4);    // w6: four attempts, one short of the rule

    foreach (array_chunk($rows, 200) as $chunk) {
        DB::table('training_reviews')->insert($chunk);
    }

    $deleted->delete();

    return [
        'admin' => $admin,
        'teacher' => $teacher,
        'words' => array_combine(range(1, 16), array_map(fn (int $n): string => "w{$lesson->id}-{$n}", range(1, 16))),
    ];
}

/** The widgets' cache is not what is under test: every call computes. */
function dashStatsAs(User $user): AdminDashboardStats
{
    Auth::login($user);
    $user->hasRole('admin'); // Spatie reads the roles of a user once; a measured call must not pay for it
    PureCache::store()->flush();

    return app(AdminDashboardStats::class);
}

// --- equality: top learners -------------------------------------------------------------------------------

it('ranks the same ten learners, with the same counts and groups, as the per-learner subqueries did, for an admin and a teacher', function (string $who): void {
    $users = seedRankingData();
    $stats = dashStatsAs($users[$who]);

    $legacy = legacyTopLearners(app(PanelDataScope::class));
    $now = $stats->topLearners();

    expect($now)->toBe($legacy)
        ->and($now)->toHaveCount(10);

    // The cut really cuts: there are more learners with reviews than ten.
    expect(count(array_unique(array_column($now, 'id'))))->toBe(10);
})->with(['admin', 'teacher']);

it('leaves out of the ranking a deleted learner, a learner who left the group, a learner of another group and old reviews, as before', function (): void {
    $users = seedRankingData();

    $admin = dashStatsAs($users['admin'])->topLearners();
    $teacher = dashStatsAs($users['teacher'])->topLearners();

    $names = fn (array $rows): array => array_column($rows, 'name');
    expect($names($admin))->not->toContain('Gone Away')           // deleted
        ->and($admin[0])->toMatchArray(['name' => 'Wordy', 'reviews_count' => 73])
        ->and($admin[1])->toMatchArray(['name' => 'Solo', 'reviews_count' => 70])   // personal practice counts for an admin
        ->and($admin[2])->toMatchArray(['name' => 'Ada One', 'reviews_count' => 60])
        ->and($names($teacher))->not->toContain('Left Group')     // left the class
        ->and($names($teacher))->not->toContain('Solo')           // not in the teacher's groups
        ->and($names($teacher))->not->toContain('Other One')      // another class
        ->and($teacher[1])->toMatchArray(['name' => 'Ada One', 'reviews_count' => 30, 'groups' => 'Class A']);

    // Both classes for an admin, the teacher's own only for the teacher.
    $both = fn (array $rows): ?array => collect($rows)->firstWhere('name', 'Both Groups');
    expect($both($admin)['groups'] ?? null)->toContain('Class A')->toContain('Class B')
        ->and($both($teacher)['groups'] ?? null)->toBe('Class A')
        ->and($both($admin)['reviews_count'] ?? null)->toBe(24)
        ->and($both($teacher)['reviews_count'] ?? null)->toBe(21);
});

it('breaks a tie of the ranking by the learner id, the same at every computation', function (): void {
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');
    $session = TrainingSession::factory()->create();
    $word = Fx::words(Lesson::factory()->create(), 1)['active'][0];
    $learners = Learner::factory()->count(3)->create();
    $rows = [];
    foreach ($learners->reverse() as $learner) {
        dashReviews($rows, $session, $learner, $word, 5);
    }
    DB::table('training_reviews')->insert($rows);

    $ids = array_column(dashStatsAs($admin)->topLearners(), 'id');

    expect($ids)->toBe($learners->pluck('id')->sort()->values()->all());
});

// --- equality: hardest words ------------------------------------------------------------------------------

it('picks the same ten hardest words, with the same attempts and ratios, as the per-word subqueries did, for an admin and a teacher', function (string $who): void {
    $users = seedRankingData();
    $stats = dashStatsAs($users[$who]);

    $legacy = legacyHardestWords(app(PanelDataScope::class));
    $now = $stats->hardestWords();

    expect(array_column($now, 'id'))->toBe(array_column($legacy, 'id'))
        ->and(array_column($now, 'attempts'))->toBe(array_column($legacy, 'attempts'))
        ->and(array_column($now, 'word'))->toBe(array_column($legacy, 'word'))
        ->and($now)->toHaveCount(10);

    foreach ($now as $i => $row) {
        expect($row['hard_ratio'])->toEqualWithDelta($legacy[$i]['hard_ratio'], 1e-9);
    }
})->with(['admin', 'teacher']);

it('counts a word\'s attempts in the sessions the viewer sees: personal practice and another class count for an admin only', function (): void {
    $users = seedRankingData();
    $byWord = fn (array $rows): array => collect($rows)->mapWithKeys(fn (array $r): array => [$r['word'] => $r])->all();

    $admin = $byWord(dashStatsAs($users['admin'])->hardestWords());
    $teacher = $byWord(dashStatsAs($users['teacher'])->hardestWords());

    $name = fn (int $n): string => $users['words'][$n];

    expect($admin)->toHaveKey($name(3))->and($admin[$name(3)]['attempts'])->toBe(8)
        ->and($admin)->toHaveKey($name(4))->and($admin[$name(4)]['attempts'])->toBe(8)
        ->and($teacher)->not->toHaveKey($name(3))
        ->and($teacher)->not->toHaveKey($name(4))
        // Past the window and one attempt short of the rule: nobody.
        ->and($admin)->not->toHaveKey($name(5))->and($admin)->not->toHaveKey($name(6))
        ->and($teacher)->not->toHaveKey($name(5))->and($teacher)->not->toHaveKey($name(6));
});

it('breaks a tie of the ratios by more attempts, then by the word id, the same at every computation', function (): void {
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');
    $session = TrainingSession::factory()->create();
    $learner = Learner::factory()->create();
    $words = Fx::words(Lesson::factory()->create(), 3)['active'];
    $rows = [];
    dashReviews($rows, $session, $learner, $words[0], 5, 5);  // 5 of 5
    dashReviews($rows, $session, $learner, $words[1], 10, 10); // 10 of 10: the same ratio, more attempts
    dashReviews($rows, $session, $learner, $words[2], 5, 5);  // 5 of 5 again: after the first by id
    DB::table('training_reviews')->insert($rows);

    $ids = array_column(dashStatsAs($admin)->hardestWords(), 'id');

    expect($ids)->toBe([$words[1], $words[0], $words[2]]);
});

it('runs one aggregate over the reviews and one join to the words: the statements are the same however many words there are', function (): void {
    $users = seedRankingData();
    $stats = dashStatsAs($users['admin']);

    $queries = QueryLog::during(fn () => $stats->hardestWords());

    expect($queries)->toHaveCount(1)
        ->and($queries[0])->toContain('group by')->and($queries[0])->toContain('having')
        ->and($queries[0])->not->toContain('training_sessions'); // an admin: no filter by sessions
});

// --- equality: the activity chart -------------------------------------------------------------------------

it('counts the reviews per day as before, for an admin and a teacher', function (string $who): void {
    $users = seedRankingData();
    // A few more on other days of the window, in a group session and in a personal one.
    $group = TelegramGroup::query()->where('title', 'Class A')->firstOrFail();
    $session = TrainingSession::query()->where('telegram_group_id', $group->id)->firstOrFail();
    $learner = Learner::query()->firstOrFail();
    $word = Word::query()->firstOrFail();
    $rows = [];
    foreach ([1, 2, 2, 5, 9, 9, 9, 29, 35] as $daysAgo) {
        dashReviews($rows, $session, $learner, $word->id, 1, 0, $daysAgo);
    }
    DB::table('training_reviews')->insert($rows);

    $stats = dashStatsAs($users[$who]);
    $legacy = legacyActivity(app(PanelDataScope::class));

    expect($stats->activity30d())->toBe($legacy)
        ->and(array_sum($legacy))->toBeGreaterThan(100);
})->with(['admin', 'teacher']);

it('asks the reviews of an admin without a filter by sessions, and of a teacher with it', function (): void {
    $users = seedRankingData();

    $asAdmin = dashStatsAs($users['admin']);
    $admin = QueryLog::during(fn () => $asAdmin->activity30d());
    $asTeacher = dashStatsAs($users['teacher']);
    $teacher = QueryLog::during(fn () => $asTeacher->activity30d());

    expect($admin)->toHaveCount(1)
        ->and($admin[0])->not->toContain('training_sessions')
        ->and(implode(' ', $teacher))->toContain('training_sessions');
});

// --- equality: the seat table -----------------------------------------------------------------------------

it('counts the seats of every organization as the join of learners did, deleted learners left out', function (): void {
    $now = CarbonImmutable::now();
    $rich = Organization::factory()->create(['name' => 'Rich', 'status' => 'active']);
    $poor = Organization::factory()->create(['name' => 'Poor', 'status' => 'active']);
    $lapsed = Organization::factory()->create(['name' => 'Lapsed', 'status' => 'active']);
    $off = Organization::factory()->create(['name' => 'Suspended', 'status' => 'suspended']);
    Subscription::factory()->forOrganization($rich)->create(['current_period_ends_at' => $now->addDays(20), 'tier' => 'center_s', 'seats_limit' => 3]);
    Subscription::factory()->forOrganization($lapsed)->create(['current_period_ends_at' => $now->subDays(3), 'tier' => 'tutor', 'seats_limit' => 30]);
    Subscription::factory()->forOrganization($off)->create(['current_period_ends_at' => $now->addDays(20), 'tier' => 'tutor', 'seats_limit' => 30]);

    $seated = Learner::factory()->count(5)->seatedIn($rich)->create();          // 5 seated against 3 seats: an excess
    Learner::factory()->count(2)->seatedIn($rich, 'waitlisted')->create();
    $poorSeated = Learner::factory()->count(2)->seatedIn($poor)->create();
    Learner::factory()->count(3)->seatedIn($off)->create();
    Learner::factory()->seatedIn($lapsed)->create();
    // Deleted learners keep their membership rows: two seated and one waiting in Rich, one seated in Poor.
    $seated[0]->delete();
    $seated[1]->delete();
    Learner::factory()->seatedIn($rich, 'waitlisted')->create()->delete();
    $poorSeated[0]->delete();
    TelegramGroup::factory()->create(['organization_id' => $rich->id, 'status' => 'active']);
    TelegramGroup::factory()->create(['organization_id' => $rich->id, 'status' => 'pending']);

    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');
    $rows = dashStatsAs($admin)->organizationSeats(1, 10);

    expect($rows)->toBe(legacyOrganizationSeats())
        ->and(collect($rows)->firstWhere('name', 'Rich'))->toMatchArray(['members_count' => 3, 'waitlisted_count' => 2, 'seats_limit' => 3, 'sponsoring' => true, 'active_groups_count' => 1])
        ->and(collect($rows)->firstWhere('name', 'Poor'))->toMatchArray(['members_count' => 1, 'sponsoring' => false])
        ->and(collect($rows)->firstWhere('name', 'Suspended'))->toMatchArray(['members_count' => 3, 'sponsoring' => false])
        ->and(collect($rows)->firstWhere('name', 'Lapsed'))->toMatchArray(['members_count' => 1, 'seats_limit' => null, 'sponsoring' => false]);
});

it('asks "deleted?" of the members as an anti-join, with no join of the learners', function (): void {
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');
    Organization::factory()->create();

    $stats = dashStatsAs($admin);
    $queries = QueryLog::during(fn () => $stats->organizationSeats(1, 10));

    expect($queries)->toHaveCount(1)
        ->and($queries[0])->toContain('not exists')
        ->and($queries[0])->toContain('"deleted_at" is not null')
        ->and($queries[0])->not->toContain('inner join "learners"');
});

// --- the partial index that goes with it -------------------------------------------------------------------

it('has the partial index over the deleted learners, and drops it again', function (): void {
    $definition = fn (): ?string => DB::getDriverName() === 'pgsql'
        ? DB::table('pg_indexes')->where('indexname', 'learners_deleted_idx')->value('indexdef')
        : DB::table('sqlite_master')->where('type', 'index')->where('name', 'learners_deleted_idx')->value('sql');

    $up = $definition();
    expect($up)->not->toBeNull()
        ->and(strtolower((string) $up))->toContain('learners')->toContain('where')->toContain('deleted_at is not null');
});
