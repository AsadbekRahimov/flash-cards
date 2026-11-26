<?php

declare(strict_types=1);

use App\Domain\Cup\Exceptions\CupException;
use App\Domain\Cup\Services\CupMembershipGate;
use App\Domain\Cup\Services\CupParticipationService;
use App\Domain\Cup\Services\CupRoundService;
use App\Domain\Operations\Support\PureCache;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Models\Cup;
use App\Models\CupParticipant;
use App\Models\Learner;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\Cup\CupKit;
use Tests\Support\Practice\PracticeKit;

/*
 * Review of release 5 (docs/33 §11.2 п. 7, §11.7 п. 2, §11.8): the doors of the cup by its numeric id. A stranger and a missing cup get
 * ONE 403 for every failure of the check (a pause, a bot that cannot check, Telegram out, a busy check) - only a definite "member" opens
 * a cup to him; a participant (any status) is told the reasons. A participant who left or was removed sees the table only while he is
 * a member again, and never renames himself into the chat.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CupKit::open();
    CupKit::course();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    Carbon::setTestNow();
});

/** @param array<string, mixed> $body */
function doorHttp(Learner $learner, string $method, string $path, array $body = []): TestResponse
{
    return test()->withHeaders(PracticeKit::headers($learner))->json($method, '/api/twa'.$path, $body);
}

/**
 * The refusal of the check for `$learner` (or for the whole group): returns what to release afterwards.
 *
 * @return Closure(): void
 */
function doorFailure(string $case, Cup $cup, Learner $learner): Closure
{
    Cache::flush();
    PureCache::store()->flush();
    switch ($case) {
        case 'paused':
            $cup->telegramGroup->forceFill(['bot_status' => 'kicked'])->save();
            break;
        case 'bot not admin':
            CupKit::telegram()->chatMemberStatus = 'member';
            CupKit::memberFails($learner, TelegramApiException::apiError('Bad Request: member list is inaccessible', 400));
            break;
        case 'telegram down':
            CupKit::memberFails($learner, new TelegramApiException('cURL error 28: timeout'));
            break;
        case 'busy':
            CarbonImmutable::setTestNow();
            Carbon::setTestNow();
            config(['cup.membership.wait_seconds' => 1]);
            $lock = Cache::lock("cup:member-check:{$cup->id}:{$learner->id}", 10);
            $lock->get();

            return static fn () => $lock instanceof Lock ? $lock->release() : null;
    }

    return static fn () => null;
}

/** @return array<string, array{0: int, 1: string}> the reason a participant is told */
function doorReasons(): array
{
    return [
        'paused' => [409, 'cup_paused'],
        'bot not admin' => [409, 'cup_unverifiable'],
        'telegram down' => [503, 'cup_unverifiable'],
        'busy' => [429, 'cup_check_busy'],
    ];
}

it('answers a stranger and a missing cup by the id with one 403 whatever failed, and tells a participant why', function (string $case, string $who): void {
    ['cup' => $cup, 'learners' => [$a, $b]] = CupKit::running(2);
    app(CupParticipationService::class)->onLeftChat($b, $cup->telegramGroup);
    $stranger = Learner::factory()->create();
    $reference = doorHttp($stranger, 'GET', '/cups/'.((int) Cup::query()->max('id') + 1000))->assertForbidden()->json();

    [$learner, $id] = match ($who) {
        'active participant' => [$a, $cup->id],
        'left participant' => [$b, $cup->id],
        'stranger' => [$stranger, $cup->id],
        default => [$stranger, (int) Cup::query()->max('id') + 1000],
    };
    $release = doorFailure($case, Cup::query()->with('telegramGroup')->findOrFail($cup->id), $learner);

    try {
        $read = doorHttp($learner, 'GET', "/cups/{$id}");
        $join = doorHttp($learner, 'POST', "/cups/{$id}/join");
    } finally {
        $release();
    }

    [$status, $code] = doorReasons()[$case];
    if ($who === 'stranger' || $who === 'missing cup') {
        expect([$read->status(), $read->json()])->toBe([403, $reference])
            ->and([$join->status(), $join->json()])->toBe([403, $reference]);
    } elseif ($who === 'active participant') {
        // He sees his table without a call; his join (a repeat) asks Telegram and is told why it could not.
        expect($read->status())->toBe(200)
            ->and([$join->status(), $join->json('error.code')])->toBe([$status, $code]);
    } else {
        expect([$read->status(), $read->json('error.code')])->toBe([$status, $code])
            ->and([$join->status(), $join->json('error.code')])->toBe([$status, $code]);
    }
    expect(CupParticipant::query()->where('learner_id', $stranger->id)->exists())->toBeFalse();
})->with(array_keys(doorReasons()))->with(['active participant', 'left participant', 'stranger', 'missing cup']);

