<?php

declare(strict_types=1);

use App\Domain\Cup\Enums\CupMessageKind;
use App\Domain\Cup\Enums\CupMessageStatus;
use App\Domain\Cup\Services\CupLifecycleService;
use App\Domain\Cup\Services\CupOutbox;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Exceptions\TelegramRateLimitedException;
use App\Domain\Telegram\Services\GroupChatMigrator;
use App\Domain\Telegram\Services\TelegramMessageRateLimiter;
use App\Jobs\PostCupMessageJob;
use App\Models\Cup;
use App\Models\CupMessage;
use App\Models\CupParticipant;
use App\Models\TelegramGroup;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Cup\CupKit;

/*
 * docs/33 §6.4 (K6): the outbox of a cup - `cup_messages` + PostCupMessageJob. At most once: a repeated final is one message, a
 * timeout is `unknown` and never sent again, 403 is `skipped` and the group `disabled`, a 429 or a chat without a slot puts the row
 * back, a paused or switched-off cup sends nothing, a stuck `sending` becomes `unknown`.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CupKit::open();
    CupKit::course();
    config(['telegram.bot_username' => 'muhrla_test_bot', 'twa.base_url' => 'https://twa.test']);
    Storage::fake('public');
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** A finished cup of two players (the finish wrote its `final` row) - the row, not sent yet. */
function finishedCup(): array
{
    ['cup' => $cup, 'learners' => [$a, $b]] = CupKit::running(2);
    CupParticipant::query()->where('learner_id', $a->id)->update(['best_total' => 30, 'best_round_points' => 30]);
    CupParticipant::query()->where('learner_id', $b->id)->update(['best_total' => 18, 'best_round_points' => 18]);
    $end = CarbonImmutable::instance($cup->ends_at)->addMinutes(31);
    CarbonImmutable::setTestNow($end);
    DB::table('cup_days')->where('cup_id', $cup->id)->update(['built_at' => now()]);
    app(CupLifecycleService::class)->finish($cup->id, $end);

    return [Cup::query()->with('telegramGroup')->findOrFail($cup->id), CupMessage::query()->where('cup_id', $cup->id)->where('dedupe_key', 'final')->sole()];
}

function deliverRow(CupMessage $row): int
{
    return app(CupOutbox::class)->deliver($row->id, CarbonImmutable::now());
}

it('posts the final once: a second queueing, a retry and a manual run send nothing more', function (): void {
    [$cup, $row] = finishedCup();

    deliverRow($row);
    deliverRow($row);
    (new PostCupMessageJob($row->id))->handle(app(CupOutbox::class));
    expect(app(CupOutbox::class)->enqueue($cup->id, 'final', CupMessageKind::Final, CarbonImmutable::now()))->toBeFalse();
    Artisan::call('cups:send-outbox');

    $row->refresh();
    $photo = CupKit::telegram()->photoMessages[0];
    expect(CupKit::telegram()->photoMessages)->toHaveCount(1)
        ->and(CupMessage::query()->where('cup_id', $cup->id)->count())->toBe(1)
        ->and($row->status)->toBe(CupMessageStatus::Sent)
        ->and($row->message_id)->toBe($photo['message_id'])
        ->and($row->attempts)->toBe(1)
        ->and($photo['caption'])->toContain('Кубок друзей завершён!')->toContain('🥇 Player 0 — 30')->toContain('🥈 Player 1 — 18')
        ->and($photo['caption'])->toContain('Поздравляем: Player 0!');
});

it('does not send again after a timeout: `unknown`, a retry and the sweep stay silent', function (): void {
    [, $row] = finishedCup();
    CupKit::telegram()->failSends = 1;
    CupKit::telegram()->failSendsAsTransport = true;

    deliverRow($row);
    deliverRow($row);
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(30));
    Artisan::call('cups:send-outbox');

    expect($row->fresh()->status)->toBe(CupMessageStatus::Unknown)
        ->and($row->fresh()->error)->toContain('api:0')
        ->and(CupKit::telegram()->photoMessages)->toBe([]);
});

it('skips the message and disables the group on 403 - nothing was posted and nothing will be', function (): void {
    [$cup, $row] = finishedCup();
    CupKit::telegram()->failSends = 1;
    CupKit::telegram()->failSendsForbidden = TelegramForbiddenException::REASON_FORBIDDEN;

    expect(deliverRow($row))->toBe(0);

    expect($row->fresh()->status)->toBe(CupMessageStatus::Skipped)
        ->and($row->fresh()->error)->toBe('forbidden:forbidden')
        ->and(TelegramGroup::query()->findOrFail($cup->telegram_group_id)->status)->toBe('disabled');
});

