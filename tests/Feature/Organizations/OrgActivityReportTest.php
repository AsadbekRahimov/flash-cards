<?php

declare(strict_types=1);

use App\Domain\Organizations\DTO\DateRange;
use App\Domain\Organizations\Exceptions\ReportRangeException;
use App\Domain\Organizations\Services\OrgActivityReport;
use App\Domain\Organizations\Services\OrgActivityReportCsv;
use App\Models\Assignment;
use App\Models\AssignmentResult;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\Lesson;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\TelegramGroup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
 * OrgActivityReport (docs/13 §2.3, §6.4, DoD 2B.5): the figures equal the sums of learner_daily_stats
 * over a period whose both ends are inclusive; assignments count by due date in the center's
 * timezone; a learner of another organization never appears; a teacher sees only their groups;
 * no session-level table is read.
 */

uses(RefreshDatabase::class);

function rpStats(Learner $learner, string $date, int $reviews, int $newWords, bool $goal = false): void
{
    LearnerDailyStats::factory()->create([
        'learner_id' => $learner->id,
        'date' => $date,
        'reviews_count' => $reviews,
        'new_words_count' => $newWords,
        'goal_met_at' => $goal ? CarbonImmutable::parse($date.' 10:00:00') : null,
    ]);
}

beforeEach(function (): void {
    $this->now = CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'); // 17:00 in Tashkent
    $this->range = DateRange::between('2026-10-10', '2026-10-16');

    $this->org = Organization::factory()->create(['timezone' => 'Asia/Tashkent', 'slug' => 'center-a']);
    $this->other = Organization::factory()->create(['timezone' => 'Asia/Tashkent']);

    $this->owner = User::factory()->create();
    OrganizationUser::factory()->create(['organization_id' => $this->org->id, 'user_id' => $this->owner->id]);
    $this->teacher = User::factory()->create();
    OrganizationUser::factory()->teacher()->create(['organization_id' => $this->org->id, 'user_id' => $this->teacher->id]);

    $this->g1 = TelegramGroup::factory()->create(['organization_id' => $this->org->id, 'title' => 'Alpha']);
    $this->g2 = TelegramGroup::factory()->create(['organization_id' => $this->org->id, 'title' => 'Beta']);
    $this->gOther = TelegramGroup::factory()->create(['organization_id' => $this->other->id, 'title' => 'Foreign']);
    $this->teacher->telegramGroups()->attach($this->g1->id, ['is_primary' => true]);

    $this->l1 = Learner::factory()->memberOf($this->g1)->seatedIn($this->org)->create(['first_name' => 'Anna', 'last_name' => null, 'username' => null, 'streak_current' => 5, 'streak_last_date' => '2026-10-19', 'last_seen_at' => '2026-10-19 08:00:00']);
    $this->l2 = Learner::factory()->memberOf($this->g2)->seatedIn($this->org)->create(['first_name' => 'Boris', 'last_name' => null, 'username' => null, 'streak_current' => 9, 'streak_last_date' => '2026-10-17']);
    $this->l3 = Learner::factory()->memberOf($this->g1)->seatedIn($this->org, 'waitlisted')->create(['first_name' => 'Clara', 'last_name' => null, 'username' => null]);
    $this->l4 = Learner::factory()->memberOf($this->g1, 'left')->seatedIn($this->org)->create(['first_name' => 'Left', 'last_name' => null, 'username' => null]);
    $this->notMember = Learner::factory()->memberOf($this->g1)->create(['first_name' => 'NotInOrg']); // in the group, but no organization_members row
    $this->foreign = Learner::factory()->memberOf($this->gOther)->seatedIn($this->other)->create(['first_name' => 'Foreign']);

    // l1: boundaries 10-10 and 10-16 count, 10-09 and 10-17 do not; 10-13 is a row without activity.
    rpStats($this->l1, '2026-10-09', 100, 10, true);
    rpStats($this->l1, '2026-10-10', 5, 2, true);
    rpStats($this->l1, '2026-10-13', 0, 0);
    rpStats($this->l1, '2026-10-16', 7, 1, true);
    rpStats($this->l1, '2026-10-17', 50, 5, true);
    rpStats($this->l2, '2026-10-12', 0, 4);
    rpStats($this->l3, '2026-10-14', 3, 0);
    rpStats($this->l4, '2026-10-14', 77, 0);
    rpStats($this->notMember, '2026-10-14', 66, 0);
    rpStats($this->foreign, '2026-10-14', 55, 0);

    $lesson = Lesson::factory()->create();
    $mk = fn (TelegramGroup $g, Organization $o, string $due): Assignment => Assignment::factory()->create(['organization_id' => $o->id, 'telegram_group_id' => $g->id, 'lesson_id' => $lesson->id, 'due_at' => $due]);
    $as1 = $mk($this->g1, $this->org, '2026-10-12 12:00:00');
    $asLate = $mk($this->g1, $this->org, '2026-10-20 12:00:00');            // outside the period
    $asEdgeIn = $mk($this->g1, $this->org, '2026-10-16 18:59:59');          // 23:59:59 Tashkent on the last day
    $asEdgeOut = $mk($this->g1, $this->org, '2026-10-16 19:00:00');         // 00:00 on 10-17 Tashkent
    $as3 = $mk($this->g2, $this->org, '2026-10-11 12:00:00');
    $asForeign = $mk($this->gOther, $this->other, '2026-10-12 12:00:00');

    AssignmentResult::factory()->completed()->create(['assignment_id' => $as1->id, 'learner_id' => $this->l1->id]);
    AssignmentResult::factory()->create(['assignment_id' => $as1->id, 'learner_id' => $this->l3->id]);
    AssignmentResult::factory()->completed()->create(['assignment_id' => $as1->id, 'learner_id' => $this->l4->id]);
    AssignmentResult::factory()->completed()->create(['assignment_id' => $asLate->id, 'learner_id' => $this->l1->id]);
    AssignmentResult::factory()->create(['assignment_id' => $asEdgeIn->id, 'learner_id' => $this->l1->id]);
    AssignmentResult::factory()->completed()->create(['assignment_id' => $asEdgeOut->id, 'learner_id' => $this->l1->id]);
    AssignmentResult::factory()->completed()->create(['assignment_id' => $as3->id, 'learner_id' => $this->l2->id]);
    AssignmentResult::factory()->completed()->create(['assignment_id' => $asForeign->id, 'learner_id' => $this->foreign->id]);

    $this->report = app(OrgActivityReport::class);
    $this->byName = fn (array $rows): array => collect($rows)->keyBy('name')->all();
});

