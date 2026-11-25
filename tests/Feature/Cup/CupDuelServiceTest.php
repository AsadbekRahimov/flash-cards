<?php

declare(strict_types=1);

use App\Domain\Cup\Enums\CupDuelEndReason;
use App\Domain\Cup\Enums\CupDuelStatus;
use App\Domain\Cup\Exceptions\CupException;
use App\Domain\Cup\Services\CupDuelRenderer;
use App\Domain\Cup\Services\CupDuelService;
use App\Domain\Cup\Services\CupLifecycleService;
use App\Domain\Cup\Services\CupMembershipGate;
use App\Domain\Cup\Services\CupParticipationService;
use App\Domain\Cup\Services\CupScheduler;
use App\Domain\Engagement\Services\FriendshipService;
use App\Domain\Operations\Support\PureCache;
use App\Domain\Practice\Enums\BattleEndReason;
use App\Domain\Practice\Enums\BattleStatus;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Exceptions\BattleException;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Domain\Practice\Services\PracticeLimits;
use App\Jobs\PostCupMessageJob;
use App\Jobs\ScoreCupDuelJob;
use App\Models\Battle;
use App\Models\CupDuel;
use App\Models\CupParticipant;
use App\Models\Learner;
use App\Models\ProductEvent;
use App\Models\TelegramGroup;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Cup\CupDuelKit;
use Tests\Support\Cup\CupKit;
use Tests\Support\Practice\BattleKit;
use Tests\Support\Practice\PracticeKit;

/*
 * docs/33 §5.7, §9.2 D1 (column «проверка»), §11.9: the duels of a cup - the challenge and its refusals, the limits, the acceptance
 * into a live battle, the decline / withdrawal / expiry, the result (own right answers only, assigned to the day of the acceptance), the
 * void of a match nobody played, the rated / friendly status decided again at the acceptance, Free and Pro alike, the finish of the cup,
 * the outbox rows of a public challenge (one message, its edit - never a new one) and its text.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // The outbox rows are what D1 makes; their delivery (an edit of a message) is D2.
    Queue::fake([PostCupMessageJob::class]);
    // On PostgreSQL + Redis the ids repeat from test to test: nothing cached by an earlier one may answer for this one.
    Cache::flush();
    PureCache::store()->flush();
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** @return array{0: string, 1: array<string, mixed>} */
function duelRefusal(Closure $fn): array
{
    try {
        $fn();
    } catch (CupException|BattleException|PracticeException $e) {
        return [$e->errorCode, $e->details];
    }

    throw new RuntimeException('Nothing was refused.');
}

/** @return list<string> the dedupe keys of the outbox of the cup, in order */
function duelOutbox(int $cupId): array
{
    return DB::table('cup_messages')->where('cup_id', $cupId)->where('kind', 'duel')->orderBy('id')->pluck('dedupe_key')->all();
}

function dayScore(CupParticipant $participant, int $day): ?object
{
    return DB::table('cup_day_scores')->where('participant_id', $participant->id)->where('day', $day)->first();
}

// ------------------------------------------------------------------------------------------------------------ challenge

it('challenges a participant: an open rated duel for ten minutes, one open challenge per participant, a repeat answers the same duel', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'participants' => [$pa, $pb], 'start' => $now] = CupDuelKit::running();

    $outcome = CupDuelKit::challenge($a, $cup, $pb, requestId: 'AAAAAAAA-0000-4000-8000-000000000001');
    $duel = $outcome->duel;

    expect($outcome->created)->toBeTrue()
        ->and($duel->status)->toBe(CupDuelStatus::Open)
        ->and($duel->rated_offered)->toBeTrue()
        ->and($duel->day)->toBe(1)
        ->and($duel->challenger_participant_id)->toBe($pa->id)
        ->and($duel->opponent_participant_id)->toBe($pb->id)
        ->and(CupDuelKit::fresh($duel)->open_challenger_id)->toBe($pa->id)
        ->and($duel->expires_at->equalTo($now->startOfSecond()->addMinutes(10)))->toBeTrue()
        ->and(duelOutbox($cup->id))->toBe([]);

    $repeat = CupDuelKit::challenge($a, $cup, $pb, requestId: 'aaaaaaaa-0000-4000-8000-000000000001');
    expect($repeat->created)->toBeFalse()->and($repeat->duel->id)->toBe($duel->id);

    [$code, $details] = duelRefusal(fn () => CupDuelKit::challenge($a, $cup));
    expect($code)->toBe('cup_duel_already_open')->and($details)->toBe(['duel_id' => $duel->id])
        ->and(CupDuel::query()->count())->toBe(1)
        ->and(ProductEvent::query()->where('name', 'cup.duel_challenged')->count())->toBe(1);
});

