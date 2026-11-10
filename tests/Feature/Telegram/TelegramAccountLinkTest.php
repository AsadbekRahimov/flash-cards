<?php

declare(strict_types=1);

use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Enums\AccountLinkResult;
use App\Domain\Telegram\Enums\LinkTokenKind;
use App\Domain\Telegram\Exceptions\TelegramLinkException;
use App\Domain\Telegram\Handlers\AccountLinkCallbackHandler;
use App\Domain\Telegram\Services\TelegramAccountLinkService;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Domain\Telegram\Services\TelegramLinkTokens;
use App\Models\AuditLog;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\TelegramLinkToken;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\FakeTelegramClient;

/*
 * Connecting a staff user's Telegram through the bot (docs/07 §5, audit 2026-11-10 H1, review W1 / W2): the cabinet issues
 * `t.me/<bot>?start=link_<token>`, the bot ASKS "Connect this Telegram to the cabinet of X?", and only "Yes" from the account that
 * was asked writes `message.from.id` and `telegram_verified_at`. A hand-typed id proves nothing: it gives way to a confirmed link
 * and never switches a learner's bot off. `/unlink` lets the owner of the account disconnect it themselves.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['telegram.bot_username' => 'LexiFlowBot']);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-10 12:00:00', 'UTC'));

    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);
    $this->service = app(TelegramAccountLinkService::class);
    $this->staff = User::factory()->create(['telegram_user_id' => null, 'is_active' => true, 'name' => 'Nodira K.', 'email' => 'nodira@example.test']);
    $org = Organization::factory()->create(['name' => 'Bright Center']);
    OrganizationUser::factory()->create(['organization_id' => $org->id, 'user_id' => $this->staff->id, 'role' => OrganizationRole::Owner->value]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/** `/start <payload>` (or any $text) in the private chat of Telegram user $tgId. */
function talStart(int $tgId, string $payload, array $from = [], ?string $text = null): void
{
    app(TelegramDispatcher::class)->dispatch(['message' => [
        'message_id' => 1,
        'chat' => ['id' => $tgId, 'type' => 'private'],
        'from' => ['id' => $tgId, 'first_name' => 'Nodira', 'last_name' => 'K.', 'username' => 'nodira_k', 'language_code' => 'ru', ...$from],
        'text' => $text ?? '/start '.$payload,
    ]]);
}

/** A tap on a `lnk1:` button in the private chat of $tgId (the message is in $chatId — the tapper's own DM unless a test says otherwise). */
function talTap(int $tgId, string $data, ?int $chatId = null, array $from = []): void
{
    app(TelegramDispatcher::class)->dispatch(['callback_query' => [
        'id' => 'cb-'.md5($data.$tgId),
        'from' => ['id' => $tgId, 'first_name' => 'Nodira', 'last_name' => 'K.', 'username' => 'nodira_k', 'language_code' => 'ru', ...$from],
        'message' => ['message_id' => 55, 'chat' => ['id' => $chatId ?? $tgId, 'type' => 'private']],
        'data' => $data,
    ]]);
}

/** Opens the link and taps "Yes": the whole happy path of one person. */
function talLink(int $tgId, string $token, array $from = []): void
{
    talStart($tgId, 'link_'.$token, $from);
    talTap($tgId, 'lnk1:yes:'.$token, from: $from);
}

function talLastText(FakeTelegramClient $tg): string
{
    return (string) end($tg->sent)['text'];
}

const TAL_REFUSED = 'Ссылка для подключения недействительна или устарела. Откройте кабинет центра → Профиль → «Подключить Telegram» и получите новую.';

// --- issuing ---------------------------------------------------------------------------------------