it('matches learner_daily_stats for the owner, both ends of the period inclusive', function (): void {
    $rows = ($this->byName)($this->report->perLearner($this->owner, $this->org, null, $this->range, $this->now));

    expect(array_keys($rows))->toEqualCanonicalizing(['Anna', 'Boris', 'Clara', 'Left']);

    $anna = $rows['Anna'];
    expect($anna->reviews)->toBe(12)
        ->and($anna->newWords)->toBe(3)
        ->and($anna->daysActive)->toBe(2)
        ->and($anna->goalMetDays)->toBe(2)
        ->and($anna->streakCurrent)->toBe(5)
        ->and($anna->assignmentsDone)->toBe(1)
        ->and($anna->assignmentsTotal)->toBe(2)
        ->and($anna->lastSeenAt?->toDateTimeString())->toBe('2026-10-19 08:00:00');

    $boris = $rows['Boris'];
    expect($boris->reviews)->toBe(0)
        ->and($boris->newWords)->toBe(4)
        ->and($boris->daysActive)->toBe(1)
        ->and($boris->goalMetDays)->toBe(0)
        ->and($boris->streakCurrent)->toBe(0) // last credited day 10-17 is older than yesterday (10-19)
        ->and($boris->assignmentsDone)->toBe(1)
        ->and($boris->assignmentsTotal)->toBe(1)
        ->and($boris->lastSeenAt)->toBeNull();

    $clara = $rows['Clara']; // waitlisted members are still members of the center
    expect($clara->reviews)->toBe(3)
        ->and($clara->daysActive)->toBe(1)
        ->and($clara->assignmentsDone)->toBe(0)
        ->and($clara->assignmentsTotal)->toBe(1);
});