it('lets a new challenge replace an open one whose time is up, closing it on the way', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $now] = CupDuelKit::running();
    $first = CupDuelKit::challenge($a, $cup, public: true, now: $now)->duel;

    $second = CupDuelKit::challenge($a, $cup, now: $now->addMinutes(11))->duel;

    expect(CupDuelKit::fresh($first)->status)->toBe(CupDuelStatus::Expired)
        ->and(CupDuelKit::fresh($first)->end_reason)->toBe(CupDuelEndReason::Expired)
        ->and($second->status)->toBe(CupDuelStatus::Open)
        ->and(duelOutbox($cup->id))->toBe(['duel:'.$first->id, 'duel:'.$first->id.':end']);
});

it('refuses a challenge of yourself, of a stranger to the cup, of a left or unchecked participant and between blocked learners', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b], 'participants' => [$pa, $pb, $pc], 'start' => $now] = CupDuelKit::running();
    $other = CupDuelKit::running(2);

    expect(duelRefusal(fn () => CupDuelKit::challenge($a, $cup, $pa))[0])->toBe('cup_duel_self')
        ->and(duelRefusal(fn () => CupDuelKit::challenge($a, $cup, $other['participants'][0]))[0])->toBe('cup_duel_unavailable');

    $pc->forceFill(['status' => 'left', 'left_at' => $now])->save();
    expect(duelRefusal(fn () => CupDuelKit::challenge($a, $cup, $pc))[0])->toBe('cup_duel_unavailable');

    $pc->forceFill(['status' => 'active', 'left_at' => null, 'membership_checked_at' => $now->subHours(25)])->save();
    expect(duelRefusal(fn () => CupDuelKit::challenge($a, $cup, $pc))[0])->toBe('cup_duel_unavailable');

    app(FriendshipService::class)->block($b, $a);
    expect(duelRefusal(fn () => CupDuelKit::challenge($a, $cup, $pb))[0])->toBe('cup_duel_unavailable')
        ->and(CupDuel::query()->count())->toBe(0);
});

it('refuses a learner who is no participant, and one who left the chat (Telegram says so) - he becomes left', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'participants' => [$pa]] = CupDuelKit::running();
    $stranger = Learner::factory()->create();

    expect(duelRefusal(fn () => CupDuelKit::challenge($stranger, $cup))[0])->toBe('cup_not_member');

    CupKit::member($a, 'left', false);
    Cache::forget(CupMembershipGate::key($cup->id, $a->id)); // the answer of the join is reused 10 s (real time on Redis)
    expect(duelRefusal(fn () => CupDuelKit::challenge($a, $cup))[0])->toBe('cup_not_member')
        ->and($pa->fresh()?->status->value)->toBe('left')
        ->and(CupDuel::query()->count())->toBe(0);
});

it('refuses while the duels, the battles or the practice are off, the cup is paused or not running', function (): void {
    ['cup' => $cup, 'learners' => [$a]] = CupDuelKit::running();

    config(['cup.duels' => false]);
    expect(duelRefusal(fn () => CupDuelKit::challenge($a, $cup))[0])->toBe('cup_duel_disabled');
    config(['cup.duels' => true, 'practice.battle.enabled' => false]);
    expect(duelRefusal(fn () => CupDuelKit::challenge($a, $cup))[0])->toBe('cup_duel_disabled');
    config(['practice.battle.enabled' => true, 'practice.access' => 'off']);
    expect(duelRefusal(fn () => CupDuelKit::challenge($a, $cup))[0])->toBe('cup_duel_disabled');
    config(['practice.access' => 'all']);

    TelegramGroup::query()->whereKey($cup->telegram_group_id)->update(['bot_status' => 'kicked']);
    expect(duelRefusal(fn () => CupDuelKit::challenge($a, $cup))[0])->toBe('cup_paused');
    TelegramGroup::query()->whereKey($cup->telegram_group_id)->update(['bot_status' => 'administrator']);

    app(CupLifecycleService::class)->cancelByPlatform($cup);
    expect(duelRefusal(fn () => CupDuelKit::challenge($a, $cup))[0])->toBe('cup_closed')
        ->and(CupDuel::query()->count())->toBe(0);
});

it('keeps the limits of a day: one participant to the same opponent, every challenge, the public ones per participant and per chat', function (): void {
    config(['cup.duel.pair_daily_challenges' => 2, 'cup.duel.daily_challenges' => 4, 'cup.duel.public_per_participant' => 1, 'cup.duel.public_per_chat' => 2]);
    ['cup' => $cup, 'learners' => [$a, $b, $c], 'participants' => [$pa, $pb, $pc]] = CupDuelKit::running();
    $service = CupDuelKit::service();

    foreach ([1, 2] as $i) {
        $duel = CupDuelKit::challenge($a, $cup, $pb)->duel;
        $service->cancel($a, $duel->id);
    }
    expect(duelRefusal(fn () => CupDuelKit::challenge($a, $cup, $pb)))->toBe(['cup_duel_limit', ['scope' => 'pair', 'limit' => 2]]);

    $service->cancel($a, CupDuelKit::challenge($a, $cup, $pc, public: true)->duel->id);
    expect(duelRefusal(fn () => CupDuelKit::challenge($a, $cup, public: true)))->toBe(['cup_duel_limit', ['scope' => 'public_participant', 'limit' => 1]]);
    $service->cancel($a, CupDuelKit::challenge($a, $cup)->duel->id);
    expect(duelRefusal(fn () => CupDuelKit::challenge($a, $cup)))->toBe(['cup_duel_limit', ['scope' => 'daily', 'limit' => 4]]);

    $service->cancel($b, CupDuelKit::challenge($b, $cup, public: true)->duel->id);
    expect(duelRefusal(fn () => CupDuelKit::challenge($c, $cup, public: true)))->toBe(['cup_duel_limit', ['scope' => 'public_chat', 'limit' => 2]])
        ->and(CupDuelKit::challenge($c, $cup)->created)->toBeTrue();
});

