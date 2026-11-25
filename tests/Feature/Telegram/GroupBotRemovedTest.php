<?php

declare(strict_types=1);

use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramRateLimitedException;
use App\Domain\Telegram\Services\ClassCaravanMessenger;
use App\Domain\Telegram\Services\GroupSessionAnnouncer;
use App\Jobs\PostClassCaravanSummaryJob;
use App\Jobs\PostLeaderboardJob;
use App\Models\ClassCaravan;
use App\Models\ExamSession;
use App\Models\TelegramGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Tests\Support\FakeTelegramClient;

/*
 * docs/34 §4в п. 2, 3, 8 (B4): a send to a group that the bot is no longer in (403) turns the group `disabled` THROUGH ELOQUENT (its
 * observer drops the members' plans) and ends the job without an error - nothing lands in `failed_jobs`, no marker is written. A 429
 * in the group session announcer is waited out when short, otherwise the session is withdrawn without a second message into the chat.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['twa.base_url' => 'https://twa.test']);
    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);
    $this->group = TelegramGroup::factory()->create(['chat_id' => -100_700, 'status' => 'active']);
    $this->statusChanges = [];
    TelegramGroup::updated(function (TelegramGroup $group): void {
        if ($group->wasChanged('status')) {
            $this->statusChanges[] = $group->status;
        }
    });
});

it('disables the group on a 403 to the leaderboard, ends the job without an error and writes no marker', function (): void {
    $session = ExamSession::factory()->create(['telegram_group_id' => $this->group->id, 'status' => 'closed', 'closed_at' => now()]);
    $this->tg->failSends = 1;
    $this->tg->failSendsForbidden = 'forbidden';

    dispatch_sync(new PostLeaderboardJob($session->id));

    expect($this->group->fresh()?->status)->toBe('disabled')
        ->and($this->statusChanges)->toBe(['disabled'])
        ->and($session->fresh()?->leaderboard_posted_at)->toBeNull()
        ->and($this->tg->sent)->toBe([]);
});

it('still lets a 429 to the leaderboard out of the job (the middleware puts it back) and posts it once on the retry', function (): void {
    $session = ExamSession::factory()->create(['telegram_group_id' => $this->group->id, 'status' => 'closed', 'closed_at' => now()]);
    $this->tg->failSends = 1;
    $this->tg->failSendsRetryAfter = 4;

    expect(fn () => app()->call([new PostLeaderboardJob($session->id), 'handle']))->toThrow(TelegramRateLimitedException::class);
    expect($session->fresh()?->leaderboard_posted_at)->toBeNull();

    dispatch_sync(new PostLeaderboardJob($session->id));
    dispatch_sync(new PostLeaderboardJob($session->id));

    expect($this->tg->sent)->toHaveCount(1)
        ->and($session->fresh()?->leaderboard_posted_at)->not->toBeNull()
        ->and($this->group->fresh()?->status)->toBe('active');
});

it('disables the group on a 403 to the summary of a class caravan, without the marker and without an error', function (): void {
    $caravan = ClassCaravan::factory()->finished()->create(['telegram_group_id' => $this->group->id]);
    $this->tg->failSends = 1;
    $this->tg->failSendsForbidden = 'forbidden';

    dispatch_sync(new PostClassCaravanSummaryJob($caravan->id));

    expect($this->group->fresh()?->status)->toBe('disabled')
        ->and($this->statusChanges)->toBe(['disabled'])
        ->and($caravan->fresh()?->summary_posted_at)->toBeNull();

    // A later run meets the disabled group and closes the summary as skipped, quietly.
    dispatch_sync(new PostClassCaravanSummaryJob($caravan->id));
    expect($caravan->fresh()?->summary_posted_at)->not->toBeNull()
        ->and($this->tg->sent)->toBe([]);
});

it('disables the group when the answer of a caravan command gets a 403, and only logs a 429', function (): void {
    $messenger = app(ClassCaravanMessenger::class);

    $this->tg->failSends = 1;
    $this->tg->failSendsRetryAfter = 30;
    $messenger->announceCanceled($this->group);
    expect($this->group->fresh()?->status)->toBe('active');

    $this->tg->failSends = 1;
    $this->tg->failSendsRetryAfter = null;
    $this->tg->failSendsForbidden = 'forbidden';
    $messenger->announceCanceled($this->group);
    expect($this->group->fresh()?->status)->toBe('disabled')
        ->and($this->statusChanges)->toBe(['disabled']);
});

it('waits out a short 429 of the group session button and sends it once more: the session stays', function (): void {
    Sleep::fake();
    $this->tg->failSends = 1;
    $this->tg->failSendsRetryAfter = 3;
    $canceled = false;

    $sent = app(GroupSessionAnnouncer::class)->announce(-100_700, 'Exam', 'Open', 'https://twa.test/twa/exam/7', 'failed', function () use (&$canceled): void {
        $canceled = true;
    });

    expect($sent)->toBeTrue()
        ->and($canceled)->toBeFalse()
        ->and($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['text'])->toBe('Exam');
    Sleep::assertSlept(fn ($duration): bool => (int) $duration->totalSeconds === 3, 1);
});

it('withdraws the session on a long 429 or a second one, and does not write the failure text into the chat', function (int $retryAfter, int $failures, int $sleeps): void {
    Sleep::fake();
    $this->tg->failSends = $failures;
    $this->tg->failSendsRetryAfter = $retryAfter;
    $canceled = false;

    $sent = app(GroupSessionAnnouncer::class)->announce(-100_700, 'Exam', 'Open', 'https://twa.test/twa/exam/7', 'failed', function () use (&$canceled): void {
        $canceled = true;
    });

    expect($sent)->toBeFalse()
        ->and($canceled)->toBeTrue()
        ->and($this->tg->sent)->toBe([])
        ->and($this->group->fresh()?->status)->toBe('active');
    Sleep::assertSleptTimes($sleeps);
})->with([
    'longer than 3 s' => [4, 1, 0],
    'a 429 again after the wait' => [2, 2, 1],
]);

it('withdraws the session and disables the group on a 403 of the session button, without the failure text', function (): void {
    $this->tg->failSends = 1;
    $this->tg->failSendsForbidden = 'forbidden';
    $canceled = false;

    $sent = app(GroupSessionAnnouncer::class)->announce(-100_700, 'Exam', 'Open', 'https://twa.test/twa/exam/7', 'failed', function () use (&$canceled): void {
        $canceled = true;
    });

    expect($sent)->toBeFalse()
        ->and($canceled)->toBeTrue()
        ->and($this->tg->sent)->toBe([])
        ->and($this->group->fresh()?->status)->toBe('disabled');
});

it('keeps the old answer to another refusal of the session button: withdrawn, the failure text goes to the chat', function (): void {
    $this->tg->failSends = 1;
    $canceled = false;

    $sent = app(GroupSessionAnnouncer::class)->announce(-100_700, 'Exam', 'Open', 'https://twa.test/twa/exam/7', 'failed', function () use (&$canceled): void {
        $canceled = true;
    });

    expect($sent)->toBeFalse()
        ->and($canceled)->toBeTrue()
        ->and(array_column($this->tg->sent, 'text'))->toBe(['failed'])
        ->and($this->group->fresh()?->status)->toBe('active');
});
