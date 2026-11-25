<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Engagement\DTO\PreparedShare;
use App\Domain\Engagement\Enums\ShareKind;
use App\Domain\Engagement\Exceptions\ShareException;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\DTO\PreparedInlineMessage;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TelegramRateLimitedException;
use App\Domain\Telegram\Services\TelegramMessageRateLimiter;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The message a learner shares from the Mini App (docs/36 §1в п. 2): {@see ShareMessageBuilder} builds it, `savePreparedInlineMessage`
 * stores it for the learner, the Mini App passes the id to `WebApp.shareMessage`. The call is made in a user's HTTP request, so it never
 * waits for Telegram longer than one call and never queues.
 *
 * - **Who:** `user_id` of the Bot API is the `telegram_user_id` of the authenticated learner - the only learner this class is given. A
 *   blocked learner, sharing switched off, a bot without a username → `share_unavailable`.
 * - **Cache** (docs/19 §3): `share:prep:v1:{learner}:{kind}:{subject|-}:{locale}:{hash of the result}` → `{id, expires_at, fresh_until}`
 *   (an array),
 *   TTL = Telegram's `expiration_date` − `cache_margin_seconds`, at most `cache_max_seconds`. A changed text, card or link changes the
 *   hash, so nothing needs invalidating. A failure is not cached.
 * - **Two taps at once:** a lock per key; the second request waits for the first (≤ `lock_wait_seconds`), then takes its answer from
 *   the cache - one call to Telegram. A lock still held after the wait → 503 (the client falls back).
 * - **429:** the client pauses the whole outbound budget of the bot after a 429 of this method too ({@see TelegramMessageRateLimiter::pause()},
 *   ≤ 60 s). Accepted: a 429 here means the bot as a whole is over Telegram's limits, and this request is a "nice to have" with a fallback.
 *   While a pause lasts no call is made: 503 `share_failed {retry_after}` at once, so learners' taps cannot prolong the flood.
 * - **Analytics:** `share.prepared {kind, subject, via: mini_app, cached, photo}` on every id handed out.
 */
final class SharePreparer
{
    public const CACHE_VERSION = 'v1';

    public function __construct(
        private readonly ShareMessageBuilder $builder,
        private readonly TelegramClient $telegram,
        private readonly TelegramMessageRateLimiter $outbound,
        private readonly LocaleResolver $locales,
        private readonly ProductEventRecorder $events,
    ) {}

    /**
     * @param  string|null  $subject  ignored for the kinds without one (the invitations)
     *
     * @throws ShareException share_subject_not_found | share_unavailable | share_failed
     */
    public function prepare(Learner $learner, ShareKind $kind, ?string $subject): PreparedShare
    {
        $this->assertCanShare($learner);

        $subject = $kind->needsSubject() ? $subject : null;
        $locale = $this->locales->forLearner($learner);
        $result = $this->builder->build($learner, $kind, $subject, $locale);
        $key = $this->cacheKey($learner, $kind, $subject, $locale, $result);

        $prepared = $this->fromCache($key);
        if ($prepared === null) {
            $pause = $this->outbound->globalPauseRemaining();
            if ($pause > 0) {
                $this->logFailure($learner, $kind, 'telegram_paused');

                throw ShareException::failed('telegram_paused', $pause);
            }

            try {
                /** @var PreparedShare $prepared */
                $prepared = Cache::lock($key.':lock', max(1, $this->setting('lock_seconds', 40)))
                    ->block($this->setting('lock_wait_seconds', 3), fn (): PreparedShare => $this->fromCache($key) ?? $this->save($learner, $kind, $result, $key));
            } catch (LockTimeoutException) {
                $this->logFailure($learner, $kind, 'busy');

                throw ShareException::failed('busy', 1);
            }
        }

        $this->events->record(ProductEventName::SharePrepared, [
            'kind' => $kind->value,
            'subject' => $subject,
            'via' => 'mini_app',
            'cached' => $prepared->cached,
            'photo' => ($result['type'] ?? null) === 'photo',
        ], learner: $learner);

        return $prepared;
    }

