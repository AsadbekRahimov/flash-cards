<?php

declare(strict_types=1);

use App\Domain\Administration\Services\WidgetCache;
use App\Domain\Analytics\Services\RetentionReportService;
use App\Domain\Learning\Services\TrainingSessionService;
use App\Domain\Operations\Support\PureCache;
use App\Models\GroupMember;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use App\Models\TrainingReview;
use App\Models\TrainingSession;
use App\Models\Word;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\QueryLog;

/*
 * The retention report (docs/ADMIN_GUIDE.md «Удержание»): cohorts by registration week (Monday-Sunday in Asia/Tashkent), D-N on
 * the learner's LOCAL days, a dash for a day that has not ended for the whole cohort, the first training session, the slice
 * by language and trial. The numbers are worked out by hand in the tests; the same file runs on sqlite and on PostgreSQL.
 */

uses(RefreshDatabase::class);

beforeEach(fn () => $this->travelTo(CarbonImmutable::parse(RT_NOW, 'UTC')));

// Wednesday 2026-11-18, 15:00 in Tashkent. Weeks (Mon-Sun, Tashkent): W0 Nov 16, W1 Nov 9, W2 Nov 2, W3 Oct 26, ..., W11 Aug 31.
const RT_NOW = '2026-11-18 10:00:00';

function rt_service(): RetentionReportService
{
    return app(RetentionReportService::class);
}

function rt_report(string $now = RT_NOW): array
{
    return rt_service()->compute(CarbonImmutable::parse($now, 'UTC'));
}

/** @param  array<string, mixed>  $attributes */
function rt_learner(string $createdUtc, array $attributes = []): Learner
{
    return Learner::factory()->create([...$attributes, 'created_at' => $createdUtc, 'updated_at' => $createdUtc]);
}

/** Studied (reviews_count > 0) on these local dates. */
function rt_active(Learner $learner, string ...$dates): void
{
    foreach ($dates as $date) {
        LearnerDailyStats::query()->create(['learner_id' => $learner->id, 'date' => $date, 'reviews_count' => 3]);
    }
}

/** One accepted learning answer of the learner at this UTC moment (a `training_reviews` row). */
function rt_answer(Learner $learner, string $atUtc): void
{
    TrainingReview::factory()->create(['learner_id' => $learner->id, 'created_at' => $atUtc]);
}

/** The learner joined a group at this UTC moment; the group belongs to a center unless `$center` is false. */
function rt_join(Learner $learner, string $joinedUtc, bool $center = true, string $status = 'active'): void
{
    $group = TelegramGroup::factory()->create(['organization_id' => $center ? Organization::factory()->create()->id : null]);
    GroupMember::factory()->create([
        'telegram_group_id' => $group->id,
        'learner_id' => $learner->id,
        'status' => $status,
        'joined_at' => $joinedUtc,
        'left_at' => $status === 'active' ? null : RT_NOW,
    ]);
}

/** @return array<string, mixed> the cohort of the week that starts on this Monday */
function rt_cohort(array $report, string $monday): array
{
    foreach ($report['cohorts'] as $cohort) {
        if ($cohort['week_start'] === $monday) {
            return $cohort;
        }
    }

    throw new RuntimeException("No cohort of {$monday}.");
}

/** A personal session of the learner; returns it. @param  array<string, mixed>  $attributes */
function rt_session(Learner $learner, string $startedUtc, array $attributes = []): TrainingSession
{
    return TrainingSession::factory()->personal($learner, TrainingSession::MODE_DAILY_REVIEW)->create([
        'started_at' => $startedUtc,
        'status' => 'closed',
        'ended_at' => $startedUtc,
        'done_reason' => TrainingSession::DONE_FINISHED,
        'expires_at' => null,
        ...$attributes,
    ]);
}

/** Graded cards of a session, with these times on the card (ms). */
function rt_reviews(TrainingSession $session, int ...$timesMs): void
{
    $word = Word::factory()->create();

    foreach ($timesMs as $ms) {
        TrainingReview::factory()->create([
            'training_session_id' => $session->id,
            'learner_id' => $session->learner_id,
            'word_id' => $word->id,
            'time_spent_ms' => $ms,
            'created_at' => $session->started_at,
        ]);
    }
}