it('posts a public challenge in the chat as one outbox row with an accept link, the names made safe for the chat', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'participants' => [$pa, $pb]] = CupDuelKit::running();
    config(['telegram.bot_username' => 'muhrla_test_bot', 'twa.base_url' => 'https://twa.test']);
    $pa->forceFill(['display_name' => '@admin'])->save();

    $duel = CupDuelKit::challenge($a, $cup, $pb, public: true)->duel;

    expect(duelOutbox($cup->id))->toBe(['duel:'.$duel->id]);
    Queue::assertPushed(PostCupMessageJob::class, 1);

    $content = app(CupDuelRenderer::class)->message(CupDuelKit::fresh($duel), CupDuelKit::cup($cup), CupDuelKit::cup($cup)->telegramGroup);
    expect($content->text)->toContain('＠admin')
        ->not->toContain('@admin')
        ->toContain($pb->display_name)
        ->and($content->keyboard['inline_keyboard'][0][0]['url'] ?? '')->toStartWith('https://t.me/muhrla_test_bot?startapp=')
        ->and(base64_decode(strtr(explode('startapp=', $content->keyboard['inline_keyboard'][0][0]['url'] ?? '')[1] ?? '', '-_', '+/'), true))->toContain("/duels/{$duel->id}");
});

// ------------------------------------------------------------------------------------------------------------ accept

it('accepts into a live battle at once: a room with both players busy, two slots of the day, no friendship and no Free limit', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b], 'participants' => [$pa, $pb], 'start' => $now] = CupDuelKit::running();
    PracticeKit::limits(null, 0); // Free learners may call no battle at all: the rated duel is the cup's own quota
    $duel = CupDuelKit::challenge($a, $cup, $pb)->duel;

    $accepted = CupDuelKit::accept($b, $duel, true, $now->addMinute());
    $battle = CupDuelKit::battleOf($accepted);

    expect($accepted->status)->toBe(CupDuelStatus::Accepted)
        ->and($accepted->rated)->toBeTrue()
        ->and($accepted->accepted_day)->toBe(1)
        ->and(CupDuelKit::fresh($accepted)->open_challenger_id)->toBeNull()
        ->and($battle->status)->toBe(BattleStatus::Room)
        ->and($battle->cup_duel_id)->toBe($duel->id)
        ->and($battle->limit_exempt)->toBeTrue()
        ->and($battle->friendship_id)->toBeNull()
        ->and($battle->challenger_id)->toBe($a->id)
        ->and($battle->opponent_id)->toBe($b->id)
        ->and($battle->participants->pluck('active_learner_id')->sort()->values()->all())->toBe(collect([$a->id, $b->id])->sort()->values()->all())
        ->and(DB::table('cup_duel_slots')->where('cup_duel_id', $duel->id)->orderBy('participant_id')->pluck('participant_id')->all())->toBe([$pa->id, $pb->id]);

    // The rated duel did not spend the Free limit: an ordinary call is still refused by the limit (0), not counted with the duel.
    $limits = app(PracticeLimits::class)->forLearner($a, $now->addMinute());
    expect($limits['battles']['used'] ?? null)->toBe(0);

    $again = CupDuelKit::service()->accept($b, $duel->id, true, $now->addMinutes(2));
    expect($again->created)->toBeFalse()->and($again->duel->battle_id)->toBe($battle->id)
        ->and(Battle::query()->count())->toBe(1);
});

it('refuses to accept your own challenge, somebody else\'s addressed one, a closed one and an expired one', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b, $c], 'participants' => [, $pb], 'start' => $now] = CupDuelKit::running();
    $duel = CupDuelKit::challenge($a, $cup, $pb)->duel;
    $stranger = Learner::factory()->create();

    expect(duelRefusal(fn () => CupDuelKit::accept($a, $duel))[0])->toBe('cup_duel_own')
        ->and(duelRefusal(fn () => CupDuelKit::accept($c, $duel))[0])->toBe('cup_duel_not_found')
        ->and(duelRefusal(fn () => CupDuelKit::accept($stranger, $duel))[0])->toBe('cup_duel_not_found')
        ->and(duelRefusal(fn () => CupDuelKit::accept($b, 999_999))[0])->toBe('cup_duel_not_found')
        ->and(duelRefusal(fn () => CupDuelKit::accept($b, $duel, true, $now->addMinutes(10)))) ->toBe(['cup_duel_closed', ['status' => 'expired', 'reason' => 'expired']])
        ->and(CupDuelKit::fresh($duel)->status)->toBe(CupDuelStatus::Expired)
        ->and(Battle::query()->count())->toBe(0);
});

