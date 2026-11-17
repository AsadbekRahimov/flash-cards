<?php

declare(strict_types=1);

/*
 * The statement budget of `GET /me` and `GET /today` on the second and later calls (docs/19 K9, R12i) for the learner the
 * client meets on every opening of the Mini App: Free, an active course, a session graded today, a place in the league.
 *
 *   /me:    the learner (auth), ONE statement of counts (learned, due) and - the practice being open to everybody by default -
 *           ONE statement of the day's use of the Free practice limits (`practice.limits`: sessions, battles); the plan, the groups,
 *           the subscription blocks and the course are the profile snapshot of the pure cache;
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
function todayMeScenario(object $test, string $game = 'all'): array
{
    // Both ends of GAME_ACCESS are pinned below ('all' is the shipped default, 'off' the closed game); the rest is as shipped: the readiness,
    // the round of hard words and the practice with `pairs_timed` on, so the numbers are those of a production learner.
    config([
        'game.access' => $game,
        'practice.access' => 'all', 'practice.plan' => 'all', 'practice.modes.pairs_timed' => true,
        'readiness.enabled' => true, 'learning.hard_round.enabled' => true,
    ]);
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

it('answers the second /me with the learner, one statement of counts, one of the practice limits and - the game being open - one of its limits', function (string $game, int $budget): void {
    ['headers' => $h] = todayMeScenario($this, $game);

    $queries = QueryLog::during(fn () => $this->withHeaders($h)->getJson('/api/twa/me')->assertOk()->assertJsonStructure(['stats' => ['words_learned', 'words_due_today']]));

    // Before R12i: 7 - the groups, the live and the lapsed subscription, the course and two counts. Since the practice is open to
    // everybody (PRACTICE_ACCESS=all) the Free learner's use of the day is the third (not part of the snapshot: it changes per session).
    // GAME_ACCESS=all (the default) adds the fourth: the limits, the coins and the tickets of the game in ONE statement (`game_runs` + `game_profiles`).
    expect(count($queries))->toBe($budget)
        ->and(QueryLog::matching($queries, '/from practice_sessions/'))->toHaveCount(1)
        ->and(QueryLog::matching($queries, '/from game_runs/'))->toHaveCount($game === 'all' ? 1 : 0)
        ->and(QueryLog::matching($queries, '/^select \(select count\(\*\) from ["`]word_repetitions["`]/'))->toHaveCount(1)
        ->and(QueryLog::matching($queries, '/from ["`](telegram_groups|subscriptions|courses)["`]/'))->toBe([]);
})->with(['the game closed' => ['off', 3], 'the game open to all (default)' => ['all', 4]]);

it('answers the second /today within a pinned number of statements, including the lapse banner that used to request /me', function (string $game, int $budget): void {
    ['headers' => $h] = todayMeScenario($this, $game);

    $queries = QueryLog::during(fn () => $this->withHeaders($h)->getJson('/api/twa/today')->assertOk()
        ->assertJsonPath('league.rank', 1)->assertJsonPath('goal.done', 5)->assertJsonPath('new_words_remaining', 14)->assertJsonPath('game.available', $game === 'all')); // five graded, a sixth served

    // The active course is one learner query beside the cached tree (was two: the progress and the next-lesson anti-join: 8 -> 7).
    // 7 -> 8 (docs/13 §8.5): the quiz line of the week of the readiness block is ONE indexed read of `level_quiz_results`; the readiness
    // itself costs nothing - its counts are two more sums of the statement above (no cache, nothing else is read for it).
    // 8 -> 9 (docs/06 §14): the candidates of the round of hard words are ONE indexed read; the day's rounds ride in the statement of the
    // open daily session (no statement of their own).
    // 9 -> 11 with GAME_ACCESS=all (the default, docs/18): the daily caravan state (`game_runs`: the live run and today's daily key) and the
    // card of the class caravan (`class_caravans` + the learner's groups). A closed game reads nothing of its tables.
    // Before R12i: 13 - the plan six times (from its cache), two day rows, two counts, five league statements.
    expect(count($queries))->toBe($budget)
        ->and(QueryLog::matching($queries, '/from ["`]level_quiz_results["`]/'))->toHaveCount(1)
        ->and(QueryLog::matching($queries, '/from ["`]game_runs["`]/'))->toHaveCount($game === 'all' ? 1 : 0)
        ->and(QueryLog::matching($queries, '/from ["`]class_caravans["`]/'))->toHaveCount($game === 'all' ? 1 : 0)
        ->and(QueryLog::matching($queries, '/from ["`]learner_daily_stats["`] where ["`]learner_id["`] = \? and ["`]date["`] in \(\?, \?\)/'))->toHaveCount(1)
        ->and(QueryLog::matching($queries, '/^select \(select count\(\*\) from ["`]word_repetitions["`].* as ["`]due["`], \(select count\(\*\) from ["`]word_repetitions["`].* as ["`]served["`]/'))->toHaveCount(1)
        ->and(QueryLog::matching($queries, '/from ["`](league_weeks|league_members|league_groups)["`]|from ["`]league_members["`] as/'))->toBe([])
        ->and(QueryLog::matching($queries, '/from ["`]subscriptions["`].*order by ["`]ended_at["`] desc/'))->toHaveCount(1);
})->with(['the game closed' => ['off', 9], 'the game open to all (default)' => ['all', 11]]);

it('reads the plan once per /today when its cache is cold', function (): void {
    ['learner' => $learner, 'headers' => $h] = todayMeScenario($this);
    app(EntitlementResolver::class)->forget($learner);

    $queries = QueryLog::during(fn () => $this->withHeaders($h)->getJson('/api/twa/today')->assertOk());

    expect(QueryLog::matching($queries, '/from ["`]subscriptions["`].*organization_members/'))->toHaveCount(1);
});
