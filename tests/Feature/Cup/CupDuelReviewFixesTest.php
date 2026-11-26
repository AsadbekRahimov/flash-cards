<?php

declare(strict_types=1);

use App\Domain\Cup\Enums\CupDuelEndReason;
use App\Domain\Cup\Enums\CupDuelStatus;
use App\Domain\Cup\Exceptions\CupException;
use App\Domain\Engagement\Enums\FriendshipStatus;
use App\Domain\Operations\Support\PureCache;
use App\Domain\Practice\Enums\BattleEndReason;
use App\Domain\Practice\Enums\BattleStatus;
use App\Domain\Practice\Exceptions\BattleException;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Domain\Practice\Services\Battle\BattleClock;
use App\Jobs\PostCupMessageJob;
use App\Jobs\ScoreCupDuelJob;
use App\Jobs\SendCupDuelDmJob;
use App\Models\Battle;
use App\Models\CupDuel;
use App\Models\Friendship;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\Cup\CupDuelKit;
use Tests\Support\Practice\BattleKit;
use Tests\Support\Practice\PracticeKit;

/*
 * docs/33 §11.11 (review of releases 6 and 7): the kind of a duel for the one who looks (`rated_for_me`) - the acceptance of an open rated
 * challenge by a participant who has played his rated duel today goes through at the first try; the match of a duel is played whatever
 * the access of the player to the practice (pilot, plan); a duel nobody answered in, or one the system voided, gives the slots back; the
 * open challenges of a blocked learner are not listed; a blocked or deleted learner is not challenged; the dates of a duel are immutable.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake([PostCupMessageJob::class, SendCupDuelDmJob::class]);
    Cache::flush();
    PureCache::store()->flush();
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** @param array<string, mixed> $body */
function fixHttp(Learner $learner, string $method, string $path, array $body = []): TestResponse
{
    return test()->withHeaders(PracticeKit::headers($learner))->json($method, '/api/twa'.$path, $body);
}

/** @return array{0: string, 1: array<string, mixed>} */
function fixRefusal(Closure $fn): array
{
    try {
        $fn();
    } catch (CupException|BattleException|PracticeException $e) {
        return [$e->errorCode, $e->details];
    }

    throw new RuntimeException('Nothing was refused.');
}

// ------------------------------------------------------------------------------------------------ 1. rated_for_me

it('shows an open rated challenge as friendly to a participant who has played his rated duel today, and accepts it at the first try', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b, $c], 'participants' => [, $pb]] = CupDuelKit::running(3);
    // $b plays his rated duel of the day with $a; $c then challenges anybody - rated for $c.
    CupDuelKit::playOut(CupDuelKit::accept($b, CupDuelKit::challenge($a, $cup, $pb)->duel), $a, $b, 1, 1);
    $open = CupDuelKit::challenge($c, $cup, public: true)->duel;
    expect($open->rated_offered)->toBeTrue();

    $shown = fixHttp($b, 'GET', "/cups/{$cup->id}/duels/{$open->id}")->assertOk();
    $listed = collect(fixHttp($b, 'GET', "/cups/{$cup->id}/duels")->assertOk()->json('open'))->firstWhere('id', $open->id);
    // The challenger sees what he offered; another participant with a free slot - rated.
    $mine = fixHttp($c, 'GET', "/cups/{$cup->id}/duels/{$open->id}")->assertOk();
    $other = fixHttp($a, 'GET', "/cups/{$cup->id}/duels/{$open->id}")->assertOk();

    expect($shown->json('rated_offered'))->toBeTrue()
        ->and($shown->json('rated_for_me'))->toBeFalse()
        ->and($listed['rated_for_me'] ?? null)->toBeFalse()
        ->and($mine->json('rated_for_me'))->toBeTrue()
        // $a has played his rated duel too (with $b).
        ->and($other->json('rated_for_me'))->toBeFalse();

    $accepted = fixHttp($b, 'POST', "/cups/{$cup->id}/duels/{$open->id}/accept", ['expect_rated' => $shown->json('rated_for_me')]);

    $accepted->assertCreated();
    expect($accepted->json('rated'))->toBeFalse()
        ->and($accepted->json('rated_for_me'))->toBeFalse()
        ->and(CupDuelKit::fresh($open)->status)->toBe(CupDuelStatus::Accepted)
        ->and(DB::table('cup_duel_slots')->where('cup_duel_id', $open->id)->count())->toBe(0)
        ->and(CupDuelKit::battleOf($open)->limit_exempt)->toBeFalse();
});

