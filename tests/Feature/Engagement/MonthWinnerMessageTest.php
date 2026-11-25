<?php

declare(strict_types=1);

use App\Domain\Engagement\Events\LeagueMonthConfirmed;
use App\Domain\Engagement\Services\MonthWinnerSender;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Exceptions\TelegramRateLimitedException;
use App\Listeners\Engagement\SendMonthWinnerMessages;
use App\Models\LeagueMonth;
use App\Models\Learner;
use App\Models\NotificationLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\FakeTelegramClient;

/*
 * The DM to the winners of a confirmed month (docs/13 §7.6): "you took place N of the rating of the month - P points. Your
 * prize: X. We will contact you", in the winner's language with the prize of their place; without the prize sentences when the
 * month has none; once per (winner, month) however often the event is raised; nothing for a blocked learner or a closed DM.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);
    config(['leagues.monthly.prizes' => [
        1 => ['ru' => 'Telegram Premium на 3 месяца', 'uz' => 'Telegram Premium 3 oyga'],
        2 => ['ru' => 'Telegram Premium на 1 месяц', 'uz' => 'Telegram Premium 1 oyga'],
    ]]);
});

function mwMonth(array $attributes = []): LeagueMonth
{
    return LeagueMonth::factory()->startingOn('2026-10-01')->confirmed()->create($attributes);
}

function mwWinner(array $attributes = []): Learner
{
    return Learner::factory()->create(['interface_locale' => 'ru', ...$attributes]);
}

/** @param list<array{learner_id: int, place: int, points: int}> $winners */
function mwRaise(LeagueMonth $month, array $winners): void
{
    event(new LeagueMonthConfirmed($month->id, $month->starts_on->toDateString(), $winners));
}

it('congratulates every winner with the prize of their place', function (): void {
    $month = mwMonth();
    $first = mwWinner();
    $second = mwWinner();

    mwRaise($month, [
        ['learner_id' => $first->id, 'place' => 1, 'points' => 1204],
        ['learner_id' => $second->id, 'place' => 2, 'points' => 1],
    ]);

    expect($this->tg->sent)->toHaveCount(2)
        ->and($this->tg->sent[0]['chat_id'])->toBe($first->telegram_user_id)
        ->and($this->tg->sent[0]['text'])->toBe('🏆 Поздравляем! Вы заняли 1-е место в рейтинге за октябрь 2026 — 1204 балла. Ваш приз: Telegram Premium на 3 месяца. Мы свяжемся с вами в Telegram, чтобы вручить его.')
        ->and($this->tg->sent[1]['text'])->toContain('2-е место')->toContain('1 балл.')->toContain('Telegram Premium на 1 месяц')
        ->and(NotificationLog::query()->where('type', 'league_month_won')->count())->toBe(2);
});

it('speaks Uzbek to an uz winner', function (): void {
    $month = mwMonth();
    $winner = mwWinner(['interface_locale' => 'uz']);

    mwRaise($month, [['learner_id' => $winner->id, 'place' => 1, 'points' => 900]]);

    expect($this->tg->sent[0]['text'])->toBe("🏆 Tabriklaymiz! Siz 2026-yil oktyabr reytingida 1-o'rinni egalladingiz — 900 ball. Sovg'angiz: Telegram Premium 3 oyga. Uni topshirish uchun Telegram orqali siz bilan bog'lanamiz.");
});

