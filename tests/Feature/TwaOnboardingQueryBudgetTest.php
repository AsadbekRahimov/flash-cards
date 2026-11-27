<?php

declare(strict_types=1);

/*
 * The statement budget of the onboarding of the Mini App (docs/40 §2.1, docs/04 A.24), the catalog and the plan warm unless said:
 *
 *   GET  /onboarding                 the learner (auth); the catalog of the default locale (+1 cold) and of the learner's (+1 cold,
 *                                    another locale only) and the plan come from their caches;
 *   PUT  /onboarding/steps/name|age|goals
 *                                    the learner (auth), the row lock, ONE update (the column and the progress), the events (ONE insert
 *                                    after the response);
 *   PUT  /onboarding/steps/level|daily_goal|reminder
 *                                    the same through LearnerSettingsService::update(), which reads the row again after its commit; the
 *                                    daily goal adds the reconcile of today's goal (one read of the day row) when it changes;
 *   POST /onboarding/complete        the learner (auth), the row lock, the recommended course (`Course::find`), ONE update, the
 *                                    learner's progress over the lessons (the next lesson; the tree is cached), the events.
 *
 * And `/auth` / `/me` pay nothing for `display_name` and `onboarding`: the resources read the loaded row.
 */

use App\Domain\Analytics\Services\ClientTimingRecorder;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Twa\Services\InitDataValidator;
use App\Domain\Twa\Services\JwtService;
use App\Http\Resources\Twa\AuthTokenResource;
use App\Models\Learner;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Support\HotPathFixtures as Fx;
use Tests\Support\QueryLog;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['billing.plans.free.limits.daily_goal_max' => 10, 'learners.daily_goal_options' => [10, 20, 40], 'learners.goals' => ['general']]);
    foreach (['A1', 'B1', 'B2'] as $level) {
        Fx::course([1 => [1 => 3, 2 => 3]], ['slug' => 'english-'.strtolower($level), 'exam_tag' => 'general', 'level' => $level]);
    }
});

/** @return array{learner: Learner, headers: array<string, string>} a learner of the Mini App, the catalog and the plan warm */
function obBudgetLearner(object $test, array $attributes = []): array
{
    $learner = Learner::factory()->create(['daily_goal' => 10, 'reminder_time' => '19:00', 'interface_locale' => 'ru', ...$attributes]);
    $headers = ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];
    $test->withHeaders($headers)->getJson('/api/twa/onboarding')->assertOk(); // warms the catalog and the plan

    return ['learner' => $learner, 'headers' => $headers];
}

/**
 * @param  list<string>  $queries
 * @return list<string>
 */
function obWrites(array $queries): array
{
    return QueryLog::matching($queries, '/^(insert|update|delete)/i');
}

it('reads GET /onboarding with the learner alone when the caches are warm, and one statement per catalog when they are cold', function (): void {
    ['headers' => $h, 'learner' => $learner] = obBudgetLearner($this);

    $warm = QueryLog::during(fn () => $this->withHeaders($h)->getJson('/api/twa/onboarding')->assertOk()->assertJsonCount(3, 'options.levels'));
    expect($warm)->toHaveCount(1)
        ->and(QueryLog::matching($warm, '/from ["`]learners["`]/'))->toHaveCount(1);

    // Cold: the catalog of the default locale (ru) - and nothing for the plan, it stays warm. A learner of uz pays for the uz catalog too.
    app(CourseCatalogService::class)->forgetPublished();
    $cold = QueryLog::during(fn () => $this->withHeaders($h)->getJson('/api/twa/onboarding')->assertOk());
    expect($cold)->toHaveCount(2)
        ->and(QueryLog::matching($cold, '/from ["`]courses["`]/'))->toHaveCount(1);

    $learner->update(['interface_locale' => 'uz']);
    app(CourseCatalogService::class)->forgetPublished();
    $coldUz = QueryLog::during(fn () => $this->withHeaders($h)->getJson('/api/twa/onboarding')->assertOk());
    expect($coldUz)->toHaveCount(3)
        ->and(QueryLog::matching($coldUz, '/from ["`]courses["`]/'))->toHaveCount(2);
});

it('saves a step of its own columns with the lock, one update and one insert of the events', function (string $step, array $body): void {
    ['headers' => $h] = obBudgetLearner($this);

    $queries = QueryLog::during(fn () => $this->withHeaders($h)->putJson("/api/twa/onboarding/steps/{$step}", $body)->assertOk());

    // auth, lock, update, the events (`onboarding.started` + `onboarding.step_completed` in ONE insert after the response).
    expect($queries)->toHaveCount(4)
        ->and(QueryLog::matching($queries, '/for update|for no key update/i'))->toHaveCount(DB::getDriverName() === 'sqlite' ? 0 : 1)
        ->and(QueryLog::matching($queries, '/^update ["`]learners["`]/'))->toHaveCount(1)
        ->and(QueryLog::matching($queries, '/^insert into ["`]product_events["`]/'))->toHaveCount(1)
        ->and(obWrites($queries))->toHaveCount(2);

    // The same request again: the lock and the read, no write, no event.
    $again = QueryLog::during(fn () => $this->withHeaders($h)->putJson("/api/twa/onboarding/steps/{$step}", $body)->assertOk());
    expect($again)->toHaveCount(2)
        ->and(obWrites($again))->toBe([]);
})->with([
    'name' => ['name', ['display_name' => 'Азиз']],
    'age' => ['age', ['age_range' => '18_24']],
    'goals' => ['goals', ['goals' => ['career', 'travel']]],
]);