it('puts the row back on a 429 (the middleware releases the job) and sends it once on the retry', function (): void {
    [, $row] = finishedCup();
    CupKit::telegram()->failSends = 1;
    CupKit::telegram()->failSendsRetryAfter = 7;

    expect(fn () => deliverRow($row))->toThrow(TelegramRateLimitedException::class);
    expect($row->fresh()->status)->toBe(CupMessageStatus::Pending)
        ->and($row->fresh()->attempts)->toBe(0);

    deliverRow($row);

    expect($row->fresh()->status)->toBe(CupMessageStatus::Sent)
        ->and(CupKit::telegram()->photoMessages)->toHaveCount(1);
});

it('waits while ITS chat is paused after a 429 and sends to another group meanwhile', function (): void {
    [$cup, $row] = finishedCup();
    [$other, $otherRow] = finishedCup();
    app(TelegramMessageRateLimiter::class)->pause(30, $cup->telegramGroup->chat_id);

    $wait = deliverRow($row);
    deliverRow($otherRow);

    expect($wait)->toBeGreaterThan(0)->toBeLessThanOrEqual(30)
        ->and($row->fresh()->status)->toBe(CupMessageStatus::Pending)
        ->and($row->fresh()->attempts)->toBe(0)
        ->and($otherRow->fresh()->status)->toBe(CupMessageStatus::Sent)
        ->and(CupKit::telegram()->photoMessages)->toHaveCount(1)
        ->and(CupKit::telegram()->photoMessages[0]['chat_id'])->toBe($other->telegramGroup->chat_id)
        ->and(app(TelegramMessageRateLimiter::class)->globalPauseRemaining())->toBe(0);
});

it('retries another refusal of Telegram and gives the row up after the limit of attempts', function (): void {
    [, $row] = finishedCup();
    CupKit::telegram()->failSends = 10;

    for ($i = 1; $i < CupOutbox::MAX_ATTEMPTS; $i++) {
        expect(fn () => deliverRow($row))->toThrow(TelegramApiException::class);
        expect($row->fresh()->status)->toBe(CupMessageStatus::Pending);
    }
    deliverRow($row);

    expect($row->fresh()->status)->toBe(CupMessageStatus::Failed)
        ->and($row->fresh()->attempts)->toBe(CupOutbox::MAX_ATTEMPTS);
});

it('skips the messages of a paused cup and of a cup switched off', function (Closure $pause): void {
    [$cup, $row] = finishedCup();
    $pause($cup->telegramGroup);

    deliverRow($row);

    expect($row->fresh()->status)->toBe(CupMessageStatus::Skipped)
        ->and(CupKit::telegram()->photoMessages)->toBe([])
        ->and(CupKit::telegram()->groupMessages)->toBe([]);
})->with([
    'the bot was removed' => fn (TelegramGroup $g) => $g->update(['bot_status' => 'kicked']),
    'cups forbidden in the group' => fn (TelegramGroup $g) => $g->update(['meta' => ['cups_blocked' => true]]),
    'CUP_ACCESS=off' => fn () => config(['cup.access' => 'off']),
]);

it('turns a row stuck in `sending` into `unknown` after 10 minutes and queues a forgotten `pending` row', function (): void {
    [$cup, $row] = finishedCup();
    CupMessage::query()->whereKey($row->id)->update(['status' => 'sending', 'claimed_at' => CarbonImmutable::now()->subMinutes(11)]);
    $summary = CupMessage::query()->create(['cup_id' => $cup->id, 'dedupe_key' => 'summary:7', 'kind' => 'summary', 'status' => 'pending', 'attempts' => 0]);
    CupMessage::query()->whereKey($summary->id)->update(['updated_at' => CarbonImmutable::now()->subMinutes(3)]);

    Artisan::call('cups:send-outbox');

    expect($row->fresh()->status)->toBe(CupMessageStatus::Unknown)
        ->and($row->fresh()->error)->toBe('stale_sending')
        ->and($summary->fresh()->status)->toBe(CupMessageStatus::Sent)
        ->and(CupKit::telegram()->groupMessages)->toHaveCount(1)
        ->and(CupKit::telegram()->groupMessages[0]['text'])->toContain('итоги дня 7 из 7');
});

it('follows a group that became a supergroup: the next message goes to the new chat', function (): void {
    [$cup, $row] = finishedCup();
    $old = $cup->telegramGroup->chat_id;
    app(GroupChatMigrator::class)->migrate($old, -1_009_999_999_999);

    deliverRow($row);

    expect(CupKit::telegram()->photoMessages[0]['chat_id'])->toBe(-1_009_999_999_999)
        ->and($row->fresh()->chat_id)->toBe(-1_009_999_999_999);
});
