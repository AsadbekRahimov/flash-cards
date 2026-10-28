<?php

declare(strict_types=1);

/*
 * The week-result DM (docs/13 §7.3): text per result and language, the TWA button, at most once
 * per learner and closed week, DM-blocked learners, Telegram 403, and the batch job that sends
 * many members in a row under the bot-wide send budget (one slot per message, the rest handed to
 * a fresh job when the budget or the time is up).
 */

use App\Domain\Engagement\Services\LeagueResultSender;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Services\TelegramMessageRateLimiter;
use App\Jobs\SendLeagueResultsJob;
use App\Models\LeagueGroup;
use App\Models\LeagueMember;
use App\Models\LeagueWeek;
use App\Models\Learner;
use App\Models\NotificationLog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeTelegramClient;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['twa.base_url' => 'https://twa.test']);
    $this->travelTo(CarbonImmutable::parse('2026-10-18 19:10:00', 'UTC'));
    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);
});

/** A closed member of a closed week (2026-10-12 … 18) in the given tier. */
function lrMember(Learner $learner, int $tier, string $result, int $rank = 2, int $points = 120, ?LeagueWeek $week = null): LeagueMember
{
    /** @var LeagueWeek $week */
    $week ??= LeagueWeek::query()->firstOrCreate(
        ['starts_on' => (new LeagueWeek)->fromDateTime('2026-10-12')],
        ['ends_on' => '2026-10-18', 'status' => 'closed', 'closed_at' => CarbonImmutable::parse('2026-10-18 19:05:00', 'UTC')],
    );
    $group = LeagueGroup::factory()->create(['league_week_id' => $week->id, 'tier' => $tier, 'closed_at' => $week->closed_at]);

    return LeagueMember::factory()->closed($result, $rank, $points)->create(['league_group_id' => $group->id, 'learner_id' => $learner->id]);
}

function lrSend(LeagueMember $member): string
{
    return app(LeagueResultSender::class)->send($member->id);
}

/** @return list<LeagueMember> three closed members of one week */
function lrThree(): array
{
    return array_map(fn (int $i): LeagueMember => lrMember(Learner::factory()->create(['telegram_user_id' => 777_000 + $i]), 0, 'stayed'), [1, 2, 3]);
}

it('tells a promoted learner the place, the points and the next league, with the TWA button', function (): void {
    $learner = Learner::factory()->create(['telegram_user_id' => 777001, 'interface_locale' => 'ru']);
    $member = lrMember($learner, 1, 'promoted', rank: 2, points: 120);

    expect(lrSend($member))->toBe('sent')
        ->and($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['chat_id'])->toBe(777001)
        ->and($this->tg->sent[0]['text'])->toBe('🏆 Итоги недели в лиге «Серебро»: вы заняли 2-е место с 120 очками и переходите в лигу «Золото»! Поздравляем!')
        ->and($this->tg->sent[0]['button'])->toBe('Открыть лигу')
        ->and($this->tg->sent[0]['url'])->toBe('https://twa.test/twa/league');

    $log = NotificationLog::query()->sole();
    expect($log->type)->toBe('league_result')
        ->and($log->learner_id)->toBe($learner->id)
        ->and(ksorted($log->payload))->toBe(ksorted(['member_id' => $member->id, 'week_id' => $member->league_week_id, 'result' => 'promoted']));
});

it('words stayed and demoted results, in Uzbek too', function (): void {
    $stayed = Learner::factory()->create(['interface_locale' => 'ru']);
    $demoted = Learner::factory()->create(['interface_locale' => 'uz']);
    lrSend(lrMember($stayed, 0, 'stayed', rank: 7, points: 40));
    lrSend(lrMember($demoted, 3, 'demoted', rank: 28, points: 3));

    expect($this->tg->sent[0]['text'])->toBe('🏅 Итоги недели в лиге «Бронза»: вы заняли 7-е место с 40 очками и остаётесь в лиге «Бронза». На этой неделе — выше!')
        ->and($this->tg->sent[1]['text'])->toBe("📉 «Platina» ligasida hafta yakuni: 28-o'rin, 3 ochko — bu hafta siz «Oltin» ligasidasiz. Kuniga bir-ikki mashg'ulot sizni yana yuqoriga qaytaradi.")
        ->and($this->tg->sent[1]['button'])->toBe('Ligani ochish');
});

