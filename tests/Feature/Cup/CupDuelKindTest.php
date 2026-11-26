<?php

declare(strict_types=1);

use App\Domain\Cup\Enums\CupDuelKind;
use App\Domain\Cup\Enums\CupDuelStatus;
use App\Domain\Cup\Exceptions\CupException;
use App\Domain\Cup\Services\CupDuelRenderer;
use App\Domain\Operations\Support\PureCache;
use App\Domain\Practice\Exceptions\BattleException;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Domain\Practice\Services\PracticeLimits;
use App\Jobs\PostCupMessageJob;
use App\Jobs\SendCupDuelDmJob;
use App\Models\CupDuel;
use App\Models\ProductEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\Cup\CupDuelKit;
use Tests\Support\Practice\PracticeKit;
use Tests\Support\QueryLog;

/*
 * The kind of a duel the challenger chooses (owner's decision of 27.11.2026, docs/33 §11.12, docs/04 A.23d `kind`): `friendly` is never
 * rated - no slot, no points of the cup for either player, whatever their slots; `rated` is the duel of the cup as before (the slots
 * decide it again at the acceptance) and is refused with 409 `cup_duel_rating_changed {rated: false}` when the rated duel of today is
 * played already by the challenger or the addressee; no `kind` (an older client) - the behaviour before the choice. The statements of
 * the challenge and of the acceptance do not grow.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake([PostCupMessageJob::class, SendCupDuelDmJob::class]);
    Cache::flush();
    PureCache::store()->flush();
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** @return array{0: string, 1: array<string, mixed>} */
function kindRefusal(Closure $fn): array
{
    try {
        $fn();
    } catch (CupException|BattleException|PracticeException $e) {
        return [$e->errorCode, $e->details];
    }

    throw new RuntimeException('Nothing was refused.');
}

it('makes a friendly duel by choice: never rated, no slot, no points of the cup, even with both slots free', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b], 'participants' => [$pa, $pb]] = CupDuelKit::running();

    $duel = CupDuelKit::challenge($a, $cup, $pb, kind: CupDuelKind::Friendly)->duel;
    expect($duel->rated_offered)->toBeFalse()
        ->and(CupDuelKit::fresh($duel)->rated)->toBeFalse()
        ->and(ProductEvent::query()->where('name', 'cup.duel_challenged')->sole()->props)->toMatchArray(['kind' => 'friendly', 'rated_offered' => false]);

    // The acceptance as rated is refused: the kind is decided for good.
    expect(kindRefusal(fn () => CupDuelKit::accept($b, $duel, true)))->toBe(['cup_duel_rating_changed', ['rated' => false]]);
    $accepted = CupDuelKit::accept($b, $duel, false);
    expect($accepted->rated)->toBeFalse()
        ->and(DB::table('cup_duel_slots')->count())->toBe(0)
        ->and(CupDuelKit::battleOf($accepted)->limit_exempt)->toBeFalse();

    CupDuelKit::playOut($accepted, $a, $b, 4, 3);
    expect(CupDuelKit::fresh($duel)->status)->toBe(CupDuelStatus::Done)
        ->and(DB::table('cup_day_scores')->whereIn('participant_id', [$pa->id, $pb->id])->sum('duel_points'))->toBe(0);

    // Both can still play the rated duel of the day.
    $rated = CupDuelKit::accept($b, CupDuelKit::challenge($a, $cup, $pb, kind: CupDuelKind::Rated)->duel, true);
    expect($rated->rated)->toBeTrue()
        ->and(DB::table('cup_duel_slots')->where('cup_duel_id', $rated->id)->count())->toBe(2);
});

it('spends the Free limit of battles of the challenger for a friendly duel by choice (К5)', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b], 'participants' => [, $pb]] = CupDuelKit::running();

    PracticeKit::limits(null, 0);
    expect(kindRefusal(fn () => CupDuelKit::challenge($a, $cup, $pb, kind: CupDuelKind::Friendly))[0])->toBe('practice_limit_reached');

    PracticeKit::limits(null, 1);
    CupDuelKit::accept($b, CupDuelKit::challenge($a, $cup, $pb, kind: CupDuelKind::Friendly)->duel, false);
    expect(app(PracticeLimits::class)->forLearner($a)['battles']['used'] ?? null)->toBe(1);
});

it('refuses a rated challenge once the challenger has played the rated duel of today; the addressee\'s played duel and an older client get a friendly offer', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b, $c, $d], 'participants' => [$pa, $pb, $pc, $pd]] = CupDuelKit::running(4);
    CupDuelKit::playOut(CupDuelKit::accept($b, CupDuelKit::challenge($a, $cup, $pb)->duel), $a, $b, 1, 1);

    // The challenger has played: refused before anything is made.
    expect(kindRefusal(fn () => CupDuelKit::challenge($a, $cup, $pc, kind: CupDuelKind::Rated)))->toBe(['cup_duel_rating_changed', ['rated' => false]])
        ->and(kindRefusal(fn () => CupDuelKit::challenge($a, $cup, null, kind: CupDuelKind::Rated)))->toBe(['cup_duel_rating_changed', ['rated' => false]]);
    expect(CupDuel::query()->where('status', 'open')->count())->toBe(0);
    // The addressee has played: as before the choice, a friendly match is offered (the screen warns of it), never a rated one.
    $toPlayed = CupDuelKit::challenge($c, $cup, $pb, kind: CupDuelKind::Rated)->duel;
    expect($toPlayed->rated_offered)->toBeFalse()
        ->and(CupDuelKit::fresh($toPlayed)->rated)->toBeNull();
    CupDuelKit::service()->cancel($c, $toPlayed->id);

    // An open challenge to anybody by one who has not played is rated; the one who accepts may have played - then friendly (as before).
    $open = CupDuelKit::challenge($c, $cup, null, kind: CupDuelKind::Rated)->duel;
    expect($open->rated_offered)->toBeTrue();
    expect(kindRefusal(fn () => CupDuelKit::accept($b, $open, true)))->toBe(['cup_duel_rating_changed', ['rated' => false]]);

    // No kind: the behaviour before the choice - a friendly challenge offered, no refusal.
    $old = CupDuelKit::challenge($a, $cup, $pd)->duel;
    expect($old->rated_offered)->toBeFalse()
        ->and(CupDuelKit::fresh($old)->rated)->toBeNull();
});