it('issues a one-time link bound to the user: CSPRNG token of 32 url-safe chars, only its hash stored, 15 minutes', function (): void {
    $link = $this->service->issueLink($this->staff);

    expect($link->kind)->toBe(LinkTokenKind::Account)
        ->and($link->code)->toMatch('/^[A-Za-z0-9_-]{32}$/')
        ->and($link->url)->toBe('https://t.me/LexiFlowBot?start=link_'.$link->code)
        ->and(strlen('link_'.$link->code))->toBeLessThanOrEqual(64)
        ->and(strlen('lnk1:yes:'.$link->code))->toBeLessThanOrEqual(64)
        ->and($link->expiresAt->toIso8601String())->toBe('2026-11-10T12:15:00+00:00');

    $row = TelegramLinkToken::query()->sole();
    expect($row->kind)->toBe('account')
        ->and($row->user_id)->toBe($this->staff->id)
        ->and($row->organization_id)->toBeNull()
        ->and($row->token_hash)->toBe(hash('sha256', $link->code))
        ->and($row->token_hash)->not->toContain($link->code)
        ->and($row->used_at)->toBeNull()
        ->and($row->prompted_telegram_user_id)->toBeNull();
});

it('replaces the live link of the user with a new one and limits how many may be asked for', function (): void {
    $first = $this->service->issueLink($this->staff);
    $second = $this->service->issueLink($this->staff);

    expect(TelegramLinkToken::query()->count())->toBe(1)
        ->and($this->service->prompt($first->code, ['id' => 501])->result)->toBe(AccountLinkResult::TokenInvalid)
        ->and($this->service->prompt($second->code, ['id' => 501])->result)->toBe(AccountLinkResult::Prompted);

    config(['organizations.telegram_link.issue_per_10_minutes' => 3]);
    RateLimiter::clear('tg-link-issue:'.$this->staff->id);
    $this->service->issueLink($this->staff);
    $this->service->issueLink($this->staff);
    $this->service->issueLink($this->staff);

    expect(fn () => $this->service->issueLink($this->staff))->toThrow(TelegramLinkException::class, 'Too many');
});

// --- the two steps through the bot (W2) -------------------------------------------------------------

it('asks before connecting: /start link_<token> names the cabinet and the masked account, shows Yes / No, and writes nothing yet', function (): void {
    $link = $this->service->issueLink($this->staff);

    talStart(777001, 'link_'.$link->code);

    expect(talLastText($this->tg))->toBe("Подключить этот Telegram к кабинету центра «Bright Center»?\n\nАккаунт: Nodira K. (n***@e***.test).\n\nПосле подключения бот будет считать вас сотрудником центра: в этом чате он перестанет отвечать вам как ученику. Отключить можно в любой момент командой /unlink.")
        ->and($this->tg->lastCallbackData())->toBe(['lnk1:yes:'.$link->code, 'lnk1:no:'.$link->code])
        ->and($this->staff->fresh()?->telegram_user_id)->toBeNull()
        ->and(TelegramLinkToken::query()->sole()->used_at)->toBeNull()
        ->and(TelegramLinkToken::query()->sole()->prompted_telegram_user_id)->toBe(777001)
        ->and(TelegramLinkToken::query()->sole()->last_result)->toBe('prompted')
        ->and(Learner::query()->count())->toBe(0);
});

it('"Yes" links the tapper to the staff account: id, proof, username and name, audit, no learner row; the buttons are cleared', function (): void {
    $link = $this->service->issueLink($this->staff);

    talLink(777001, $link->code);

    $user = $this->staff->fresh();
    expect($user?->telegram_user_id)->toBe(777001)
        ->and($user?->telegram_verified_at?->toIso8601String())->toBe('2026-11-10T12:00:00+00:00')
        ->and($user?->telegram_username)->toBe('nodira_k')
        ->and($user?->telegram_name)->toBe('Nodira K.')
        ->and($user?->hasVerifiedTelegram())->toBeTrue()
        ->and(Learner::query()->where('telegram_user_id', 777001)->exists())->toBeFalse()
        ->and(TelegramLinkToken::query()->sole()->used_at)->not->toBeNull()
        ->and(talLastText($this->tg))->toStartWith('✅ Telegram подключён к аккаунту Nodira K.')
        ->and(end($this->tg->sent)['chat_id'])->toBe(777001)
        ->and(end($this->tg->edits))->toMatchArray(['chat_id' => 777001, 'message_id' => 55, 'markup' => null]);

    $audit = AuditLog::query()->where('action', TelegramAccountLinkService::AUDIT_LINKED)->sole();
    expect($audit->user_id)->toBe($this->staff->id)
        ->and($audit->meta)->toMatchArray(['telegram_user_id' => 777001, 'previous_telegram_user_id' => null, 'previous_verified' => false, 'superseded_user_id' => null]);
});