it('sends at most once per learner and closed week, and a fresh week again', function (): void {
    $learner = Learner::factory()->create();
    $member = lrMember($learner, 0, 'stayed');

    expect(lrSend($member))->toBe('sent')
        ->and(lrSend($member))->toBe('skipped')
        ->and($this->tg->sent)->toHaveCount(1)
        ->and(NotificationLog::query()->count())->toBe(1);

    $this->travelTo(CarbonImmutable::parse('2026-10-25 19:10:00', 'UTC'));
    $next = LeagueWeek::factory()->startingOn('2026-10-19')->closed()->create(['closed_at' => CarbonImmutable::parse('2026-10-25 19:05:00', 'UTC')]);
    expect(lrSend(lrMember($learner, 0, 'promoted', week: $next)))->toBe('sent')
        ->and($this->tg->sent)->toHaveCount(2);
});

it('stays quiet for a DM-blocked, banned or opted-out learner, an unknown member and an open week', function (): void {
    $blocked = lrMember(Learner::factory()->create(['dm_blocked_at' => now()]), 0, 'stayed');
    $banned = lrMember(Learner::factory()->create(['blocked_at' => now()]), 0, 'stayed');
    $optedOut = lrMember(Learner::factory()->create(['meta' => ['leagues_opt_out' => true]]), 0, 'stayed');
    $open = LeagueMember::factory()->create(['league_group_id' => LeagueGroup::factory()->create(['league_week_id' => LeagueWeek::factory()->startingOn('2026-10-19')->create()->id])->id]);

    expect(lrSend($blocked))->toBe('skipped')
        ->and(lrSend($banned))->toBe('skipped')
        ->and(lrSend($optedOut))->toBe('skipped')
        ->and(lrSend($open))->toBe('skipped')
        ->and(app(LeagueResultSender::class)->send(999_999))->toBe('skipped')
        ->and($this->tg->sent)->toBe([])
        ->and(NotificationLog::query()->count())->toBe(0);
});

it('marks the DM blocked on a Telegram 403 and keeps no log row', function (): void {
    $learner = Learner::factory()->create();
    $member = lrMember($learner, 0, 'stayed');
    $telegram = Mockery::mock(TelegramClient::class);
    $telegram->shouldReceive('sendWebAppButton')->once()->andThrow(new TelegramForbiddenException(TelegramForbiddenException::REASON_BOT_BLOCKED));
    $this->app->instance(TelegramClient::class, $telegram);

    expect(lrSend($member))->toBe('forbidden')
        ->and($learner->fresh()?->dm_blocked_at?->utc()->toDateTimeString())->toBe('2026-10-18 19:10:00')
        ->and(NotificationLog::query()->count())->toBe(0);
});

// --- batches ---------------------------------------------------------------------------------

it('sends a batch in order and reports what happened; a second pass skips everybody', function (): void {
    [$a, $b, $c] = lrThree();
    $ids = [$a->id, $b->id, $c->id];

    expect(app(LeagueResultSender::class)->sendBatch($ids, 30))->toBe(['sent' => 3, 'skipped' => 0, 'forbidden' => 0, 'failed' => 0, 'remaining' => []])
        ->and(array_column($this->tg->sent, 'chat_id'))->toBe([777_001, 777_002, 777_003])
        ->and(NotificationLog::query()->where('type', 'league_result')->count())->toBe(3);

    expect(app(LeagueResultSender::class)->sendBatch($ids, 30))->toBe(['sent' => 0, 'skipped' => 3, 'forbidden' => 0, 'failed' => 0, 'remaining' => []])
        ->and($this->tg->sent)->toHaveCount(3);
});