it('shows the table to a participant who left only while he is a member again, in a finished cup too', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b]] = CupKit::running(2);
    $stranger = Learner::factory()->create();
    CupKit::member($stranger, 'left', false);
    $reference = doorHttp($stranger, 'GET', "/cups/{$cup->id}")->assertForbidden()->json();

    app(CupParticipationService::class)->onLeftChat($b, $cup->telegramGroup);
    CupKit::member($b, 'kicked', false);
    Cache::flush();
    CupKit::telegram()->chatMemberLookups = [];

    // Removed from the chat: the very 403 of a stranger - no names, no points; the "no" is kept for 30 s.
    expect(doorHttp($b, 'GET', "/cups/{$cup->id}")->assertForbidden()->json())->toBe($reference)
        ->and(doorHttp($b, 'GET', "/cups/{$cup->id}/rounds/today")->assertForbidden()->json())->toBe($reference)
        ->and(CupKit::telegram()->chatMemberLookups)->toHaveCount(1);

    // Back in the chat: he sees it again (his status stays `left` until a join or a round brings him back).
    Cache::flush();
    CupKit::member($b);
    $table = doorHttp($b, 'GET', "/cups/{$cup->id}")->assertOk();
    expect($table->json('me.status'))->toBe('left')->and($table->json('standings'))->toHaveCount(2);

    // A finished cup: the same rule; an active participant still sees it without a call.
    $cup->forceFill(['status' => 'finished', 'active_group_id' => null, 'finished_at' => now()])->save();
    Cache::flush();
    CupKit::member($b, 'left', false);
    CupKit::telegram()->chatMemberLookups = [];
    expect(doorHttp($b, 'GET', "/cups/{$cup->id}")->assertForbidden()->json())->toBe($reference)
        ->and(doorHttp($a, 'GET', "/cups/{$cup->id}")->assertOk()->json('cup.status'))->toBe('finished')
        ->and(CupKit::telegram()->chatMemberLookups)->toHaveCount(1);
});

it('lets only an active participant who is in the chat right now rename himself', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b]] = CupKit::running(2);
    $reference = doorHttp(Learner::factory()->create(), 'PATCH', "/cups/{$cup->id}/me", ['display_name' => 'Spam'])->assertForbidden()->json();

    // Left (Telegram told us): the 403 of a stranger, without asking Telegram, nothing written.
    app(CupParticipationService::class)->onLeftChat($b, $cup->telegramGroup);
    CupKit::telegram()->chatMemberLookups = [];
    expect(doorHttp($b, 'PATCH', "/cups/{$cup->id}/me", ['display_name' => 'Spam'])->assertForbidden()->json())->toBe($reference)
        ->and(CupKit::telegram()->chatMemberLookups)->toBe([]);

    // Active by the database but kicked by now (the event never came): the fresh check refuses and makes him `left`.
    Cache::flush();
    CupKit::member($a, 'kicked', false);
    expect(doorHttp($a, 'PATCH', "/cups/{$cup->id}/me", ['display_name' => 'Spam'])->assertForbidden()->json())->toBe($reference);
    $row = CupParticipant::query()->where('learner_id', $a->id)->firstOrFail();
    expect($row->status->value)->toBe('left')->and($row->display_name)->toBe('Player 0')->and($row->rename_count)->toBe(0);

    // A participant is told why the check failed - and keeps his name.
    $c = Learner::factory()->create();
    CupKit::member($c);
    doorHttp($c, 'POST', "/cups/{$cup->id}/join")->assertCreated();
    Cache::flush();
    CupKit::memberFails($c, new TelegramApiException('cURL error 28: timeout'));
    expect(doorHttp($c, 'PATCH', "/cups/{$cup->id}/me", ['display_name' => 'Cleo'])->assertStatus(503)->json('error.details.reason'))->toBe('telegram_unavailable');
    $cup->telegramGroup->forceFill(['meta' => ['cups_blocked' => true]])->save();
    expect(doorHttp($c, 'PATCH', "/cups/{$cup->id}/me", ['display_name' => 'Cleo'])->assertStatus(409)->json('error.code'))->toBe('cup_paused');
    $cup->telegramGroup->forceFill(['meta' => []])->save();

    // In the chat now: renamed, the check written.
    Cache::flush();
    CupKit::member($c);
    CupKit::telegram()->chatMemberLookups = [];
    doorHttp($c, 'PATCH', "/cups/{$cup->id}/me", ['display_name' => 'Cleo'])->assertOk()->assertJsonPath('display_name', 'Cleo');
    expect(CupKit::telegram()->chatMemberLookups)->toHaveCount(1);
});

