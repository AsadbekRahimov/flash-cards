<?php

declare(strict_types=1);

use App\Domain\Cup\Enums\CupMessageKind;
use App\Domain\Cup\Services\CupCards;
use App\Domain\Cup\Services\CupLifecycleService;
use App\Domain\Cup\Services\CupOutbox;
use App\Domain\Cup\Services\CupStandings;
use App\Domain\Cup\Services\CupSummaries;
use App\Domain\Operations\Support\PureCache;
use App\Domain\Telegram\Support\CupCallback;
use App\Jobs\PostCupMessageJob;
use App\Models\Cup;
use App\Models\CupMessage;
use App\Models\CupParticipant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Cup\CupBotKit;
use Tests\Support\Cup\CupKit;
use Tests\Support\QueryLog;

/*
 * The owner's requirement on SQL (docs/33 §11, docs/38) for the bot of the cup (K6): the statements of a tap, of a message of the
 * outbox and of the three commands are pinned here; the commands grow by a CONSTANT per cup (never per participant), and the choice
 * of the cups is one statement whatever their number. A change that adds a statement fails this file and has to say why.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CupKit::open();
    CupKit::course();
    config(['telegram.bot_username' => 'muhrla_test_bot', 'twa.base_url' => 'https://twa.test']);
    Storage::fake('public');
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * `$cups` running cups of `$players` each, every card posted at the start; one point for everybody on day 1.
 *
 * @return array{ids: list<int>, start: CarbonImmutable}
 */
function budgetCups(int $cups, int $players = 3): array
{
    $ids = [];
    $start = null;
    for ($i = 0; $i < $cups; $i++) {
        ['cup' => $cup, 'start' => $start] = CupKit::running($players);
        app(CupOutbox::class)->enqueue($cup->id, 'card', CupMessageKind::Card, $start);
        foreach (CupParticipant::query()->where('cup_id', $cup->id)->get() as $participant) {
            DB::table('cup_day_scores')->insert(['cup_id' => $cup->id, 'participant_id' => $participant->id, 'day' => 1, 'round_points' => 2, 'updated_at' => $start->addMinute()]);
            $participant->forceFill(['best_total' => 2, 'best_round_points' => 2])->saveQuietly();
        }
        $ids[] = $cup->id;
    }

    return ['ids' => $ids, 'start' => $start ?? throw new LogicException('no cups')];
}

/**
 * Both stores cold: the default one (the throttle of the membership) AND the pure one - the table (`cup:standings`) and the emoji
 * (`cup:emoji`) live in {@see PureCache}, which on PostgreSQL + Redis is its own store: `Cache::flush()` alone left them warm there and
 * the counts differed from SQLite.
 */
function cupBotColdCaches(): void
{
    Cache::flush();
    PureCache::store()->flush();
}

it('answers a tap of a member who is not an administrator without a write and a publication in a fixed number', function (): void {
    CarbonImmutable::setTestNow('2026-12-01 06:00:00');
    $group = CupKit::group();
    $cup = CupBotKit::propose($group);
    $messageId = (int) $cup->fresh()->card_message_id;
    $member = CupBotKit::plainMember();
    $admin = CupKit::admin();
    app(CupStandings::class)->forget($cup->id);

    $refused = QueryLog::during(fn () => CupBotKit::dispatch(CupBotKit::tap($group, CupCallback::publish($cup->id), $member, $messageId)));
    $published = QueryLog::during(fn () => CupBotKit::dispatch(CupBotKit::tap($group, CupCallback::publish($cup->id), $admin, $messageId)));

    // the group by chat id, the cup of the group - the core asks Telegram who tapped BEFORE it reads the course and counts its words
    // (CupLifecycleService::publish; it was 4 with those two reads) - nothing is written
    expect($refused)->toHaveCount(2)
        ->and(QueryLog::matching($refused, '/^(insert|update|delete)/i'))->toBe([])
        // + FOR UPDATE and UPDATE of the cup, the event (the core); the table of the card (no participants: no emoji), the hash
        ->and($published)->toHaveCount(9);
});