it('lets anybody else accept an open challenge, and only one of them', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b, $c], 'participants' => [, , $pc]] = CupDuelKit::running();
    $duel = CupDuelKit::challenge($a, $cup)->duel;

    $accepted = CupDuelKit::accept($c, $duel);

    expect($accepted->opponent_participant_id)->toBe($pc->id)
        ->and(duelRefusal(fn () => CupDuelKit::accept($b, $duel))[0])->toBe('cup_duel_not_found');
});

it('refuses the acceptance when the rated status changed since the challenge (409 rating_changed); the friendly one goes through', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b, $c], 'participants' => [, $pb, $pc]] = CupDuelKit::running();
    $toB = CupDuelKit::challenge($a, $cup, $pb)->duel;
    expect($toB->rated_offered)->toBeTrue();

    // $c and $b play their rated duel of the day first: $b's slot is taken.
    CupDuelKit::playOut(CupDuelKit::accept($b, CupDuelKit::challenge($c, $cup, $pb)->duel), $c, $b, 1, 1);

    expect(duelRefusal(fn () => CupDuelKit::accept($b, $toB, true)))->toBe(['cup_duel_rating_changed', ['rated' => false]]);
    $friendly = CupDuelKit::accept($b, $toB, false);

    expect($friendly->rated)->toBeFalse()
        ->and(DB::table('cup_duel_slots')->where('cup_duel_id', $toB->id)->count())->toBe(0)
        ->and(CupDuelKit::battleOf($friendly)->limit_exempt)->toBeFalse();
});

it('offers a friendly match when the challenger has played the rated duel of the day; a Free challenger at the battle limit is refused', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b, $c], 'participants' => [, $pb, $pc], 'start' => $now] = CupDuelKit::running();
    $first = CupDuelKit::accept($b, CupDuelKit::challenge($a, $cup, $pb)->duel);
    CupDuelKit::playOut($first, $a, $b, 3, 2);

    PracticeKit::limits(null, 0);
    expect(duelRefusal(fn () => CupDuelKit::challenge($a, $cup, $pc))[0])->toBe('practice_limit_reached');

    PracticeKit::limits(null, 1);
    $friendly = CupDuelKit::challenge($a, $cup, $pc)->duel;
    expect($friendly->rated_offered)->toBeFalse();

    // The limit is claimed at the acceptance, under the challenger's row: the match is the call of the day.
    $accepted = CupDuelKit::accept($c, $friendly, false);
    expect($accepted->rated)->toBeFalse()
        ->and(app(PracticeLimits::class)->forLearner($a)['battles']['used'] ?? null)->toBe(1);
});

it('closes a friendly challenge whose Free challenger spent his last battle before the acceptance', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b, $c], 'participants' => [, $pb, $pc]] = CupDuelKit::running();
    CupDuelKit::playOut(CupDuelKit::accept($b, CupDuelKit::challenge($a, $cup, $pb)->duel), $a, $b, 1, 1);
    PracticeKit::limits(null, 1);
    $friendly = CupDuelKit::challenge($a, $cup, $pc)->duel;
    // An ordinary battle of the challenger spends the call of the day in the meantime.
    Battle::factory()->create(['challenger_id' => $a->id, 'status' => BattleStatus::Finished, 'end_reason' => BattleEndReason::Completed, 'open_pair' => null]);

    expect(duelRefusal(fn () => CupDuelKit::accept($c, $friendly, false)))->toBe(['cup_duel_closed', ['status' => 'canceled', 'reason' => 'challenger_limit']])
        ->and(CupDuelKit::fresh($friendly)->status)->toBe(CupDuelStatus::Canceled);
});

it('lets a Free and a Pro learner both start the rated duel of the day', function (): void {
    ['cup' => $cup, 'learners' => [$free, $pro, $third], 'participants' => [$pFree, $pPro, $pThird]] = CupDuelKit::running();
    PracticeKit::limits(0, 0);
    PracticeKit::pro($pro);

    $byFree = CupDuelKit::accept($third, CupDuelKit::challenge($free, $cup, $pThird)->duel);
    expect($byFree->rated)->toBeTrue();

    config(['cup.duel.daily_challenges' => 20]);
    $other = CupDuelKit::running(2);
    PracticeKit::limits(0, 0);
    [$x, $y] = $other['learners'];
    PracticeKit::pro($y);
    $byPro = CupDuelKit::accept($x, CupDuelKit::challenge($y, $other['cup'], $other['participants'][0])->duel);
    expect($byPro->rated)->toBeTrue();
});