it('goes on after a Bot API failure or a 403 of one member and keeps the failed one\'s marker, so nobody is doubled later', function (): void {
    Exceptions::fake();
    [$a, $b, $c] = lrThree();
    $b->learner?->forceFill(['telegram_user_id' => 777_999])->save();
    $this->tg->failSends = 1; // the first send of the batch fails like an outage

    $outcome = app(LeagueResultSender::class)->sendBatch([$a->id, $b->id, $c->id], 30);

    expect($outcome)->toBe(['sent' => 2, 'skipped' => 0, 'forbidden' => 0, 'failed' => 1, 'remaining' => []])
        ->and(array_column($this->tg->sent, 'chat_id'))->toBe([777_999, 777_003])
        ->and(NotificationLog::query()->where('type', 'league_result')->count())->toBe(3); // the failed one's row stays: no second attempt
    Exceptions::assertReported(TelegramApiException::class);

    // A retry of the batch sends nothing more.
    expect(app(LeagueResultSender::class)->sendBatch([$a->id, $b->id, $c->id], 30)['skipped'])->toBe(3)
        ->and($this->tg->sent)->toHaveCount(2);

    // A 403 in the middle of a batch: forbidden, DM closed, the batch goes on.
    $telegram = Mockery::mock(TelegramClient::class);
    $telegram->shouldReceive('sendWebAppButton')->once()->andThrow(new TelegramForbiddenException(TelegramForbiddenException::REASON_BOT_BLOCKED));
    $telegram->shouldReceive('sendWebAppButton')->once();
    $this->app->instance(TelegramClient::class, $telegram);
    $this->travelTo(CarbonImmutable::parse('2026-10-25 19:10:00', 'UTC'));
    $next = LeagueWeek::factory()->startingOn('2026-10-19')->closed()->create(['closed_at' => CarbonImmutable::parse('2026-10-25 19:05:00', 'UTC')]);
    $d = lrMember(Learner::factory()->create(), 0, 'stayed', week: $next);
    $e = lrMember(Learner::factory()->create(), 0, 'stayed', week: $next);

    expect(app(LeagueResultSender::class)->sendBatch([$d->id, $e->id], 30))->toBe(['sent' => 1, 'skipped' => 0, 'forbidden' => 1, 'failed' => 0, 'remaining' => []])
        ->and($d->learner?->fresh()?->dm_blocked_at)->not->toBeNull();
});

it('stops when no slot of the bot-wide budget comes and hands the rest back; the job queues them again with a short delay', function (): void {
    Queue::fake();
    config(['telegram.outbound.sync_wait_ms' => 0]);
    [$a, $b, $c] = lrThree();
    $limiter = new TelegramMessageRateLimiter(globalPerSecond: 1);
    $this->app->instance(TelegramMessageRateLimiter::class, $limiter);
    $limiter->reserveGlobal(); // the only slot of this second is gone

    expect(app(LeagueResultSender::class)->sendBatch([$a->id, $b->id, $c->id], 30))->toBe(['sent' => 0, 'skipped' => 0, 'forbidden' => 0, 'failed' => 0, 'remaining' => [$a->id, $b->id, $c->id]])
        ->and($this->tg->sent)->toBe([])
        ->and(NotificationLog::query()->count())->toBe(0);

    // Through the job: nothing sent, the same three members in a fresh job, delayed 1-5 s.
    $job = new SendLeagueResultsJob([$a->id, $b->id, $c->id]);
    $job->handle(app(LeagueResultSender::class), $limiter);
    Queue::assertPushed(SendLeagueResultsJob::class, function (SendLeagueResultsJob $pushed) use ($a, $b, $c): bool {
        return $pushed->memberIds === [$a->id, $b->id, $c->id] && is_int($pushed->delay) && $pushed->delay >= 1 && $pushed->delay <= 5;
    });

    // The next second has a slot again: one message goes out, the budget is gone, two come back.
    $this->travel(1)->seconds();
    expect(app(LeagueResultSender::class)->sendBatch([$a->id, $b->id, $c->id], 30))->toBe(['sent' => 1, 'skipped' => 0, 'forbidden' => 0, 'failed' => 0, 'remaining' => [$b->id, $c->id]])
        ->and($this->tg->sent)->toHaveCount(1);
});

