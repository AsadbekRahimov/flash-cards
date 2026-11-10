<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use App\Domain\Telegram\DTO\IssuedLink;
use App\Domain\Telegram\Enums\LinkTokenKind;
use App\Domain\Telegram\Exceptions\TelegramLinkException;
use App\Models\Organization;
use App\Models\TelegramLinkToken;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Issues and looks up the one-time deep-link tokens of the cabinet (`telegram_link_tokens`, docs/13 §6.2):
 *
 *  - the token is 32 characters of `[A-Za-z0-9_-]` from 24 CSPRNG bytes (192 bits): it fits Telegram's `start` parameter
 *    (`link_` / `grp_` + 32 ≤ 64) and only its SHA-256 is stored — a database dump does not give usable links;
 *  - short-lived (`organizations.telegram_link.*_ttl_minutes`), bound to the user who asked for it (and, for a group link, to the
 *    organization) and consumed ONCE: {@see consume()} is a conditional UPDATE on `used_at IS NULL`, so two parallel redemptions
 *    cannot both win;
 *  - refused attempts leave `last_result` on the row for the cabinet while the bot's answer in a group stays neutral (L8).
 *
 * Issuing is rate limited per user (`organizations.telegram_link.issue_per_10_minutes`); a new token of the same kind replaces the
 * user's live ones of that kind (a lost link cannot be used after "Get a new link").
 */
final class TelegramLinkTokens
{
    public const TOKEN_LENGTH = 32;

    /** Tokens — the part after the prefix — are exactly this shape (base64url of 24 bytes). */
    public const TOKEN_PATTERN = '[A-Za-z0-9_-]{32}';

    private const RANDOM_BYTES = 24;

    /**
     * @throws TelegramLinkException rate_limited
     */
    public function issueAccountLink(User $user, ?CarbonImmutable $now = null): IssuedLink
    {
        $now ??= CarbonImmutable::now();
        $ttl = max(1, (int) config('organizations.telegram_link.account_ttl_minutes', 15));

        return $this->issue(LinkTokenKind::Account, $user, null, $now->addMinutes($ttl), $now);
    }

    /**
     * @throws TelegramLinkException rate_limited
     */
    public function issueGroupLink(User $user, Organization $organization, ?CarbonImmutable $now = null): IssuedLink
    {
        $now ??= CarbonImmutable::now();
        $ttl = max(1, (int) config('organizations.telegram_link.group_ttl_minutes', 30));

        return $this->issue(LinkTokenKind::Group, $user, $organization, $now->addMinutes($ttl), $now);
    }

    /**
     * The row of a token, whatever its state (the caller decides what "used" / "expired" mean for its answer); null for an
     * unknown token or a token of another kind. Malformed input never reaches the database.
     */
    public function find(LinkTokenKind $kind, string $token): ?TelegramLinkToken
    {
        if (! self::isWellFormed($token)) {
            return null;
        }

        /** @var TelegramLinkToken|null $row */
        $row = TelegramLinkToken::query()->where('kind', $kind->value)->where('token_hash', self::hash($token))->first();

        return $row;
    }

    /**
     * Marks the token used — atomically: only the first caller gets `true`, a parallel one sees the row already used. Call
     * it inside the transaction that performs the linked write, so a failed write rolls the consumption back.
     */
    public function consume(TelegramLinkToken $token, CarbonImmutable $now): bool
    {
        $updated = TelegramLinkToken::query()
            ->whereKey($token->id)
            ->whereNull('used_at')
            ->where('expires_at', '>', $now)
            ->update(['used_at' => $now, 'last_result' => null, 'last_attempt_at' => $now, 'updated_at' => $now]);

        if ($updated === 1) {
            $token->forceFill(['used_at' => $now, 'last_result' => null, 'last_attempt_at' => $now])->syncOriginal();

            return true;
        }

        return false;
    }

    /**
     * Remembers WHO the bot asked "Connect this Telegram?" (W2): `from.id` of the account that opened the link. The answer "Yes"
     * is accepted from that account alone; a later opening by another account re-binds the question to it.
     */
    public function markPrompted(TelegramLinkToken $token, int $telegramUserId, CarbonImmutable $now): void
    {
        TelegramLinkToken::query()->whereKey($token->id)->whereNull('used_at')->update([
            'prompted_telegram_user_id' => $telegramUserId,
            'last_result' => 'prompted',
            'last_attempt_at' => $now,
            'updated_at' => $now,
        ]);

        $token->forceFill(['prompted_telegram_user_id' => $telegramUserId, 'last_result' => 'prompted', 'last_attempt_at' => $now])->syncOriginal();
    }

    /** Remembers why the last attempt with this token was refused (shown to the staff member in the cabinet, never in the chat). */
    public function recordRefusal(TelegramLinkToken $token, string $result, CarbonImmutable $now): void
    {
        TelegramLinkToken::query()->whereKey($token->id)->whereNull('used_at')->update([
            'last_result' => mb_substr($result, 0, 32),
            'last_attempt_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** The newest token of the kind the user asked for (for the cabinet: what the last attempt said, whether a link is still live). */
    public function latest(LinkTokenKind $kind, User $user, ?Organization $organization = null): ?TelegramLinkToken
    {
        $query = TelegramLinkToken::query()->where('kind', $kind->value)->where('user_id', $user->id);

        if ($organization !== null) {
            $query->where('organization_id', $organization->id);
        }

        /** @var TelegramLinkToken|null $row */
        $row = $query->orderByDesc('id')->first();

        return $row;
    }

    public static function isWellFormed(string $token): bool
    {
        return preg_match('/^'.self::TOKEN_PATTERN.'$/', $token) === 1;
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::RANDOM_BYTES)), '+/', '-_'), '=');
    }

    /** `https://t.me/<bot>?start=link_<token>` / `?startgroup=grp_<token>`; an empty bot username gives a link without a host part — the cabinet shows the code then. */
    public static function deepLink(LinkTokenKind $kind, string $token): string
    {
        $bot = ltrim(trim((string) config('telegram.bot_username', '')), '@');
        $parameter = $kind === LinkTokenKind::Group ? 'startgroup' : 'start';

        return "https://t.me/{$bot}?{$parameter}=".$kind->prefix().$token;
    }

    // ---------------------------------------------------------------------------------------------

    /**
     * @throws TelegramLinkException rate_limited
     */
    private function issue(LinkTokenKind $kind, User $user, ?Organization $organization, CarbonImmutable $expiresAt, CarbonImmutable $now): IssuedLink
    {
        $key = 'tg-link-issue:'.$user->id;
        $max = max(1, (int) config('organizations.telegram_link.issue_per_10_minutes', 10));

        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw TelegramLinkException::rateLimited(RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, 600);

        $token = self::generateToken();

        // A new link of the kind replaces the live ones: a link that leaked before "Get a new link" is dead from now on.
        TelegramLinkToken::query()
            ->where('kind', $kind->value)
            ->where('user_id', $user->id)
            ->when($organization !== null, fn ($q) => $q->where('organization_id', $organization?->id))
            ->whereNull('used_at')
            ->delete();

        $row = TelegramLinkToken::query()->create([
            'kind' => $kind->value,
            'token_hash' => self::hash($token),
            'user_id' => $user->id,
            'organization_id' => $organization?->id,
            'expires_at' => $expiresAt,
        ]);

        return new IssuedLink(
            kind: $kind,
            code: $token,
            url: self::deepLink($kind, $token),
            expiresAt: $expiresAt,
            tokenId: $row->id,
        );
    }
}
