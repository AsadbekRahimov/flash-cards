<?php

declare(strict_types=1);

/*
 * The week-result DM (docs/13 §7.3): text per result and language, the TWA button, at most once
 * per learner and closed week, DM-blocked learners, Telegram 403, and the bot-wide send budget
 * (ThrottleTelegramOutbound releases the job without running it when no slot is free).
 */

use App\Domain\Engagement\Services\LeagueResultSender;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Services\TelegramMessageRateLimiter;
use App\Jobs\Middleware\ThrottleTelegramOutbound;
use App\Jobs\SendLeagueResultJob;
use App\Models\LeagueGroup;
use App\Models\LeagueMember;
use App\Models\LeagueWeek;
use App\Models\Learner;
use App\Models\NotificationLog;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    $group = LeagueGroup::factory()->create(['league_week_id' => $week->id, 'tier' => $tier]);

    return LeagueMember::factory()->closed($result, $rank, $points)->create(['league_group_id' => $group->id, 'learner_id' => $learner->id]);
}

function lrSend(LeagueMember $member): string
{
    return app(LeagueResultSender::class)->send($member->id);
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

it('runs through the job with the bot-wide send budget: no slot → released, nothing sent', function (): void {
    $learner = Learner::factory()->create();
    $member = lrMember($learner, 0, 'stayed');
    $limiter = new TelegramMessageRateLimiter(globalPerSecond: 1);
    $limiter->reserveGlobal(); // the only slot of this second is gone
    $middleware = new ThrottleTelegramOutbound($limiter);

    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('release')->once()->with(Mockery::on(fn (int $d): bool => $d >= 1 && $d <= 5));
    $job = new SendLeagueResultJob($member->id);
    $job->job = $queueJob;
    $run = fn (SendLeagueResultJob $j): string => (string) app(LeagueResultSender::class)->send($j->memberId);

    expect($middleware->handle($job, $run))->toBeNull()
        ->and($this->tg->sent)->toBe([])
        ->and(NotificationLog::query()->count())->toBe(0);

    $this->travel(1)->seconds();
    expect($middleware->handle($job, $run))->toBe('sent')
        ->and($this->tg->sent)->toHaveCount(1);

    expect($job->tries)->toBe(0)
        ->and($job->maxExceptions)->toBeGreaterThanOrEqual(1)
        ->and($job->retryUntil()->getTimestamp())->toBe(now()->addHours(12)->getTimestamp())
        ->and(array_map(fn (object $m): string => $m::class, $job->middleware()))->toContain(ThrottleTelegramOutbound::class);
});

it('is executed from the queue after a close with the real sender', function (): void {
    $learner = Learner::factory()->create();
    $member = lrMember($learner, 0, 'stayed');

    SendLeagueResultJob::dispatchSync($member->id);

    expect($this->tg->sent)->toHaveCount(1);
});