it('hands the rest to a fresh job when the time budget is spent — at least one message per job', function (): void {
    Queue::fake();
    [$a, $b, $c] = lrThree();

    expect(app(LeagueResultSender::class)->sendBatch([$a->id, $b->id, $c->id], 0))->toBe(['sent' => 1, 'skipped' => 0, 'forbidden' => 0, 'failed' => 0, 'remaining' => [$b->id, $c->id]]);

    // A zero budget = one attempt per job: $a is skipped (already sent) and the rest is handed on; the next job sends $b.
    config(['leagues.notify_batch_seconds' => 0]);
    (new SendLeagueResultsJob([$a->id, $b->id, $c->id]))->handle(app(LeagueResultSender::class), app(TelegramMessageRateLimiter::class));
    Queue::assertPushed(SendLeagueResultsJob::class, fn (SendLeagueResultsJob $pushed): bool => $pushed->memberIds === [$b->id, $c->id]);
    (new SendLeagueResultsJob([$b->id, $c->id]))->handle(app(LeagueResultSender::class), app(TelegramMessageRateLimiter::class));
    Queue::assertPushed(SendLeagueResultsJob::class, fn (SendLeagueResultsJob $pushed): bool => $pushed->memberIds === [$c->id]);
    expect($this->tg->sent)->toHaveCount(2);
});

it('is executed from the queue after a close with the real sender, and is bounded like the other senders', function (): void {
    [$a, $b] = array_slice(lrThree(), 0, 2);

    SendLeagueResultsJob::dispatchSync([$a->id, $b->id]);

    $job = new SendLeagueResultsJob([$a->id]);
    expect($this->tg->sent)->toHaveCount(2)
        ->and($job->tries)->toBe(0)
        ->and($job->maxExceptions)->toBeGreaterThanOrEqual(1)
        ->and($job->retryUntil()->getTimestamp())->toBe(now()->addHours(12)->getTimestamp())
        ->and(method_exists($job, 'middleware'))->toBeFalse(); // one slot per MESSAGE inside the batch, not one per job
});

// --- the deadline of the mailing -----------------------------------------------------------------

it('hands the rest on with the deadline of the mailing, never with a fresh TTL', function (): void {
    Queue::fake();
    config(['telegram.outbound.sync_wait_ms' => 0]);
    [$a, $b, $c] = lrThree();
    $notAfter = now()->addHours(12)->getTimestamp();

    $handle = function (SendLeagueResultsJob $job): void {
        // No slot of the bot-wide budget in this second: nothing goes out, everything is handed on.
        $limiter = new TelegramMessageRateLimiter(globalPerSecond: 1);
        $this->app->instance(TelegramMessageRateLimiter::class, $limiter);
        $limiter->reserveGlobal();
        $job->handle(app(LeagueResultSender::class), $limiter);
    };

    $handle(new SendLeagueResultsJob([$a->id, $b->id, $c->id], $notAfter));
    $remainder = Queue::pushed(SendLeagueResultsJob::class)->sole();
    expect($remainder->memberIds)->toBe([$a->id, $b->id, $c->id])
        ->and($remainder->notAfter)->toBe($notAfter)
        ->and($remainder->retryUntil()->getTimestamp())->toBe($notAfter);

    // Eleven hours on the remainder meets the same wall again and passes the same deadline to its own remainder:
    // 23 hours after the mailing began it would still be alive if each dispatch started the TTL over.
    $this->travel(11)->hours();
    Queue::fake();
    $handle($remainder);
    $again = Queue::pushed(SendLeagueResultsJob::class)->sole();
    expect($again->notAfter)->toBe($notAfter)
        ->and($again->retryUntil()->getTimestamp())->toBe($notAfter)
        ->and($again->retryUntil()->getTimestamp())->toBeLessThan(now()->addHours(12)->getTimestamp());

    // A job built without a deadline starts the TTL now (what a hand-made dispatch gets).
    $fresh = new SendLeagueResultsJob([$a->id]);
    expect($fresh->notAfter)->toBeNull()
        ->and($fresh->retryUntil()->getTimestamp())->toBe(now()->addHours(12)->getTimestamp());
    config(['leagues.notify_ttl_hours' => 3]);
    expect($fresh->retryUntil()->getTimestamp())->toBe(now()->addHours(3)->getTimestamp());
});

