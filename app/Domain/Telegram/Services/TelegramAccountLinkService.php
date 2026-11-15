<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use App\Domain\Organizations\Services\OrgGroupLinkService;
use App\Domain\Telegram\DTO\AccountLinkOutcome;
use App\Domain\Telegram\DTO\IssuedLink;
use App\Domain\Telegram\Enums\AccountLinkResult;
use App\Domain\Telegram\Enums\LinkTokenKind;
use App\Domain\Telegram\Exceptions\TelegramLinkException;
use App\Models\AuditLog;
use App\Models\TelegramLinkToken;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Connecting a staff / admin user's Telegram account through the bot (docs/07 §5, audit 2026-11-10 H1 and the review W1 / W2).
 *
 * Before, `/org/profile` took `users.telegram_user_id` as a number typed by hand: a center owner could claim any Telegram id —
 * the bot then treated that person as a teacher (a learner stopped getting answers) and the owner could pass the
 * `getChatMember` check of somebody else's group. Now the cabinet only issues `t.me/<bot>?start=link_<token>`
 * ({@see issueLink()}); opening it makes the bot ASK "Connect this Telegram to the cabinet of X?" ({@see prompt()}), and only
 * "Yes" from the very account that was asked writes `message.from.id` and `telegram_verified_at` ({@see confirm()}) — the one
 * proof that the account belongs to the person. The token is bound to the user who asked for it, lives
 * `organizations.telegram_link.account_ttl_minutes`, is consumed on "Yes" alone, and the write happens under the user's row lock.
 *
 * Hand-typed ids of existing rows are not wiped (working teachers keep working); they stay unverified, and whatever relies on the
 * id as proof of ownership ({@see OrgGroupLinkService}) requires {@see User::hasVerifiedTelegram()}. An unverified holder does
 * not block the real owner: a confirmed link takes the id from them (`telegram_superseded_at` explains it in their profile,
 * {@see self::AUDIT_SUPERSEDED}); a VERIFIED holder is refused (`telegram_taken`). The person may disconnect from the bot
 * themselves (`/unlink`, {@see unlinkByTelegram()}), after which the bot treats them as a learner again.
 */
final class TelegramAccountLinkService
{
    public const AUDIT_LINKED = 'user.telegram_linked';

    public const AUDIT_UNLINKED = 'user.telegram_unlinked';

    /** An unverified `telegram_user_id` was taken from this user because another user confirmed the account through the bot. */
    public const AUDIT_SUPERSEDED = 'user.telegram_superseded';

    public function __construct(private readonly TelegramLinkTokens $tokens) {}

    /**
     * A one-time link for the signed-in user. Only the user themselves may ask for their own link (the cabinet enforces it:
     * the profile page acts on `auth()->user()`).
     *
     * @throws TelegramLinkException rate_limited
     */
    public function issueLink(User $user, ?CarbonImmutable $now = null): IssuedLink
    {
        return $this->tokens->issueAccountLink($user, $now);
    }

    /**
     * `/start link_<token>` from a private chat: the first step. Nothing is connected yet — the token is checked, the sender's
     * `from.id` is remembered on it, and the bot asks the question. A malformed / unknown / expired / used token and a token
     * whose user is gone or inactive are one answer (`token_invalid`).
     *
     * @param  array<string, mixed>  $from  Telegram `User` of the sender (`message.from`)
     */
    public function prompt(string $token, array $from, ?CarbonImmutable $now = null): AccountLinkOutcome
    {
        $now ??= CarbonImmutable::now();
        $telegramUserId = (int) ($from['id'] ?? 0);

        $row = $telegramUserId > 0 ? $this->tokens->find(LinkTokenKind::Account, $token) : null;
        if ($row === null || ! $row->isLiveAt($now)) {
            return new AccountLinkOutcome(AccountLinkResult::TokenInvalid);
        }

        /** @var User|null $user */
        $user = User::query()->whereKey($row->user_id)->first();
        if ($user === null || $user->is_active !== true) {
            $this->tokens->recordRefusal($row, AccountLinkResult::TokenInvalid->value, $now);

            return new AccountLinkOutcome(AccountLinkResult::TokenInvalid);
        }

        $this->tokens->markPrompted($row, $telegramUserId, $now);

        /** @var list<string> $organizations */
        $organizations = $user->organizations()->orderBy('organizations.name')->limit(3)->pluck('organizations.name')->map(fn (mixed $n): string => (string) $n)->values()->all();

        return new AccountLinkOutcome(AccountLinkResult::Prompted, $user, $organizations);
    }

