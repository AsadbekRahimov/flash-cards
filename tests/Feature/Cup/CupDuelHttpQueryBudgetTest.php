<?php

declare(strict_types=1);

use App\Domain\Cup\Services\CupMembershipGate;
use App\Domain\Cup\Services\CupProEmoji;
use App\Domain\Operations\Support\PureCache;
use App\Jobs\PostCupMessageJob;
use App\Jobs\ScoreCupDuelJob;
use App\Jobs\SendCupDuelDmJob;
use App\Models\Cup;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\Cup\CupDuelKit;
use Tests\Support\Practice\PracticeKit;
use Tests\Support\QueryLog;

/*
 * The owner's requirement on SQL (docs/33 §11.9, D2): the HTTP layer of the duels adds to the core (CupDuelQueryBudgetTest) the learner
 * of the JWT (1) and the door of the cup (1 - CupReader::locate with the scalars the route needs: the names of the players, whether I may
 * challenge) and nothing else when the emoji of Pro are warm (cold: + 1, the cache of the table). `GET duels` is one statement after the
 * door, whatever the number of duels. Product events are written after the response in one INSERT (counted). The DMs and the outbox
 * jobs are faked here: their statements are pinned by CupDuelDmTest and CupDuelOutboxTest. SQLite and PostgreSQL alike.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake([PostCupMessageJob::class, SendCupDuelDmJob::class, ScoreCupDuelJob::class]);
    Cache::flush();
    PureCache::store()->flush();
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * @param  array<string, mixed>  $body
 * @return array{0: TestResponse, 1: list<string>}
 */
function duelHttpBudget(Learner $learner, string $method, string $path, array $body = [], bool $warm = true, ?Cup $cup = null): array
{
    $headers = PracticeKit::headers($learner);
    if ($warm && $cup !== null) {
        app(CupProEmoji::class)->forCup($cup);
    }
    $response = null;
    $queries = QueryLog::during(function () use (&$response, $headers, $method, $path, $body): void {
        $response = test()->withHeaders($headers)->json($method, '/api/twa'.$path, $body);
    });

    return [$response, $queries];
}

it('lists the duels in 3 statements warm (4 cold) whatever their number, and reads one duel in 3', function (int $duels): void {
    config(['cup.duel.daily_challenges' => 50, 'cup.duel.pair_daily_challenges' => 50]);
    ['cup' => $cup, 'learners' => $learners, 'participants' => $participants, 'start' => $now] = CupDuelKit::running(6);
    [$a] = $learners;
    // Open challenges to a, from the others; and a's own history of the day (challenges made and withdrawn).
    $open = min($duels, 5);
    for ($i = 0; $i < $duels; $i++) {
        $from = $learners[1 + $i % 5];
        $duel = CupDuelKit::challenge($from, $cup, $participants[0], now: $now)->duel;
        if ($i < $duels - $open) {
            CupDuelKit::service()->cancel($from, $duel->id, $now);
        }
    }
    $mine = CupDuelKit::challenge($a, $cup, now: $now)->duel;

    [$cold, $coldQueries] = duelHttpBudget($a, 'GET', "/cups/{$cup->id}/duels", warm: false);
    [$warm, $warmQueries] = duelHttpBudget($a, 'GET', "/cups/{$cup->id}/duels", cup: $cup);
    [$one, $oneQueries] = duelHttpBudget($a, 'GET', "/cups/{$cup->id}/duels/{$mine->id}", cup: $cup);

    $cold->assertOk();
    $one->assertOk();
    // learner, the door (the cup, the group, my row + whether I may challenge), the list (one UNION of four index walks), [the emoji]
    expect($coldQueries)->toHaveCount(4, implode("\n", $coldQueries))
        ->and($warmQueries)->toHaveCount(3, implode("\n", $warmQueries))
        ->and($warm->assertOk()->json('incoming'))->toHaveCount($open)
        ->and($warm->json('today'))->toHaveCount($duels - $open)
        ->and($warm->json('mine.id'))->toBe($mine->id)
        ->and($oneQueries)->toHaveCount(3, implode("\n", $oneQueries)); // learner, the door, the duel with both names
})->with([1, 9]);

