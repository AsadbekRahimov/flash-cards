<?php

declare(strict_types=1);

use App\Domain\Practice\Enums\PracticeMode;
use App\Models\Battle;
use App\Models\BattleAnswer;
use App\Models\Friendship;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Practice\BattleHttp;
use Tests\Support\Practice\BattleKit;
use Tests\Support\Practice\PracticeKit;

/*
 * Who may do what with a battle over HTTP (docs/16 §3.2, §7, §12): the two players only - a stranger is told "no such battle", as for an
 * id that does not exist, and changes nothing, not even the presence; the signed in only; the access to the practice; the switch of the
 * battle (it blocks the invitation and the acceptance, never a match that is on); surrender needs its `confirm`.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(BattleKit::T0);
    Storage::fake('practice_audio');
    Queue::fake();
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * Every route of a battle with a body that is valid, as [method, path, body]; `{id}` is the battle, `{el}` an element of it.
 *
 * @return list<array{0: string, 1: string, 2: array<string, mixed>}>
 */
function battleRoutes(int $id, string $element): array
{
    return [
        ['GET', "/{$id}", []],
        ['GET', "/{$id}/poll", []],
        ['GET', "/{$id}/round?no=1", []],
        ['POST', "/{$id}/accept", []],
        ['POST', "/{$id}/decline", []],
        ['POST', "/{$id}/cancel", []],
        ['POST', "/{$id}/ready", []],
        ['POST', "/{$id}/surrender", ['confirm' => true]],
        ['POST', "/{$id}/answer", ['request_id' => (string) Str::uuid(), 'round' => 1, 'answer' => ['skip' => true]]],
        ['POST', "/{$id}/audio-failed", ['request_id' => (string) Str::uuid(), 'element_id' => $element]],
        ['GET', "/{$id}/audio/{$element}", []],
    ];
}

it('tells a stranger "no such battle" on every route - the very body of an id that does not exist - and changes nothing, not even the presence', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $stranger = Learner::factory()->create();
    $id = BattleHttp::playing($me, $friend, $friendship, PracticeMode::Dictation, $course, $lesson);
    $element = BattleKit::element(BattleKit::fresh($id), 1);
    $before = [BattleKit::fresh($id)->toArray(), BattleKit::fresh($id)->participants->toArray(), BattleAnswer::query()->count()];

    CarbonImmutable::setTestNow('2026-10-30 10:00:20');   // late enough for a heartbeat to be written, if anybody could write one
    foreach (battleRoutes($id, $element['id']) as [$method, $path, $body]) {
        PracticeKit::resetLimiters($stranger);
        $real = $method === 'GET' ? BattleHttp::get($stranger, $path) : BattleHttp::post($stranger, $path, $body);
        $none = $method === 'GET'
            ? BattleHttp::get($stranger, str_replace("/{$id}", '/999999', $path))
            : BattleHttp::post($stranger, str_replace("/{$id}", '/999999', $path), $body);

        $real->assertNotFound()->assertJsonPath('error.code', 'battle_not_found');
        expect($real->json())->toBe($none->json(), "{$method} {$path}");
    }

    expect([BattleKit::fresh($id)->toArray(), BattleKit::fresh($id)->participants->toArray(), BattleAnswer::query()->count()])->toBe($before);
});

it('answers an id that is no integer - too long to fit one, negative, fractional - with the 404 of the router, never with a 500', function (): void {
    [$me] = BattleKit::world();

    foreach (['99999999999999999999', '9223372036854775808', '-1', '1.5', 'abc'] as $id) {
        foreach ([['GET', "/{$id}"], ['GET', "/{$id}/poll"], ['GET', "/{$id}/round?no=1"], ['GET', "/{$id}/audio/eX"], ['POST', "/{$id}/accept"], ['POST', "/{$id}/answer"]] as [$method, $path]) {
            $response = $method === 'GET' ? BattleHttp::get($me, $path) : BattleHttp::post($me, $path);

            expect($response->status())->toBe(404, "{$method} {$path}");
        }
    }

    // The longest id that fits is a battle that is not there: the envelope of the battle.
    BattleHttp::view($me, 999_999_999_999_999_999)->assertNotFound()->assertJsonPath('error.code', 'battle_not_found');
});

it('answers 401 on every route - the poll and the audio, which live outside `twa-api`, included - without a token or with a token that is no good', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $id = BattleHttp::room($me, $friend, $friendship, PracticeMode::Dictation, $course, $lesson);
    $element = BattleKit::element(BattleKit::fresh($id), 1);

    foreach (battleRoutes($id, $element['id']) as [$method, $path, $body]) {
        $url = BattleHttp::BASE.$path;
        test()->flushHeaders();
        $anonymous = test()->json($method, $url, $body);
        $anonymous->assertUnauthorized()->assertJsonPath('error.code', 'missing_token');
        test()->withHeaders(['Authorization' => 'Bearer not.a.token'])->json($method, $url, $body)->assertUnauthorized()->assertJsonPath('error.code', 'invalid_token');
    }
    foreach ([['GET', ''], ['GET', '/options?friendship_id=1&course=x'], ['POST', '']] as [$method, $path]) {
        test()->flushHeaders()->json($method, BattleHttp::BASE.$path)->assertUnauthorized();
    }
    expect(BattleKit::fresh($id)->status->value)->toBe('room');
});

