<?php

declare(strict_types=1);

use App\Domain\Practice\Enums\PracticeMode;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Practice\BattleHttp;
use Tests\Support\Practice\BattleKit;

/*
 * docs/16 §10.3: «audio-failed» of a battle looks at the file BEFORE the row of the match is locked (on R2 it is a HEAD), and a
 * disk that cannot answer is "not confirmed" - the match goes on, never a 500.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(BattleKit::T0);
    Storage::fake('practice_audio');
    Queue::fake();
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** The disk of the dictations replaced by a mock whose `exists` runs `$exists` (the file check of the report). */
function battleDiskProbe(Closure $exists): void
{
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('exists')->andReturnUsing($exists);
    $disk->shouldReceive('size')->andReturn(2048);
    Storage::set('practice_audio', $disk);
}

it('looks at the file outside the transaction of the locked match', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $id = BattleHttp::room($me, $friend, $friendship, PracticeMode::Dictation, $course, $lesson);
    $element = BattleKit::element(BattleKit::fresh($id), 2);
    $outside = DB::transactionLevel();
    $levels = [];
    battleDiskProbe(function () use (&$levels): bool {
        $levels[] = DB::transactionLevel();

        return false;
    });

    $voided = BattleHttp::audioFailed($me, $id, $element['id'])->assertOk();

    expect($voided->json('verified'))->toBeTrue()
        ->and($voided->json('battle.status'))->toBe('closed')
        ->and($levels)->toBe([$outside]);
});

it('counts a disk that fails as "not confirmed": 200, the match goes on, a warning without the message', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $id = BattleHttp::room($me, $friend, $friendship, PracticeMode::Dictation, $course, $lesson);
    $element = BattleKit::element(BattleKit::fresh($id), 2);
    Log::spy();
    battleDiskProbe(fn (): bool => throw new RuntimeException('r2 timeout at secret-endpoint'));

    $response = BattleHttp::audioFailed($me, $id, $element['id'])->assertOk();

    expect($response->json('verified'))->toBeFalse()
        ->and($response->json('battle.status'))->toBe('room');
    Log::shouldHaveReceived('warning')->with('battle.audio_check_failed', Mockery::on(
        fn (array $context): bool => $context['exception'] === RuntimeException::class && ! str_contains((string) json_encode($context), 'secret-endpoint'),
    ));
});

it('does not ask the disk again for a repeated report', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $id = BattleHttp::room($me, $friend, $friendship, PracticeMode::Dictation, $course, $lesson);
    $element = BattleKit::element(BattleKit::fresh($id), 2);
    $calls = 0;
    battleDiskProbe(function () use (&$calls): bool {
        $calls++;

        return true;
    });
    $request = '0b6a8f8e-6a0e-4c1e-9d3e-2f7f2f9c1a11';

    BattleHttp::audioFailed($me, $id, $element['id'], $request)->assertOk()->assertJsonPath('verified', false);
    BattleHttp::audioFailed($me, $id, $element['id'], $request)->assertOk()->assertJsonPath('verified', false);

    expect($calls)->toBe(1);
});

it('does not ask the disk for an ordinary match of a learner refused the practice: the same 403 as before', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $id = BattleHttp::room($me, $friend, $friendship, PracticeMode::Dictation, $course, $lesson);
    $element = BattleKit::element(BattleKit::fresh($id), 2);
    $calls = 0;
    battleDiskProbe(function () use (&$calls): bool {
        $calls++;

        return false;
    });
    config(['practice.plan' => 'pro']);

    BattleHttp::audioFailed($me, $id, $element['id'])->assertForbidden()->assertJsonPath('error.code', 'practice_pro_required');

    expect($calls)->toBe(0)
        ->and(BattleKit::fresh($id)->status->value)->toBe('room');
});
