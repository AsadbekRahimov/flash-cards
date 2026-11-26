<?php

declare(strict_types=1);

use App\Domain\Cup\Enums\CupDuelStatus;
use App\Domain\Cup\Enums\CupParticipantStatus;
use App\Domain\Cup\Exceptions\CupException;
use App\Domain\Cup\Services\CupLifecycleService;
use App\Domain\Cup\Services\CupMembershipGate;
use App\Domain\Cup\Services\CupParticipationService;
use App\Domain\Cup\Services\CupRoundService;
use App\Domain\Cup\Services\CupScoreBook;
use App\Domain\Cup\Services\CupStandings;
use App\Domain\Operations\Support\PureCache;
use App\Domain\Telegram\DTO\ChatMemberInfo;
use App\Jobs\PostCupMessageJob;
use App\Jobs\SendCupDuelDmJob;
use App\Models\Cup;
use App\Models\CupParticipant;
use App\Models\CupRoundAttempt;
use App\Models\Learner;
use App\Models\ProductEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\Cup\CupDuelKit;
use Tests\Support\Cup\CupKit;
use Tests\Support\Practice\PracticeKit;
use Tests\Support\QueryLog;

/*
 * «Выйти из кубка» (owner's decision of 27.11.2026, docs/33 §11.12, docs/04 A.23 `POST /cups/{id}/leave`): a participant leaves the cup
 * himself, for good. `left` with `withdrawn_at`: his points stay in the table with the mark and take their place at the finish; he no
 * longer sees the cup nor lists it, and neither a join nor a fresh "member" of Telegram brings him back, though he is still in the chat.
 * His open challenges and the challenges to him close (`canceled/left`, the public ones edited), an accepted duel and a started attempt
 * play out. The organiser may leave (the cup is the chat's). A repeat answers the same; a stranger and a missing cup get the 403 of
 * every route; no call to Telegram; the budget of statements is pinned.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake([PostCupMessageJob::class, SendCupDuelDmJob::class]);
    Cache::flush();
    PureCache::store()->flush();
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** @param array<string, mixed> $body */
function leaveHttp(Learner $learner, string $method, string $path, array $body = []): TestResponse
{
    return test()->withHeaders(PracticeKit::headers($learner))->json($method, '/api/twa'.$path, $body);
}

function leaveRow(Learner $learner, Cup $cup): CupParticipant
{
    return CupParticipant::query()->where('cup_id', $cup->id)->where('learner_id', $learner->id)->firstOrFail();
}

it('leaves the cup for good: left with its points in the table, gone from my cups, the table closed to me though I am in the chat', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b, $c], 'start' => $now] = CupDuelKit::running(3);
    $pa = leaveRow($a, $cup);
    app(CupScoreBook::class)->addRoundPoints($cup->id, $pa->id, 1, 8, 5, $now);
    app(CupStandings::class)->forget($cup->id);

    $response = leaveHttp($a, 'POST', "/cups/{$cup->id}/leave")->assertOk();
    expect($response->json())->toBe([
        'participant_id' => $pa->id,
        'cup_id' => $cup->id,
        'status' => 'left',
        'left' => true,
        'left_at' => $response->json('left_at'),
    ])->and($response->json('left_at'))->toBeString();

    $row = $pa->fresh();
    expect($row->status)->toBe(CupParticipantStatus::Left)
        ->and($row->withdrawn_at)->not->toBeNull()
        ->and($row->left_at)->not->toBeNull()
        ->and($row->best_total)->toBe(8)
        ->and(ProductEvent::query()->where('name', 'cup.left')->sole()->props)->toMatchArray(['cup_id' => $cup->id, 'participant_id' => $pa->id, 'status' => 'running', 'duels_closed' => 0]);

    // The others see him with his points and the mark.
    $table = leaveHttp($b, 'GET', "/cups/{$cup->id}")->assertOk()->json('standings');
    $line = collect($table)->firstWhere('participant_id', $pa->id);
    expect($line['total'])->toBe(8)->and($line['left'])->toBeTrue();

    // He: the cup is gone from his list, and every route is the 403 of a stranger - without a call to Telegram, though he is a member.
    CupKit::member($a);
    CupKit::telegram()->chatMemberLookups = [];
    expect(leaveHttp($a, 'GET', '/cups')->assertOk()->json('cups'))->toBe([]);
    $bodies = [];
    foreach ([['GET', "/cups/{$cup->id}"], ['GET', "/cups/{$cup->id}/rounds/today"], ['POST', "/cups/{$cup->id}/join"], ['POST', "/cups/{$cup->id}/rounds/today"], ['PATCH', "/cups/{$cup->id}/me", ['display_name' => 'Back']], ['GET', "/cups/by-code/{$cup->public_code}"]] as $route) {
        $bodies[] = leaveHttp($a, $route[0], $route[1], $route[2] ?? [])->assertForbidden()->getContent();
    }
    expect(array_unique($bodies))->toHaveCount(1)
        ->and(json_decode((string) $bodies[0], true)['error']['code'])->toBe('cup_not_member')
        ->and(CupKit::telegram()->chatMemberLookups)->toBe([])
        ->and($pa->fresh()->status)->toBe(CupParticipantStatus::Left);
});