it('"No" connects nothing and keeps the token live for a second thought', function (): void {
    $link = $this->service->issueLink($this->staff);
    talStart(777002, 'link_'.$link->code);

    talTap(777002, 'lnk1:no:'.$link->code);

    expect(talLastText($this->tg))->toBe('Хорошо, ничего не подключено. Если передумаете — откройте ссылку из кабинета ещё раз.')
        ->and($this->staff->fresh()?->telegram_user_id)->toBeNull()
        ->and(TelegramLinkToken::query()->sole()->used_at)->toBeNull()
        ->and(TelegramLinkToken::query()->sole()->last_result)->toBe('declined');

    talLink(777002, $link->code);
    expect($this->staff->fresh()?->telegram_user_id)->toBe(777002);
});

it('accepts "Yes" from the account the question was asked only: another from.id, a tap in another chat, a tap without the question — refused, token kept', function (): void {
    $link = $this->service->issueLink($this->staff);

    // Nobody was asked yet: a crafted callback cannot skip the question.
    talTap(777003, 'lnk1:yes:'.$link->code);
    expect(talLastText($this->tg))->toBe(TAL_REFUSED)
        ->and($this->staff->fresh()?->telegram_user_id)->toBeNull();

    talStart(777003, 'link_'.$link->code);

    // Somebody else taps "Yes" with the same token (a forwarded question, a crafted update).
    talTap(777004, 'lnk1:yes:'.$link->code);
    expect(talLastText($this->tg))->toBe(TAL_REFUSED)
        ->and(end($this->tg->sent)['chat_id'])->toBe(777004)
        ->and($this->staff->fresh()?->telegram_user_id)->toBeNull()
        ->and(TelegramLinkToken::query()->sole()->used_at)->toBeNull();

    // The right person, but the button lives in a chat that is not their DM: ignored without a word.
    $sent = count($this->tg->sent);
    talTap(777003, 'lnk1:yes:'.$link->code, chatId: 777004);
    expect(count($this->tg->sent))->toBe($sent)
        ->and($this->staff->fresh()?->telegram_user_id)->toBeNull();

    // The right person, in their DM: connected.
    talTap(777003, 'lnk1:yes:'.$link->code);
    expect($this->staff->fresh()?->telegram_user_id)->toBe(777003);

    // A repeated "Yes" on the spent token.
    talTap(777003, 'lnk1:yes:'.$link->code);
    expect(talLastText($this->tg))->toBe(TAL_REFUSED);
});

it('re-binds the question to whoever opened the link last', function (): void {
    $link = $this->service->issueLink($this->staff);
    talStart(777005, 'link_'.$link->code);
    talStart(777006, 'link_'.$link->code);

    talTap(777005, 'lnk1:yes:'.$link->code);
    expect(talLastText($this->tg))->toBe(TAL_REFUSED)->and($this->staff->fresh()?->telegram_user_id)->toBeNull();

    talTap(777006, 'lnk1:yes:'.$link->code);
    expect($this->staff->fresh()?->telegram_user_id)->toBe(777006);
});

it('answers in the sender\'s Telegram language', function (): void {
    $link = $this->service->issueLink($this->staff);

    talStart(777007, 'link_'.$link->code, ['language_code' => 'uz']);
    expect(talLastText($this->tg))->toStartWith('Bu Telegramni «Bright Center» markazi kabinetiga ulaysizmi?');

    talTap(777007, 'lnk1:yes:'.$link->code, from: ['language_code' => 'uz']);
    expect(talLastText($this->tg))->toStartWith('✅ Telegram Nodira K. akkauntiga ulandi.');
});