it('leaves the prize sentences out for a month without prizes and for a place without one', function (): void {
    $month = mwMonth(['prizes' => []]);
    $winner = mwWinner();
    mwRaise($month, [['learner_id' => $winner->id, 'place' => 1, 'points' => 500]]);

    expect($this->tg->sent[0]['text'])->toBe('🏆 Поздравляем! Вы заняли 1-е место в рейтинге за октябрь 2026 — 500 баллов.');

    $other = mwMonth(['starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'prizes' => null]);
    $third = mwWinner();
    mwRaise($other, [['learner_id' => $third->id, 'place' => 3, 'points' => 20]]);

    expect($this->tg->sent[1]['text'])->not->toContain('приз')->not->toContain('свяжемся');
});

it('sends one message per (winner, month) however often the event is raised', function (): void {
    $month = mwMonth();
    $winner = mwWinner();
    $winners = [['learner_id' => $winner->id, 'place' => 1, 'points' => 500]];

    mwRaise($month, $winners);
    mwRaise($month, $winners); // a retried job

    expect($this->tg->sent)->toHaveCount(1)
        ->and(NotificationLog::query()->where('learner_id', $winner->id)->count())->toBe(1);
});

it('sends nothing to a blocked, deleted or DM-closed winner but still reaches the others', function (): void {
    $month = mwMonth();
    $blocked = mwWinner(['blocked_at' => now()]);
    $closed = mwWinner(['dm_blocked_at' => now()]);
    $deleted = mwWinner();
    $deleted->delete();
    $fine = mwWinner();

    mwRaise($month, [
        ['learner_id' => $blocked->id, 'place' => 1, 'points' => 4],
        ['learner_id' => $closed->id, 'place' => 2, 'points' => 3],
        ['learner_id' => $deleted->id, 'place' => 3, 'points' => 2],
        ['learner_id' => $fine->id, 'place' => 4, 'points' => 1],
    ]);

    expect($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['chat_id'])->toBe($fine->telegram_user_id);
});

it('closes the DM and keeps no marker when Telegram answers 403', function (): void {
    $month = mwMonth();
    $winner = mwWinner();
    $telegram = Mockery::mock(TelegramClient::class);
    $telegram->shouldReceive('sendMessage')->once()->andThrow(new TelegramForbiddenException(TelegramForbiddenException::REASON_BOT_BLOCKED));
    $this->app->instance(TelegramClient::class, $telegram);

    mwRaise($month, [['learner_id' => $winner->id, 'place' => 1, 'points' => 4]]);

    expect($winner->fresh()?->dm_blocked_at)->not->toBeNull()
        ->and(NotificationLog::query()->count())->toBe(0);
});

it('stops at the first 429 without a marker: the other winners are not tried, and the retry reaches each exactly once', function (): void {
    $month = mwMonth();
    $first = mwWinner();
    $second = mwWinner();
    $winners = [
        ['learner_id' => $first->id, 'place' => 1, 'points' => 10],
        ['learner_id' => $second->id, 'place' => 2, 'points' => 5],
    ];
    $this->tg->failSends = 1;
    $this->tg->failSendsRetryAfter = 8;

    expect(fn () => app(MonthWinnerSender::class)->sendAll($month->id, $winners))->toThrow(TelegramRateLimitedException::class);
    expect($this->tg->failSends)->toBe(0)
        ->and($this->tg->sent)->toBe([])
        ->and(NotificationLog::query()->count())->toBe(0);

    expect(app(MonthWinnerSender::class)->sendAll($month->id, $winners))->toBe(['sent' => 2, 'skipped' => 0, 'forbidden' => 0])
        ->and(app(MonthWinnerSender::class)->sendAll($month->id, $winners))->toBe(['sent' => 0, 'skipped' => 2, 'forbidden' => 0])
        ->and($this->tg->sent)->toHaveCount(2);
});

it('keeps the marker after a timeout, goes on with the others and rethrows at the end', function (): void {
    $month = mwMonth();
    $first = mwWinner();
    $second = mwWinner();
    $winners = [
        ['learner_id' => $first->id, 'place' => 1, 'points' => 10],
        ['learner_id' => $second->id, 'place' => 2, 'points' => 5],
    ];
    $this->tg->failSends = 1;
    $this->tg->failSendsAsTransport = true;

    expect(fn () => app(MonthWinnerSender::class)->sendAll($month->id, $winners))->toThrow(TelegramApiException::class);
    expect($this->tg->sent)->toHaveCount(1)
        ->and(NotificationLog::query()->count())->toBe(2)
        ->and(app(MonthWinnerSender::class)->sendAll($month->id, $winners))->toBe(['sent' => 0, 'skipped' => 2, 'forbidden' => 0]);
});

it('is picked up by the event system without a manual registration', function (): void {
    Event::fake();
    Event::assertListening(LeagueMonthConfirmed::class, SendMonthWinnerMessages::class);
});