it('keeps the Free limit of the challenger for such an acceptance (К5): at the limit the challenge closes, not a 409 loop', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b, $c], 'participants' => [, $pb]] = CupDuelKit::running(3);
    CupDuelKit::playOut(CupDuelKit::accept($b, CupDuelKit::challenge($a, $cup, $pb)->duel), $a, $b, 1, 1);
    PracticeKit::limits(null, 1);
    $open = CupDuelKit::challenge($c, $cup, public: true)->duel;
    // The challenger spends his battle of the day elsewhere.
    Battle::factory()->create(['challenger_id' => $c->id, 'status' => BattleStatus::Finished, 'end_reason' => BattleEndReason::Completed, 'open_pair' => null]);

    $forMe = fixHttp($b, 'GET', "/cups/{$cup->id}/duels/{$open->id}")->assertOk()->json('rated_for_me');
    $refused = fixHttp($b, 'POST', "/cups/{$cup->id}/duels/{$open->id}/accept", ['expect_rated' => $forMe]);

    expect($forMe)->toBeFalse();
    $refused->assertStatus(409)->assertJsonPath('error.code', 'cup_duel_closed')->assertJsonPath('error.details.reason', 'challenger_limit');
    expect(CupDuelKit::fresh($open)->status)->toBe(CupDuelStatus::Canceled);
});

it('still refuses an expect_rated that is not the kind for this participant (409 rating_changed {rated: false}), once', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b, $c], 'participants' => [, $pb]] = CupDuelKit::running(3);
    CupDuelKit::playOut(CupDuelKit::accept($b, CupDuelKit::challenge($a, $cup, $pb)->duel), $a, $b, 1, 1);
    $open = CupDuelKit::challenge($c, $cup)->duel;

    fixHttp($b, 'POST', "/cups/{$cup->id}/duels/{$open->id}/accept", ['expect_rated' => true])
        ->assertStatus(409)->assertJsonPath('error.code', 'cup_duel_rating_changed')->assertJsonPath('error.details.rated', false);
    // What the screen reads next is the kind the next acceptance is accepted with.
    $forMe = fixHttp($b, 'GET', "/cups/{$cup->id}/duels/{$open->id}")->assertOk()->json('rated_for_me');
    fixHttp($b, 'POST', "/cups/{$cup->id}/duels/{$open->id}/accept", ['expect_rated' => $forMe])->assertCreated();
});

// ------------------------------------------------------------------------------------------------ 2. practice access