it('refuses the acceptance while a player is busy with another match, keeping the challenge and the slots untouched', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b, $c], 'participants' => [$pa, $pb, $pc]] = CupDuelKit::running();
    $toB = CupDuelKit::challenge($a, $cup, $pb)->duel;
    $busyMatch = CupDuelKit::accept($b, CupDuelKit::challenge($c, $cup, $pb)->duel, true);
    $battleId = (int) $busyMatch->battle_id;

    expect(duelRefusal(fn () => CupDuelKit::accept($b, $toB, false)))->toBe(['battle_busy', ['battle_id' => $battleId]])
        ->and(CupDuelKit::fresh($toB)->status)->toBe(CupDuelStatus::Open)
        ->and(DB::table('cup_duel_slots')->where('cup_duel_id', $toB->id)->count())->toBe(0);

    // The challenger is the busy one now: $a is challenged by... $c and $b are in a match; $a accepts nobody, $c challenged nobody new.
    $aBusy = CupDuelKit::running(3);
    [$x, $y, $z] = $aBusy['learners'];
    $xToY = CupDuelKit::challenge($x, $aBusy['cup'], $aBusy['participants'][1])->duel;
    CupDuelKit::accept($x, CupDuelKit::challenge($z, $aBusy['cup'], $aBusy['participants'][0])->duel); // $x is in a match with $z
    expect(duelRefusal(fn () => CupDuelKit::accept($y, $xToY, false))[0])->toBe('cup_duel_opponent_busy');
});

it('closes an open challenge whose challenger left the chat, and one of a cup that is over', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b, $c], 'participants' => [$pa, $pb], 'start' => $now] = CupDuelKit::running();
    $duel = CupDuelKit::challenge($a, $cup, $pb, public: true)->duel;
    app(CupParticipationService::class)->onLeftChat($a, CupDuelKit::cup($cup)->telegramGroup, $now->addMinute());

    expect(duelRefusal(fn () => CupDuelKit::accept($b, $duel, true, $now->addMinutes(2))))->toBe(['cup_duel_closed', ['status' => 'canceled', 'reason' => 'left']])
        ->and(duelOutbox($cup->id))->toBe(['duel:'.$duel->id, 'duel:'.$duel->id.':end']);

    $open = CupDuelKit::challenge($b, $cup, now: $now->addMinutes(3))->duel;
    app(CupLifecycleService::class)->cancelByPlatform(CupDuelKit::cup($cup));
    expect(duelRefusal(fn () => CupDuelKit::accept($c, $open, true, $now->addMinutes(4))))->toBe(['cup_duel_closed', ['status' => 'canceled', 'reason' => 'cup_closed']]);
});

// ------------------------------------------------------------------------------------------------------------ decline, cancel, expiry

it('declines by the addressee only; the public message gets one edit, never a new message; a repeat is a no-op', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b, $c], 'participants' => [, $pb]] = CupDuelKit::running();
    $duel = CupDuelKit::challenge($a, $cup, $pb, public: true)->duel;
    $service = CupDuelKit::service();

    expect(duelRefusal(fn () => $service->decline($a, $duel->id))[0])->toBe('cup_duel_not_recipient')
        ->and(duelRefusal(fn () => $service->decline($c, $duel->id))[0])->toBe('cup_duel_not_found');

    $declined = $service->decline($b, $duel->id);
    $service->decline($b, $duel->id);

    expect($declined->status)->toBe(CupDuelStatus::Declined)
        ->and(duelOutbox($cup->id))->toBe(['duel:'.$duel->id, 'duel:'.$duel->id.':end'])
        ->and(app(CupDuelRenderer::class)->message(CupDuelKit::fresh($duel), CupDuelKit::cup($cup), CupDuelKit::cup($cup)->telegramGroup)->text)->toBe('Вызов не состоялся.')
        ->and(duelRefusal(fn () => CupDuelKit::accept($b, $duel))[0])->toBe('cup_duel_closed');
});

it('withdraws by the challenger only; an open challenge cannot be declined', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b]] = CupDuelKit::running();
    $duel = CupDuelKit::challenge($a, $cup)->duel;
    $service = CupDuelKit::service();

    expect(duelRefusal(fn () => $service->decline($b, $duel->id))[0])->toBe('cup_duel_not_recipient')
        ->and(duelRefusal(fn () => $service->cancel($b, $duel->id))[0])->toBe('cup_duel_not_found')
        ->and($service->cancel($a, $duel->id)->status)->toBe(CupDuelStatus::Canceled)
        ->and($service->cancel($a, $duel->id)->end_reason)->toBe(CupDuelEndReason::Challenger);
});

it('expires the open challenges past their time in one sweep, edits the public ones, and a second sweep does nothing', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b, $c], 'start' => $now] = CupDuelKit::running();
    $public = CupDuelKit::challenge($a, $cup, public: true, now: $now)->duel;
    $private = CupDuelKit::challenge($b, $cup, now: $now)->duel;
    $fresh = CupDuelKit::challenge($c, $cup, now: $now->addMinutes(5))->duel;

    $first = CupDuelKit::service()->sweep($now->addMinutes(10));
    $second = CupDuelKit::service()->sweep($now->addMinutes(10));

    expect($first['expired'])->toBe(2)->and($second['expired'])->toBe(0)
        ->and(CupDuelKit::fresh($public)->status)->toBe(CupDuelStatus::Expired)
        ->and(CupDuelKit::fresh($private)->status)->toBe(CupDuelStatus::Expired)
        ->and(CupDuelKit::fresh($fresh)->status)->toBe(CupDuelStatus::Open)
        ->and(CupDuelKit::fresh($public)->open_challenger_id)->toBeNull()
        ->and(duelOutbox($cup->id))->toBe(['duel:'.$public->id, 'duel:'.$public->id.':end']);
});

