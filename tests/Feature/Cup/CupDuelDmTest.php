<?php

declare(strict_types=1);

use App\Domain\Cup\Enums\CupDuelDmKind;
use App\Domain\Cup\Services\CupDuelService;
use App\Domain\Operations\Support\PureCache;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Exceptions\TelegramRateLimitedException;
use App\Domain\Telegram\Services\CupDuelMessenger;
use App\Jobs\PostCupMessageJob;
use App\Jobs\ScoreCupDuelJob;
use App\Jobs\SendCupDuelDmJob;
use App\Models\CupDuel;
use App\Models\Learner;
use App\Models\NotificationLog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Cup\CupDuelKit;
use Tests\Support\Cup\CupKit;
use Tests\Support\QueryLog;

/*
 * docs/33 §5.7, §11.9.4 п. 3 (D2): the DMs of a duel - the private challenge to the one challenged, "accepted" to the challenger - by
 * the rules of the senders of the bot (docs/34 §4в п. 8): the state read again, nobody blocked or unreachable, once per (kind, duel), a
 * 403 closes the DM, a 429 goes back to the job, a timeout is never sent again.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake([PostCupMessageJob::class, ScoreCupDuelJob::class, SendCupDuelDmJob::class]);
    Cache::flush();
    PureCache::store()->flush();
    config(['twa.base_url' => 'https://twa.test']);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function duelDm(CupDuelDmKind $kind, CupDuel $duel): void
{
    (new SendCupDuelDmJob($kind->value, $duel->id))->handle(app(CupDuelMessenger::class));
}

/** @return array{0: array<string, mixed>, 1: CupDuel, 2: list<Learner>} the private challenge of a to b, its DM to b sent */
function challengedDm(): array
{
    ['cup' => $cup, 'learners' => $learners, 'participants' => [, $pb]] = CupDuelKit::running();
    $duel = CupDuelKit::challenge($learners[0], $cup, $pb)->duel;
    duelDm(CupDuelDmKind::Challenged, $duel);

    return [CupKit::telegram()->sent[0] ?? [], $duel, $learners];
}

it('tells the one challenged privately, in his language, with a button into the challenge', function (): void {
    [$sent, $duel, [, $b]] = challengedDm();
    $cup = CupDuelKit::cup($duel->cup_id);
    $button = $sent['markup']['inline_keyboard'][0][0] ?? [];

    // Player 1 reads Uzbek (CupKit): the text and the button are his.
    expect($sent['chat_id'])->toBe($b->telegram_user_id)
        ->and($sent['text'])->toContain('Player 0')->toContain($cup->telegramGroup->title)->toContain('duelga chaqirmoqda')->toContain('Hisobli duel')
        ->and($button['text'])->toBe('⚔️ Chaqiruvni ochish')
        ->and($button['web_app']['url'])->toBe("https://twa.test/twa/cups/c/{$cup->public_code}/duels/{$duel->id}?from=cup_duel_dm");

    duelDm(CupDuelDmKind::Challenged, $duel);
    expect(CupKit::telegram()->sent)->toHaveCount(1)
        ->and(NotificationLog::query()->where('type', 'cup_duel_dm')->count())->toBe(1);
});

it('tells the challenger his challenge is accepted, with a button into the match - while the room waits', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b], 'participants' => [, $pb]] = CupDuelKit::running();
    $duel = CupDuelKit::accept($b, CupDuelKit::challenge($a, $cup, $pb, public: true)->duel);

    duelDm(CupDuelDmKind::Accepted, $duel);
    $sent = CupKit::telegram()->sent[0] ?? [];

    expect($sent['chat_id'])->toBe($a->telegram_user_id)
        ->and($sent['text'])->toContain('Player 1')->toContain('принял(а) ваш вызов')->toContain('30 секунд')
        ->and($sent['markup']['inline_keyboard'][0][0]['web_app']['url'])->toBe("https://twa.test/twa/battles/{$duel->battle_id}?from=cup_duel_accepted");

    // The room is over (the challenger never came): nothing more to say.
    DB::table('battles')->where('id', $duel->battle_id)->update(['status' => 'closed', 'end_reason' => 'room_expired', 'open_pair' => null, 'phase' => null, 'finished_at' => now()]);
    DB::table('notifications_log')->delete();
    duelDm(CupDuelDmKind::Accepted, $duel);
    expect(CupKit::telegram()->sent)->toHaveCount(1);
});