it('sends a message of the outbox in a fixed number of statements', function (): void {
    ['ids' => [$id]] = budgetCups(1, 6);
    $row = CupMessage::query()->create(['cup_id' => $id, 'dedupe_key' => 'summary:1', 'kind' => 'summary', 'status' => 'pending', 'attempts' => 0]);
    cupBotColdCaches();

    $sent = QueryLog::during(fn () => app(CupOutbox::class)->deliver($row->id, CarbonImmutable::now()));
    $again = QueryLog::during(fn () => app(CupOutbox::class)->deliver($row->id, CarbonImmutable::now()));

    // the claim, the row, the cup, its group, the table, the emoji, `sent`, the event
    expect($sent)->toHaveCount(8)
        ->and($again)->toHaveCount(1); // the claim finds nothing
});

it('chooses the cards to refresh in ONE statement and refreshes each in a constant number', function (int $cups): void {
    ['ids' => $ids, 'start' => $start] = budgetCups($cups);
    $idle = QueryLog::during(fn () => app(CupCards::class)->refreshDue($start->addMinutes(2), 200)); // nothing older than 5 minutes
    cupBotColdCaches();

    $run = QueryLog::during(fn () => app(CupCards::class)->refreshDue($start->addMinutes(6), 200));

    expect($idle)->toHaveCount(1)
        // the choice, the cups, their groups, the tables of all cards, the emoji of all cards, ONE `UPDATE … CASE` of the hashes -
        // whatever the number of cards (it was 3 + 3·N: the table, the emoji and the hash per card)
        ->and(count($run))->toBe(6)
        ->and(count(CupKit::telegram()->captionEdits))->toBe($cups);
})->with([2, 5]);

it('decides the summaries of N cups with one statement for the choice and a constant per cup', function (int $cups): void {
    ['start' => $start] = budgetCups($cups);
    Bus::fake([PostCupMessageJob::class]);
    cupBotColdCaches();

    $early = QueryLog::during(fn () => app(CupSummaries::class)->enqueueDue($start->addHours(10), 500));
    $run = QueryLog::during(fn () => app(CupSummaries::class)->enqueueDue($start->addHours(20), 500));

    expect($early)->toHaveCount(1)
        // the choice (the time of the summary in the SQL), the tables of all due cups, the cups to write locked first (docs/33
        // §11.9.2 п. 1: `cups → cup_days`, no cycle with a cancel / finish), the days decided (UPDATE … RETURNING), the hashes
        // (UPDATE … CASE), the rows (INSERT … RETURNING) - whatever the number of cups (it was 1 + 5·N)
        ->and(count($run))->toBe(6);
    Bus::assertDispatchedTimes(PostCupMessageJob::class, $cups);
})->with([2, 5]);

it('sweeps the outbox in two statements whatever the number of rows', function (int $rows): void {
    ['ids' => $ids, 'start' => $start] = budgetCups(1);
    for ($i = 1; $i <= $rows; $i++) {
        CupMessage::query()->create(['cup_id' => $ids[0], 'dedupe_key' => 'summary:'.$i, 'kind' => 'summary', 'status' => 'pending', 'attempts' => 0, 'created_at' => $start, 'updated_at' => $start]);
    }
    Bus::fake([PostCupMessageJob::class]);

    $sweep = QueryLog::during(fn () => app(CupOutbox::class)->sweep($start->addMinutes(10), 200));

    expect($sweep)->toHaveCount(2);
    Bus::assertDispatchedTimes(PostCupMessageJob::class, $rows);
})->with([2, 5]);

it('lets the finish write its final row without any statement of the bot', function (): void {
    ['ids' => [$id]] = budgetCups(1);
    $cup = Cup::query()->findOrFail($id);
    DB::table('cup_days')->where('cup_id', $id)->update(['built_at' => now()]);

    $finish = QueryLog::during(fn () => app(CupLifecycleService::class)->finish($id, CarbonImmutable::instance($cup->ends_at)->addMinutes(31)));

    expect($finish)->toHaveCount(8); // unchanged by K6 (CupQueryBudgetTest)
});