// ------------------------------------------------------------------------------------------------------------ the result

it('scores a played match into the day of the acceptance: own right answers only, the winner, the best days', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b], 'participants' => [$pa, $pb]] = CupDuelKit::running();
    $duel = CupDuelKit::accept($b, CupDuelKit::challenge($a, $cup, $pb, public: true)->duel);

    CupDuelKit::playOut($duel, $a, $b, 4, 2);
    $done = CupDuelKit::fresh($duel);

    expect($done->status)->toBe(CupDuelStatus::Done)
        ->and($done->end_reason)->toBeNull()
        ->and($done->challenger_points)->toBe(4)
        ->and($done->opponent_points)->toBe(2)
        ->and($done->winner_participant_id)->toBe($pa->id)
        ->and(dayScore($pa, 1)?->duel_points)->toBe(4)
        ->and(dayScore($pb, 1)?->duel_points)->toBe(2)
        ->and($pa->fresh()?->best_total)->toBe(4)
        ->and($pa->fresh()?->days_played)->toBe(1)
        ->and(duelOutbox($cup->id))->toBe(['duel:'.$duel->id, 'duel:'.$duel->id.':end']);

    $text = app(CupDuelRenderer::class)->message($done, CupDuelKit::cup($cup), CupDuelKit::cup($cup)->telegramGroup)->text;
    expect($text)->toContain('4 : 2')->toContain($pa->display_name);
});

it('gives the surrendering side nothing to the other: each keeps only his own right answers', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b], 'participants' => [$pa, $pb]] = CupDuelKit::running();
    $duel = CupDuelKit::accept($b, CupDuelKit::challenge($a, $cup, $pb)->duel);
    $battle = CupDuelKit::playOut($duel, $a, $b, 2, 1, stopAfterRound: 2);

    BattleKit::service()->surrender($b, $battle->id);
    $done = CupDuelKit::fresh($duel);

    expect(BattleKit::fresh($battle)->end_reason)->toBe(BattleEndReason::Surrender)
        ->and($done->status)->toBe(CupDuelStatus::Done)
        ->and($done->challenger_points)->toBe(2)
        ->and($done->opponent_points)->toBe(1)
        ->and($done->winner_participant_id)->toBe($pa->id)
        ->and(dayScore($pa, 1)?->duel_points)->toBe(2);
});

it('makes a match that closed before anybody answered void: no points, the slots of the day are free again', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b], 'participants' => [$pa, $pb], 'start' => $now] = CupDuelKit::running();
    $duel = CupDuelKit::accept($b, CupDuelKit::challenge($a, $cup, $pb, public: true)->duel, true, $now);

    // The challenger never comes: the room runs out (the presence grace) and the sweeper of the battles closes it.
    CarbonImmutable::setTestNow($now->addMinutes(3));
    Artisan::call('practice:battles-sweep');

    $void = CupDuelKit::fresh($duel);
    expect(CupDuelKit::battleOf($duel)->status)->toBe(BattleStatus::Closed)
        ->and($void->status)->toBe(CupDuelStatus::Void)
        ->and($void->end_reason)->toBe(CupDuelEndReason::NoAnswers)
        ->and(DB::table('cup_duel_slots')->count())->toBe(0)
        ->and(dayScore($pa, 1))->toBeNull()
        ->and(duelOutbox($cup->id))->toBe(['duel:'.$duel->id, 'duel:'.$duel->id.':end'])
        ->and(ProductEvent::query()->where('name', 'cup.duel_void')->count())->toBe(1);

    // The rated duel of the day can be played again.
    $again = CupDuelKit::challenge($a, $cup, $pb, now: $now->addMinutes(4))->duel;
    expect($again->rated_offered)->toBeTrue();
});

it('scores a duel once: a repeated hook, the job twice and the sweep change nothing', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b], 'participants' => [$pa, $pb]] = CupDuelKit::running();
    $duel = CupDuelKit::accept($b, CupDuelKit::challenge($a, $cup, $pb)->duel);
    CupDuelKit::playOut($duel, $a, $b, 5, 3);
    $updatedAt = DB::table('cup_day_scores')->where('participant_id', $pa->id)->value('updated_at');

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(5));
    expect(app(CupDuelService::class)->score($duel->id))->toBeNull();
    (new ScoreCupDuelJob($duel->id))->handle(app(CupDuelService::class));
    $sweep = CupDuelKit::service()->sweep();

    expect($sweep['scored'])->toBe(0)
        ->and(dayScore($pa, 1)?->duel_points)->toBe(5)
        ->and(DB::table('cup_day_scores')->where('participant_id', $pa->id)->value('updated_at'))->toBe($updatedAt)
        ->and(ProductEvent::query()->where('name', 'cup.duel_scored')->count())->toBe(1);
});