it('ignores a late or repeated "left" of Telegram older than the last successful check', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $now] = CupKit::running(2);
    app(CupParticipationService::class)->onLeftChat($a, $cup->telegramGroup, $now);
    CupKit::member($a);
    Cache::flush();
    app(CupRoundService::class)->startToday($a, $cup, $now->addMinutes(5)); // he came back: active, checked at +5 min

    // The same "left" delivered again (Telegram retries a webhook), or an older one arriving late: nothing changes.
    expect(app(CupParticipationService::class)->onLeftChat($a, $cup->telegramGroup, $now))->toBe(0)
        ->and(app(CupParticipationService::class)->onLeftChat($a, $cup->telegramGroup, $now->addMinutes(4)))->toBe(0)
        ->and(DB::table('cup_participants')->where('learner_id', $a->id)->value('status'))->toBe('active');

    // A newer one does.
    expect(app(CupParticipationService::class)->onLeftChat($a, $cup->telegramGroup, $now->addMinutes(6)))->toBe(1);
});

it('does not keep a "member" asked before a leave that was processed while Telegram answered', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $now] = CupKit::running(2);
    Cache::flush();
    $gate = app(CupMembershipGate::class);
    $fired = false;
    CupKit::telegram()->beforeChatMemberAnswer = function () use (&$fired, $a, $cup, $now): void {
        if (! $fired) {
            $fired = true;
            // The webhook "left" runs between the question and the write of its answer to the cache.
            app(CupParticipationService::class)->onLeftChat($a, $cup->telegramGroup, $now);
        }
    };
    CupKit::telegram()->chatMemberLookups = [];

    expect($gate->check($cup, $a, $now)->value)->toBe('member');      // the answer Telegram gave before the leave
    expect(Cache::get(CupMembershipGate::key($cup->id, $a->id)))->toStartWith('member@'); // written after the drop of the key ...
    CupKit::member($a, 'left', false);
    expect($gate->check($cup, $a, $now)->value)->toBe('not_member')   // ... but not reused: Telegram is asked again
        ->and(CupKit::telegram()->chatMemberLookups)->toHaveCount(2);
});

it('does not make a participant active again with a "member" asked before his recorded leave', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $now] = CupKit::running(2);
    Cache::flush();
    $fired = false;
    CupKit::telegram()->beforeChatMemberAnswer = function () use (&$fired, $a, $cup, $now): void {
        if (! $fired) {
            $fired = true;
            app(CupParticipationService::class)->onLeftChat($a, $cup->telegramGroup, $now->addSecond()); // he left just after the question
        }
    };

    expect(fn () => app(CupRoundService::class)->startToday($a, $cup, $now))->toThrow(fn (CupException $e) => expect($e->errorCode)->toBe('cup_not_member'));
    expect(DB::table('cup_participants')->where('learner_id', $a->id)->value('status'))->toBe('left')
        ->and(DB::table('cup_round_attempts')->count())->toBe(0);
});