it('counts D1, D3, D7, D14 and "within 7 days" of a cohort of known learners, and dashes what has not come yet', function (): void {
    // Week of Nov 2-8, everybody registered on the local day Nov 3 (11:00 in Tashkent).
    $a = rt_learner('2026-11-03 06:00:00', ['onboarding_completed_at' => '2026-11-03 06:10:00']);
    $b = rt_learner('2026-11-03 06:00:00', ['onboarding_completed_at' => '2026-11-03 06:10:00']);
    $c = rt_learner('2026-11-03 06:00:00');
    rt_learner('2026-11-03 06:00:00');

    rt_active($a, '2026-11-03', '2026-11-04', '2026-11-06', '2026-11-10'); // day 0 is not retention; days 1, 3, 7
    rt_active($b, '2026-11-04');                                              // day 1
    rt_active($c, '2026-11-12');                                              // day 9: neither D7 nor "within 7"
    LearnerDailyStats::query()->create(['learner_id' => $c->id, 'date' => '2026-11-04', 'reviews_count' => 0, 'game_runs_count' => 2]); // a game-only day is not studying

    rt_reviews(rt_session($a, '2026-11-03 06:20:00'), 1000);
    rt_reviews(rt_session($b, '2026-11-03 06:20:00'), 1000);

    $cohort = rt_cohort(rt_report(), '2026-11-02');

    expect($cohort['week_end'])->toBe('2026-11-08')
        ->and($cohort['current'])->toBeFalse()
        ->and($cohort['size'])->toBe(4)
        ->and($cohort['onboarded'])->toBe(2)
        ->and($cohort['started'])->toBe(2)
        ->and($cohort['started_pct'])->toBe(50.0)
        ->and($cohort['retention']['d1'])->toBe(['count' => 2, 'of' => 4, 'pct' => 50.0])
        ->and($cohort['retention']['d3'])->toBe(['count' => 1, 'of' => 4, 'pct' => 25.0])
        ->and($cohort['retention']['d7'])->toBe(['count' => 1, 'of' => 4, 'pct' => 25.0])
        // The youngest learner lived through 15 whole days (Nov 3 -> Nov 18): day 14 is over, day 30 is not.
        ->and($cohort['retention']['d14'])->toBe(['count' => 0, 'of' => 4, 'pct' => 0.0])
        ->and($cohort['retention']['d30'])->toBeNull()
        ->and($cohort['retention']['within'])->toBe(['count' => 2, 'of' => 4, 'pct' => 50.0]);
});

it('shows a dash, not 0 %, for a day that has not ended for the whole cohort, and for a week nobody registered in', function (): void {
    // Registered yesterday (local Nov 17): day 1 is today, still running.
    $fresh = rt_learner('2026-11-17 06:00:00');
    rt_active($fresh, '2026-11-18');

    $report = rt_report();
    $current = rt_cohort($report, '2026-11-16');
    $empty = rt_cohort($report, '2026-11-09');

    expect($current['current'])->toBeTrue()
        ->and($current['size'])->toBe(1)
        ->and($current['retention'])->each->toBeNull()
        ->and($empty['size'])->toBe(0)
        ->and($empty['started_pct'])->toBeNull()
        ->and($empty['retention'])->each->toBeNull()
        ->and($report['cohorts'])->toHaveCount(12);

    // The next day closes day 1 for him.
    $next = rt_cohort(rt_report('2026-11-19 10:00:00'), '2026-11-16');
    expect($next['retention']['d1'])->toBe(['count' => 1, 'of' => 1, 'pct' => 100.0])
        ->and($next['retention']['d3'])->toBeNull();
});