it('lets a participant outside the practice play the match of a duel (pilot, plan pro), and refuses him an ordinary match', function (string $access): void {
    ['cup' => $cup, 'learners' => [$a, $b], 'participants' => [, $pb]] = CupDuelKit::running(2);
    $duel = CupDuelKit::accept($b, CupDuelKit::challenge($a, $cup, $pb)->duel);
    match ($access) {
        'pilot' => config(['practice.access' => 'pilot']), // nobody of the two is in the pilot
        'pro' => config(['practice.plan' => 'pro']), // both are Free
    };
    $battleId = (int) $duel->battle_id;

    // The switch of the duels is a switch of the function, not of a learner: the duels stay on.
    fixHttp($a, 'GET', "/cups/{$cup->id}/duels")->assertOk();
    fixHttp($a, 'GET', "/practice/battles/{$battleId}")->assertOk()->assertJsonPath('id', $battleId);
    fixHttp($a, 'GET', "/practice/battles/{$battleId}/poll")->assertOk();
    fixHttp($a, 'POST', "/practice/battles/{$battleId}/ready")->assertOk();
    fixHttp($b, 'POST', "/practice/battles/{$battleId}/ready")->assertOk();
    $battle = BattleKit::fresh($battleId);
    expect($battle->status)->toBe(BattleStatus::Countdown);
    $start = CarbonImmutable::instance($battle->starts_at ?? throw new RuntimeException('No countdown.'));
    BattleKit::bothPoll($battle, $a, $b, $start->toDateTimeString());
    $element = BattleKit::element($battle, 1);
    expect(BattleKit::answer($battle, $a, 1, BattleKit::correct($element))->verdict->value)->toBe('correct');
    fixHttp($b, 'POST', "/practice/battles/{$battleId}/surrender", ['confirm' => true])->assertOk();
    expect(CupDuelKit::fresh($duel)->status)->toBe(CupDuelStatus::Done);

    // An ordinary match of the same learner: the refusal of the practice, as before.
    $plain = Battle::factory()->create(['challenger_id' => $a->id, 'opponent_id' => $b->id, 'status' => BattleStatus::Finished, 'end_reason' => BattleEndReason::Completed, 'open_pair' => null]);
    $code = $access === 'pilot' ? 'practice_unavailable' : 'practice_pro_required';
    fixHttp($a, 'GET', "/practice/battles/{$plain->id}")->assertForbidden()->assertJsonPath('error.code', $code);
    fixHttp($a, 'POST', "/practice/battles/{$plain->id}/ready")->assertForbidden()->assertJsonPath('error.code', $code);
    // A match that is not his, or none: the same refusal (nothing learned about it).
    fixHttp($a, 'GET', '/practice/battles/999999')->assertForbidden()->assertJsonPath('error.code', $code);
})->with(['pilot', 'pro']);

it('keeps the rest of the door of a duel match: a stranger to it gets 404, the switch of the practice off voids it', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b, $c], 'participants' => [, $pb]] = CupDuelKit::running(3);
    $duel = CupDuelKit::accept($b, CupDuelKit::challenge($a, $cup, $pb)->duel);
    config(['practice.access' => 'pilot']);

    fixHttp($c, 'GET', "/practice/battles/{$duel->battle_id}")->assertForbidden()->assertJsonPath('error.code', 'practice_unavailable');
    Learner::query()->whereKey($c->id)->update(['practice_pilot_at' => now()]);
    fixHttp($c->fresh() ?? $c, 'GET', "/practice/battles/{$duel->battle_id}")->assertNotFound();
});

// ------------------------------------------------------------------------------------------------ 4. void

it('voids a duel nobody answered in whatever the end - a surrender in the countdown too: no burnt rated duel, the slots are free', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b], 'participants' => [$pa, $pb]] = CupDuelKit::running(2);
    $duel = CupDuelKit::accept($b, CupDuelKit::challenge($a, $cup, $pb)->duel);
    $service = BattleKit::service();
    $service->ready($a, (int) $duel->battle_id);
    $service->ready($b, (int) $duel->battle_id);
    expect(BattleKit::fresh((int) $duel->battle_id)->status)->toBe(BattleStatus::Countdown);

    $service->surrender($b, (int) $duel->battle_id);

    $void = CupDuelKit::fresh($duel);
    expect(BattleKit::fresh((int) $duel->battle_id)->status)->toBe(BattleStatus::Finished)
        ->and($void->status)->toBe(CupDuelStatus::Void)
        ->and($void->end_reason)->toBe(CupDuelEndReason::NoAnswers)
        ->and(DB::table('cup_duel_slots')->count())->toBe(0)
        ->and(DB::table('cup_day_scores')->whereIn('participant_id', [$pa->id, $pb->id])->count())->toBe(0)
        ->and(CupDuelKit::challenge($a, $cup, $pb)->duel->rated_offered)->toBeTrue();
});

