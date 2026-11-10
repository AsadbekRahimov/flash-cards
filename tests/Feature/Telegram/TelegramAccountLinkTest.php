<?php

declare(strict_types=1);

use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Enums\AccountLinkResult;
use App\Domain\Telegram\Enums\LinkTokenKind;
use App\Domain\Telegram\Exceptions\TelegramLinkException;
use App\Domain\Telegram\Services\TelegramAccountLinkService;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Domain\Telegram\Services\TelegramLinkTokens;
use App\Models\AuditLog;
use App\Models\Learner;
use App\Models\TelegramLinkToken;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\FakeTelegramClient;

/*
 * Connecting a staff user's Telegram through the bot (docs/07 §5, audit 2026-11-10 H1): the cabinet issues
 * `t.me/<bot>?start=link_<token>`, the bot writes `message.from.id` of whoever opens it and sets `telegram_verified_at`.
 * A hand-typed id proves nothing; nothing but the bot writes the proof.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['telegram.bot_username' => 'LexiFlowBot']);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-10 12:00:00', 'UTC'));

    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);
    $this->service = app(TelegramAccountLinkService::class);
    $this->staff = User::factory()->create(['telegram_user_id' => null, 'is_active' => true]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/** `/start <payload>` in the private chat of Telegram user $tgId. */
function talStart(int $tgId, string $payload, array $from = []): void
{
    app(TelegramDispatcher::class)->dispatch(['message' => [
        'message_id' => 1,
        'chat' => ['id' => $tgId, 'type' => 'private'],
        'from' => ['id' => $tgId, 'first_name' => 'Nodira', 'last_name' => 'K.', 'username' => 'nodira_k', 'language_code' => 'ru', ...$from],
        'text' => '/start '.$payload,
    ]]);
}

function talLastText(FakeTelegramClient $tg): string
{
    return (string) end($tg->sent)['text'];
}

// --- issuing ---------------------------------------------------------------------------------------

it('issues a one-time link bound to the user: CSPRNG token of 32 url-safe chars, only its hash stored, 15 minutes', function (): void {
    $link = $this->service->issueLink($this->staff);

    expect($link->kind)->toBe(LinkTokenKind::Account)
        ->and($link->code)->toMatch('/^[A-Za-z0-9_-]{32}$/')
        ->and($link->url)->toBe('https://t.me/LexiFlowBot?start=link_'.$link->code)
        ->and(strlen('link_'.$link->code))->toBeLessThanOrEqual(64)
        ->and($link->expiresAt->toIso8601String())->toBe('2026-11-10T12:15:00+00:00');

    $row = TelegramLinkToken::query()->sole();
    expect($row->kind)->toBe('account')
        ->and($row->user_id)->toBe($this->staff->id)
        ->and($row->organization_id)->toBeNull()
        ->and($row->token_hash)->toBe(hash('sha256', $link->code))
        ->and($row->token_hash)->not->toContain($link->code)
        ->and($row->used_at)->toBeNull();
});

it('replaces the live link of the user with a new one and limits how many may be asked for', function (): void {
    $first = $this->service->issueLink($this->staff);
    $second = $this->service->issueLink($this->staff);

    expect(TelegramLinkToken::query()->count())->toBe(1)
        ->and($this->service->linkFromStart($first->code, ['id' => 501])->result)->toBe(AccountLinkResult::TokenInvalid)
        ->and($this->service->linkFromStart($second->code, ['id' => 501])->result)->toBe(AccountLinkResult::Linked);

    config(['organizations.telegram_link.issue_per_10_minutes' => 3]);
    RateLimiter::clear('tg-link-issue:'.$this->staff->id);
    $this->service->issueLink($this->staff);
    $this->service->issueLink($this->staff);
    $this->service->issueLink($this->staff);

    expect(fn () => $this->service->issueLink($this->staff))->toThrow(TelegramLinkException::class, 'Too many');
});

// --- the happy path through the bot -----------------------------------------------------------------