it('saves a step of the settings through LearnerSettingsService within its pinned budget', function (string $step, array $body, int $budget): void {
    ['headers' => $h] = obBudgetLearner($this);

    $queries = QueryLog::during(fn () => $this->withHeaders($h)->putJson("/api/twa/onboarding/steps/{$step}", $body)->assertOk());

    // auth, lock, update, the re-read of LearnerSettingsService::update(), the events (one insert); the goal of the day: + the reconcile.
    expect($queries)->toHaveCount($budget)
        ->and(QueryLog::matching($queries, '/^update ["`]learners["`]/'))->toHaveCount(1)
        ->and(QueryLog::matching($queries, '/^insert into ["`]product_events["`]/'))->toHaveCount(1)
        ->and(QueryLog::matching($queries, '/from ["`]courses["`]/'))->toBe([]); // the course of the level: the cached catalog
})->with([
    'level' => ['level', ['level' => 'B1', 'course_slug' => 'english-b1'], 5],
    'daily_goal' => ['daily_goal', ['daily_goal' => 10], 5],
    'reminder' => ['reminder', ['enabled' => true, 'time' => '20:00', 'write_access' => 'granted'], 5],
]);

it('pays the reconcile of today\'s goal when the goal of the day really changes (Pro)', function (): void {
    ['learner' => $learner, 'headers' => $h] = obBudgetLearner($this);
    Subscription::factory()->create(['subscriber_id' => $learner->id]);
    app(EntitlementResolver::class)->forget($learner);
    $this->withHeaders($h)->getJson('/api/twa/onboarding')->assertOk(); // the plan warm again

    $queries = QueryLog::during(fn () => $this->withHeaders($h)->putJson('/api/twa/onboarding/steps/daily_goal', ['daily_goal' => 40])->assertOk());

    expect($queries)->toHaveCount(6)
        ->and(QueryLog::matching($queries, '/from ["`]learner_daily_stats["`]/'))->toHaveCount(1);
});

it('completes the onboarding within its pinned budget, and a repeat costs the read of the progress alone', function (): void {
    ['headers' => $h] = obBudgetLearner($this);
    $this->withHeaders($h)->putJson('/api/twa/onboarding/steps/level', ['level' => 'B1', 'course_slug' => 'english-b1'])->assertOk();

    $queries = QueryLog::during(fn () => $this->withHeaders($h)->postJson('/api/twa/onboarding/complete')->assertOk()->assertJsonPath('course.slug', 'english-b1'));

    // auth, lock, Course::find of the recommendation, update, the cold tree of the course, the learner's progress over its lessons, the events.
    expect($queries)->toHaveCount(7)
        ->and(QueryLog::matching($queries, '/^update ["`]learners["`]/'))->toHaveCount(1)
        ->and(QueryLog::matching($queries, '/^select \* from ["`]courses["`] where ["`]courses["`].["`]id["`] = \?/'))->toHaveCount(1)
        ->and(QueryLog::matching($queries, '/^insert into ["`]product_events["`]/'))->toHaveCount(1);

    // Done already: the cached course row and tree, the learner's progress - no lock, no write, no event.
    $again = QueryLog::during(fn () => $this->withHeaders($h)->postJson('/api/twa/onboarding/complete')->assertOk()->assertJsonPath('course.slug', 'english-b1'));
    expect($again)->toHaveCount(3)
        ->and(obWrites($again))->toBe([]);
});

it('adds no statement to /auth and /me: display_name and onboarding come from the loaded row', function (): void {
    Config::set('telegram.bot_token', 'test-bot-token-1234567890');
    ['learner' => $learner, 'headers' => $h] = obBudgetLearner($this, ['telegram_user_id' => 5151, 'display_name' => 'Азиз', 'meta' => ['twa_onboarding' => ['step' => 'level']]]);
    $learner->refresh();

    // The resource of /auth on the loaded model: not a single statement (the learner block of /me is built the same way).
    $resource = new AuthTokenResource(['jwt' => ['token' => 't', 'expires_in' => 1, 'expires_at' => 1], 'learner' => $learner, 'account_restored' => null]);
    $built = null;
    expect(QueryLog::during(function () use ($resource, &$built): void {
        $built = $resource->toArray(Request::create('/'));
    }))->toBe([])
        ->and($built['learner']['display_name'] ?? null)->toBe('Азиз')
        ->and($built['learner']['onboarding'] ?? null)->toBe(['completed' => false, 'step' => 'level'])
        ->and(ClientTimingRecorder::samplePercent())->toBeInt();

    // /me answers with the new fields; its budget is pinned by TwaTodayMeQueryBudgetTest, which is unchanged.
    $this->withHeaders($h)->getJson('/api/twa/me')->assertOk()->assertJsonPath('learner.display_name', 'Азиз')->assertJsonPath('learner.onboarding.step', 'level');

    $initData = InitDataValidator::sign('test-bot-token-1234567890', ['user' => json_encode(['id' => 5151, 'first_name' => 'Aziz']), 'auth_date' => (string) time()]);
    $auth = QueryLog::during(fn () => $this->flushHeaders()->postJson('/api/twa/auth', ['init_data' => $initData])->assertOk()->assertJsonPath('learner.display_name', 'Азиз'));
    expect(QueryLog::matching($auth, '/from ["`](courses|product_events)["`]/'))->toBe([]);
});