it('reads a payload queued before the deadline existed as a job without one', function (): void {
    // Exactly what the previous build serialized: the members only.
    $legacy = unserialize('O:29:"App\Jobs\SendLeagueResultsJob":1:{s:9:"memberIds";a:2:{i:0;i:5;i:1;i:6;}}');

    expect($legacy)->toBeInstanceOf(SendLeagueResultsJob::class)
        ->and($legacy->memberIds)->toBe([5, 6])
        ->and($legacy->notAfter)->toBeNull()
        ->and($legacy->retryUntil()->getTimestamp())->toBe(now()->addHours(12)->getTimestamp());
});

it('lets the worker drop a remainder that outlived the mailing, sending nothing', function (): void {
    config(['queue.default' => 'database', 'telegram.outbound.sync_wait_ms' => 0]);
    [$a, $b] = array_slice(lrThree(), 0, 2);
    $notAfter = now()->addHours(12)->getTimestamp();

    // Eleven hours into the mailing a batch finds no slot of the bot-wide budget and hands both members on: the
    // remainder goes into a real queued job (the database queue; the worker below runs it).
    $this->travel(11)->hours();
    $limiter = new TelegramMessageRateLimiter(globalPerSecond: 1);
    $this->app->instance(TelegramMessageRateLimiter::class, $limiter);
    $limiter->reserveGlobal();
    (new SendLeagueResultsJob([$a->id, $b->id], $notAfter))->handle(app(LeagueResultSender::class), $limiter);
    expect(DB::table('jobs')->count())->toBe(1)
        // The deadline of the mailing, not eleven hours later (a TTL restarted at the dispatch).
        ->and(DB::table('jobs')->value('payload'))->toContain('"retryUntil":'.$notAfter.',');

    // One second past the deadline the worker fails the job before handle(): nothing is sent, however free the budget is.
    $this->travelTo(CarbonImmutable::createFromTimestamp($notAfter + 1));
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0]);

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and($this->tg->sent)->toBe([])
        ->and(NotificationLog::query()->count())->toBe(0);
});

it('still runs a remainder that is inside the deadline', function (): void {
    config(['queue.default' => 'database', 'telegram.outbound.sync_wait_ms' => 0]);
    [$a, $b] = array_slice(lrThree(), 0, 2);
    $notAfter = now()->addHours(12)->getTimestamp();
    $limiter = new TelegramMessageRateLimiter(globalPerSecond: 1);
    $this->app->instance(TelegramMessageRateLimiter::class, $limiter);
    $limiter->reserveGlobal();
    (new SendLeagueResultsJob([$a->id, $b->id], $notAfter))->handle(app(LeagueResultSender::class), $limiter);

    $this->travelTo(CarbonImmutable::createFromTimestamp($notAfter - 1));
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0]);

    // The first member got the slot of the new second; the rest is queued again, with the same deadline.
    expect($this->tg->sent)->toHaveCount(1)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and(DB::table('jobs')->value('payload'))->toContain('"retryUntil":'.$notAfter.',');
});