it('says nothing that no longer holds, nor to a learner who cannot be reached', function (Closure $spoil): void {
    ['cup' => $cup, 'learners' => [$a, $b], 'participants' => [, $pb]] = CupDuelKit::running();
    $duel = CupDuelKit::challenge($a, $cup, $pb)->duel;
    $spoil($duel, $a, $b);

    duelDm(CupDuelDmKind::Challenged, $duel);

    expect(CupKit::telegram()->sent)->toBe([])
        ->and(NotificationLog::query()->count())->toBe(0);
})->with([
    'declined' => [fn (CupDuel $duel, Learner $a, Learner $b) => app(CupDuelService::class)->decline($b, $duel->id)],
    'withdrawn' => [fn (CupDuel $duel, Learner $a) => app(CupDuelService::class)->cancel($a, $duel->id)],
    'past its time' => [fn () => CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(11))],
    'DM closed' => [fn (CupDuel $duel, Learner $a, Learner $b) => $b->forceFill(['dm_blocked_at' => now(), 'dm_blocked_reason' => 'bot_blocked'])->save()],
    'blocked' => [fn (CupDuel $duel, Learner $a, Learner $b) => $b->forceFill(['blocked_at' => now()])->save()],
    'deleted' => [fn (CupDuel $duel, Learner $a, Learner $b) => $b->delete()],
    'cups off' => [fn () => config(['cup.access' => 'off'])],
]);

it('sends no DM for a public challenge: its message is in the chat', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'participants' => [, $pb]] = CupDuelKit::running();
    $duel = CupDuelKit::challenge($a, $cup, $pb, public: true)->duel;

    duelDm(CupDuelDmKind::Challenged, $duel);

    expect(CupKit::telegram()->sent)->toBe([]);
});

it('closes the DM on a 403 and keeps no marker', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b], 'participants' => [, $pb]] = CupDuelKit::running();
    $duel = CupDuelKit::challenge($a, $cup, $pb)->duel;
    CupKit::telegram()->failSends = 1;
    CupKit::telegram()->failSendsForbidden = TelegramForbiddenException::REASON_BOT_BLOCKED;

    duelDm(CupDuelDmKind::Challenged, $duel);

    expect($b->fresh()?->dm_blocked_at)->not->toBeNull()
        ->and($b->fresh()?->dm_blocked_reason)->toBe('bot_blocked')
        ->and(NotificationLog::query()->count())->toBe(0)
        ->and(CupKit::telegram()->sent)->toBe([]);
});

it('gives a 429 back to the job without a marker and sends once on the retry; a timeout is never sent again', function (): void {
    ['cup' => $cup, 'learners' => [$a, , $c], 'participants' => [, $pb, $pc]] = CupDuelKit::running();
    $duel = CupDuelKit::challenge($a, $cup, $pb)->duel;
    CupKit::telegram()->failSends = 1;
    CupKit::telegram()->failSendsRetryAfter = 9;

    expect(fn () => duelDm(CupDuelDmKind::Challenged, $duel))->toThrow(TelegramRateLimitedException::class);
    expect(NotificationLog::query()->count())->toBe(0);
    duelDm(CupDuelDmKind::Challenged, $duel);
    expect(CupKit::telegram()->sent)->toHaveCount(1);

    $other = CupDuelKit::challenge($c, $cup, $pb)->duel;
    CupKit::telegram()->failSends = 1;
    CupKit::telegram()->failSendsRetryAfter = null;
    CupKit::telegram()->failSendsAsTransport = true;
    expect(fn () => duelDm(CupDuelDmKind::Challenged, $other))->toThrow(TelegramApiException::class);
    duelDm(CupDuelDmKind::Challenged, $other);
    expect(CupKit::telegram()->sent)->toHaveCount(1)
        ->and(NotificationLog::query()->count())->toBe(2);
});

it('sends a DM in 3 statements: the duel with everything it needs, the marker read, the marker written', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'participants' => [, $pb]] = CupDuelKit::running();
    $duel = CupDuelKit::challenge($a, $cup, $pb)->duel;

    $queries = QueryLog::during(fn () => duelDm(CupDuelDmKind::Challenged, $duel));

    expect($queries)->toHaveCount(3, implode("\n", $queries))
        ->and(CupKit::telegram()->sent)->toHaveCount(1);
});