it('cuts the weeks at Monday 00:00 in Tashkent, keeps twelve of them and leaves out deleted learners', function (): void {
    rt_learner('2026-11-08 18:59:00');            // Sunday 23:59 in Tashkent: week of Nov 2
    rt_learner('2026-11-08 19:00:00');            // Monday 00:00: week of Nov 9
    rt_learner('2026-08-30 18:59:00');            // before the 12th week (Aug 31): not in the report
    rt_learner('2026-08-30 19:00:00');            // Monday Aug 31 00:00: the oldest week
    rt_learner('2026-11-04 06:00:00')->delete();  // deleted: not counted

    $report = rt_report();

    expect(rt_cohort($report, '2026-11-02')['size'])->toBe(1)
        ->and(rt_cohort($report, '2026-11-09')['size'])->toBe(1)
        ->and(rt_cohort($report, '2026-08-31')['size'])->toBe(1)
        ->and(array_sum(array_column($report['cohorts'], 'size')))->toBe(3)
        ->and($report['cohorts'][11]['week_start'])->toBe('2026-08-31')
        ->and($report['cohorts'][0]['week_start'])->toBe('2026-11-16');
});

it('reads the registration day in the learner\'s own zone, DST included', function (): void {
    // New York left DST on Nov 1: 04:30 UTC on Nov 2 is 23:30 EST on Nov 1 (with the summer offset it would be Nov 2 00:30).
    $newYork = rt_learner('2026-11-02 04:30:00', ['timezone' => 'America/New_York']);
    rt_active($newYork, '2026-11-02');            // the day after the local registration day: D1

    // The same instant in Tashkent is Nov 2 09:30, so a stats row of Nov 2 is day 0 there.
    $tashkent = rt_learner('2026-11-02 04:30:00');
    rt_active($tashkent, '2026-11-02');

    // Kiritimati (UTC+14): 12:00 UTC on Nov 2 is 02:00 on Nov 3 there; Nov 4 is D1, not D2.
    $kiritimati = rt_learner('2026-11-02 12:00:00', ['timezone' => 'Pacific/Kiritimati']);
    rt_active($kiritimati, '2026-11-04');

    $cohort = rt_cohort(rt_report(), '2026-11-02');

    expect($cohort['size'])->toBe(3)
        ->and($cohort['retention']['d1'])->toBe(['count' => 2, 'of' => 3, 'pct' => 66.7]);
});

it('closes a day by the clock of the learner who lives it last', function (): void {
    // Monday Nov 16, 13:00 in Tashkent... it is Tuesday Nov 17 08:00 UTC. A Tashkent learner of the week of Nov 9 has lived
    // through Nov 16 (day 1 of a registration on Nov 15) -> D1 is over for him.
    $now = '2026-11-17 08:00:00';
    $tashkent = rt_learner('2026-11-15 06:00:00');
    rt_active($tashkent, '2026-11-16');

    expect(rt_cohort(rt_report($now), '2026-11-09')['retention']['d1'])->toBe(['count' => 1, 'of' => 1, 'pct' => 100.0]);

    // A Samoa learner of the same week (registered Nov 15 18:00 UTC = Nov 15 07:00 there): at this moment it is still
    // Nov 16 21:00 for him, day 1 is running -> the whole week's D1 is a dash.
    rt_learner('2026-11-15 18:00:00', ['timezone' => 'Pacific/Pago_Pago']);

    $cohort = rt_cohort(rt_report($now), '2026-11-09');
    expect($cohort['size'])->toBe(2)
        ->and($cohort['retention']['d1'])->toBeNull();
});

it('does not fail on a timezone nobody knows: the learner is read in the default zone', function (): void {
    $odd = rt_learner('2026-11-03 06:00:00', ['timezone' => 'Mars/Phobos']);
    rt_active($odd, '2026-11-04');

    expect(rt_cohort(rt_report(), '2026-11-02')['retention']['d1'])->toBe(['count' => 1, 'of' => 1, 'pct' => 100.0]);
});