    /** @throws ShareException share_unavailable */
    private function assertCanShare(Learner $learner): void
    {
        if (! (bool) config('share.enabled', true)) {
            throw ShareException::unavailable('disabled');
        }
        if ($learner->blocked_at !== null) {
            throw ShareException::unavailable('learner_blocked');
        }
        if ($learner->telegram_user_id <= 0) {
            throw ShareException::unavailable('no_telegram_id');
        }
        if (ltrim(trim((string) config('telegram.bot_username')), '@') === '') {
            throw ShareException::unavailable('bot_username_missing');
        }
    }

    /**
     * @param  array<string, mixed>  $result
     *
     * @throws ShareException share_failed
     */
    private function save(Learner $learner, ShareKind $kind, array $result, string $key): PreparedShare
    {
        try {
            $message = $this->telegram->savePreparedInlineMessage(
                $learner->telegram_user_id,
                $result,
                allowUserChats: true,
                allowBotChats: false,
                allowGroupChats: true,
                allowChannelChats: true,
            );
        } catch (TelegramRateLimitedException $e) {
            $this->logFailure($learner, $kind, 'telegram_rate_limited', $e);

            throw ShareException::failed('telegram_rate_limited', $e->retryAfter, $e);
        } catch (TelegramApiException $e) {
            $reason = $e->isApiResponse() ? 'telegram_refused' : 'telegram_unreachable';
            $this->logFailure($learner, $kind, $reason, $e);

            throw ShareException::failed($reason, previous: $e);
        }

        $this->remember($key, $message);

        return new PreparedShare($message->id, CarbonImmutable::createFromTimestamp($message->expirationDate), cached: false);
    }

    private function remember(string $key, PreparedInlineMessage $message): void
    {
        $ttl = min(
            $this->setting('cache_max_seconds', 3600),
            $message->expirationDate - now()->getTimestamp() - $this->setting('cache_margin_seconds', 300),
        );

        // A message that lives shorter than the margin is handed out, not kept: the next tap prepares a new one.
        // `fresh_until` repeats the TTL inside the value: a store that keeps a key longer (or a clock moved on) cannot serve it late.
        if ($ttl >= 1) {
            Cache::put($key, ['id' => $message->id, 'expires_at' => $message->expirationDate, 'fresh_until' => now()->getTimestamp() + $ttl], $ttl);
        }
    }

    private function fromCache(string $key): ?PreparedShare
    {
        $value = Cache::get($key);
        if (! is_array($value)) {
            return null;
        }

        $id = $value['id'] ?? null;
        $expiresAt = $value['expires_at'] ?? null;
        $freshUntil = $value['fresh_until'] ?? null;
        $now = now()->getTimestamp();
        if (! is_string($id) || $id === '' || ! is_int($expiresAt) || ! is_int($freshUntil) || $freshUntil <= $now || $expiresAt <= $now) {
            return null;
        }

        return new PreparedShare($id, CarbonImmutable::createFromTimestamp($expiresAt), cached: true);
    }

    /** @param  array<string, mixed>  $result */
    private function cacheKey(Learner $learner, ShareKind $kind, ?string $subject, string $locale, array $result): string
    {
        // The Telegram id is a part of the content: a prepared message belongs to one Telegram user.
        $hash = substr(hash('sha256', $learner->telegram_user_id.'|'.json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), 0, 20);

        return implode(':', ['share', 'prep', self::CACHE_VERSION, $learner->id, $kind->value, $subject ?? '-', $locale, $hash]);
    }

    private function logFailure(Learner $learner, ShareKind $kind, string $reason, ?TelegramApiException $e = null): void
    {
        Log::warning('share.prepare_failed', array_filter([
            'learner_id' => $learner->id,
            'kind' => $kind->value,
            'reason' => $reason,
            // TelegramApiException carries Telegram's description, never the token (docs/36 H2).
            'error' => $e === null ? null : Str::limit($e->getMessage(), 200),
            'retry_after' => $e instanceof TelegramRateLimitedException ? $e->retryAfter : null,
        ], static fn (mixed $value): bool => $value !== null));
    }

    private function setting(string $name, int $default): int
    {
        return max(0, (int) config("share.prepare.{$name}", $default));
    }
}
