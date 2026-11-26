<?php

declare(strict_types=1);

use App\Domain\Cup\Enums\CupDuelEndReason;
use App\Domain\Cup\Enums\CupDuelStatus;
use App\Domain\Cup\Enums\CupMessageKind;
use App\Domain\Cup\Enums\CupMessageStatus;
use App\Domain\Cup\Services\CupDuelService;
use App\Domain\Cup\Services\CupOutbox;
use App\Domain\Operations\Support\PureCache;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Exceptions\TelegramRateLimitedException;
use App\Jobs\PostCupMessageJob;
use App\Jobs\ScoreCupDuelJob;
use App\Models\Cup;
use App\Models\CupDuel;
use App\Models\CupMessage;
use App\Models\Learner;
use App\Models\TelegramGroup;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Cup\CupDuelKit;
use Tests\Support\Cup\CupKit;
use Tests\Support\QueryLog;

/*
 * docs/33 §6.1, §11.9 п. 10 (D2): the delivery of the rows of a public duel - `duel:{id}` a new message in the chat with the url button
 * «Принять вызов», `duel:{id}:end` the EDIT of that message without buttons: a decline, an expiry, a withdrawal never post a new
 * message; the edit waits for its message; «not modified» is a success; 403 / 429 / a timeout as every row of the outbox (docs/34 §4в).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake([PostCupMessageJob::class, ScoreCupDuelJob::class]);
    Cache::flush();
    PureCache::store()->flush();
    config(['telegram.bot_username' => 'muhrla_test_bot', 'twa.base_url' => 'https://twa.test']);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function duelRow(CupDuel $duel, bool $end = false): CupMessage
{
    return CupMessage::query()->where('cup_id', $duel->cup_id)->where('dedupe_key', $end ? CupOutbox::duelEndKey($duel->id) : CupOutbox::duelKey($duel->id))->sole();
}

function deliverDuelRow(CupMessage $row, ?CarbonImmutable $now = null): int
{
    return app(CupOutbox::class)->deliver($row->id, $now ?? CarbonImmutable::now());
}

function duelPlayer(int $participantId): Learner
{
    return Learner::query()->findOrFail(DB::table('cup_participants')->where('id', $participantId)->value('learner_id'));
}

/** A public challenge of a running cup, its message posted. @return array{0: Cup, 1: CupDuel, 2: array<string, mixed>} */
function postedChallenge(bool $addressed = true): array
{
    ['cup' => $cup, 'learners' => [$a], 'participants' => [, $pb]] = CupDuelKit::running();
    $duel = CupDuelKit::challenge($a, $cup, $addressed ? $pb : null, public: true)->duel;
    deliverDuelRow(duelRow($duel));

    return [CupDuelKit::cup($cup), $duel, CupKit::telegram()->groupMessages[0]];
}

it('posts a public challenge once: the text of the duel, the url button «Принять вызов» into the Mini App, the topic of the card', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'participants' => [, $pb]] = CupDuelKit::running();
    DB::table('cups')->where('id', $cup->id)->update(['card_thread_id' => 77]);
    $duel = CupDuelKit::challenge($a, $cup, $pb, public: true)->duel;
    $row = duelRow($duel);

    expect(deliverDuelRow($row))->toBe(0);
    deliverDuelRow($row);
    (new PostCupMessageJob($row->id))->handle(app(CupOutbox::class));

    $message = CupKit::telegram()->groupMessages[0] ?? [];
    $button = $message['markup']['inline_keyboard'][0][0] ?? [];
    expect(CupKit::telegram()->groupMessages)->toHaveCount(1)
        ->and($message['chat_id'])->toBe($cup->telegramGroup->chat_id)
        ->and($message['thread_id'])->toBe(77)
        ->and($message['text'])->toContain('вызывает на дуэль')->toContain('Зачётная дуэль')
        ->and($button['text'])->toBe('⚔️ Принять вызов')
        ->and($button['url'])->toStartWith('https://t.me/muhrla_test_bot')
        ->and($button)->not->toHaveKey('web_app')
        ->and($row->fresh()->status)->toBe(CupMessageStatus::Sent)
        ->and($row->fresh()->message_id)->toBe($message['message_id'])
        ->and($row->fresh()->attempts)->toBe(1);
});