it('works out the first session: opened, finished, abandoned, medians and the second session in 24 hours and 7 days', function (): void {
    // Everybody registered inside the last 30 days. now = 2026-11-18 10:00 UTC.
    $l1 = rt_learner('2026-11-05 08:00:00');
    $l2 = rt_learner('2026-11-05 08:00:00');
    $l3 = rt_learner('2026-11-17 08:00:00');
    $l4 = rt_learner('2026-11-06 08:00:00');
    $l5 = rt_learner('2026-11-06 08:00:00');
    $l6 = rt_learner('2026-11-18 06:00:00');
    rt_learner('2026-09-01 08:00:00');                                    // registered long ago: out of the 30 days

    // L1: finished, 3 cards in 60 s of card time; the second session 5 hours after the first began.
    rt_reviews(rt_session($l1, '2026-11-08 08:00:00'), 10_000, 20_000, 30_000);
    rt_session($l1, '2026-11-08 13:00:00');
    // L2: expired, 1 card (5 s); the second session 3 days later.
    rt_reviews(rt_session($l2, '2026-11-08 08:00:00', ['done_reason' => TrainingSession::DONE_EXPIRED]), 5_000);
    rt_session($l2, '2026-11-11 08:00:00');
    // L3: open and still alive: in progress, no cards.
    rt_session($l3, '2026-11-18 09:00:00', ['status' => 'open', 'done_reason' => null, 'ended_at' => null, 'expires_at' => '2026-11-18 21:00:00']);
    // L4: left open past its expiry (the scheduler has not run): abandoned, 2 cards (8 s).
    rt_reviews(rt_session($l4, '2026-11-09 08:00:00', ['status' => 'open', 'done_reason' => null, 'ended_at' => null, 'expires_at' => '2026-11-09 20:00:00']), 4_000, 4_000);
    // L5 never opened a session. L6: finished 2 hours ago, 1 card (7 s): too young for the "second session" bases.
    rt_reviews(rt_session($l6, '2026-11-18 08:00:00'), 7_000);
    // A group session of a teacher is nobody's first session.
    TrainingSession::factory()->create(['started_at' => '2026-11-08 08:00:00']);

    $first = rt_report()['first_session'];

    expect($first['since'])->toBe('2026-10-19')
        ->and($first['registered'])->toBe(6)
        ->and($first['opened'])->toBe(['count' => 5, 'of' => 6, 'pct' => 83.3])
        ->and($first['finished'])->toBe(['count' => 2, 'of' => 5, 'pct' => 40.0])
        ->and($first['abandoned'])->toBe(['count' => 2, 'of' => 5, 'pct' => 40.0])
        ->and($first['in_progress'])->toBe(1)
        // Cards of the opened first sessions: 3, 1, 0, 2, 1 -> median 1; card time of those with cards: 5, 7, 8, 60 s -> 7.5.
        ->and($first['median_cards'])->toBe(1.0)
        ->and($first['median_seconds'])->toBe(7.5)
        // First sessions begun a day ago or earlier: L1, L2, L4 -> 1 of 3 came back within 24 hours; 7 days: L1, L2.
        ->and($first['second_24h'])->toBe(['count' => 1, 'of' => 3, 'pct' => 33.3])
        ->and($first['second_7d'])->toBe(['count' => 2, 'of' => 3, 'pct' => 66.7])
        // Nobody played another round at once: first sessions begun an hour ago or earlier are L1, L2, L3, L4, L6.
        ->and($first['round_again'])->toBe(['count' => 0, 'of' => 5, 'pct' => 0.0]);
});

it('counts a round played to the end but never finished by the client as finished once the scheduler closed it, not as abandoned', function (): void {
    // Registered ten days ago; the first session was played through (completed on the server, docs/06 §13.2) and the Mini App
    // was closed on the last verdict: no `/finish`. Left open past its expiry, then closed by the scheduler.
    $learner = rt_learner('2026-11-08 08:00:00');
    $session = rt_session($learner, '2026-11-08 09:00:00', [
        'status' => 'open', 'done_reason' => null, 'ended_at' => null, 'expires_at' => '2026-11-08 21:00:00', 'completed_at' => '2026-11-08 09:20:00',
    ]);
    rt_reviews($session, 3_000, 3_000);
    // Another learner's first session, abandoned in the middle the same way (no completion), for contrast.
    $other = rt_learner('2026-11-08 08:00:00');
    rt_reviews(rt_session($other, '2026-11-08 09:00:00', ['status' => 'open', 'done_reason' => null, 'ended_at' => null, 'expires_at' => '2026-11-08 21:00:00']), 2_000);

    // Before the scheduler: both are open past their expiry - the report reads them as abandoned (a dash would hide nothing).
    expect(rt_report()['first_session']['finished'])->toBe(['count' => 0, 'of' => 2, 'pct' => 0.0]);

    expect(app(TrainingSessionService::class)->closeExpired(CarbonImmutable::parse(RT_NOW, 'UTC')))->toBe(2);
    $first = rt_report()['first_session'];
    expect($session->fresh()?->done_reason)->toBe(TrainingSession::DONE_FINISHED)
        ->and($first['finished'])->toBe(['count' => 1, 'of' => 2, 'pct' => 50.0])
        ->and($first['abandoned'])->toBe(['count' => 1, 'of' => 2, 'pct' => 50.0])
        ->and($first['in_progress'])->toBe(0);
});