it('scores through the sweep when the job was lost', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b], 'participants' => [$pa, $pb]] = CupDuelKit::running();
    $duel = CupDuelKit::accept($b, CupDuelKit::challenge($a, $cup, $pb)->duel);
    Queue::fake([PostCupMessageJob::class, ScoreCupDuelJob::class]);
    CupDuelKit::playOut($duel, $a, $b, 2, 2);
    Queue::assertPushed(ScoreCupDuelJob::class, 1);
    expect(CupDuelKit::fresh($duel)->status)->toBe(CupDuelStatus::Accepted);

    $report = CupDuelKit::service()->sweep();

    expect($report['scored'])->toBe(1)
        ->and(CupDuelKit::fresh($duel)->winner_participant_id)->toBeNull()
        ->and(app(CupDuelRenderer::class)->message(CupDuelKit::fresh($duel), CupDuelKit::cup($cup), CupDuelKit::cup($cup)->telegramGroup)->text)->toContain('Ничья');
});

it('writes nothing of the cup for a friendly match, and nothing outside the cup and the battle for any duel (К3)', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b, $c], 'participants' => [$pa, $pb, $pc]] = CupDuelKit::running();
    CupDuelKit::playOut(CupDuelKit::accept($b, CupDuelKit::challenge($a, $cup, $pb)->duel), $a, $b, 3, 3);
    $friendly = CupDuelKit::accept($c, CupDuelKit::challenge($a, $cup, $pc)->duel, false);
    CupDuelKit::playOut($friendly, $a, $c, 5, 5);

    expect(CupDuelKit::fresh($friendly)->status)->toBe(CupDuelStatus::Done)
        ->and(CupDuelKit::fresh($friendly)->challenger_points)->toBe(5)
        ->and(dayScore($pa, 1)?->duel_points)->toBe(3)
        ->and(dayScore($pc, 1))->toBeNull()
        ->and(DB::table('learner_daily_stats')->count())->toBe(0)
        ->and(DB::table('word_repetitions')->count())->toBe(0)
        ->and(DB::table('game_runs')->count())->toBe(0);
});

it('takes a duel accepted after midnight into the day of the acceptance', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b], 'participants' => [$pa, $pb], 'start' => $start] = CupDuelKit::running();
    $lateEvening = CarbonImmutable::instance($start)->addDay()->subMinutes(3); // 23:58 of day 1
    $pb->forceFill(['membership_checked_at' => $lateEvening->subHour()])->save(); // he played the round of the day an hour ago
    $duel = CupDuelKit::challenge($a, $cup, $pb, now: $lateEvening)->duel;
    $afterMidnight = $lateEvening->addMinutes(4);
    CarbonImmutable::setTestNow($afterMidnight);

    $accepted = CupDuelKit::accept($b, $duel, true, $afterMidnight);
    CupDuelKit::playOut($accepted, $a, $b, 1, 0);

    expect($duel->day)->toBe(1)
        ->and(CupDuelKit::fresh($duel)->accepted_day)->toBe(2)
        ->and(dayScore($pa, 2)?->duel_points)->toBe(1)
        ->and(dayScore($pa, 1))->toBeNull()
        ->and(DB::table('cup_duel_slots')->where('cup_duel_id', $duel->id)->value('day'))->toBe(2);
});

it('credits a duel whose player left the chat while it was played (a started duel plays out)', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b], 'participants' => [$pa, $pb], 'start' => $now] = CupDuelKit::running();
    $duel = CupDuelKit::accept($b, CupDuelKit::challenge($a, $cup, $pb)->duel, true, $now);
    $battle = CupDuelKit::playOut($duel, $a, $b, 2, 2, stopAfterRound: 2);
    app(CupParticipationService::class)->onLeftChat($b, CupDuelKit::cup($cup)->telegramGroup, CarbonImmutable::now());

    BattleKit::service()->surrender($a, $battle->id);

    expect($pb->fresh()?->status->value)->toBe('left')
        ->and(dayScore($pb, 1)?->duel_points)->toBe(2)
        ->and(dayScore($pa, 1)?->duel_points)->toBe(2);
});

it('takes a result that comes after the finish of the cup into nothing: the places stay as the finish wrote them', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b], 'participants' => [$pa, $pb]] = CupDuelKit::running();
    $duel = CupDuelKit::accept($b, CupDuelKit::challenge($a, $cup, $pb)->duel);
    Queue::fake([PostCupMessageJob::class, ScoreCupDuelJob::class]);
    CupDuelKit::playOut($duel, $a, $b, 5, 0);

    // The finish without the settle of the duels (the switch off at that moment): the places come first.
    $endsAt = CarbonImmutable::instance(CupDuelKit::cup($cup)->ends_at);
    config(['cup.duels' => false]);
    app(CupScheduler::class)->advance($endsAt->addMinutes(31));
    expect(CupDuelKit::cup($cup)->status->value)->toBe('finished');
    $ranks = CupParticipant::query()->where('cup_id', $cup->id)->orderBy('id')->pluck('final_rank')->all();

    app(CupDuelService::class)->score($duel->id);

    expect(CupDuelKit::fresh($duel)->status)->toBe(CupDuelStatus::Done)
        ->and(CupDuelKit::fresh($duel)->end_reason)->toBe(CupDuelEndReason::CupClosed)
        ->and(DB::table('cup_day_scores')->where('cup_id', $cup->id)->sum('duel_points'))->toBe(0)
        ->and(CupParticipant::query()->where('cup_id', $cup->id)->orderBy('id')->pluck('final_rank')->all())->toBe($ranks);
});