it('sorts by reviews descending', function (): void {
    $names = array_map(fn ($r) => $r->name, $this->report->perLearner($this->owner, $this->org, null, $this->range, $this->now));

    expect($names)->toBe(['Left', 'Anna', 'Clara', 'Boris']);
});

it('never includes learners of another organization or non-members; a left group member keeps no assignments', function (): void {
    $ids = array_map(fn ($r) => $r->learnerId, $this->report->perLearner($this->owner, $this->org, null, $this->range, $this->now));

    expect($ids)->not->toContain($this->foreign->id)->not->toContain($this->notMember->id);

    // A foreign group handed in by mistake yields nothing for this organization's owner.
    expect($this->report->perLearner($this->owner, $this->org, $this->gOther, $this->range, $this->now))->toBe([]);
    expect($this->report->perLearner($this->owner, $this->other, null, $this->range, $this->now))->toBe([]); // not staff there
});

it('filters one group for the owner', function (): void {
    $rows = $this->report->perLearner($this->owner, $this->org, $this->g2, $this->range, $this->now);

    expect(array_map(fn ($r) => $r->name, $rows))->toBe(['Boris']);
});

it('shows a teacher only the learners of their own groups', function (): void {
    $rows = $this->report->perLearner($this->teacher, $this->org, null, $this->range, $this->now);

    expect(array_map(fn ($r) => $r->name, $rows))->toBe(['Anna', 'Clara']);
    expect($this->report->perLearner($this->teacher, $this->org, $this->g2, $this->range, $this->now))->toBe([]);

    $groups = $this->report->perGroup($this->teacher, $this->org, $this->range);
    expect(array_map(fn ($r) => $r->title, $groups))->toBe(['Alpha']);
});

it('summarises the groups: learners, active learners, sums and assignments', function (): void {
    $groups = collect($this->report->perGroup($this->owner, $this->org, $this->range))->keyBy('title');

    expect($groups->keys()->all())->toBe(['Alpha', 'Beta']);

    $alpha = $groups['Alpha'];
    expect($alpha->learners)->toBe(2)           // Anna and Clara; Left and NotInOrg are not counted
        ->and($alpha->activeLearners)->toBe(2)
        ->and($alpha->reviews)->toBe(15)         // 12 + 3
        ->and($alpha->newWords)->toBe(3)
        ->and($alpha->goalMetDays)->toBe(2)
        ->and($alpha->assignmentsDone)->toBe(1)
        ->and($alpha->assignmentsTotal)->toBe(3); // Anna: as1 done + edge-in; Clara: as1

    $beta = $groups['Beta'];
    expect($beta->learners)->toBe(1)
        ->and($beta->activeLearners)->toBe(1)
        ->and($beta->reviews)->toBe(0)
        ->and($beta->newWords)->toBe(4)
        ->and($beta->assignmentsDone)->toBe(1)
        ->and($beta->assignmentsTotal)->toBe(1);
});

it('reports a group without any activity as zeros', function (): void {
    $empty = TelegramGroup::factory()->create(['organization_id' => $this->org->id, 'title' => 'Empty']);

    $row = collect($this->report->perGroup($this->owner, $this->org, $this->range))->firstWhere('title', 'Empty');

    expect($row->learners)->toBe(0)->and($row->reviews)->toBe(0)->and($row->assignmentsTotal)->toBe(0)
        ->and($row->groupId)->toBe($empty->id);
});