    /**
     * "Yes, connect" tapped: connects `from.id` to the token's user — only when the token is live AND the question was asked to
     * this very `from.id` ({@see prompt()}); anything else is `token_invalid`.
     *
     * The Telegram account may be held by another user already: a VERIFIED holder is `telegram_taken` (the sender owns that
     * account, so telling them is no leak); an UNVERIFIED holder (a hand-typed id, W1) loses the id — this confirmation is the
     * proof, the typed number was not — with an audit row and `telegram_superseded_at` for their profile. A repeated tap by the
     * same, already connected account is `already_linked` and consumes the token. Nothing is written for a refusal except
     * `last_result` on the token row (for the cabinet).
     *
     * @param  array<string, mixed>  $from  Telegram `User` of the tapper (`callback_query.from`)
     */
    public function confirm(string $token, array $from, ?CarbonImmutable $now = null): AccountLinkOutcome
    {
        $now ??= CarbonImmutable::now();
        $telegramUserId = (int) ($from['id'] ?? 0);

        if ($telegramUserId <= 0) {
            return new AccountLinkOutcome(AccountLinkResult::TokenInvalid);
        }

        $row = $this->tokens->find(LinkTokenKind::Account, $token);
        if ($row === null || ! $row->isLiveAt($now) || $row->prompted_telegram_user_id !== $telegramUserId) {
            return new AccountLinkOutcome(AccountLinkResult::TokenInvalid);
        }

        try {
            return DB::transaction(function () use ($row, $telegramUserId, $from, $now): AccountLinkOutcome {
                /** @var TelegramLinkToken|null $locked */
                $locked = TelegramLinkToken::query()->whereKey($row->id)->lockForUpdate()->first();
                if ($locked === null || ! $locked->isLiveAt($now) || $locked->prompted_telegram_user_id !== $telegramUserId) {
                    return new AccountLinkOutcome(AccountLinkResult::TokenInvalid);
                }

                // The token's user and the current holder of the Telegram id (if another user), locked in id order: two
                // confirmations that cross each other's accounts cannot deadlock.
                $holderId = User::query()->where('telegram_user_id', $telegramUserId)->whereKeyNot($locked->user_id)->value('id');
                $ids = $holderId === null ? [$locked->user_id] : [$locked->user_id, (int) $holderId];
                sort($ids);
                $users = User::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

                /** @var User|null $user */
                $user = $users->get($locked->user_id);
                if ($user === null || $user->is_active !== true) {
                    $this->tokens->recordRefusal($locked, AccountLinkResult::TokenInvalid->value, $now);

                    return new AccountLinkOutcome(AccountLinkResult::TokenInvalid);
                }

                /** @var User|null $holder */
                $holder = $holderId === null ? null : $users->get((int) $holderId);
                // Re-read under the lock: the holder may have changed since the unlocked lookup.
                if ($holder !== null && $holder->telegram_user_id !== $telegramUserId) {
                    $holder = null;
                }

                if ($holder !== null && $holder->hasVerifiedTelegram()) {
                    $this->tokens->recordRefusal($locked, AccountLinkResult::TelegramTaken->value, $now);

                    return new AccountLinkOutcome(AccountLinkResult::TelegramTaken);
                }

                if (! $this->tokens->consume($locked, $now)) {
                    return new AccountLinkOutcome(AccountLinkResult::TokenInvalid);
                }

                if ($holder !== null) {
                    $this->supersede($holder, $user, $now);
                }

                $previous = $user->telegram_user_id;
                $wasVerified = $user->hasVerifiedTelegram();

                $user->forceFill([
                    'telegram_user_id' => $telegramUserId,
                    'telegram_verified_at' => $now,
                    'telegram_username' => self::text($from['username'] ?? null, 64),
                    'telegram_name' => self::text(trim(((string) ($from['first_name'] ?? '')).' '.((string) ($from['last_name'] ?? ''))), 129),
                    'telegram_superseded_at' => null,
                ])->save();

                AuditLog::create([
                    'user_id' => $user->id,
                    'action' => self::AUDIT_LINKED,
                    'entity_type' => User::class,
                    'entity_id' => $user->id,
                    'ip' => null,
                    'meta' => [
                        'telegram_user_id' => $telegramUserId,
                        'previous_telegram_user_id' => $previous,
                        'previous_verified' => $wasVerified,
                        'superseded_user_id' => $holder?->id,
                    ],
                ]);

                $repeat = $previous === $telegramUserId && $wasVerified;

                return new AccountLinkOutcome($repeat ? AccountLinkResult::AlreadyLinked : AccountLinkResult::Linked, $user);
            });
        } catch (UniqueConstraintViolationException $e) {
            // Two users confirmed the same Telegram account at the same instant: the second loses on the unique index.
            Log::info('telegram.account_link_race', ['token_id' => $row->id, 'error' => $e->getMessage()]);
            $this->tokens->recordRefusal($row, AccountLinkResult::TelegramTaken->value, $now);

            return new AccountLinkOutcome(AccountLinkResult::TelegramTaken);
        }
    }