it('takes the results of the finished matches into the cup before the places of the finish', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b], 'participants' => [$pa, $pb]] = CupDuelKit::running();
    $duel = CupDuelKit::accept($b, CupDuelKit::challenge($a, $cup, $pb)->duel);
    Queue::fake([PostCupMessageJob::class, ScoreCupDuelJob::class]);
    CupDuelKit::playOut($duel, $a, $b, 3, 1);

    $endsAt = CarbonImmutable::instance(CupDuelKit::cup($cup)->ends_at);
    app(CupScheduler::class)->advance($endsAt->addMinutes(31));

    expect(CupDuelKit::fresh($duel)->end_reason)->toBeNull()
        ->and($pa->fresh()?->best_total)->toBe(3)
        ->and($pa->fresh()?->final_rank)->toBe(1)
        ->and($pb->fresh()?->final_rank)->toBe(2);
});

it('voids a duel whose battle is gone', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b], 'participants' => [, $pb]] = CupDuelKit::running();
    $duel = CupDuelKit::accept($b, CupDuelKit::challenge($a, $cup, $pb)->duel);
    Battle::query()->whereKey($duel->battle_id)->delete();

    expect(CupDuelKit::service()->sweep()['void'])->toBe(1)
        ->and(CupDuelKit::fresh($duel)->end_reason)->toBe(CupDuelEndReason::BattleGone)
        ->and(DB::table('cup_duel_slots')->count())->toBe(0);
});

it('leaves an ordinary battle alone: its end queues no scoring', function (): void {
    Queue::fake([ScoreCupDuelJob::class]);
    Illuminate\Support\Facades\Storage::fake('practice_audio');
    [$me, $friend, $course, , $friendship] = BattleKit::world();
    $battle = BattleKit::invite($me, $friendship, PracticeMode::Pairs, $course);
    BattleKit::service()->decline($friend, $battle->id);

    Queue::assertNotPushed(ScoreCupDuelJob::class);
    expect(BattleKit::fresh($battle)->cup_duel_id)->toBeNull()
        ->and(BattleKit::fresh($battle)->limit_exempt)->toBeFalse();
});

it('keeps the shape of a duel on PostgreSQL: one open challenge per participant, one rated duel a day, the CHECKs', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'participants' => [$pa, $pb]] = CupDuelKit::running();
    $duel = CupDuelKit::challenge($a, $cup, $pb)->duel;

    expect(fn () => DB::transaction(fn () => CupDuel::factory()->create(['cup_id' => $cup->id, 'challenger_participant_id' => $pa->id])))->toThrow(Illuminate\Database\QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('cup_duels')->where('id', $duel->id)->update(['status' => 'done'])))->toThrow(Illuminate\Database\QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('cup_duels')->where('id', $duel->id)->update(['challenger_points' => 6])))->toThrow(Illuminate\Database\QueryException::class);

    DB::table('cup_duel_slots')->insert(['cup_id' => $cup->id, 'participant_id' => $pa->id, 'day' => 1, 'cup_duel_id' => $duel->id]);
    expect(fn () => DB::transaction(fn () => DB::table('cup_duel_slots')->insert(['cup_id' => $cup->id, 'participant_id' => $pa->id, 'day' => 1, 'cup_duel_id' => $duel->id])))
        ->toThrow(Illuminate\Database\QueryException::class);
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'The CHECK constraints are PostgreSQL only.');

it('rolls the tables of the duel back and forth with the link of the battles', function (): void {
    $path = 'database/migrations/2026_11_29_100001_create_cup_duels_tables.php';
    $this->artisan('migrate:rollback', ['--path' => $path])->assertSuccessful();
    expect(Illuminate\Support\Facades\Schema::hasTable('cup_duels'))->toBeFalse()
        ->and(Illuminate\Support\Facades\Schema::hasColumn('battles', 'cup_duel_id'))->toBeFalse()
        ->and(Illuminate\Support\Facades\Schema::hasColumn('battles', 'limit_exempt'))->toBeFalse();

    $this->artisan('migrate', ['--path' => $path])->assertSuccessful();
    expect(Illuminate\Support\Facades\Schema::hasTable('cup_duel_slots'))->toBeTrue()
        ->and(Illuminate\Support\Facades\Schema::hasIndex('cup_duels', 'cup_duels_open_expiry_idx'))->toBeTrue()
        ->and(Illuminate\Support\Facades\Schema::hasIndex('battles', 'battles_cup_duel_id_unique'))->toBeTrue();
});
