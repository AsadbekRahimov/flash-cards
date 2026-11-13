<?php

declare(strict_types=1);

/*
 * The statement budget of `GET /me` and `GET /today` on the second and later calls (docs/19 K9, R12i) for the learner the
 * client meets on every opening of the Mini App: Free, an active course, a session graded today, a place in the league.
 *
 *   /me:    the learner (auth) and ONE statement of counts (learned, due) - the plan, the groups, the subscription blocks
 *           and the course are the profile snapshot of the pure cache;
 *   /today: the learner, today + yesterday in one statement, due + served today in one statement, the course, its
 *           progress, the next lesson, the open session - the plan is read once (from its cache) and the league line comes
 *           from the cached facts and standings. One additional indexed lapse lookup for Free saves a full /me call.
 */

use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Twa\Services\JwtService;
use App\Models\Learner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HotPathFixtures as Fx;
use Tests\Support\QueryLog;

uses(RefreshDatabase::class);

/** @return array{learner: Learner, headers: array<string, string>} a Free learner with a course, five cards graded today, placed in the league */
function todayMeScenario(object $test): array
{
    ['course' => $course, 'lessons' => $lessons] = Fx::course([1 => [1 => 25, 2 => 25]]);
    $learner = Learner::factory()->onboarded()->create(['timezone' => 'Asia/Tashkent', 'active_course_id' => $course->id]);
    $headers = ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];

    $opened = $test->withHeaders($headers)->postJson('/api/twa/training/sessions', ['mode' => 'lesson', 'lesson_id' => $lessons['1.1']->id, 'source' => 'self'])->assertOk()->json();
    $card = $opened['card'];
    for ($i = 0; $i < 5; $i++) {
        $card = $test->withHeaders($headers)->postJson("/api/twa/training/sessions/{$opened['session_id']}/review", ['word_id' => $card['word_id'], 'quality' => 4, 'time_spent_ms' => 3000, 'serve_token' => $card['serve_token']])->assertOk()->json('card');
    }
    $test->withHeaders($headers)->postJson("/api/twa/training/sessions/{$opened['session_id']}/finish")->assertOk();

    // The first calls warm the per-learner entries (the profile snapshot, the league facts and standings).
    $test->withHeaders($headers)->getJson('/api/twa/me')->assertOk();
    $test->withHeaders($headers)->getJson('/api/twa/today')->assertOk()->assertJsonPath('league.rank', 1);

    return ['learner' => $learner, 'headers' => $headers];
}

it('answers the second /me with the learner and one statement of counts', function (): void {
    ['headers' => $h] = todayMeScenario($this);

    $queries = QueryLog::during(fn () => $this->withHeaders($h)->getJson('/api/twa/me')->assertOk()->assertJsonStructure(['stats' => ['words_learned', 'words_due_today']]));

    // Before R12i: 7 - the groups, the live and the lapsed subscription, the course and two counts.
    expect(count($queries))->toBeLessThanOrEqual(2)
        ->and(QueryLog::matching($queries, '/^select \(select count\(\*\) from ["`]word_repetitions["`]/'))->toHaveCount(1)
        ->and(QueryLog::matching($queries, '/from ["`](telegram_groups|subscriptions|courses)["`]/'))->toBe([]);
});

it('answers the second /today within seven statements, including the lapse banner that used to request /me', function (): void {
    ['headers' => $h] = todayMeScenario($this);

    $queries = QueryLog::during(fn () => $this->withHeaders($h)->getJson('/api/twa/today')->assertOk()
        ->assertJsonPath('league.rank', 1)->assertJsonPath('goal.done', 5)->assertJsonPath('new_words_remaining', 14)); // five graded, a sixth served

    // The active course is one learner query beside the cached tree (was two: the progress and the next-lesson anti-join: 8 -> 7).
    // Before R12i: 13 - the plan six times (from its cache), two day rows, two counts, five league statements.
    expect(count($queries))->toBeLessThanOrEqual(7)
        ->and(QueryLog::matching($queries, '/from ["`]learner_daily_stats["`] where ["`]learner_id["`] = \? and ["`]date["`] in \(\?, \?\)/'))->toHaveCount(1)
        ->and(QueryLog::matching($queries, '/^select \(select count\(\*\) from ["`]word_repetitions["`].* as ["`]due["`], \(select count\(\*\) from ["`]word_repetitions["`].* as ["`]served["`]/'))->toHaveCount(1)
        ->and(QueryLog::matching($queries, '/from ["`](league_weeks|league_members|league_groups)["`]|from ["`]league_members["`] as/'))->toBe([])
        ->and(QueryLog::matching($queries, '/from ["`]subscriptions["`].*order by ["`]ended_at["`] desc/'))->toHaveCount(1);
});

it('reads the plan once per /today when its cache is cold', function (): void {
    ['learner' => $learner, 'headers' => $h] = todayMeScenario($this);
    app(EntitlementResolver::class)->forget($learner);

    $queries = QueryLog::during(fn () => $this->withHeaders($h)->getJson('/api/twa/today')->assertOk());

    expect(QueryLog::matching($queries, '/from ["`]subscriptions["`].*organization_members/'))->toHaveCount(1);
});
