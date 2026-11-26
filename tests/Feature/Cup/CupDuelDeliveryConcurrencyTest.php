<?php

declare(strict_types=1);

use App\Domain\Cup\Enums\CupMessageStatus;
use App\Domain\Cup\Services\CupDuelService;
use App\Domain\Cup\Services\CupOutbox;
use App\Jobs\PostCupMessageJob;
use App\Jobs\ScoreCupDuelJob;
use App\Models\CupMessage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Cup\CupDuelKit;
use Tests\Support\Cup\CupKit;
use Tests\Support\Game\GameRace;

/*
 * docs/33 §6.4, §11.9 п. 10 (D2): the rows of a public duel under real races - forked processes, each with its own PostgreSQL connection
 * and its own (fake) Telegram, released at the same instant (GameRace). The challenge is posted by ONE of them, its edit is made by ONE.
 */

if ((getenv('DB_CONNECTION') ?: 'sqlite') === 'pgsql') {
    uses(DatabaseTruncation::class);
}

beforeEach(function (): void {
    if (config('database.default') !== 'pgsql') {
        $this->markTestSkipped('Needs PostgreSQL: real parallel connections and row locks.');
    }
    if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
        $this->markTestSkipped('Needs the pcntl and posix extensions to fork workers.');
    }
    if (config('cache.default') !== 'redis') {
        $this->markTestSkipped('Needs a cache shared by the processes (Redis) for the slots of the chat.');
    }
    Cache::flush();
    Queue::fake([PostCupMessageJob::class, ScoreCupDuelJob::class]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    if (config('database.default') === 'pgsql') {
        DB::reconnect();
        $this->truncateTablesForAllConnections();
    }
});

it('posts a public challenge once and edits it once when four workers deliver each row at the same instant', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b], 'participants' => [, $pb]] = CupDuelKit::running();
    $duel = CupDuelKit::challenge($a, $cup, $pb, public: true)->duel;
    $send = CupMessage::query()->where('cup_id', $cup->id)->where('dedupe_key', CupOutbox::duelKey($duel->id))->sole();

    $posted = GameRace::run(array_fill(0, 4, static function () use ($send): int {
        app(CupOutbox::class)->deliver($send->id, CarbonImmutable::now());

        return count(CupKit::telegram()->groupMessages);
    }));

    app(CupDuelService::class)->decline($b, $duel->id);
    $end = CupMessage::query()->where('cup_id', $cup->id)->where('dedupe_key', CupOutbox::duelEndKey($duel->id))->sole();
    $edited = GameRace::run(array_fill(0, 4, static function () use ($end): int {
        app(CupOutbox::class)->deliver($end->id, CarbonImmutable::now());

        return count(CupKit::telegram()->textEdits) + 100 * count(CupKit::telegram()->groupMessages);
    }));

    expect(GameRace::crashes($posted))->toBe([])
        ->and(array_sum(array_column($posted, 'value')))->toBe(1)
        ->and($send->fresh()->status)->toBe(CupMessageStatus::Sent)
        ->and($send->fresh()->attempts)->toBe(1)
        ->and(GameRace::crashes($edited))->toBe([])
        ->and(array_sum(array_column($edited, 'value')))->toBe(1)
        ->and($end->fresh()->status)->toBe(CupMessageStatus::Sent)
        ->and($end->fresh()->message_id)->toBe($send->fresh()->message_id);
});