it('edits the same message on a decline, an expiry, a withdrawal - never a new one, and no button', function (string $end): void {
    [, $duel, $posted] = postedChallenge();
    $service = app(CupDuelService::class);
    $a = duelPlayer($duel->challenger_participant_id);
    $b = duelPlayer((int) $duel->opponent_participant_id);
    match ($end) {
        'declined' => $service->decline($b, $duel->id),
        'canceled' => $service->cancel($a, $duel->id),
        'expired' => $service->sweep(CarbonImmutable::now()->addMinutes(11)),
    };
    $row = duelRow($duel, end: true);

    deliverDuelRow($row);
    deliverDuelRow($row);

    $edit = CupKit::telegram()->lastTextEdit();
    expect(CupDuelKit::fresh($duel)->status->value)->toBe($end)
        ->and(CupKit::telegram()->groupMessages)->toHaveCount(1)
        ->and(CupKit::telegram()->textEdits)->toHaveCount(1)
        ->and($edit['chat_id'])->toBe($posted['chat_id'])
        ->and($edit['message_id'])->toBe($posted['message_id'])
        ->and($edit['text'])->toBe('Вызов не состоялся.')
        ->and($edit['markup'])->toBeNull()
        ->and($row->fresh()->status)->toBe(CupMessageStatus::Sent)
        ->and($row->fresh()->message_id)->toBe($posted['message_id']);
})->with(['declined', 'canceled', 'expired']);

it('edits the message with the result of the match', function (): void {
    [, $duel, $posted] = postedChallenge();
    DB::table('cup_duels')->where('id', $duel->id)->update([
        'status' => CupDuelStatus::Done->value, 'open_challenger_id' => null, 'opponent_participant_id' => $duel->opponent_participant_id,
        'rated' => true, 'accepted_at' => now(), 'accepted_day' => 1, 'scored_at' => now(), 'closed_at' => now(),
        'challenger_points' => 4, 'opponent_points' => 3, 'winner_participant_id' => $duel->challenger_participant_id,
    ]);
    app(CupOutbox::class)->enqueueMany([['cup_id' => $duel->cup_id, 'dedupe_key' => CupOutbox::duelEndKey($duel->id)]], CupMessageKind::Duel, CarbonImmutable::now());

    deliverDuelRow(duelRow($duel, end: true));

    expect(CupKit::telegram()->lastTextEdit()['text'])->toContain('4 : 3')->toContain('Победа: Player 0')
        ->and(CupKit::telegram()->lastTextEdit()['message_id'])->toBe($posted['message_id'])
        ->and(CupKit::telegram()->groupMessages)->toHaveCount(1);
});

it('lets the edit wait for its message, and posts nothing at all for a challenge that fell through before it went out', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b], 'participants' => [, $pb]] = CupDuelKit::running();
    $duel = CupDuelKit::challenge($a, $cup, $pb, public: true)->duel;
    app(CupDuelService::class)->decline($b, $duel->id);
    $end = duelRow($duel, end: true);

    expect(deliverDuelRow($end))->toBe(CupOutbox::DUEL_EDIT_WAIT_SECONDS)
        ->and($end->fresh()->status)->toBe(CupMessageStatus::Pending)
        ->and($end->fresh()->attempts)->toBe(0);

    deliverDuelRow(duelRow($duel));
    deliverDuelRow($end);

    expect(CupKit::telegram()->groupMessages)->toBe([])
        ->and(CupKit::telegram()->textEdits)->toBe([])
        ->and(duelRow($duel)->status)->toBe(CupMessageStatus::Skipped)
        ->and(duelRow($duel)->error)->toBe('fell_through')
        ->and($end->fresh()->status)->toBe(CupMessageStatus::Skipped);
});

it('posts an accepted challenge with its state when it went out late, and counts «not modified» of the edit as a success', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b], 'participants' => [, $pb]] = CupDuelKit::running();
    $duel = CupDuelKit::challenge($a, $cup, $pb, public: true)->duel;
    CupDuelKit::accept($b, $duel);

    deliverDuelRow(duelRow($duel));
    DB::table('cup_duels')->where('id', $duel->id)->update(['status' => 'void', 'end_reason' => 'no_answers', 'scored_at' => now(), 'closed_at' => now()]);
    app(CupOutbox::class)->enqueueMany([['cup_id' => $duel->cup_id, 'dedupe_key' => CupOutbox::duelEndKey($duel->id)]], CupMessageKind::Duel, CarbonImmutable::now());
    CupKit::telegram()->failTextEdits = true;
    deliverDuelRow(duelRow($duel, end: true));

    expect(CupKit::telegram()->groupMessages[0]['text'])->toContain('Дуэль идёт')
        ->and(CupKit::telegram()->groupMessages[0]['markup'])->toBeNull()
        ->and(duelRow($duel, end: true)->status)->toBe(CupMessageStatus::Sent);
});