it('names the account without a center as "LexiFlow", and masks short and odd e-mails without revealing them', function (): void {
    OrganizationUser::query()->delete();
    $link = $this->service->issueLink($this->staff);

    talStart(777008, 'link_'.$link->code);
    expect(talLastText($this->tg))->toStartWith('Подключить этот Telegram к кабинету центра LexiFlow?');

    expect(AccountLinkCallbackHandler::maskEmail('a@b.co'))->toBe('a***@b***.co')
        ->and(AccountLinkCallbackHandler::maskEmail('nodira.k@mail.example.org'))->toBe('n***@m***.org')
        ->and(AccountLinkCallbackHandler::maskEmail('odd@localhost'))->toBe('o***@l***')
        ->and(AccountLinkCallbackHandler::maskEmail('broken'))->toBe('***');
});

it('verifies a hand-typed id when the same account confirms, and replaces a different hand-typed id', function (): void {
    $this->staff->forceFill(['telegram_user_id' => 777010])->save();
    $link = $this->service->issueLink($this->staff);

    talLink(777010, $link->code);
    expect($this->staff->fresh()?->hasVerifiedTelegram())->toBeTrue()
        ->and($this->staff->fresh()?->telegram_user_id)->toBe(777010);

    // A new link from another Telegram account: the person owns both; the connected one is the one that confirmed.
    $link = $this->service->issueLink($this->staff);
    talLink(777011, $link->code);
    expect($this->staff->fresh()?->telegram_user_id)->toBe(777011)
        ->and(User::query()->where('telegram_user_id', 777010)->exists())->toBeFalse();
});

it('after the link the bot treats the person as a teacher: the ordinary /start greets them and creates no learner', function (): void {
    $link = $this->service->issueLink($this->staff);
    talLink(777012, $link->code);

    talStart(777012, '');

    expect(Learner::query()->where('telegram_user_id', 777012)->exists())->toBeFalse()
        ->and(talLastText($this->tg))->toContain('Ваш аккаунт учителя подключён');
});

// --- attacks and edge cases ----------------------------------------------------------------------

it('refuses an unknown, malformed, expired and already used token with one and the same answer, writing nothing', function (): void {
    $link = $this->service->issueLink($this->staff);
    $expired = TelegramLinkToken::factory()->create(['user_id' => $this->staff->id, 'token_hash' => TelegramLinkTokens::hash(str_repeat('E', 32)), 'expires_at' => now()->subMinute()]);

    $cases = [
        'unknown' => str_repeat('A', 32),
        'malformed (too short)' => 'abc',
        'malformed (too long)' => str_repeat('a', 40),
        'expired' => str_repeat('E', 32),
    ];

    foreach ($cases as $name => $token) {
        $before = count($this->tg->sent);
        talStart(777020, 'link_'.$token);
        expect(talLastText($this->tg))->toBe(TAL_REFUSED, $name)
            ->and(count($this->tg->sent))->toBe($before + 1, $name);
    }

    // Used once: the second opening — by anybody — is refused the same way.
    talLink(777020, $link->code);
    talStart(777021, 'link_'.$link->code);

    expect($this->staff->fresh()?->telegram_user_id)->toBe(777020)
        ->and(talLastText($this->tg))->toBe(TAL_REFUSED)
        ->and(Learner::query()->count())->toBe(0)
        ->and($expired->fresh()?->used_at)->toBeNull();
});

it('a token issued to user A confirmed by Telegram account X writes X to A — and never to anybody else (the token carries its owner)', function (): void {
    $other = User::factory()->create(['telegram_user_id' => null]);
    $link = $this->service->issueLink($this->staff);

    talLink(777030, $link->code);

    expect($this->staff->fresh()?->telegram_user_id)->toBe(777030)
        ->and($other->fresh()?->telegram_user_id)->toBeNull();
});

it('refuses a Telegram account that is VERIFIED on another staff / admin user, and records the reason for the cabinet', function (): void {
    User::factory()->create(['telegram_user_id' => 777040, 'telegram_verified_at' => now()]);
    $link = $this->service->issueLink($this->staff);

    talLink(777040, $link->code);

    expect($this->staff->fresh()?->telegram_user_id)->toBeNull()
        ->and(talLastText($this->tg))->toContain('уже подтверждён у другого аккаунта')
        ->and(TelegramLinkToken::query()->sole()->used_at)->toBeNull()
        ->and(TelegramLinkToken::query()->sole()->last_result)->toBe('telegram_taken')
        ->and($this->service->latestToken($this->staff)?->last_result)->toBe('telegram_taken');

    // The token is still live: after the other account is disconnected, the same link works.
    $this->service->unlink(User::query()->where('telegram_user_id', 777040)->sole());
    talLink(777040, $link->code);
    expect($this->staff->fresh()?->telegram_user_id)->toBe(777040);
});