it('never brings back one who left the cup himself: not the join, not a fresh "member" at a round or a duel', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b], 'start' => $now] = CupDuelKit::running(2);
    $pa = leaveRow($a, $cup);
    app(CupParticipationService::class)->leave($cup, $pa, $a, $now);
    CupKit::member($a);

    $refused = static function (Closure $fn): string {
        try {
            $fn();
        } catch (CupException $e) {
            return $e->errorCode;
        }

        return 'passed';
    };
    $withdrawn = $pa->fresh();
    expect($refused(fn () => app(CupParticipationService::class)->join($cup, $a, null, $now)))->toBe('cup_not_member')
        ->and($refused(fn () => app(CupMembershipGate::class)->admitToRound($cup, $withdrawn, $a, $now)))->toBe('cup_not_member')
        ->and($refused(fn () => app(CupRoundService::class)->startToday($a, $cup, $now)))->toBe('cup_not_member')
        ->and($refused(fn () => CupDuelKit::challenge($a, $cup, leaveRow($b, $cup), now: $now)))->toBe('cup_not_member')
        ->and($pa->fresh()->status)->toBe(CupParticipantStatus::Left)
        ->and($pa->fresh()->withdrawn_at)->not->toBeNull();

    // The event of Telegram "left the chat" afterwards changes nothing either.
    expect(app(CupParticipationService::class)->onLeftChat($a, $cup->telegramGroup, $now->addMinute()))->toBe(0);
});

it('answers a double tap with the same body and one event; a stranger, a member who never joined and a missing cup the same 403', function (): void {
    ['cup' => $cup, 'learners' => [$a]] = CupDuelKit::running(2);
    $first = leaveHttp($a, 'POST', "/cups/{$cup->id}/leave")->assertOk()->json();
    $again = leaveHttp($a, 'POST', "/cups/{$cup->id}/leave")->assertOk()->json();
    expect($again)->toBe($first)
        ->and(ProductEvent::query()->where('name', 'cup.left')->count())->toBe(1);

    $stranger = Learner::factory()->create();
    $member = Learner::factory()->create();
    CupKit::member($member);
    CupKit::telegram()->chatMemberLookups = [];
    $bodies = [];
    foreach ([[$stranger, $cup->id], [$member, $cup->id], [$a, 999_999]] as [$who, $cupId]) {
        $bodies[] = leaveHttp($who, 'POST', "/cups/{$cupId}/leave")->assertForbidden()->getContent();
    }
    expect(array_unique($bodies))->toHaveCount(1)
        ->and(json_decode((string) $bodies[0], true))->toBe(['error' => ['code' => 'cup_not_member', 'message' => 'The cup is open to the members of its chat only.']])
        ->and(CupKit::telegram()->chatMemberLookups)->toBe([]);
});