it('voids a duel whose match the system called off, even with answers given: the slots are free again', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b], 'participants' => [$pa, $pb]] = CupDuelKit::running(2);
    $duel = CupDuelKit::accept($b, CupDuelKit::challenge($a, $cup, $pb)->duel);
    Queue::fake([PostCupMessageJob::class, SendCupDuelDmJob::class, ScoreCupDuelJob::class]);
    $battle = CupDuelKit::playOut($duel, $a, $b, 2, 1, stopAfterRound: 2);
    // A clip goes missing / the practice is switched off: the battle is closed `voided` by the system.
    DB::transaction(function () use ($battle): void {
        $locked = Battle::query()->whereKey($battle->id)->lockForUpdate()->firstOrFail();
        $locked->load('participants');
        app(BattleClock::class)->close($locked, BattleEndReason::Voided, CarbonImmutable::now(), [BattleClock::META_VOIDED_REASON => BattleClock::VOIDED_AUDIO_MISSING]);
    });
    expect(BattleKit::fresh($battle)->end_reason)->toBe(BattleEndReason::Voided);

    expect(CupDuelKit::service()->score($duel->id))->toBe(CupDuelStatus::Void);
    $void = CupDuelKit::fresh($duel);
    expect($void->end_reason)->toBe(CupDuelEndReason::BattleVoided)
        ->and($void->challenger_points)->toBeNull()
        ->and(DB::table('cup_duel_slots')->count())->toBe(0)
        ->and(DB::table('cup_day_scores')->whereIn('participant_id', [$pa->id, $pb->id])->sum('duel_points'))->toBe(0);
});

// ------------------------------------------------------------------------------------------------ 8. blocks in the list

it('lists no open challenge of a learner who blocked me or whom I blocked, in one statement as before', function (bool $iBlocked): void {
    ['cup' => $cup, 'learners' => [$a, $b, $c]] = CupDuelKit::running(3);
    $fromA = CupDuelKit::challenge($a, $cup, public: true)->duel;
    $fromC = CupDuelKit::challenge($c, $cup, public: true)->duel;
    [$low, $high] = $a->id < $b->id ? [$a->id, $b->id] : [$b->id, $a->id];
    Friendship::query()->forceCreate([
        'learner_id' => $low, 'friend_id' => $high, 'status' => FriendshipStatus::Blocked->value,
        'requested_by_learner_id' => $iBlocked ? $b->id : $a->id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $open = collect(fixHttp($b, 'GET', "/cups/{$cup->id}/duels")->assertOk()->json('open'))->pluck('id')->all();
    $byA = fixHttp($a, 'GET', "/cups/{$cup->id}/duels")->assertOk();

    expect($open)->toBe([$fromC->id])
        // The challenger still sees his own challenge.
        ->and($byA->json('mine.id'))->toBe($fromA->id);
})->with(['I blocked' => true, 'blocked me' => false]);

// ------------------------------------------------------------------------------------------------ 12. blocked / deleted learners

it('never challenges a learner blocked by the platform or deleted, and closes a challenge whose challenger was blocked since', function (string $mark): void {
    ['cup' => $cup, 'learners' => [$a, $b, $c], 'participants' => [$pa, $pb]] = CupDuelKit::running(3);
    $open = CupDuelKit::challenge($a, $cup)->duel;
    $set = $mark === 'blocked' ? ['blocked_at' => now()] : ['deleted_at' => now()];
    DB::table('learners')->where('id', $b->id)->update($set);

    expect(fixRefusal(fn () => CupDuelKit::challenge($c, $cup, $pb))[0])->toBe('cup_duel_unavailable');

    DB::table('learners')->where('id', $a->id)->update($set);
    expect(fixRefusal(fn () => CupDuelKit::accept($c, $open, true)))->toBe(['cup_duel_closed', ['status' => 'canceled', 'reason' => 'left']])
        ->and(CupDuelKit::fresh($open)->status)->toBe(CupDuelStatus::Canceled);
})->with(['blocked', 'deleted']);

// ------------------------------------------------------------------------------------------------ 13. immutable dates

it('reads the dates of a duel as immutable', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b], 'participants' => [, $pb]] = CupDuelKit::running(2);
    $duel = CupDuel::query()->findOrFail(CupDuelKit::accept($b, CupDuelKit::challenge($a, $cup, $pb)->duel)->id);

    expect($duel->expires_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($duel->accepted_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($duel->created_at)->toBeInstanceOf(CarbonImmutable::class);
});