it('takes an UNVERIFIED (hand-typed) id from its holder when the real owner confirms: the holder is told why, both audited (W1)', function (): void {
    $holder = User::factory()->create(['telegram_user_id' => 777050, 'telegram_verified_at' => null, 'telegram_username' => 'typed']);
    $link = $this->service->issueLink($this->staff);

    talLink(777050, $link->code);

    expect($this->staff->fresh()?->telegram_user_id)->toBe(777050)
        ->and($this->staff->fresh()?->hasVerifiedTelegram())->toBeTrue()
        ->and(talLastText($this->tg))->toStartWith('✅ Telegram подключён');

    $holder = $holder->fresh();
    expect($holder?->telegram_user_id)->toBeNull()
        ->and($holder?->telegram_username)->toBeNull()
        ->and($holder?->telegram_superseded_at?->toIso8601String())->toBe('2026-11-10T12:00:00+00:00');

    expect(AuditLog::query()->where('action', TelegramAccountLinkService::AUDIT_SUPERSEDED)->sole())
        ->user_id->toBe($holder?->id)
        ->meta->toMatchArray(['previous_telegram_user_id' => 777050, 'claimed_by_user_id' => $this->staff->id]);
    expect(AuditLog::query()->where('action', TelegramAccountLinkService::AUDIT_LINKED)->sole()->meta)->toMatchArray(['superseded_user_id' => $holder?->id]);

    // The next successful link of the holder clears the mark.
    $link = $this->service->issueLink($holder ?? $this->staff);
    talLink(777051, $link->code);
    expect($holder?->fresh()?->telegram_superseded_at)->toBeNull();
});

it('refuses the link of a deactivated user at both steps', function (): void {
    $link = $this->service->issueLink($this->staff);
    talStart(777060, 'link_'.$link->code);
    $this->staff->forceFill(['is_active' => false])->save();

    expect($this->service->confirm($link->code, ['id' => 777060])->result)->toBe(AccountLinkResult::TokenInvalid)
        ->and($this->service->prompt($link->code, ['id' => 777060])->result)->toBe(AccountLinkResult::TokenInvalid)
        ->and($this->staff->fresh()?->telegram_user_id)->toBeNull();
});

it('consumes the token exactly once when two confirmations race (the second sees it spent)', function (): void {
    $link = $this->service->issueLink($this->staff);
    $this->service->prompt($link->code, ['id' => 777070]);
    $tokens = app(TelegramLinkTokens::class);
    $row = TelegramLinkToken::query()->sole();

    // The conditional UPDATE is the arbiter: the first wins, the second gets false.
    expect($tokens->consume($row, CarbonImmutable::now()))->toBeTrue()
        ->and($tokens->consume(TelegramLinkToken::query()->sole(), CarbonImmutable::now()))->toBeFalse()
        ->and($this->service->confirm($link->code, ['id' => 777070])->result)->toBe(AccountLinkResult::TokenInvalid);
});

it('a repeated confirmation by the already connected account is harmless and says so', function (): void {
    $link = $this->service->issueLink($this->staff);
    talLink(777080, $link->code);
    $link = $this->service->issueLink($this->staff);
    $this->service->prompt($link->code, ['id' => 777080]);

    $outcome = $this->service->confirm($link->code, ['id' => 777080, 'first_name' => 'Nodira']);

    expect($outcome->result)->toBe(AccountLinkResult::AlreadyLinked)
        ->and($this->staff->fresh()?->telegram_user_id)->toBe(777080);
});

// --- disconnecting -----------------------------------------------------------------------------------