it('lets a participant who left the chat leave the cup for good, and the organiser leave too; a closed cup is a 410', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b, $c], 'start' => $now] = CupDuelKit::running(3);
    CupParticipant::query()->whereKey(leaveRow($b, $cup)->id)->update(['status' => 'left', 'left_at' => $now->subHour()]);
    leaveHttp($b, 'POST', "/cups/{$cup->id}/leave")->assertOk()->assertJsonPath('left', true);
    expect(leaveRow($b, $cup)->left_at?->equalTo($now->subHour()))->toBeTrue(); // the first leave is kept

    // The organiser: the cup is the chat's - its administrators keep managing it (the cancel asks Telegram, not the participation).
    Cup::query()->whereKey($cup->id)->update(['proposed_by_telegram_user_id' => $c->telegram_user_id]);
    leaveHttp($c, 'POST', "/cups/{$cup->id}/leave")->assertOk();
    CupKit::telegram()->chatMembersByUser[(int) $c->telegram_user_id] = new ChatMemberInfo('administrator', true);
    expect(app(CupLifecycleService::class)->cancelByChatAdmin(CupDuelKit::cup($cup), (int) $c->telegram_user_id, $now))->toBeTrue();

    leaveHttp($a, 'POST', "/cups/{$cup->id}/leave")->assertStatus(410)->assertJsonPath('error.code', 'cup_closed');
    expect(leaveRow($a, $cup)->status)->toBe(CupParticipantStatus::Active);
});

it('closes my open challenge and the challenges to me (the public one edited), and lets an accepted duel play out and be scored', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b, $c, $d], 'participants' => [$pa, $pb, $pc, $pd], 'start' => $now] = CupDuelKit::running(4);
    $playing = CupDuelKit::accept($b, CupDuelKit::challenge($a, $cup, $pb, now: $now)->duel, true, $now);
    $mine = CupDuelKit::challenge($c, $cup, null, public: true, now: $now)->duel;  // $c: open to anybody, in the chat
    $toMe = CupDuelKit::challenge($d, $cup, $pc, now: $now)->duel;                 // to $c, private

    $left = app(CupParticipationService::class)->leave(CupDuelKit::cup($cup), $pc, $c, $now->addSecond());
    expect($left->withdrawn_at)->not->toBeNull();

    foreach ([$mine, $toMe] as $duel) {
        $fresh = CupDuelKit::fresh($duel);
        expect($fresh->status)->toBe(CupDuelStatus::Canceled)
            ->and($fresh->end_reason?->value)->toBe('left')
            ->and($fresh->open_challenger_id)->toBeNull();
    }
    expect(DB::table('cup_messages')->where('dedupe_key', "duel:{$mine->id}:end")->count())->toBe(1)
        ->and(ProductEvent::query()->where('name', 'cup.left')->sole()->props['duels_closed'])->toBe(2);

    // A player of an accepted duel leaves: the match plays out and is scored (docs/33 §11.9.2 п. 5).
    app(CupParticipationService::class)->leave(CupDuelKit::cup($cup), $pa->fresh(), $a, $now->addSeconds(2));
    CupDuelKit::playOut($playing, $a, $b, 3, 2);
    expect(CupDuelKit::fresh($playing)->status)->toBe(CupDuelStatus::Done)
        ->and((int) DB::table('cup_day_scores')->where('participant_id', $pa->id)->value('duel_points'))->toBe(3);
});

it('lets a started attempt of the round play out: its answers after the leave count', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $now] = CupDuelKit::running(2);
    $view = app(CupRoundService::class)->startToday($a, $cup, $now)->view;
    $dayId = (int) CupRoundAttempt::query()->whereKey($view->attemptId)->value('cup_day_id');

    app(CupParticipationService::class)->leave($cup, leaveRow($a, $cup), $a, $now->addSeconds(2));
    $answer = app(CupRoundService::class)->answer($a, $view->attemptId, (string) Str::uuid(), (string) $view->current['id'], CupKit::rightAnswer($dayId, (string) $view->current['id']), $now->addSeconds(5));

    expect($answer->body['points'])->toBe(2)
        ->and(leaveRow($a, $cup)->best_total)->toBe(2);
    // The resume of the started attempt still answers; a new start is refused (no new attempt).
    expect(app(CupRoundService::class)->startToday($a, $cup, $now->addSeconds(6))->created)->toBeFalse();
});

