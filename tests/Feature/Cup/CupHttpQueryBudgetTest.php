<?php

declare(strict_types=1);

use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Cup\Services\CupMembershipGate;
use App\Domain\Cup\Services\CupParticipationService;
use App\Domain\Cup\Services\CupProEmoji;
use App\Domain\Cup\Services\CupStandings;
use App\Domain\Learners\Services\ProEmojiService;
use App\Domain\Operations\Support\PureCache;
use App\Models\Cup;
use App\Models\CupParticipant;
use App\Models\CupRoundAttempt;
use App\Models\Learner;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\Cup\CupKit;
use Tests\Support\Practice\PracticeKit;
use Tests\Support\QueryLog;

/*
 * The owner's requirement on SQL (docs/33 §11.3, K7): the statements of every route of the cup, cold and warm. The core is pinned by
 * CupQueryBudgetTest; the HTTP layer adds the learner of the JWT (1), the cup with its group and the learner's participation (1 -
 * CupReader::locate) and, on the table, the emoji of Pro (1 cold, 0 warm). Product events are written after the response (the buffer):
 * they are counted here, one INSERT per request that records any.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CupKit::open();
    CupKit::course();
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * @param  array<string, mixed>  $body
 * @return array{0: TestResponse, 1: list<string>}
 */
function cupBudget(Learner $learner, string $method, string $path, array $body = []): array
{
    $headers = PracticeKit::headers($learner);
    $response = null;
    $queries = QueryLog::during(function () use (&$response, $headers, $method, $path, $body): void {
        $response = test()->withHeaders($headers)->json($method, '/api/twa'.$path, $body);
    });

    return [$response, $queries];
}

function cupBudgetPro(Learner $learner, string $emoji): void
{
    Subscription::factory()->create([
        'subscriber_id' => $learner->id,
        'provider' => 'trial',
        'status' => 'trialing',
        'starts_at' => now()->subDay(),
        'current_period_ends_at' => now()->addDays(10),
    ]);
    $learner->forceFill(['pro_emoji' => $emoji])->save();
    app(EntitlementResolver::class)->forget($learner);
}

it('reads the table in 7 statements cold and 5 warm, whatever the number of participants and Pro emoji', function (int $players): void {
    ['cup' => $cup, 'learners' => $learners] = CupKit::running($players);
    foreach (array_slice($learners, 0, 2) as $learner) {
        cupBudgetPro($learner, 'fire');
    }
    [$a] = $learners;
    app(CupStandings::class)->forget($cup->id);
    app(CupProEmoji::class)->forget($cup->id);

    [$cold, $coldQueries] = cupBudget($a, 'GET', "/cups/{$cup->id}");
    [$warm, $warmQueries] = cupBudget($a, 'GET', "/cups/{$cup->id}");

    // learner, the cup with its group and my row, the table, the emoji, today (the day, my attempt), my days
    expect($coldQueries)->toHaveCount(7)
        ->and($warmQueries)->toHaveCount(5)
        ->and($cold->assertOk()->json('standings'))->toHaveCount($players)
        ->and(collect($cold->json('standings'))->whereNotNull('pro_emoji')->pluck('pro_emoji')->all())->toBe(['🔥', '🔥'])
        ->and($warm->json('standings'))->toBe($cold->json('standings'));
})->with([3, 12]);

it('opens the invitation in 3 statements (and the view event), joins in 6', function (): void {
    CarbonImmutable::setTestNow('2026-12-01 06:00:00');
    $cup = CupKit::scheduled(CupKit::group(), CarbonImmutable::now());
    $member = Learner::factory()->create();
    CupKit::member($member);
    CupKit::telegram()->chatMemberLookups = [];

    [$invite, $inviteQueries] = cupBudget($member, 'GET', "/cups/by-code/{$cup->public_code}");
    [$join, $joinQueries] = cupBudget($member, 'POST', "/cups/{$cup->id}/join");
    [, $againQueries] = cupBudget($member, 'GET', "/cups/by-code/{$cup->public_code}");

    $invite->assertOk();
    $join->assertCreated();
    expect($inviteQueries)->toHaveCount(3) // learner, the cup + group + my row + the count, the event cup.invite_viewed
        ->and($joinQueries)->toHaveCount(6) // learner, the cup, FOR SHARE, INSERT ON CONFLICT, the row FOR UPDATE, the event cup.joined
        ->and($againQueries)->toHaveCount(2) // a participant now: no Telegram, the view already recorded this hour
        ->and(CupKit::telegram()->chatMemberLookups)->toHaveCount(1); // the join reused the answer of the view (10 s)
});