it('closes the whole battle with 403 practice_unavailable when the practice is off, and with 403 practice_pro_required for a Free learner when it is a Pro feature', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $id = BattleHttp::room($me, $friend, $friendship, PracticeMode::Dictation, $course, $lesson);
    $element = BattleKit::element(BattleKit::fresh($id), 1);
    $routes = [
        ['GET', '', []],
        ['GET', "/options?friendship_id={$friendship->id}&course={$course->slug}", []],
        ['POST', '', ['friendship_id' => $friendship->id, 'mode' => 'pairs', 'course' => $course->slug]],
        ...battleRoutes($id, $element['id']),
    ];

    config(['practice.access' => 'off']);
    foreach ($routes as [$method, $path, $body]) {
        PracticeKit::resetLimiters($me);
        $response = $method === 'GET' ? BattleHttp::get($me, $path) : BattleHttp::post($me, $path, $body);

        $response->assertForbidden()->assertJsonPath('error.code', 'practice_unavailable');
        expect(array_keys($response->json('error')))->toBe(['code', 'message']);
    }

    config(['practice.access' => 'all', 'practice.plan' => 'pro']);
    foreach (['', "/{$id}", "/{$id}/poll"] as $path) {
        BattleHttp::get($me, $path)->assertForbidden()->assertJsonPath('error.code', 'practice_pro_required');
    }
    PracticeKit::pro($me);
    BattleHttp::get($me, "/{$id}")->assertOk();
    expect(BattleKit::fresh($id)->status->value)->toBe('room');   // nothing happened to the match while the doors were shut
});

it('blocks only the invitation and the acceptance when the battle is switched off - a match that is on goes on to its end', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $third = Learner::factory()->create(['interface_locale' => 'ru']);
    $withThird = Friendship::factory()->between($friend, $third)->create();

    $waiting = (int) BattleHttp::invite($third, $withThird, PracticeMode::Pairs, $course)->assertCreated()->json('id');
    $id = BattleHttp::playing($me, $friend, $friendship, PracticeMode::Collocation, $course, $lesson);

    config(['practice.battle.enabled' => false]);

    // The match that is on: read, answered, polled, given up - as before.
    $element = BattleKit::element(BattleKit::fresh($id), 1);
    BattleHttp::view($me, $id)->assertOk();
    BattleHttp::poll($friend, $id)->assertOk();
    BattleHttp::round($me, $id, 1)->assertOk();
    BattleHttp::answer($me, $id, 1, BattleKit::correct($element))->assertOk();
    BattleHttp::get($me, '')->assertOk();

    // The new invitation and the acceptance of one made before are refused; the invitation stays open until it runs out.
    expect(BattleHttp::invite($me, $friendship, PracticeMode::Pairs, $course)->assertStatus(409)->json('error.code'))->toBe('battle_disabled');
    BattleHttp::surrender($me, $id)->assertOk();
    expect(BattleHttp::accept($friend, $waiting)->assertStatus(409)->json('error.code'))->toBe('battle_disabled')
        ->and(BattleKit::fresh($waiting)->status->value)->toBe('invited');

    config(['practice.battle.enabled' => true]);
    BattleHttp::accept($friend, $waiting)->assertOk()->assertJsonPath('status', 'room');
});

it('takes surrender only with `confirm: true`: a body without it is 422 and the match goes on', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $id = BattleHttp::countdown($me, $friend, $friendship, PracticeMode::Collocation, $course, $lesson);

    BattleHttp::post($me, "/{$id}/surrender")->assertStatus(422)->assertJsonValidationErrors(['confirm']);
    BattleHttp::post($me, "/{$id}/surrender", ['confirm' => false])->assertStatus(422)->assertJsonValidationErrors(['confirm']);
    BattleHttp::post($me, "/{$id}/surrender", ['confirm' => 'no'])->assertStatus(422)->assertJsonValidationErrors(['confirm']);
    expect(BattleKit::fresh($id)->status->value)->toBe('countdown');

    $over = BattleHttp::surrender($me, $id)->assertOk();
    expect([$over->json('status'), $over->json('end_reason'), $over->json('result.outcome')])->toBe(['finished', 'surrender', 'lost'])
        ->and(BattleHttp::view($friend, $id)->json('result.outcome'))->toBe('won');
    expect(Battle::query()->count())->toBe(1);
});