    /**
     * "No" tapped: nothing is connected; the token stays live (the person may open the link again), the cabinet sees "declined".
     *
     * @param  array<string, mixed>  $from
     */
    public function decline(string $token, array $from, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();
        $telegramUserId = (int) ($from['id'] ?? 0);

        $row = $telegramUserId > 0 ? $this->tokens->find(LinkTokenKind::Account, $token) : null;
        if ($row === null || ! $row->isLiveAt($now) || $row->prompted_telegram_user_id !== $telegramUserId) {
            return;
        }

        $this->tokens->recordRefusal($row, 'declined', $now);
    }

    /**
     * "Disconnect Telegram" in the profile: the id, the proof and the names go; the bot stops treating the person as a teacher
     * until they connect again. Audited.
     *
     * @throws TelegramLinkException not_linked
     */
    public function unlink(User $user, ?string $ip = null): void
    {
        DB::transaction(function () use ($user, $ip): void {
            /** @var User $locked */
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($locked->telegram_user_id === null) {
                throw TelegramLinkException::notLinked($locked->id);
            }

            $this->detach($locked, $ip, ['via' => 'cabinet']);

            $user->forceFill($locked->only(['telegram_user_id', 'telegram_verified_at', 'telegram_username', 'telegram_name', 'telegram_superseded_at']))->syncOriginal();
        });
    }

    /**
     * `/unlink` in the bot (W2): the owner of the Telegram account disconnects it from whichever staff user holds it — verified
     * or hand-typed; it is their account either way. Null when no user holds this id. Audited (`via: bot`).
     */
    public function unlinkByTelegram(int $telegramUserId): ?User
    {
        if ($telegramUserId <= 0) {
            return null;
        }

        return DB::transaction(function () use ($telegramUserId): ?User {
            /** @var User|null $locked */
            $locked = User::query()->where('telegram_user_id', $telegramUserId)->lockForUpdate()->first();

            if ($locked === null) {
                return null;
            }

            $this->detach($locked, null, ['via' => 'bot']);

            return $locked;
        });
    }

    /** The staff user holding this Telegram id, if any (for the bot's `/unlink` question). */
    public function holderOf(int $telegramUserId): ?User
    {
        /** @var User|null $user */
        $user = $telegramUserId > 0 ? User::query()->where('telegram_user_id', $telegramUserId)->first() : null;

        return $user;
    }

    /** The newest account-link token of the user: whether a link is still live, what the last attempt said. */
    public function latestToken(User $user): ?TelegramLinkToken
    {
        return $this->tokens->latest(LinkTokenKind::Account, $user);
    }

    /** @param array<string, mixed> $meta */
    private function detach(User $locked, ?string $ip, array $meta): void
    {
        $previous = $locked->telegram_user_id;

        $locked->forceFill([
            'telegram_user_id' => null,
            'telegram_verified_at' => null,
            'telegram_username' => null,
            'telegram_name' => null,
            'telegram_superseded_at' => null,
        ])->save();

        AuditLog::create([
            'user_id' => $locked->id,
            'action' => self::AUDIT_UNLINKED,
            'entity_type' => User::class,
            'entity_id' => $locked->id,
            'ip' => $ip,
            'meta' => ['previous_telegram_user_id' => $previous, ...$meta],
        ]);
    }

    /** The unverified holder of the id loses it to the user who just proved the account (both rows are locked by the caller). */
    private function supersede(User $holder, User $by, CarbonImmutable $now): void
    {
        $previous = $holder->telegram_user_id;

        $holder->forceFill([
            'telegram_user_id' => null,
            'telegram_verified_at' => null,
            'telegram_username' => null,
            'telegram_name' => null,
            'telegram_superseded_at' => $now,
        ])->save();

        AuditLog::create([
            'user_id' => $holder->id,
            'action' => self::AUDIT_SUPERSEDED,
            'entity_type' => User::class,
            'entity_id' => $holder->id,
            'ip' => null,
            'meta' => ['previous_telegram_user_id' => $previous, 'claimed_by_user_id' => $by->id],
        ]);
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
