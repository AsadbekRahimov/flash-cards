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
 * Connecting a staff / admin user's Telegram account through the bot (docs/07 §5, audit 2026-11-10 H1).
 *
 * Before, `/org/profile` took `users.telegram_user_id` as a number typed by hand: a center owner could claim any Telegram id —
 * the bot then treated that person as a teacher (a learner stopped getting answers) and the owner could pass the
 * `getChatMember` check of somebody else's group. Now the cabinet only issues `t.me/<bot>?start=link_<token>`
 * ({@see issueLink()}); the bot writes `message.from.id` of whoever opens the link ({@see linkFromStart()}) and sets
 * `telegram_verified_at` — the one proof that the account belongs to the person. The token is bound to the user who asked for it,
 * lives `organizations.telegram_link.account_ttl_minutes`, is consumed once, and the write happens under the user's row lock.
 *
 * Hand-typed ids of existing rows are not wiped (working teachers keep working); they stay unverified, and whatever relies on the
 * id as proof of ownership ({@see OrgGroupLinkService}) requires {@see User::hasVerifiedTelegram()}.
 */
final class TelegramAccountLinkService
{
    public const AUDIT_LINKED = 'user.telegram_linked';

    public const AUDIT_UNLINKED = 'user.telegram_unlinked';

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
     * `/start link_<token>` from a private chat: connects `from.id` to the token's user.
     *
     * Refusals: a malformed / unknown / expired / used token and a token whose user is gone or inactive are one answer
     * (`token_invalid`); a Telegram account already connected to ANOTHER user is `telegram_taken` (the sender owns that account,
     * so telling them is no leak). A repeated tap by the same, already connected account is `already_linked` and consumes the token.
     * Nothing is written for a refusal except `last_result` on the token row (for the cabinet).
     *
     * @param  array<string, mixed>  $from  Telegram `User` of the sender (`message.from`)
     */
    public function linkFromStart(string $token, array $from, ?CarbonImmutable $now = null): AccountLinkOutcome
    {
        $now ??= CarbonImmutable::now();
        $telegramUserId = (int) ($from['id'] ?? 0);

        if ($telegramUserId <= 0) {
            return new AccountLinkOutcome(AccountLinkResult::TokenInvalid);
        }

        $row = $this->tokens->find(LinkTokenKind::Account, $token);
        if ($row === null || ! $row->isLiveAt($now)) {
            return new AccountLinkOutcome(AccountLinkResult::TokenInvalid);
        }

        try {
            return DB::transaction(function () use ($row, $telegramUserId, $from, $now): AccountLinkOutcome {
                /** @var TelegramLinkToken|null $locked */
                $locked = TelegramLinkToken::query()->whereKey($row->id)->lockForUpdate()->first();
                if ($locked === null || ! $locked->isLiveAt($now)) {
                    return new AccountLinkOutcome(AccountLinkResult::TokenInvalid);
                }

                /** @var User|null $user */
                $user = User::query()->whereKey($locked->user_id)->lockForUpdate()->first();
                if ($user === null || $user->is_active !== true) {
                    $this->tokens->recordRefusal($locked, AccountLinkResult::TokenInvalid->value, $now);

                    return new AccountLinkOutcome(AccountLinkResult::TokenInvalid);
                }

                $taken = User::query()->where('telegram_user_id', $telegramUserId)->whereKeyNot($user->id)->exists();
                if ($taken) {
                    $this->tokens->recordRefusal($locked, AccountLinkResult::TelegramTaken->value, $now);

                    return new AccountLinkOutcome(AccountLinkResult::TelegramTaken);
                }

                if (! $this->tokens->consume($locked, $now)) {
                    return new AccountLinkOutcome(AccountLinkResult::TokenInvalid);
                }

                $previous = $user->telegram_user_id;
                $wasVerified = $user->hasVerifiedTelegram();

                $user->forceFill([
                    'telegram_user_id' => $telegramUserId,
                    'telegram_verified_at' => $now,
                    'telegram_username' => self::text($from['username'] ?? null, 64),
                    'telegram_name' => self::text(trim(((string) ($from['first_name'] ?? '')).' '.((string) ($from['last_name'] ?? ''))), 129),
                ])->save();

                AuditLog::create([
                    'user_id' => $user->id,
                    'action' => self::AUDIT_LINKED,
                    'entity_type' => User::class,
                    'entity_id' => $user->id,
                    'ip' => null,
                    'meta' => ['telegram_user_id' => $telegramUserId, 'previous_telegram_user_id' => $previous, 'previous_verified' => $wasVerified],
                ]);

                $repeat = $previous === $telegramUserId && $wasVerified;

                return new AccountLinkOutcome($repeat ? AccountLinkResult::AlreadyLinked : AccountLinkResult::Linked, $user);
            });
        } catch (UniqueConstraintViolationException $e) {
            // Two users redeemed links from the same Telegram account at the same instant: the second loses on the unique index.
            Log::info('telegram.account_link_race', ['token_id' => $row->id, 'error' => $e->getMessage()]);
            $this->tokens->recordRefusal($row, AccountLinkResult::TelegramTaken->value, $now);

            return new AccountLinkOutcome(AccountLinkResult::TelegramTaken);
        }
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

            $previous = $locked->telegram_user_id;

            $locked->forceFill([
                'telegram_user_id' => null,
                'telegram_verified_at' => null,
                'telegram_username' => null,
                'telegram_name' => null,
            ])->save();

            AuditLog::create([
                'user_id' => $locked->id,
                'action' => self::AUDIT_UNLINKED,
                'entity_type' => User::class,
                'entity_id' => $locked->id,
                'ip' => $ip,
                'meta' => ['previous_telegram_user_id' => $previous],
            ]);

            $user->forceFill($locked->only(['telegram_user_id', 'telegram_verified_at', 'telegram_username', 'telegram_name']))->syncOriginal();
        });
    }

    /** The newest account-link token of the user: whether a link is still live, what the last attempt said. */
    public function latestToken(User $user): ?TelegramLinkToken
    {
        return $this->tokens->latest(LinkTokenKind::Account, $user);
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