it('reads aggregates only: no session-level table is queried, and the query count does not grow with learners', function (): void {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->report->perLearner($this->owner, $this->org, null, $this->range, $this->now);
        $this->report->perGroup($this->owner, $this->org, $this->range);
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        foreach ($log as $entry) {
            expect($entry['query'])->not->toContain('training_sessions')->not->toContain('training_reviews');
        }

        return count($log);
    };

    $before = $count();

    foreach (range(1, 6) as $i) {
        $l = Learner::factory()->memberOf($this->g1)->seatedIn($this->org)->create();
        rpStats($l, '2026-10-11', $i, 1);
    }

    expect($count())->toBe($before);
});

it('validates the period: ordering, format, at most 366 days', function (): void {
    expect(DateRange::between('2026-01-01', '2026-12-31')->days())->toBe(365)
        ->and(DateRange::between('2024-01-01', '2024-12-31')->days())->toBe(366)
        ->and(DateRange::between('2026-10-10', '2026-10-10')->days())->toBe(1);

    $code = function (string $from, string $to): string {
        try {
            DateRange::between($from, $to);
        } catch (ReportRangeException $e) {
            return $e->errorCode;
        }

        return 'ok';
    };

    expect($code('2026-10-11', '2026-10-10'))->toBe('invalid_range')
        ->and($code('nope', '2026-10-10'))->toBe('invalid_range')
        ->and($code('2026-02-30', '2026-03-10'))->toBe('invalid_range')
        ->and($code('', ''))->toBe('invalid_range')
        ->and($code('2025-10-09', '2026-10-10'))->toBe('range_too_long');
});

it('builds "last N days" in the organization timezone, today included', function (): void {
    // 2026-10-20 22:00 UTC is already 10-21 03:00 in Tashkent.
    $range = DateRange::lastDays(7, $this->org, CarbonImmutable::parse('2026-10-20 22:00:00', 'UTC'));

    expect($range->from)->toBe('2026-10-15')->and($range->to)->toBe('2026-10-21')->and($range->days())->toBe(7);
});

it('writes a UTF-8 CSV with BOM, a semicolon delimiter, localized headers and neutralised formulas', function (): void {
    app()->setLocale('ru');
    $evil = Learner::factory()->memberOf($this->g1)->seatedIn($this->org)->create(['first_name' => '=HYPERLINK("x")', 'last_name' => 'Ёж', 'username' => 'evil']);
    rpStats($evil, '2026-10-11', 1, 0);
    $rows = $this->report->perLearner($this->owner, $this->org, null, $this->range, $this->now);

    $out = fopen('php://memory', 'w+b');
    app(OrgActivityReportCsv::class)->writeLearners($out, $this->org, $rows);
    rewind($out);
    $csv = (string) stream_get_contents($out);

    expect(str_starts_with($csv, "\xEF\xBB\xBF"))->toBeTrue();
    $lines = explode("\r\n", rtrim(substr($csv, 3), "\r\n"));

    expect(str_getcsv($lines[0], ';', '"', ''))->toBe(['Ученик', 'Username', 'Повторений', 'Новых слов', 'Дней активности', 'Дней с выполненной целью', 'Серия дней', 'Заданий выполнено', 'Заданий всего', 'Был в сети'])
        ->and($lines[2])->toBe('Anna;;12;3;2;2;5;1;2;"2026-10-19 13:00"')
        ->and($csv)->toContain("\"'=HYPERLINK(\"\"x\"\") Ёж\";evil;1;0;1;0;0;0;0;")
        ->and($lines)->toHaveCount(1 + count($rows));

    app()->setLocale('uz');
    $out = fopen('php://memory', 'w+b');
    app(OrgActivityReportCsv::class)->writeGroups($out, $this->report->perGroup($this->owner, $this->org, $this->range));
    rewind($out);
    $groupCsv = (string) stream_get_contents($out);

    expect($groupCsv)->toStartWith("\xEF\xBB\xBF")
        ->and(str_getcsv(explode("\r\n", substr($groupCsv, 3))[0], ';', '"', '')[0])->toBe('Guruh')
        ->and($groupCsv)->toContain("Alpha;3;3;16;3;2;1;3\r\n");
});