it('tells "one more round at once" from "came back": a second session within an hour is a round, a later one is a return', function (): void {
    // now = 2026-11-18 10:00 UTC; every first session began two days ago (all are old enough for both bases).
    $round = rt_learner('2026-11-10 08:00:00');
    $back = rt_learner('2026-11-10 08:00:00');
    $both = rt_learner('2026-11-10 08:00:00');
    $alone = rt_learner('2026-11-10 08:00:00');

    // Another round 5 minutes after the first one: not a return.
    rt_session($round, '2026-11-10 08:00:00');
    rt_session($round, '2026-11-10 08:05:00');
    // Back after 2 hours: a return, not a round.
    rt_session($back, '2026-11-10 08:00:00');
    rt_session($back, '2026-11-10 10:00:00');
    // A round at once AND, later the same day, a return: both count (the round does not hide the return).
    rt_session($both, '2026-11-10 08:00:00');
    rt_session($both, '2026-11-10 08:03:00');
    rt_session($both, '2026-11-10 18:00:00');
    // One session and nothing else.
    rt_session($alone, '2026-11-10 08:00:00');

    $first = rt_report()['first_session'];

    expect($first['round_again'])->toBe(['count' => 2, 'of' => 4, 'pct' => 50.0])
        ->and($first['second_24h'])->toBe(['count' => 2, 'of' => 4, 'pct' => 50.0])
        ->and($first['second_7d'])->toBe(['count' => 2, 'of' => 4, 'pct' => 50.0]);
});

it('counts a return only a round-gap after the first session began: exactly at the gap it is a return, a minute earlier a round', function (): void {
    $edge = rt_learner('2026-11-10 08:00:00');
    $early = rt_learner('2026-11-10 08:00:00');
    rt_session($edge, '2026-11-10 08:00:00');
    rt_session($edge, '2026-11-10 09:00:00');
    rt_session($early, '2026-11-10 08:00:00');
    rt_session($early, '2026-11-10 08:59:00');

    $first = rt_report()['first_session'];

    expect($first['round_again'])->toBe(['count' => 1, 'of' => 2, 'pct' => 50.0])
        ->and($first['second_24h'])->toBe(['count' => 1, 'of' => 2, 'pct' => 50.0]);
});

it('has nothing to show for the first session when nobody registered: dashes and no median', function (): void {
    $first = rt_report()['first_session'];

    expect($first['registered'])->toBe(0)
        ->and($first['opened'])->toBeNull()
        ->and($first['finished'])->toBeNull()
        ->and($first['second_24h'])->toBeNull()
        ->and($first['median_cards'])->toBeNull()
        ->and($first['median_seconds'])->toBeNull();
});