it('disconnects from the cabinet: id, proof and names go, audited; disconnecting nothing is refused', function (): void {
    $link = $this->service->issueLink($this->staff);
    talLink(777090, $link->code);

    $this->service->unlink($this->staff->fresh() ?? $this->staff, '10.0.0.1');

    $user = $this->staff->fresh();
    expect($user?->telegram_user_id)->toBeNull()
        ->and($user?->telegram_verified_at)->toBeNull()
        ->and($user?->telegram_username)->toBeNull()
        ->and(AuditLog::query()->where('action', TelegramAccountLinkService::AUDIT_UNLINKED)->sole()->meta)->toMatchArray(['previous_telegram_user_id' => 777090, 'via' => 'cabinet']);

    expect(fn () => $this->service->unlink($user ?? $this->staff))->toThrow(TelegramLinkException::class, 'no Telegram account');
});

it('/unlink in the bot asks, then disconnects the tapper\'s Telegram from whichever user holds it — verified or hand-typed (W2)', function (): void {
    $link = $this->service->issueLink($this->staff);
    talLink(777100, $link->code);

    talStart(777100, '', text: '/unlink');
    expect(talLastText($this->tg))->toBe('Отключить этот Telegram от кабинета (аккаунт Nodira K.)? Бот перестанет узнавать вас как учителя и снова будет отвечать вам как ученику.')
        ->and($this->tg->lastCallbackData())->toBe(['lnk1:unlinkyes', 'lnk1:unlinkno'])
        ->and($this->staff->fresh()?->telegram_user_id)->toBe(777100);

    talTap(777100, 'lnk1:unlinkno');
    expect(talLastText($this->tg))->toBe('Хорошо, Telegram остаётся подключённым.')
        ->and($this->staff->fresh()?->telegram_user_id)->toBe(777100);

    talTap(777100, 'lnk1:unlinkyes');
    expect(talLastText($this->tg))->toBe('Telegram отключён от аккаунта Nodira K.. Бот снова отвечает вам как ученику — отправьте /start.')
        ->and($this->staff->fresh()?->telegram_user_id)->toBeNull()
        ->and(AuditLog::query()->where('action', TelegramAccountLinkService::AUDIT_UNLINKED)->sole()->meta)->toMatchArray(['previous_telegram_user_id' => 777100, 'via' => 'bot']);

    // The bot is a learner's again: /start now starts the onboarding instead of the teacher greeting.
    talStart(777100, '');
    expect(Learner::query()->where('telegram_user_id', 777100)->exists())->toBeTrue()
        ->and(talLastText($this->tg))->not->toContain('аккаунт учителя');

    // A hand-typed id is the owner's too; a Telegram nobody holds has nothing to disconnect.
    $typed = User::factory()->create(['telegram_user_id' => 777101, 'telegram_verified_at' => null]);
    talStart(777101, '', text: '/unlink');
    talTap(777101, 'lnk1:unlinkyes');
    expect($typed->fresh()?->telegram_user_id)->toBeNull();

    talStart(777102, '', text: '/unlink');
    expect(talLastText($this->tg))->toBe('Этот Telegram не подключён ни к одному кабинету. Отключать нечего.');
    talTap(777102, 'lnk1:unlinkyes');
    expect(talLastText($this->tg))->toBe('Этот Telegram не подключён ни к одному кабинету. Отключать нечего.');
});

it('a learner who confirms a staff link becomes a teacher for the bot: the learner side goes silent by design — and /unlink brings it back', function (): void {
    $learner = Learner::factory()->onboarded()->create(['telegram_user_id' => 777110]);
    $link = $this->service->issueLink($this->staff);

    talLink(777110, $link->code);

    expect($this->staff->fresh()?->telegram_user_id)->toBe(777110)
        ->and($learner->fresh())->not->toBeNull(); // the learner row is untouched; DmLearnerResolver now answers null for them

    talStart(777110, '', text: '/today');
    expect(talLastText($this->tg))->toBe('Эта команда — для учеников. Ваши команды учителя: /help');

    talStart(777110, '', text: '/unlink');
    talTap(777110, 'lnk1:unlinkyes');
    talStart(777110, '', text: '/today');
    expect(talLastText($this->tg))->not->toBe('Эта команда — для учеников. Ваши команды учителя: /help');
});