it('links the sender of /start link_<token> to the staff account: id, proof, username and name, audit, no learner row', function (): void {
    $link = $this->service->issueLink($this->staff);

    talStart(777001, 'link_'.$link->code);

    $user = $this->staff->fresh();
    expect($user?->telegram_user_id)->toBe(777001)
        ->and($user?->telegram_verified_at?->toIso8601String())->toBe('2026-11-10T12:00:00+00:00')
        ->and($user?->telegram_username)->toBe('nodira_k')
        ->and($user?->telegram_name)->toBe('Nodira K.')
        ->and($user?->hasVerifiedTelegram())->toBeTrue()
        ->and(Learner::query()->where('telegram_user_id', 777001)->exists())->toBeFalse()
        ->and(TelegramLinkToken::query()->sole()->used_at)->not->toBeNull()
        ->and(talLastText($this->tg))->toStartWith('✅ Telegram подключён к аккаунту '.$user?->name)
        ->and(end($this->tg->sent)['chat_id'])->toBe(777001);

    $audit = AuditLog::query()->where('action', TelegramAccountLinkService::AUDIT_LINKED)->sole();
    expect($audit->user_id)->toBe($this->staff->id)
        ->and($audit->meta)->toMatchArray(['telegram_user_id' => 777001, 'previous_telegram_user_id' => null, 'previous_verified' => false]);
});

it('answers in the sender\'s Telegram language', function (): void {
    $link = $this->service->issueLink($this->staff);

    talStart(777002, 'link_'.$link->code, ['language_code' => 'uz']);

    expect(talLastText($this->tg))->toStartWith('✅ Telegram '.$this->staff->name.' akkauntiga ulandi.');
});

it('verifies a hand-typed id when the same account opens the link, and replaces a different hand-typed id', function (): void {
    $this->staff->forceFill(['telegram_user_id' => 777003])->save();
    $link = $this->service->issueLink($this->staff);

    talStart(777003, 'link_'.$link->code);
    expect($this->staff->fresh()?->hasVerifiedTelegram())->toBeTrue()
        ->and($this->staff->fresh()?->telegram_user_id)->toBe(777003);

    // A new link from another Telegram account: the person owns both; the connected one is the one that opened the link.
    $link = $this->service->issueLink($this->staff);
    talStart(777004, 'link_'.$link->code);
    expect($this->staff->fresh()?->telegram_user_id)->toBe(777004)
        ->and(User::query()->where('telegram_user_id', 777003)->exists())->toBeFalse();
});

it('after the link the bot treats the person as a teacher: the ordinary /start greets them and creates no learner', function (): void {
    $link = $this->service->issueLink($this->staff);
    talStart(777005, 'link_'.$link->code);

    talStart(777005, '');

    expect(Learner::query()->where('telegram_user_id', 777005)->exists())->toBeFalse()
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
        talStart(777010, 'link_'.$token);
        expect(talLastText($this->tg))->toBe('Ссылка для подключения недействительна или устарела. Откройте кабинет центра → Профиль → «Подключить Telegram» и получите новую.', $name)
            ->and(count($this->tg->sent))->toBe($before + 1, $name);
    }

    // Used once: the second opening — by anybody — is refused the same way.
    talStart(777010, 'link_'.$link->code);
    talStart(777011, 'link_'.$link->code);

    expect($this->staff->fresh()?->telegram_user_id)->toBe(777010)
        ->and(talLastText($this->tg))->toBe('Ссылка для подключения недействительна или устарела. Откройте кабинет центра → Профиль → «Подключить Telegram» и получите новую.')
        ->and(Learner::query()->count())->toBe(0)
        ->and($expired->fresh()?->used_at)->toBeNull();
});

it('a token issued to user A opened by Telegram account X writes X to A — and never to anybody else (the token carries its owner)', function (): void {
    $other = User::factory()->create(['telegram_user_id' => null]);
    $link = $this->service->issueLink($this->staff);

    talStart(777020, 'link_'.$link->code);

    expect($this->staff->fresh()?->telegram_user_id)->toBe(777020)
        ->and($other->fresh()?->telegram_user_id)->toBeNull();
});