it('slices D1 and D7 of the last four weeks by language and by a trial, over the weeks whose day has ended', function (): void {
    $trial = fn (Learner $learner) => Subscription::factory()->create(['subscriber_id' => $learner->id, 'provider' => 'trial']);

    $ru1 = rt_learner('2026-11-03 06:00:00');                                  // W2, ru, trial: D1 and D7
    $trial($ru1);
    rt_active($ru1, '2026-11-04', '2026-11-10');
    $ru2 = rt_learner('2026-10-28 06:00:00');                                  // W3, ru, a manual grant is no trial: D1 only
    Subscription::factory()->create(['subscriber_id' => $ru2->id, 'provider' => 'manual']);
    rt_active($ru2, '2026-10-29');
    rt_learner('2026-11-03 06:00:00', ['interface_locale' => 'uz']);           // W2, uz, nothing
    $uz2 = rt_learner('2026-10-28 06:00:00', ['interface_locale' => 'uz']);    // W3, uz, trial: D1 and D7
    $trial($uz2);
    rt_active($uz2, '2026-10-29', '2026-11-04');
    $young = rt_learner('2026-11-17 02:00:00');                                // W0: day 1 is still running, left out
    rt_active($young, '2026-11-18');

    $segments = rt_report()['segments'];
    $byKey = fn (string $group) => collect($segments[$group])->keyBy('key')->all();
    $locale = $byKey('locale');
    $trials = $byKey('trial');

    expect($segments['weeks'])->toBe(4)
        ->and($locale['ru']['size'])->toBe(3)
        ->and($locale['ru']['d1'])->toBe(['count' => 2, 'of' => 2, 'pct' => 100.0])
        ->and($locale['ru']['d7'])->toBe(['count' => 1, 'of' => 2, 'pct' => 50.0])
        ->and($locale['uz']['size'])->toBe(2)
        ->and($locale['uz']['d1'])->toBe(['count' => 1, 'of' => 2, 'pct' => 50.0])
        ->and($locale['uz']['d7'])->toBe(['count' => 1, 'of' => 2, 'pct' => 50.0])
        ->and($trials['yes']['size'])->toBe(2)
        ->and($trials['yes']['d1'])->toBe(['count' => 2, 'of' => 2, 'pct' => 100.0])
        ->and($trials['yes']['d7'])->toBe(['count' => 2, 'of' => 2, 'pct' => 100.0])
        ->and($trials['no']['size'])->toBe(3)
        ->and($trials['no']['d1'])->toBe(['count' => 1, 'of' => 2, 'pct' => 50.0])
        ->and($trials['no']['d7'])->toBe(['count' => 0, 'of' => 2, 'pct' => 0.0]);
});

it('has dashes in the slice when no week of the last four has ended the day yet', function (): void {
    rt_learner('2026-11-17 02:00:00');

    foreach (rt_report()['segments']['locale'] as $row) {
        expect($row['d1'])->toBeNull()->and($row['d7'])->toBeNull();
    }
});

it('runs the same number of queries for 5 learners and for 60', function (): void {
    $queries = function (int $learners): int {
        foreach (range(1, $learners) as $i) {
            $zone = ['Asia/Tashkent', 'America/New_York', 'Europe/London'][$i % 3];
            $learner = rt_learner('2026-11-0'.(3 + $i % 4).' 06:00:00', ['timezone' => $zone, 'interface_locale' => $i % 2 === 0 ? 'uz' : 'ru']);
            rt_active($learner, '2026-11-0'.(4 + $i % 4));
            LearnerDailyStats::query()->create(['learner_id' => $learner->id, 'date' => '2026-11-1'.($i % 4), 'reviews_count' => 0, 'game_runs_count' => 1]);
            rt_reviews(rt_session($learner, '2026-11-08 08:00:00'), 1000);
            if ($i % 3 === 0) {
                rt_join($learner, '2026-11-0'.(3 + $i % 4).' 07:00:00');
            }
        }

        return count(QueryLog::during(fn () => rt_report()));
    };

    $few = $queries(5);
    $many = $queries(55);

    // Zones, cohorts (with the activation), retention (with the game days), first-session totals and histograms: the same five
    // statements as before the activation and the game row were added.
    expect($few)->toBe($many)->and($few)->toBe(5);
});

it('serves the second read from the cache and recomputes after forget()', function (): void {
    rt_learner('2026-11-03 06:00:00');
    $service = rt_service();

    $first = $service->report();
    expect(QueryLog::during(fn () => $service->report()))->toBe([]);

    rt_learner('2026-11-03 06:00:00');
    expect($service->report())->toBe($first);       // still the cached figures

    $service->forget();
    expect(rt_cohort($service->report(), '2026-11-02')['size'])->toBe(2);
});