it('takes the one who left himself into the places of the finish, like one who left the chat', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b], 'start' => $now] = CupDuelKit::running(2);
    $pa = leaveRow($a, $cup);
    app(CupScoreBook::class)->addRoundPoints($cup->id, $pa->id, 1, 10, 5, $now);
    app(CupParticipationService::class)->leave($cup, $pa, $a, $now->addMinute());

    expect(app(CupLifecycleService::class)->finish($cup->id, CarbonImmutable::instance($cup->ends_at)->addMinutes(31)))->toBeTrue()
        ->and($pa->fresh()->final_rank)->toBe(1)
        ->and(leaveRow($b, $cup)->final_rank)->toBe(2);
});

it('leaves a scheduled cup too: one player less at the start', function (): void {
    CupKit::open();
    CupKit::course();
    $group = CupKit::group();
    $now = CarbonImmutable::parse('2026-12-01 06:00:00', 'UTC');
    CarbonImmutable::setTestNow($now);
    $cup = CupKit::scheduled($group, $now);
    $learner = Learner::factory()->create();
    CupKit::member($learner);
    $participant = app(CupParticipationService::class)->join($cup->fresh(['telegramGroup']) ?? $cup, $learner, null, $now)->participant;

    leaveHttp($learner, 'POST', "/cups/{$cup->id}/leave")->assertOk()->assertJsonPath('status', 'left');
    expect(ProductEvent::query()->where('name', 'cup.left')->sole()->props['status'])->toBe('scheduled')
        ->and($participant->fresh()->withdrawn_at)->not->toBeNull();
});

it('keeps the CHECK of a withdrawal on PostgreSQL: withdrawn only as left', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('The CHECKs live on PostgreSQL.');
    }
    ['cup' => $cup, 'learners' => [$a]] = CupDuelKit::running(2);

    expect(fn () => CupParticipant::query()->whereKey(leaveRow($a, $cup)->id)->update(['withdrawn_at' => now()]))
        ->toThrow(QueryException::class);
});

it('leaves in 8 statements with a public challenge to edit, 7 without, a repeat in 2, without Telegram', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b, $c], 'participants' => [, $pb]] = CupDuelKit::running(3);
    CupDuelKit::challenge($a, $cup, $pb, public: true);
    CupKit::telegram()->chatMemberLookups = [];

    $count = static function (Learner $who) use ($cup): int {
        $headers = PracticeKit::headers($who);
        test()->withHeaders($headers)->getJson('/api/twa/me')->assertOk();

        return count(QueryLog::during(fn () => test()->withHeaders($headers)->postJson("/api/twa/cups/{$cup->id}/leave")->assertOk()));
    };

    // learner; the cup + group + my row (CupReader::locate); the cup FOR KEY SHARE; my row FOR NO KEY UPDATE (BEFORE the duels, docs/33
    // §11.12); the open duels (one UPDATE … RETURNING); the edit of the public one (one INSERT … ON CONFLICT); UPDATE of my row; the
    // event cup.left - and nothing for a quiet one but the edit.
    $headersA = PracticeKit::headers($a);
    test()->withHeaders($headersA)->getJson('/api/twa/me')->assertOk();
    $statements = QueryLog::during(fn () => test()->withHeaders($headersA)->postJson("/api/twa/cups/{$cup->id}/leave")->assertOk());
    $lock = array_keys(preg_grep('/^select .*cup_participants.* for no key update$/i', $statements) ?: []);
    $duels = array_keys(preg_grep('/^update ["`]?cup_duels/i', $statements) ?: []);
    expect($statements)->toHaveCount(8, implode("\n", $statements))
        ->and($duels)->toHaveCount(1);
    if (DB::getDriverName() === 'pgsql') {
        expect($lock)->toHaveCount(1)->and($lock[0])->toBeLessThan($duels[0]);
    }
    expect($count($c))->toBe(7);
    expect($count($a))->toBe(2) // a repeat: the learner and the door
        ->and(CupKit::telegram()->chatMemberLookups)->toBe([]);
});