it('starts a round in 11 statements, resumes it in 6 and 2, answers in 5 (wrong) and 10 (right), replays in 3', function (): void {
    ['cup' => $cup, 'learners' => [$a]] = CupKit::running(2);
    Cache::forget(CupMembershipGate::key($cup->id, $a->id));

    [$start, $startQueries] = cupBudget($a, 'POST', "/cups/{$cup->id}/rounds/today");
    [, $againQueries] = cupBudget($a, 'POST', "/cups/{$cup->id}/rounds/today");
    $attemptId = (int) $start->assertCreated()->json('attempt_id');
    [, $findQueries] = cupBudget($a, 'GET', "/cup-rounds/{$attemptId}");
    [, $todayQueries] = cupBudget($a, 'GET', "/cups/{$cup->id}/rounds/today");

    $dayId = (int) CupRoundAttempt::query()->whereKey($attemptId)->value('cup_day_id');
    $element = (string) $start->json('current.id');
    [$wrong, $wrongQueries] = cupBudget($a, 'POST', "/cup-rounds/{$attemptId}/answer", ['request_id' => (string) Str::uuid(), 'element_id' => $element, 'answer' => CupKit::wrongAnswer($dayId, $element)]);
    $element = (string) $wrong->assertOk()->json('round.current.id');
    $rightBody = ['request_id' => (string) Str::uuid(), 'element_id' => $element, 'answer' => CupKit::rightAnswer($dayId, $element)];
    [$right, $rightQueries] = cupBudget($a, 'POST', "/cup-rounds/{$attemptId}/answer", $rightBody);
    [$replay, $replayQueries] = cupBudget($a, 'POST', "/cup-rounds/{$attemptId}/answer", $rightBody);

    $right->assertOk();
    expect($replay->assertOk()->json('replayed'))->toBeTrue()->and($replayQueries)->toHaveCount(3); // learner, the attempt, the stored result
    expect($startQueries)->toHaveCount(11) // learner, the cup + my row, then the core's 9 (the event is one of them)
        ->and($againQueries)->toHaveCount(6) // learner, the cup + my row, the core's 4
        ->and($findQueries)->toHaveCount(2) // learner, the attempt with its day and cup
        ->and($todayQueries)->toHaveCount(4) // learner, the cup + my row, the day, my attempt
        ->and($wrongQueries)->toHaveCount(5)
        ->and($rightQueries)->toHaveCount(10);
});

it('lists my cups in 2 statements whatever their number, and renames in 6', function (int $cups): void {
    CarbonImmutable::setTestNow('2026-12-01 06:00:00');
    $learner = Learner::factory()->create();
    CupKit::member($learner);
    $last = null;
    for ($i = 0; $i < $cups; $i++) {
        $last = CupKit::scheduled(CupKit::group(), CarbonImmutable::now());
        app(CupParticipationService::class)->join(Cup::query()->with('telegramGroup')->findOrFail($last->id), $learner);
    }

    [$list, $listQueries] = cupBudget($learner, 'GET', '/cups');
    [$rename, $renameQueries] = cupBudget($learner, 'PATCH', "/cups/{$last?->id}/me", ['display_name' => 'New name']);

    expect($list->assertOk()->json('cups'))->toHaveCount($cups)
        ->and($listQueries)->toHaveCount(2)
        ->and($rename->assertOk()->json('display_name'))->toBe('New name')
        ->and($renameQueries)->toHaveCount(5); // learner, the cup + my row, my row FOR UPDATE, the status of the cup, UPDATE
})->with([1, 4]);

it('keeps the one statement of the emoji in step with ProEmojiService::visibleForMany', function (): void {
    ['cup' => $cup, 'learners' => $learners] = CupKit::running(5);
    [$a, $b, $c, $d] = $learners;
    cupBudgetPro($a, 'fire');
    cupBudgetPro($b, 'rocket');
    $c->forceFill(['pro_emoji' => 'fire'])->save(); // a Free learner: the choice is kept, not shown
    cupBudgetPro($d, 'not-in-the-set');

    $byLearner = app(ProEmojiService::class)->visibleForMany(array_map(fn (Learner $l): int => $l->id, $learners));
    $participants = CupParticipant::query()->where('cup_id', $cup->id)->pluck('learner_id', 'id');
    $expected = [];
    foreach ($participants as $participantId => $learnerId) {
        if (isset($byLearner[$learnerId])) {
            $expected[(int) $participantId] = $byLearner[$learnerId];
        }
    }
    ksort($expected);

    $emoji = app(CupProEmoji::class)->forCup($cup);
    ksort($emoji);
    expect($emoji)->toBe($expected)->and(count($emoji))->toBe(2);

    // A change of the choice drops the key of the open cup: the table shows the new emoji at once.
    test()->withHeaders(PracticeKit::headers($a))->patchJson('/api/twa/me/settings', ['pro_emoji' => 'star'])->assertOk();
    expect(PureCache::store()->get(CupProEmoji::key($cup->id)))->toBeNull();
});