it('adds the learner and the door to the core on every action: challenge 10 / 11, accept 22, decline 9, cancel 8', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b, $c], 'participants' => [, $pb, $pc]] = CupDuelKit::running();
    $requestId = (string) Str::uuid();

    [$private, $privateQueries] = duelHttpBudget($a, 'POST', "/cups/{$cup->id}/duels", ['opponent_participant_id' => $pb->id, 'public' => false, 'request_id' => $requestId], cup: $cup);
    [$public, $publicQueries] = duelHttpBudget($c, 'POST', "/cups/{$cup->id}/duels", ['public' => true, 'request_id' => (string) Str::uuid()], cup: $cup);
    [$repeat, $repeatQueries] = duelHttpBudget($a, 'POST', "/cups/{$cup->id}/duels", ['opponent_participant_id' => $pb->id, 'public' => false, 'request_id' => $requestId], cup: $cup);
    $private->assertCreated();
    $public->assertCreated();
    $repeat->assertOk();

    Cache::forget(CupMembershipGate::key($cup->id, $b->id));
    [$accept, $acceptQueries] = duelHttpBudget($b, 'POST', "/cups/{$cup->id}/duels/{$private->json('id')}/accept", ['expect_rated' => true], cup: $cup);
    [$again, $againQueries] = duelHttpBudget($b, 'POST', "/cups/{$cup->id}/duels/{$private->json('id')}/accept", ['expect_rated' => true], cup: $cup);
    $accept->assertCreated();
    $again->assertOk();

    $toDecline = CupDuelKit::challenge($a, $cup, $pc, public: true)->duel;
    [$decline, $declineQueries] = duelHttpBudget($c, 'POST', "/cups/{$cup->id}/duels/{$toDecline->id}/decline", cup: $cup);
    $toCancel = CupDuelKit::challenge($b, $cup, $pc)->duel;
    [$cancel, $cancelQueries] = duelHttpBudget($b, 'POST', "/cups/{$cup->id}/duels/{$toCancel->id}/cancel", cup: $cup);
    [, $coldQueries] = duelHttpBudget($b, 'POST', "/cups/{$cup->id}/duels/{$toCancel->id}/cancel", warm: false);
    $decline->assertOk();
    $cancel->assertOk();

    // the core: 8 / 9 / 2 (CupDuelQueryBudgetTest) + the learner + the door
    expect($privateQueries)->toHaveCount(10, implode("\n", $privateQueries))
        ->and($publicQueries)->toHaveCount(11, implode("\n", $publicQueries))
        ->and($repeatQueries)->toHaveCount(4, implode("\n", $repeatQueries))
        // the core's 21 (its two events are one INSERT after the response here: 20) + the learner + the door
        ->and($acceptQueries)->toHaveCount(22, implode("\n", $acceptQueries))
        ->and($againQueries)->toHaveCount(6, implode("\n", $againQueries)) // the core's 4 + 2
        ->and($declineQueries)->toHaveCount(9, implode("\n", $declineQueries)) // the core's 7 + 2
        ->and($cancelQueries)->toHaveCount(8, implode("\n", $cancelQueries)) // the core's 6 (no outbox row: private) + 2
        ->and($coldQueries)->toHaveCount(7, implode("\n", $coldQueries)); // a repeat of the cancel (the core's 4) + 2 + the emoji cold
});

it('reads the table with the availability of a duel in the same 5 statements warm', function (): void {
    ['cup' => $cup, 'learners' => [$a]] = CupDuelKit::running();
    app(CupProEmoji::class)->forCup($cup);
    test()->withHeaders(PracticeKit::headers($a))->getJson("/api/twa/cups/{$cup->id}")->assertOk();

    [$board, $queries] = duelHttpBudget($a, 'GET', "/cups/{$cup->id}", cup: $cup);

    // learner, the cup + my row + the scalars of the duel, today (the day, my attempt), my days - as with the duels off (CupHttpQueryBudgetTest)
    expect($queries)->toHaveCount(5, implode("\n", $queries))
        ->and($board->assertOk()->json('me.duel.can_challenge'))->toBeTrue();
});