it('follows the rules of the senders on the edit: 403 disables the group, a 429 puts the row back, a timeout is unknown, a deleted message is skipped', function (): void {
    [$cup, $duel] = postedChallenge();
    app(CupDuelService::class)->cancel(duelPlayer($duel->challenger_participant_id), $duel->id);
    $row = duelRow($duel, end: true);
    $telegram = CupKit::telegram();

    $telegram->failNextTextEditWith = new TelegramRateLimitedException(5, 'editMessageText');
    expect(fn () => deliverDuelRow($row))->toThrow(TelegramRateLimitedException::class);
    expect($row->fresh()->status)->toBe(CupMessageStatus::Pending)->and($row->fresh()->attempts)->toBe(0);

    $telegram->failNextTextEditWith = new TelegramApiException('cURL error 28: Operation timed out');
    deliverDuelRow($row);
    expect($row->fresh()->status)->toBe(CupMessageStatus::Unknown);

    DB::table('cup_messages')->where('id', $row->id)->update(['status' => 'pending']);
    $telegram->failNextTextEditWith = TelegramApiException::apiError('Bad Request: message to edit not found', 400);
    deliverDuelRow($row);
    expect($row->fresh()->status)->toBe(CupMessageStatus::Skipped);

    DB::table('cup_messages')->where('id', $row->id)->update(['status' => 'pending']);
    $telegram->failNextTextEditWith = TelegramApiException::apiError('Forbidden: bot was kicked from the supergroup chat', 403);
    deliverDuelRow($row);
    expect($row->fresh()->status)->toBe(CupMessageStatus::Skipped)
        ->and(TelegramGroup::query()->findOrFail($cup->telegram_group_id)->status)->toBe('disabled')
        ->and($telegram->textEdits)->toBe([])
        ->and($telegram->groupMessages)->toHaveCount(1);
});

it('follows the rules of the senders on the message: 403, 429, a timeout', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'participants' => [, $pb]] = CupDuelKit::running();
    $duel = CupDuelKit::challenge($a, $cup, $pb, public: true)->duel;
    $row = duelRow($duel);
    $telegram = CupKit::telegram();

    $telegram->failSends = 1;
    $telegram->failSendsRetryAfter = 7;
    expect(fn () => deliverDuelRow($row))->toThrow(TelegramRateLimitedException::class);
    expect($row->fresh()->status)->toBe(CupMessageStatus::Pending)->and($row->fresh()->attempts)->toBe(0);

    $telegram->failSends = 1;
    $telegram->failSendsRetryAfter = null;
    $telegram->failSendsAsTransport = true;
    deliverDuelRow($row);
    expect($row->fresh()->status)->toBe(CupMessageStatus::Unknown);
    // The message may be in the chat without its id: the edit has nothing to edit, and posts nothing.
    app(CupDuelService::class)->cancel($a, $duel->id);
    deliverDuelRow(duelRow($duel, end: true));
    expect(duelRow($duel, end: true)->status)->toBe(CupMessageStatus::Skipped)->and($telegram->textEdits)->toBe([]);

    DB::table('cup_messages')->where('id', $row->id)->update(['status' => 'pending']);
    DB::table('cup_duels')->where('id', $duel->id)->update(['status' => 'open', 'end_reason' => null, 'closed_at' => null, 'open_challenger_id' => $duel->challenger_participant_id]);
    $telegram->failSends = 1;
    $telegram->failSendsAsTransport = false;
    $telegram->failSendsForbidden = TelegramForbiddenException::REASON_FORBIDDEN;
    deliverDuelRow($row);
    expect($row->fresh()->status)->toBe(CupMessageStatus::Skipped)
        ->and($row->fresh()->error)->toBe('forbidden:forbidden')
        ->and(TelegramGroup::query()->findOrFail($cup->telegram_group_id)->status)->toBe('disabled')
        ->and($telegram->groupMessages)->toBe([]);
});

it('skips the rows of a paused cup and of a cup switched off', function (Closure $pause): void {
    ['cup' => $cup, 'learners' => [$a], 'participants' => [, $pb]] = CupDuelKit::running();
    $duel = CupDuelKit::challenge($a, $cup, $pb, public: true)->duel;
    $pause($cup);

    deliverDuelRow(duelRow($duel));

    expect(duelRow($duel)->status)->toBe(CupMessageStatus::Skipped)->and(CupKit::telegram()->groupMessages)->toBe([]);
})->with([
    'bot removed' => [fn (Cup $cup) => DB::table('telegram_groups')->where('id', $cup->telegram_group_id)->update(['bot_status' => 'kicked'])],
    'switched off' => [fn () => config(['cup.access' => 'off'])],
]);

it('delivers a challenge in 7 statements and its edit in 7', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b], 'participants' => [, $pb]] = CupDuelKit::running();
    $duel = CupDuelKit::challenge($a, $cup, $pb, public: true)->duel;
    $row = duelRow($duel);

    $send = QueryLog::during(fn () => deliverDuelRow($row));
    app(CupDuelService::class)->decline($b, $duel->id);
    $endRow = duelRow($duel, end: true);
    $edit = QueryLog::during(fn () => deliverDuelRow($endRow));

    // claim, the row, the cup, its group, the duel, the names, the row `sent`
    expect($send)->toHaveCount(7, implode("\n", $send))
        // claim, the row, the cup, its group, the duel, the row of the message it edits, the row `sent` (a fallen-through challenge
        // needs no names; a result does: 8)
        ->and($edit)->toHaveCount(7, implode("\n", $edit))
        ->and(CupDuelKit::fresh($duel)->end_reason)->toBe(CupDuelEndReason::Declined);
});