it('casts the numbers read back from the cache: a string where a number was stored does not break strict types', function (): void {
    rt_learner('2026-11-03 06:00:00');
    $service = rt_service();
    $service->report();

    // Redis hands a number over as a string: put the figures back the way it would, nested ones included.
    $key = WidgetCache::key(RetentionReportService::CACHE_WIDGET, 'all');
    $stored = PureCache::store()->get($key);
    $stored['cohorts'][2]['size'] = '1';
    $stored['cohorts'][2]['retention']['d1'] = ['count' => '0', 'of' => '1', 'pct' => '0'];
    $stored['first_session']['registered'] = '7';
    $stored['first_session']['median_cards'] = '2.5';
    PureCache::store()->put($key, $stored, 600);

    $report = $service->report();

    expect($report['cohorts'][2]['size'])->toBe(1)
        ->and($report['cohorts'][2]['retention']['d1'])->toBe(['count' => 0, 'of' => 1, 'pct' => 0])
        ->and($report['first_session']['registered'])->toBe(7)
        ->and($report['first_session']['median_cards'])->toBe(2.5)
        ->and($report['cohorts'][2]['week_start'])->toBeString();
});

it('drops a cached value that is not a report of this version and computes again', function (): void {
    rt_learner('2026-11-03 06:00:00');
    PureCache::store()->put(WidgetCache::key(RetentionReportService::CACHE_WIDGET, 'all'), ['unexpected' => 1], 600);

    $report = rt_service()->report();

    expect($report)->toHaveKeys(['cohorts', 'segments', 'first_session'])
        ->and(rt_cohort($report, '2026-11-02')['size'])->toBe(1);
});

it('counts the activation as the first accepted answer earlier than 24 hours after registration: 23 h 59 yes, 24 h 00 and 24 h 01 no', function (): void {
    // Week of Nov 2-8, registered at 06:00 UTC on Nov 3.
    $justInTime = rt_learner('2026-11-03 06:00:00');
    rt_answer($justInTime, '2026-11-04 05:59:00');                 // 23 h 59
    $exactly = rt_learner('2026-11-03 06:00:00');
    rt_answer($exactly, '2026-11-04 06:00:00');                    // 24 h 00: not "within 24 hours"
    $late = rt_learner('2026-11-03 06:00:00');
    rt_answer($late, '2026-11-04 06:01:00');                       // 24 h 01
    rt_learner('2026-11-03 06:00:00');                             // never answered
    $uz = rt_learner('2026-11-03 06:00:00', ['interface_locale' => 'uz']);
    rt_answer($uz, '2026-11-03 07:00:00');                         // within the hour
    rt_answer($uz, '2026-11-05 07:00:00');                         // a later answer changes nothing

    $cohort = rt_cohort(rt_report(), '2026-11-02');

    expect($cohort['started'])->toBe(4)
        ->and($cohort['activation']['all'])->toBe(['count' => 2, 'of' => 5, 'pct' => 40.0])
        ->and($cohort['activation']['ru'])->toBe(['count' => 1, 'of' => 4, 'pct' => 25.0])
        ->and($cohort['activation']['uz'])->toBe(['count' => 1, 'of' => 1, 'pct' => 100.0])
        // Nobody joined a group: everybody is "solo", the class cut has no base.
        ->and($cohort['activation']['solo'])->toBe(['count' => 2, 'of' => 5, 'pct' => 40.0])
        ->and($cohort['activation']['class'])->toBeNull();
});

