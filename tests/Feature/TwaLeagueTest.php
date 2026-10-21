<?php

declare(strict_types=1);

/*
 * GET /api/twa/league (docs/04 A.13) and the `leagues_opt_out` setting (docs/13 §7.3): what the
 * learner sees of their group (names and points only), the Today line, opting out.
 */

use App\Domain\Engagement\Services\LeagueService;
use App\Domain\Learners\Services\DailyStatsService;
use App\Domain\Twa\Services\JwtService;
use App\Models\LeagueGroup;
use App\Models\LeagueMember;
use App\Models\LeagueWeek;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['leagues.timezone' => 'Asia/Tashkent', 'leagues.promote' => 5, 'leagues.demote' => 5]);
    $this->travelTo(CarbonImmutable::parse('2026-10-21 05:00:00', 'UTC')); // Wednesday, week 2026-10-19 … 25
});

function twlGet(Learner $learner, string $path = '/api/twa/league'): TestResponse
{
    $token = app(JwtService::class)->issue($learner->id)['token'];

    return test()->withHeaders(['Authorization' => 'Bearer '.$token])->getJson($path);
}

function twlPatch(Learner $learner, array $body): TestResponse
{
    $token = app(JwtService::class)->issue($learner->id)['token'];

    return test()->withHeaders(['Authorization' => 'Bearer '.$token])->patchJson('/api/twa/me/settings', $body);
}

it('answers without a membership for a learner who did not study this week', function (): void {
    $learner = Learner::factory()->create();

    twlGet($learner)->assertOk()->assertJson([
        'enabled' => true,
        'opted_out' => false,
        'week' => ['starts_on' => '2026-10-19', 'ends_on' => '2026-10-25', 'closes_at' => '2026-10-25T19:00:00+00:00'],
        'rules' => ['capacity' => 30, 'promote' => 5, 'demote' => 5, 'tiers' => ['bronze', 'silver', 'gold', 'platinum', 'diamond']],
        'membership' => null,
        'last_week' => null,
    ]);

    twlGet($learner, '/api/twa/today')->assertOk()->assertJsonPath('league', null);
});

it('shows my group: place, points, zone, the standings with first names only, and the Today line', function (): void {
    $week = LeagueWeek::factory()->startingOn('2026-10-19')->create();
    $group = LeagueGroup::factory()->create(['league_week_id' => $week->id, 'tier' => 2]);
    $me = Learner::factory()->create(['first_name' => 'Me', 'username' => 'me_secret', 'telegram_user_id' => 424242]);
    $rival = Learner::factory()->create(['first_name' => 'Rival', 'username' => 'rival_secret']);
    $anon = Learner::factory()->create(['first_name' => null]);
    foreach ([$rival, $me, $anon] as $learner) {
        LeagueMember::factory()->create(['league_group_id' => $group->id, 'learner_id' => $learner->id]);
    }
    LearnerDailyStats::factory()->create(['learner_id' => $rival->id, 'date' => '2026-10-20', 'reviews_count' => 30, 'correct_count' => 25]);
    LearnerDailyStats::factory()->create(['learner_id' => $me->id, 'date' => '2026-10-20', 'reviews_count' => 10, 'correct_count' => 9, 'new_words_count' => 2]);

    $response = twlGet($me)->assertOk();

    expect($response->json('membership.tier'))->toBe(2)
        ->and($response->json('membership.tier_key'))->toBe('gold')
        ->and($response->json('membership.rank'))->toBe(2)
        ->and($response->json('membership.points'))->toBe(23)
        ->and($response->json('membership.members_count'))->toBe(3)
        ->and($response->json('membership.zone'))->toBe('promotion')
        ->and($response->json('membership.standings'))->toBe([
            ['rank' => 1, 'name' => 'Rival', 'points' => 55, 'is_me' => false],
            ['rank' => 2, 'name' => 'Me', 'points' => 23, 'is_me' => true],
            ['rank' => 3, 'name' => null, 'points' => 0, 'is_me' => false],
        ]);

    $raw = $response->getContent();
    expect($raw)->not->toContain('rival_secret')
        ->and($raw)->not->toContain('424242')
        ->and($raw)->not->toContain((string) $rival->telegram_user_id);

    twlGet($me, '/api/twa/today')->assertOk()->assertJsonPath('league', [
        'tier' => 2, 'tier_key' => 'gold', 'rank' => 2, 'points' => 23, 'members_count' => 3,
    ]);
});

it('carries the last closed result', function (): void {
    $closed = LeagueWeek::factory()->startingOn('2026-10-12')->closed()->create();
    $learner = Learner::factory()->create();
    LeagueMember::factory()->closed('demoted', 27, 12)->create([
        'league_group_id' => LeagueGroup::factory()->create(['league_week_id' => $closed->id, 'tier' => 1])->id,
        'learner_id' => $learner->id,
    ]);

    twlGet($learner)->assertOk()->assertJsonPath('last_week', [
        'starts_on' => '2026-10-12', 'tier' => 1, 'tier_key' => 'silver', 'result' => 'demoted', 'rank' => 27, 'points' => 12,
    ]);
});

it('lets the learner opt out in the settings: not placed any more, visible on the league screen', function (): void {
    $learner = Learner::factory()->onboarded()->create(['timezone' => 'Asia/Tashkent']);

    twlPatch($learner, ['leagues_opt_out' => true])->assertOk()->assertJsonPath('settings.leagues_opt_out', true);
    expect($learner->fresh()?->meta[LeagueService::OPT_OUT_META] ?? null)->toBeTrue();

    $learner->refresh();
    DB::transaction(fn () => app(DailyStatsService::class)->recordReview($learner, 4, 500, false, CarbonImmutable::now()));
    expect(LeagueMember::query()->count())->toBe(0);
    twlGet($learner)->assertOk()->assertJsonPath('opted_out', true)->assertJsonPath('membership', null);

    twlPatch($learner, ['leagues_opt_out' => false])->assertOk()->assertJsonPath('settings.leagues_opt_out', false);
    $learner->refresh();
    // Placement is attempted by the first grade of a local day: back in from the next day on.
    $this->travel(1)->days();
    DB::transaction(fn () => app(DailyStatsService::class)->recordReview($learner, 4, 500, false, CarbonImmutable::now()));
    expect(LeagueMember::query()->where('learner_id', $learner->id)->count())->toBe(1);

    // A JSON boolean only.
    twlPatch($learner, ['leagues_opt_out' => '1'])->assertStatus(422);
    twlPatch($learner, ['leagues_opt_out' => 1])->assertStatus(422);
});

it('reports leagues as disabled when switched off', function (): void {
    config(['leagues.enabled' => false]);
    $learner = Learner::factory()->create();

    twlGet($learner)->assertOk()->assertJsonPath('enabled', false)->assertJsonPath('membership', null);
});
