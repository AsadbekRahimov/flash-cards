<?php

declare(strict_types=1);

/*
 * GET /api/twa/league/month and /league/month/me (docs/04 A.19): the exact JSON shapes, what of other learners leaves the
 * server (a first name, points, active days — nothing else), the viewer's fresh own line, the prizes in the interface
 * locale, the previous month; and `league_points` of POST /training/sessions/{id}/finish.
 */

use App\Domain\Engagement\Services\LeagueService;
use App\Domain\Engagement\Services\MonthlyRatingService;
use App\Domain\Twa\Services\JwtService;
use App\Models\LeagueMonth;
use App\Models\LeagueMonthCandidate;
use App\Models\LeagueMonthScore;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\TrainingReview;
use App\Models\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const MONTH_ME = ['rank', 'points', 'active_days', 'participant', 'eligible', 'missing' => ['active_days', 'account_days']];

beforeEach(function (): void {
    config([
        'leagues.timezone' => 'Asia/Tashkent',
        'leagues.monthly.daily_cap' => 300,
        'leagues.monthly.top' => 50,
        'leagues.monthly.places' => 3,
        'leagues.monthly.min_active_days' => 3,
        'leagues.monthly.min_account_days' => 7,
    ]);
    $this->travelTo(CarbonImmutable::parse('2026-11-16 05:00:00', 'UTC')); // 2026-11-16 10:00 Tashkent
});

function twmGet(Learner $learner, string $path): TestResponse
{
    $token = app(JwtService::class)->issue($learner->id)['token'];

    return test()->withHeaders(['Authorization' => 'Bearer '.$token])->getJson($path);
}

function twmLearner(array $attributes = []): Learner
{
    return Learner::factory()->onboarded()->create(['created_at' => '2026-09-01 00:00:00', ...$attributes]);
}

function twmDay(Learner $learner, string $date, int $reviews, int $correct): void
{
    LearnerDailyStats::factory()->create(['learner_id' => $learner->id, 'date' => $date, 'reviews_count' => $reviews, 'correct_count' => $correct]);
}

