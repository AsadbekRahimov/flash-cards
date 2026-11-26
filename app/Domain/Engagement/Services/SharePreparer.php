<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Engagement\DTO\PreparedShare;
use App\Domain\Engagement\DTO\ShareDraft;
use App\Domain\Engagement\Enums\ShareKind;
use App\Domain\Engagement\Exceptions\ShareException;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Operations\Support\PureCache;
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
 * - **Checks first, always:** {@see ShareMessageBuilder::draft()} - the learner's own subject, the open game, the texts, the link - runs
 *   on every tap, a cached one included; only the card (drawing, hashing the files of the logo and the illustration) and the call to
 *   Telegram are what the cache saves.
 * - **Cache** (docs/19 §3, a PURE cache: {@see PureCache::store()}): `share:prep:v1:{learner}:{kind}:{subject|-}:{locale}:{version}` →
 *   `{id, expires_at, fresh_until, photo}` (an array); `version` - a hash of the Telegram id of the learner and the version of the
 *   draft ({@see ShareDraft::$version}: the texts, the link, the template and the settings of the card, no file read). TTL =
 *   Telegram's `expiration_date` − `cache_margin_seconds`, at most `cache_max_seconds`. A changed text, link or setting changes the
 *   key, so nothing needs invalidating; a new file of the logo / an illustration under the same path shows after the TTL (≤ an hour).
 *   A failure is not cached. A lost entry costs one more call to Telegram, nothing else.
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
        // The checks of the subject run before the cache is looked at: a subject that is no longer the learner's is refused at once.
        $draft = $this->builder->draft($learner, $kind, $subject, $locale);
        $key = self::cacheKey($learner, $draft);

        $cached = $this->fromCache($key);
        if ($cached === null) {
            $pause = $this->outbound->globalPauseRemaining();
            if ($pause > 0) {
                $this->logFailure($learner, $kind, 'telegram_paused');

                throw ShareException::failed('telegram_paused', $pause);
            }

            try {
                /** @var array{share: PreparedShare, photo: bool} $cached */
                $cached = Cache::lock($key.':lock', max(1, $this->setting('lock_seconds', 40)))
                    ->block($this->setting('lock_wait_seconds', 3), fn (): array => $this->fromCache($key) ?? $this->save($learner, $draft, $key));
            } catch (LockTimeoutException) {
                $this->logFailure($learner, $kind, 'busy');

                throw ShareException::failed('busy', 1);
            }
        }

        $this->events->record(ProductEventName::SharePrepared, [
            'kind' => $kind->value,
            'subject' => $subject,
            'via' => 'mini_app',
            'cached' => $cached['share']->cached,
            'photo' => $cached['photo'],
        ], learner: $learner);

        return $cached['share'];
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
     * The card is drawn here, under the lock: a parallel tap waiting for it takes the answer from the cache and draws nothing.
     *
     * @return array{share: PreparedShare, photo: bool}
     *
     * @throws ShareException share_failed
     */
    private function save(Learner $learner, ShareDraft $draft, string $key): array
    {
        $result = $this->builder->compose($draft);
        $photo = ($result['type'] ?? null) === 'photo';

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
            $this->logFailure($learner, $draft->kind, 'telegram_rate_limited', $e);

            throw ShareException::failed('telegram_rate_limited', $e->retryAfter, $e);
        } catch (TelegramApiException $e) {
            $reason = $e->isApiResponse() ? 'telegram_refused' : 'telegram_unreachable';
            $this->logFailure($learner, $draft->kind, $reason, $e);

            throw ShareException::failed($reason, previous: $e);
        }

        $this->remember($key, $message, $photo);

        return ['share' => new PreparedShare($message->id, CarbonImmutable::createFromTimestamp($message->expirationDate), cached: false), 'photo' => $photo];
    }

    private function remember(string $key, PreparedInlineMessage $message, bool $photo): void
    {
        $ttl = min(
            $this->setting('cache_max_seconds', 3600),
            $message->expirationDate - now()->getTimestamp() - $this->setting('cache_margin_seconds', 300),
        );

        // A message that lives shorter than the margin is handed out, not kept: the next tap prepares a new one.
        // `fresh_until` repeats the TTL inside the value: a store that keeps a key longer (or a clock moved on) cannot serve it late.
        if ($ttl >= 1) {
            PureCache::store()->put($key, [
                'id' => $message->id,
                'expires_at' => $message->expirationDate,
                'fresh_until' => now()->getTimestamp() + $ttl,
                'photo' => $photo,
            ], $ttl);
        }
    }

    /**
     * The prepared message under the key, or null: none, not of the shape, or no longer fresh. Redis hands an integer back as it was
     * put inside an array (docs/19 §3 rule 11 concerns top-level numbers only).
     *
     * @return array{share: PreparedShare, photo: bool}|null
     */
    private function fromCache(string $key): ?array
    {
        $value = PureCache::store()->get($key);
        if (! is_array($value)) {
            return null;
        }

        $id = $value['id'] ?? null;
        $expiresAt = $value['expires_at'] ?? null;
        $freshUntil = $value['fresh_until'] ?? null;
        $photo = $value['photo'] ?? null;
        $now = now()->getTimestamp();
        if (! is_string($id) || $id === '' || ! is_int($expiresAt) || ! is_int($freshUntil) || ! is_bool($photo) || $freshUntil <= $now || $expiresAt <= $now) {
            return null;
        }

        return ['share' => new PreparedShare($id, CarbonImmutable::createFromTimestamp($expiresAt), cached: true), 'photo' => $photo];
    }

    /** `share:prep:v1:{learner}:{kind}:{subject|-}:{locale}:{version}` - built from the draft, before any card is drawn. */
    public static function cacheKey(Learner $learner, ShareDraft $draft): string
    {
        // The Telegram id is a part of the content: a prepared message belongs to one Telegram user.
        $version = substr(hash('sha256', $learner->telegram_user_id.'|'.$draft->version), 0, 20);

        return implode(':', ['share', 'prep', self::CACHE_VERSION, $learner->id, $draft->kind->value, $draft->subject ?? '-', $draft->locale, $version]);
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
