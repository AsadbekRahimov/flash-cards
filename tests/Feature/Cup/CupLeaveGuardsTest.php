<?php

declare(strict_types=1);

use App\Domain\Cup\Enums\CupDuelDmKind;
use App\Domain\Cup\Enums\CupDuelStatus;
use App\Domain\Cup\Exceptions\CupException;
use App\Domain\Cup\Services\CupOutbox;
use App\Domain\Cup\Services\CupParticipationService;
use App\Domain\Cup\Services\CupRoundService;
use App\Domain\Operations\Support\PureCache;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Services\CupDuelMessenger;
use App\Jobs\PostCupMessageJob;
use App\Jobs\ScoreCupDuelJob;
use App\Jobs\SendCupDuelDmJob;
use App\Models\Cup;
use App\Models\CupDuel;
use App\Models\CupMessage;
use App\Models\CupParticipant;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Cup\CupDuelKit;
use Tests\Support\Cup\CupKit;

/*
 * docs/33 §11.12 «нет открытого вызова с вышедшим», the paths a fork cannot show cheaply: Telegram out of reach lets the door pass by a
 * row read BEFORE the transaction (`stale_ok_hours`), and the participant leaves the cup - or the chat - while Telegram is being asked
 * (the hook of the fake client runs between the read and the transaction). The statement that writes (the INSERT of the challenge, the
 * lock of the duel at the acceptance, the INSERT of the attempt) reads the status again and refuses; the senders of the bot (the DM of
 * a challenge, the message in the chat) say nothing about a challenge whose player is gone.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake([PostCupMessageJob::class, ScoreCupDuelJob::class, SendCupDuelDmJob::class]);
    Cache::flush();
    PureCache::store()->flush();
    config(['twa.base_url' => 'https://twa.test']);
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** Telegram cannot be reached for `$who`; `$meanwhile` runs once while it is being asked. */
function guardTelegramDownWhile(Learner $who, Closure $meanwhile): void
{
    CupKit::memberFails($who, new TelegramApiException('cURL error 28: timed out'));
    Cache::flush();
    $fired = false;
    CupKit::telegram()->beforeChatMemberAnswer = function () use (&$fired, $meanwhile): void {
        if (! $fired) {
            $fired = true;
            $meanwhile();
        }
    };
}

function guardLeave(Cup $cup, Learner $learner, CarbonImmutable $at): void
{
    $participant = CupParticipant::query()->where('cup_id', $cup->id)->where('learner_id', $learner->id)->firstOrFail();
    app(CupParticipationService::class)->leave(CupDuelKit::cup($cup), $participant, $learner, $at);
}

function guardRefusal(Closure $fn): string
{
    try {
        $fn();
    } catch (CupException $e) {
        return $e->errorCode;
    }

    return 'none';
}

it('refuses a challenge to one who leaves the cup while Telegram is asked about the challenger', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b], 'participants' => [, $pb], 'start' => $now] = CupDuelKit::running(2);
    guardTelegramDownWhile($a, fn () => guardLeave($cup, $b, $now));

    expect(guardRefusal(fn () => CupDuelKit::challenge($a, $cup, $pb, now: $now->addMinute())))->toBe('cup_duel_unavailable')
        ->and(CupDuel::query()->count())->toBe(0)
        ->and(DB::table('cup_messages')->where('dedupe_key', 'like', 'duel:%')->count())->toBe(0);
});

it('refuses a public challenge of one who leaves the cup from another device while Telegram is asked', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $now] = CupDuelKit::running(2);
    guardTelegramDownWhile($a, fn () => guardLeave($cup, $a, $now));

    expect(guardRefusal(fn () => CupDuelKit::challenge($a, $cup, null, public: true, now: $now->addMinute())))->toBe('cup_duel_unavailable')
        ->and(CupDuel::query()->count())->toBe(0)
        ->and(DB::table('cup_messages')->where('dedupe_key', 'like', 'duel:%')->count())->toBe(0);
});

it('refuses the acceptance of one who leaves the cup while Telegram is asked: the challenge stays open for the others', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b], 'start' => $now] = CupDuelKit::running(3);
    $duel = CupDuelKit::challenge($a, $cup, null, public: true, now: $now)->duel;
    guardTelegramDownWhile($b, fn () => guardLeave($cup, $b, $now->addMinute()));

    expect(guardRefusal(fn () => CupDuelKit::accept($b, $duel, true, $now->addMinute())))->toBe('cup_not_member')
        ->and(CupDuelKit::fresh($duel)->status)->toBe(CupDuelStatus::Open)
        ->and(DB::table('cup_duel_slots')->count())->toBe(0)
        ->and(DB::table('battles')->count())->toBe(0);
});