it('GET /league/month keeps its exact JSON shape and shows of others a first name and points only', function (): void {
    $me = twmLearner(['first_name' => 'Me', 'username' => 'me_secret', 'telegram_user_id' => 424242, 'interface_locale' => 'ru']);
    $rival = twmLearner(['first_name' => 'Rival', 'username' => 'rival_secret']);
    $anon = twmLearner(['first_name' => null]);
    twmDay($me, '2026-11-10', 10, 10);
    twmDay($me, '2026-11-11', 10, 10);
    twmDay($rival, '2026-11-10', 50, 50);
    twmDay($anon, '2026-11-10', 5, 5);
    // The scheduler has run for the others; my row will be refreshed by the request itself.
    app(MonthlyRatingService::class)->refresh(
        app(MonthlyRatingService::class)->currentMonth(CarbonImmutable::now()),
        CarbonImmutable::now(),
        full: true,
    );
    twmDay($me, '2026-11-16', 10, 10); // today, after the scheduler: only my own refresh sees it

    $response = twmGet($me, '/api/twa/league/month')
        ->assertOk()
        ->assertExactJsonStructure([
            'enabled', 'opted_out',
            'month' => ['starts_on', 'ends_on', 'closes_at', 'days_left', 'status', 'participants'],
            'rules' => ['daily_cap', 'min_active_days', 'min_account_days', 'places', 'top'],
            'prizes' => ['*' => ['place', 'title']],
            'top' => ['*' => ['rank', 'name', 'points', 'active_days', 'is_me']],
            'me' => MONTH_ME,
            'last_month',
        ]);

    expect($response->json('enabled'))->toBeTrue()
        ->and($response->json('opted_out'))->toBeFalse()
        ->and($response->json('month'))->toBe(['starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'closes_at' => '2026-11-30T19:00:00+00:00', 'days_left' => 15, 'status' => 'open', 'participants' => 3])
        ->and($response->json('rules'))->toBe(['daily_cap' => 300, 'min_active_days' => 3, 'min_account_days' => 7, 'places' => 3, 'top' => 50])
        ->and($response->json('prizes.0'))->toBe(['place' => 1, 'title' => 'Telegram Premium на 3 месяца'])
        ->and($response->json('top'))->toBe([
            ['rank' => 1, 'name' => 'Rival', 'points' => 100, 'active_days' => 1, 'is_me' => false],
            ['rank' => 2, 'name' => 'Me', 'points' => 60, 'active_days' => 3, 'is_me' => true],
            ['rank' => 3, 'name' => null, 'points' => 10, 'active_days' => 1, 'is_me' => false],
        ])
        ->and($response->json('me'))->toBe(['rank' => 2, 'points' => 60, 'active_days' => 3, 'participant' => true, 'eligible' => true, 'missing' => ['active_days' => 0, 'account_days' => 0]])
        ->and($response->json('last_month'))->toBeNull()
        ->and($response->getContent())->not->toContain('rival_secret')
        ->and($response->getContent())->not->toContain('424242');

    // The prize title follows the interface locale.
    $me->forceFill(['interface_locale' => 'uz'])->save();
    expect(twmGet($me, '/api/twa/league/month')->json('prizes.0.title'))->toBe('Telegram Premium 3 oyga');
});

it('answers a learner without activity with `me: null`, an opted-out one without a place, and a disabled rating with the flag', function (): void {
    $bare = twmLearner();
    twmGet($bare, '/api/twa/league/month')->assertOk()
        ->assertExactJsonStructure(['enabled', 'opted_out', 'month' => ['starts_on', 'ends_on', 'closes_at', 'days_left', 'status', 'participants'], 'rules' => ['daily_cap', 'min_active_days', 'min_account_days', 'places', 'top'], 'prizes', 'top', 'me', 'last_month'])
        ->assertJsonPath('me', null)
        ->assertJsonPath('top', [])
        ->assertJsonPath('prizes.2.place', 3);

    $out = twmLearner(['meta' => [LeagueService::OPT_OUT_META => true]]);
    twmDay($out, '2026-11-10', 10, 10);
    twmGet($out, '/api/twa/league/month')->assertOk()
        ->assertJsonPath('opted_out', true)
        ->assertJsonPath('me.participant', false)
        ->assertJsonPath('me.rank', null)
        ->assertJsonPath('me.points', 20)
        ->assertJsonPath('top', []);

    config(['leagues.monthly.enabled' => false]);
    twmGet($bare, '/api/twa/league/month')->assertOk()
        ->assertJsonPath('enabled', false)
        ->assertJsonPath('month.status', null)
        ->assertJsonPath('prizes', [])
        ->assertJsonPath('top', [])
        ->assertJsonPath('me', null)
        ->assertJsonPath('last_month', null);
    twmGet($bare, '/api/twa/league/month/me')->assertOk()->assertJsonPath('enabled', false)->assertJsonPath('me', null);
});

it('GET /league/month/me keeps its exact JSON shape and refreshes the viewer\'s own row on the way', function (): void {
    $me = twmLearner(['created_at' => '2026-11-27 00:00:00']); // 3 days old at the month's end: 4 missing
    twmDay($me, '2026-11-16', 10, 8);

    twmGet($me, '/api/twa/league/month/me')->assertOk()
        ->assertExactJsonStructure(['enabled', 'month' => ['starts_on', 'ends_on', 'days_left'], 'me' => MONTH_ME])
        ->assertJsonPath('month', ['starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'days_left' => 15])
        ->assertJsonPath('me', ['rank' => 1, 'points' => 18, 'active_days' => 1, 'participant' => true, 'eligible' => false, 'missing' => ['active_days' => 2, 'account_days' => 4]]);

    // More grades today → the next call shows them at once (no scheduler run in between).
    LearnerDailyStats::query()->where('learner_id', $me->id)->update(['reviews_count' => 30, 'correct_count' => 20]);
    twmGet($me, '/api/twa/league/month/me')->assertOk()->assertJsonPath('me.points', 50);

    $nobody = twmLearner();
    twmGet($nobody, '/api/twa/league/month/me')->assertOk()->assertJsonPath('me', null);
});

it('shows the previous month: the confirmed winners by place, or an empty list while the results are checked', function (): void {
    $me = twmLearner(['first_name' => 'Me']);
    $winner = twmLearner(['first_name' => 'Dilnoza', 'username' => 'dil_secret']);
    $october = LeagueMonth::factory()->startingOn('2026-10-01')->pendingReview()->create();
    LeagueMonthCandidate::factory()->create(['league_month_id' => $october->id, 'learner_id' => $winner->id, 'rank' => 1, 'points' => 900]);
    LeagueMonthScore::factory()->create(['league_month_id' => $october->id, 'learner_id' => $me->id, 'points' => 120, 'final_rank' => 17]);

    twmGet($me, '/api/twa/league/month')->assertOk()
        ->assertJsonPath('last_month', ['starts_on' => '2026-10-01', 'ends_on' => '2026-10-31', 'status' => 'pending_review', 'winners' => [], 'me' => ['rank' => 17, 'points' => 120]]);

    $october->forceFill(['status' => 'confirmed', 'confirmed_at' => now()])->save();
    LeagueMonthCandidate::query()->where('league_month_id', $october->id)->update(['status' => 'winner', 'place' => 1]);

    $response = twmGet($me, '/api/twa/league/month')->assertOk()
        ->assertJsonPath('last_month.status', 'confirmed')
        ->assertJsonPath('last_month.winners', [['place' => 1, 'name' => 'Dilnoza', 'points' => 900]]);
    expect($response->getContent())->not->toContain('dil_secret');

    // Did not take part: no own line of the last month.
    $other = twmLearner();
    twmGet($other, '/api/twa/league/month')->assertOk()->assertJsonPath('last_month.me', null);
});

it('tells in /finish how many league points the session brought, with the goal bonus only when the goal fell in this session', function (): void {
    $learner = twmLearner(['daily_goal' => 2]);
    $headers = ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];

    // The day's goal was met by an earlier session: a later session does not get the bonus again.
    $earlier = TrainingSession::factory()->personal($learner, 'daily_review')->create(['started_at' => now()->subHours(3), 'status' => 'closed', 'ended_at' => now()->subHours(2)]);
    LearnerDailyStats::factory()->create(['learner_id' => $learner->id, 'date' => '2026-11-16', 'reviews_count' => 2, 'correct_count' => 2, 'goal_met_at' => now()->subHours(2)]);
    $session = TrainingSession::factory()->personal($learner, 'daily_review')->create(['started_at' => now()->subMinutes(10)]);

    $this->withHeaders($headers)->postJson("/api/twa/training/sessions/{$session->id}/finish")->assertOk()
        ->assertJsonPath('reviewed', 0)
        ->assertJsonPath('goal.met', true)
        ->assertJsonPath('league_points', 0);
    $this->withHeaders($headers)->postJson("/api/twa/training/sessions/{$earlier->id}/finish")->assertOk()
        ->assertJsonPath('league_points', 10); // the goal fell while THIS one ran: the bonus is its, however late the finish

    // A session that met the goal while it ran: reviews + correct + new words × 2 + the bonus.
    $learner->forceFill(['daily_goal' => 2])->save();
    LearnerDailyStats::query()->where('learner_id', $learner->id)->update(['goal_met_at' => now()->subMinutes(5)]);
    $live = TrainingSession::factory()->personal($learner, 'daily_review')->create(['started_at' => now()->subMinutes(10)]);
    TrainingReview::factory()->count(2)->create(['training_session_id' => $live->id, 'learner_id' => $learner->id, 'quality' => 4]);

    $this->withHeaders($headers)->postJson("/api/twa/training/sessions/{$live->id}/finish")->assertOk()
        ->assertJsonPath('reviewed', 2)
        ->assertJsonPath('correct', 2)
        ->assertJsonPath('league_points', 14); // 2 reviews + 2 correct + 0 new words + the goal bonus (10)
});