it('cuts the activation by solo and class: a group of a center joined within 24 hours makes the learner "class"', function (): void {
    $classActive = rt_learner('2026-11-03 06:00:00');
    rt_join($classActive, '2026-11-04 05:59:00');                  // a center's group at 23 h 59: class
    rt_answer($classActive, '2026-11-03 07:00:00');
    $classLeft = rt_learner('2026-11-03 06:00:00');
    rt_join($classLeft, '2026-11-03 08:00:00', status: 'left');    // joined in time and left later: still class
    $lateJoin = rt_learner('2026-11-03 06:00:00');
    rt_join($lateJoin, '2026-11-04 06:01:00');                     // 24 h 01: solo
    $noCenter = rt_learner('2026-11-03 06:00:00');
    rt_join($noCenter, '2026-11-03 07:00:00', center: false);      // a group of no center: solo
    rt_answer($noCenter, '2026-11-03 08:00:00');

    $activation = rt_cohort(rt_report(), '2026-11-02')['activation'];

    expect($activation['all'])->toBe(['count' => 2, 'of' => 4, 'pct' => 50.0])
        ->and($activation['class'])->toBe(['count' => 1, 'of' => 2, 'pct' => 50.0])
        ->and($activation['solo'])->toBe(['count' => 1, 'of' => 2, 'pct' => 50.0])
        ->and($activation['uz'])->toBeNull();
});

it('leaves the activation of a week empty until 24 hours have passed since its youngest learner registered', function (): void {
    // now = 2026-11-18 10:00 UTC; week of Nov 16.
    $old = rt_learner('2026-11-16 08:00:00');
    rt_answer($old, '2026-11-16 09:00:00');
    rt_learner('2026-11-17 10:30:00');                              // 23 h 30 ago

    expect(rt_cohort(rt_report(), '2026-11-16')['activation'])->each->toBeNull();

    // Exactly 24 hours after the youngest registered, the week is known: 1 of 2.
    $at24 = rt_cohort(rt_report('2026-11-18 10:30:00'), '2026-11-16')['activation'];
    expect($at24['all'])->toBe(['count' => 1, 'of' => 2, 'pct' => 50.0])
        ->and($at24['ru'])->toBe(['count' => 1, 'of' => 2, 'pct' => 50.0]);
});

it('shows the game days apart from the learning days and never adds a game-only day into D1 / D7', function (): void {
    $both = rt_learner('2026-11-03 06:00:00');
    LearnerDailyStats::query()->create(['learner_id' => $both->id, 'date' => '2026-11-04', 'reviews_count' => 3, 'game_runs_count' => 2]); // day 1: studied and played
    LearnerDailyStats::query()->create(['learner_id' => $both->id, 'date' => '2026-11-10', 'reviews_count' => 0, 'game_runs_count' => 1]); // day 7: game only
    $gamer = rt_learner('2026-11-03 06:00:00');
    LearnerDailyStats::query()->create(['learner_id' => $gamer->id, 'date' => '2026-11-04', 'reviews_count' => 0, 'game_runs_count' => 4]); // day 1: game only
    rt_learner('2026-11-03 06:00:00');

    $report = rt_report();
    $cohort = rt_cohort($report, '2026-11-02');

    expect($cohort['retention']['d1'])->toBe(['count' => 1, 'of' => 3, 'pct' => 33.3])
        ->and($cohort['retention']['d7'])->toBe(['count' => 0, 'of' => 3, 'pct' => 0.0])
        ->and($cohort['retention']['within'])->toBe(['count' => 1, 'of' => 3, 'pct' => 33.3])
        ->and($cohort['game']['d1'])->toBe(['count' => 2, 'of' => 3, 'pct' => 66.7])
        ->and($cohort['game']['d7'])->toBe(['count' => 1, 'of' => 3, 'pct' => 33.3])
        ->and(array_keys($cohort['game']))->toBe(['d1', 'd7']);

    // The slice is about learning only: the game-only learner has no D1 there.
    $ru = collect($report['segments']['locale'])->keyBy('key')['ru'];
    expect($ru['d1'])->toBe(['count' => 1, 'of' => 3, 'pct' => 33.3]);
});

it('dashes the game row of a day that has not ended for the whole cohort', function (): void {
    $fresh = rt_learner('2026-11-17 06:00:00');
    LearnerDailyStats::query()->create(['learner_id' => $fresh->id, 'date' => '2026-11-18', 'reviews_count' => 0, 'game_runs_count' => 1]);

    expect(rt_cohort(rt_report(), '2026-11-16')['game'])->toBe(['d1' => null, 'd7' => null])
        ->and(rt_cohort(rt_report('2026-11-19 10:00:00'), '2026-11-16')['game']['d1'])->toBe(['count' => 1, 'of' => 1, 'pct' => 100.0]);
});