it('tells the kind in the chat and in the DM: a friendly duel by choice is a friendly match', function (): void {
    ['cup' => $cup, 'learners' => [$a]] = CupDuelKit::running();

    $duel = CupDuelKit::challenge($a, $cup, null, public: true, kind: CupDuelKind::Friendly)->duel;
    $text = app(CupDuelRenderer::class)->message(CupDuelKit::fresh($duel), CupDuelKit::cup($cup), CupDuelKit::cup($cup)->telegramGroup)->text;

    expect($text)->toContain(__('cup.duel.friendly', [], 'ru'))
        ->and($text)->not->toContain('Зачётная');
});

it('shows the kind to every viewer: rated_for_me and rated are false for a friendly duel by choice', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b, $c], 'participants' => [, $pb]] = CupDuelKit::running();
    $friendly = CupDuelKit::challenge($a, $cup, null, kind: CupDuelKind::Friendly)->duel;

    $row = test()->withHeaders(PracticeKit::headers($b))->getJson("/api/twa/cups/{$cup->id}/duels/{$friendly->id}")->assertOk();
    expect($row->json('data.rated_for_me') ?? $row->json('rated_for_me'))->toBeFalse()
        ->and($row->json('data.rated') ?? $row->json('rated'))->toBeFalse()
        ->and($row->json('data.rated_offered') ?? $row->json('rated_offered'))->toBeFalse();
});

it('takes `kind` in the request: rated, friendly, none; anything else is a 422', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b, $c], 'participants' => [, $pb, $pc]] = CupDuelKit::running();
    $body = fn (array $extra): array => ['opponent_participant_id' => $pb->id, 'public' => false, 'mode' => 'pairs', 'request_id' => (string) Str::uuid(), ...$extra];
    $post = fn (array $extra) => test()->withHeaders(PracticeKit::headers($a))->postJson("/api/twa/cups/{$cup->id}/duels", $body($extra));

    $post(['kind' => 'duel'])->assertStatus(422);
    $made = $post(['kind' => 'friendly'])->assertCreated();
    expect(CupDuel::query()->findOrFail($made->json('data.id') ?? $made->json('id'))->rated)->toBeFalse();

    // The rated duel of $a is played: a rated challenge is a 409 with the kind, a friendly one goes through.
    CupDuel::query()->update(['status' => 'canceled', 'end_reason' => 'challenger', 'open_challenger_id' => null, 'closed_at' => now()]);
    CupDuelKit::playOut(CupDuelKit::accept($c, CupDuelKit::challenge($a, $cup, $pc, kind: CupDuelKind::Rated)->duel), $a, $c, 1, 1);
    $post(['kind' => 'rated'])->assertStatus(409)->assertJsonPath('error.code', 'cup_duel_rating_changed')->assertJsonPath('error.details.rated', false);
});

it('costs the same statements: a challenge of either kind as before (8), a friendly acceptance by choice reads no slot (19; the rated one stays 21, CupDuelQueryBudgetTest)', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b, $c, $d], 'participants' => [, $pb, $pc, $pd], 'start' => $now] = CupDuelKit::running(4);
    $service = CupDuelKit::service();
    $loaded = CupDuelKit::cup($cup);

    $count = fn (Closure $fn): int => count(QueryLog::during($fn));
    // The challenge: the same 8 statements whatever the kind (the slots ride in the one statement of the limits).
    $none = $count(fn () => $service->challenge($a, $loaded, $pb->id, false, \App\Domain\Practice\Enums\PracticeMode::Pairs, (string) Str::uuid(), $now));
    CupDuel::query()->update(['status' => 'canceled', 'end_reason' => 'challenger', 'open_challenger_id' => null, 'closed_at' => $now]);
    $rated = $count(fn () => $service->challenge($a, $loaded, $pb->id, false, \App\Domain\Practice\Enums\PracticeMode::Pairs, (string) Str::uuid(), $now, CupDuelKind::Rated));
    CupDuel::query()->update(['status' => 'canceled', 'end_reason' => 'challenger', 'open_challenger_id' => null, 'closed_at' => $now]);
    $friendly = $count(fn () => $service->challenge($c, $loaded, $pd->id, false, \App\Domain\Practice\Enums\PracticeMode::Pairs, (string) Str::uuid(), $now, CupDuelKind::Friendly));

    expect($none)->toBe(8)->and($rated)->toBe(8)->and($friendly)->toBe(8);

    // The friendly acceptance by choice reads no slot.
    $duel = CupDuel::query()->where('challenger_participant_id', $pc->id)->where('status', 'open')->sole();
    $log = QueryLog::during(fn () => $service->accept($d, $duel->id, false, $now->addMinute()));
    expect(count($log))->toBe(19)
        ->and(QueryLog::matching($log, '/from ["`]cup_duel_slots["`]/'))->toBe([])
        ->and(QueryLog::matching($log, '/into ["`]cup_duel_slots["`]/'))->toBe([]);
});