it('closes, not accepts, the challenge of one who left the chat while Telegram is asked about the one who accepts', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b], 'start' => $now] = CupDuelKit::running(3);
    $duel = CupDuelKit::challenge($a, $cup, null, public: true, now: $now)->duel;
    guardTelegramDownWhile($b, fn () => app(CupParticipationService::class)->onLeftChat($a, $cup->telegramGroup, $now->addMinute()));

    expect(guardRefusal(fn () => CupDuelKit::accept($b, $duel, true, $now->addMinute())))->toBe('cup_duel_closed');
    $final = CupDuelKit::fresh($duel);
    expect($final->status)->toBe(CupDuelStatus::Canceled)
        ->and($final->end_reason?->value)->toBe('left')
        ->and(DB::table('cup_duel_slots')->count())->toBe(0)
        ->and(DB::table('battles')->count())->toBe(0)
        ->and(DB::table('cup_messages')->where('dedupe_key', "duel:{$duel->id}:end")->count())->toBe(1);
});

it('starts no attempt of the round for one who leaves the cup while Telegram is asked', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $now] = CupDuelKit::running(2);
    guardTelegramDownWhile($a, fn () => guardLeave($cup, $a, $now));

    expect(guardRefusal(fn () => app(CupRoundService::class)->startToday($a, CupDuelKit::cup($cup), $now->addMinute())))->toBe('cup_not_member')
        ->and(DB::table('cup_round_attempts')->count())->toBe(0)
        ->and(DB::table('cup_day_scores')->count())->toBe(0);
});

it('still starts the round by the stale row when Telegram is out of reach and nobody left', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $now] = CupDuelKit::running(2);
    guardTelegramDownWhile($a, fn () => null);

    expect(app(CupRoundService::class)->startToday($a, CupDuelKit::cup($cup), $now->addMinute())->created)->toBeTrue()
        ->and(DB::table('cup_round_attempts')->count())->toBe(1);
});

it('sends no DM of a challenge whose addressee or challenger is no longer an active participant', function (string $gone): void {
    ['cup' => $cup, 'learners' => [$a, $b], 'participants' => [$pa, $pb]] = CupDuelKit::running(2);
    $duel = CupDuelKit::challenge($a, $cup, $pb)->duel;
    // The state the core never leaves behind (an open challenge with a player gone) - the sender must not trust it anyway.
    DB::table('cup_participants')->where('id', $gone === 'addressee' ? $pb->id : $pa->id)->update(
        $gone === 'left the chat' ? ['status' => 'left', 'left_at' => now()] : ['status' => 'left', 'left_at' => now(), 'withdrawn_at' => now()],
    );

    (new SendCupDuelDmJob(CupDuelDmKind::Challenged->value, $duel->id))->handle(app(CupDuelMessenger::class));

    expect(CupKit::telegram()->sent)->toBe([])
        ->and(DB::table('notifications_log')->where('type', 'cup_duel_dm')->count())->toBe(0);
})->with(['addressee', 'challenger', 'left the chat']);

it('posts no public challenge whose challenger or addressee is gone, and still posts one whose players are active', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b, $c], 'participants' => [$pa, $pb]] = CupDuelKit::running(3);
    $gone = CupDuelKit::challenge($a, $cup, $pb, public: true)->duel;
    $fine = CupDuelKit::challenge($c, $cup, null, public: true)->duel;
    DB::table('cup_participants')->where('id', $pb->id)->update(['status' => 'left', 'left_at' => now()]);
    $row = static fn (CupDuel $duel): CupMessage => CupMessage::query()->where('cup_id', $duel->cup_id)->where('dedupe_key', CupOutbox::duelKey($duel->id))->sole();

    app(CupOutbox::class)->deliver($row($gone)->id, CarbonImmutable::now());
    app(CupOutbox::class)->deliver($row($fine)->id, CarbonImmutable::now());

    expect($row($gone)->status->value)->toBe('skipped')
        ->and($row($gone)->error)->toBe('player_gone')
        ->and($row($fine)->status->value)->toBe('sent')
        ->and(CupKit::telegram()->groupMessages)->toHaveCount(1);
});