it('refuses a Telegram account that is already connected to another staff / admin user, and records the reason for the cabinet', function (): void {
    User::factory()->create(['telegram_user_id' => 777030]);
    $link = $this->service->issueLink($this->staff);

    talStart(777030, 'link_'.$link->code);

    expect($this->staff->fresh()?->telegram_user_id)->toBeNull()
        ->and(talLastText($this->tg))->toContain('уже подключён к другому аккаунту')
        ->and(TelegramLinkToken::query()->sole()->used_at)->toBeNull()
        ->and(TelegramLinkToken::query()->sole()->last_result)->toBe('telegram_taken')
        ->and($this->service->latestToken($this->staff)?->last_result)->toBe('telegram_taken');

    // The token is still live: after the other account is disconnected, the same link works.
    $this->service->unlink(User::query()->where('telegram_user_id', 777030)->sole());
    talStart(777030, 'link_'.$link->code);
    expect($this->staff->fresh()?->telegram_user_id)->toBe(777030);
});

it('refuses the link of a deactivated user', function (): void {
    $link = $this->service->issueLink($this->staff);
    $this->staff->forceFill(['is_active' => false])->save();

    expect($this->service->linkFromStart($link->code, ['id' => 777040])->result)->toBe(AccountLinkResult::TokenInvalid)
        ->and($this->staff->fresh()?->telegram_user_id)->toBeNull();
});

it('consumes the token exactly once when two redemptions race (the second sees it spent)', function (): void {
    $link = $this->service->issueLink($this->staff);
    $tokens = app(TelegramLinkTokens::class);
    $row = TelegramLinkToken::query()->sole();

    // The conditional UPDATE is the arbiter: the first wins, the second gets false.
    expect($tokens->consume($row, CarbonImmutable::now()))->toBeTrue()
        ->and($tokens->consume(TelegramLinkToken::query()->sole(), CarbonImmutable::now()))->toBeFalse()
        ->and($this->service->linkFromStart($link->code, ['id' => 777050])->result)->toBe(AccountLinkResult::TokenInvalid);
});

it('a repeated tap by the already connected account is harmless and says so', function (): void {
    $link = $this->service->issueLink($this->staff);
    talStart(777060, 'link_'.$link->code);
    $link = $this->service->issueLink($this->staff);

    $outcome = $this->service->linkFromStart($link->code, ['id' => 777060, 'first_name' => 'Nodira']);

    expect($outcome->result)->toBe(AccountLinkResult::AlreadyLinked)
        ->and($this->staff->fresh()?->telegram_user_id)->toBe(777060);
});

// --- disconnecting -----------------------------------------------------------------------------------

it('disconnects: id, proof and names go, audited; disconnecting nothing is refused', function (): void {
    $link = $this->service->issueLink($this->staff);
    talStart(777070, 'link_'.$link->code);

    $this->service->unlink($this->staff->fresh() ?? $this->staff, '10.0.0.1');

    $user = $this->staff->fresh();
    expect($user?->telegram_user_id)->toBeNull()
        ->and($user?->telegram_verified_at)->toBeNull()
        ->and($user?->telegram_username)->toBeNull()
        ->and(AuditLog::query()->where('action', TelegramAccountLinkService::AUDIT_UNLINKED)->sole()->meta)->toMatchArray(['previous_telegram_user_id' => 777070]);

    expect(fn () => $this->service->unlink($user ?? $this->staff))->toThrow(TelegramLinkException::class, 'no Telegram account');
});

it('a learner who opens a staff link becomes a teacher for the bot: the learner side goes silent by design', function (): void {
    $learner = Learner::factory()->onboarded()->create(['telegram_user_id' => 777080]);
    $link = $this->service->issueLink($this->staff);

    talStart(777080, 'link_'.$link->code);

    expect($this->staff->fresh()?->telegram_user_id)->toBe(777080)
        ->and($learner->fresh())->not->toBeNull(); // the learner row is untouched; DmLearnerResolver now answers null for them
});
