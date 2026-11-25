<?php

declare(strict_types=1);

/*
 * SharePreparer (docs/36 §1в п. 2, H3): `savePreparedInlineMessage` for the authenticated learner only, the cache of the prepared message
 * (docs/19 §3: an array, the version and the hash of the content in the key, TTL from Telegram's `expiration_date` minus a margin), the
 * refusals before any call (blocked, switched off, no bot username, someone else's subject, the bot paused after a 429), the failures of
 * Telegram (a refusal, a timeout, a 429) - never cached - and `share.prepared`.
 */

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Engagement\DTO\PreparedShare;
use App\Domain\Engagement\Enums\AchievementKey;
use App\Domain\Engagement\Enums\ShareKind;
use App\Domain\Engagement\Exceptions\ShareException;
use App\Domain\Engagement\Services\ShareMessageBuilder;
use App\Domain\Engagement\Services\SharePreparer;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Services\TelegramMessageRateLimiter;
use App\Models\Achievement;
use App\Models\Learner;
use App\Models\ProductEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeTelegramClient;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public', ['url' => 'https://muhrla.test/storage']);
    config([
        'app.brand' => 'Muhrla',
        'app.brand_logo' => null,
        'telegram.bot_username' => 'MuhrlaBot',
        'achievements.illustrations' => sys_get_temp_dir().'/lf-no-card-art',
        'billing.trial.days' => 14,
        'share.enabled' => true,
        'share.prepare.cache_max_seconds' => 3600,
        'share.prepare.cache_margin_seconds' => 300,
        'share.prepare.lock_wait_seconds' => 0,
    ]);
});

function shareTelegram(): FakeTelegramClient
{
    $telegram = app(TelegramClient::class);
    assert($telegram instanceof FakeTelegramClient);

    return $telegram;
}

function sharePrepare(Learner $learner, ShareKind $kind = ShareKind::Invite, ?string $subject = null): PreparedShare
{
    return app(SharePreparer::class)->prepare($learner, $kind, $subject);
}

function sharePrepareError(Learner $learner, ShareKind $kind = ShareKind::Invite, ?string $subject = null): ?ShareException
{
    try {
        sharePrepare($learner, $kind, $subject);
    } catch (ShareException $e) {
        return $e;
    }

    return null;
}

/** The cache key of the n-th prepared message (docs/19 §3: version, learner, kind, subject, language, hash of the content). */
function shareCacheKey(Learner $learner, int $call = 0, string $kindAndSubject = 'invite:-', string $locale = 'ru'): string
{
    $result = shareTelegram()->preparedMessages[$call]['result'];
    $hash = substr(hash('sha256', $learner->telegram_user_id.'|'.json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), 0, 20);

    return "share:prep:v1:{$learner->id}:{$kindAndSubject}:{$locale}:{$hash}";
}

function shareLearner(array $attributes = []): Learner
{
    return Learner::factory()->create(['interface_locale' => 'ru', ...$attributes]);
}

it('prepares the message of the authenticated learner: their Telegram id, the built result, user / group / channel chats, no bots', function (): void {
    $learner = shareLearner(['telegram_user_id' => 777001]);
    Achievement::factory()->key(AchievementKey::Streak7)->create(['learner_id' => $learner->id]);
    shareTelegram()->preparedExpirationDate = now()->addDay()->getTimestamp();

    $prepared = sharePrepare($learner, ShareKind::Achievement, 'streak_7');
    $call = shareTelegram()->preparedMessages[0];

    expect(shareTelegram()->preparedMessages)->toHaveCount(1)
        ->and($call['user_id'])->toBe(777001)
        ->and($call['result'])->toBe(app(ShareMessageBuilder::class)->build($learner, ShareKind::Achievement, 'streak_7', 'ru'))
        ->and($call['allow_user_chats'])->toBeTrue()
        ->and($call['allow_group_chats'])->toBeTrue()
        ->and($call['allow_channel_chats'])->toBeTrue()
        ->and($call['allow_bot_chats'])->toBeFalse()
        ->and($prepared->id)->toBe($call['id'])
        ->and($prepared->cached)->toBeFalse()
        ->and($prepared->expiresAt->getTimestamp())->toBe(shareTelegram()->preparedExpirationDate);
});

it('serves a repeated tap from the cache without the Bot API, and records share.prepared every time', function (): void {
    $learner = shareLearner();

    $first = sharePrepare($learner);
    $second = sharePrepare($learner);

    $events = ProductEvent::query()->where('name', ProductEventName::SharePrepared->value)->orderBy('id')->get();
    expect(shareTelegram()->preparedMessages)->toHaveCount(1)
        ->and($second->id)->toBe($first->id)
        ->and($second->expiresAt->getTimestamp())->toBe($first->expiresAt->getTimestamp())
        ->and($second->cached)->toBeTrue()
        ->and($events)->toHaveCount(2)
        ->and($events[0]->learner_id)->toBe($learner->id)
        ->and($events[0]->props)->toEqual(['kind' => 'invite', 'subject' => null, 'via' => 'mini_app', 'cached' => false, 'photo' => true])
        ->and($events[1]->props['cached'])->toBeTrue();
});

it('keeps an array under a versioned key of the learner, the kind, the subject, the language and the content', function (): void {
    $this->freezeTime();
    $learner = shareLearner();
    Achievement::factory()->key(AchievementKey::Streak7)->create(['learner_id' => $learner->id]);
    $expires = now()->addDay()->getTimestamp();
    shareTelegram()->preparedExpirationDate = $expires;

    sharePrepare($learner, ShareKind::Achievement, 'streak_7');

    expect(Cache::get(shareCacheKey($learner, 0, 'achievement:streak_7')))->toBe(['id' => 'prepared-1', 'expires_at' => $expires, 'fresh_until' => now()->getTimestamp() + 3600]);
});

it('prepares anew for another language, another learner, another subject and changed content', function (): void {
    $learner = shareLearner();
    $other = shareLearner();
    foreach ([AchievementKey::Streak7, AchievementKey::Streak30] as $key) {
        Achievement::factory()->key($key)->create(['learner_id' => $learner->id]);
    }

    sharePrepare($learner, ShareKind::Achievement, 'streak_7');
    sharePrepare($learner, ShareKind::Achievement, 'streak_30');
    sharePrepare($other);
    sharePrepare($learner);

    $learner->update(['interface_locale' => 'uz']);
    sharePrepare($learner);

    // The trial changes the caption of the invitation: a new hash, no stale message.
    config(['billing.trial.days' => 7]);
    sharePrepare($learner);
    sharePrepare($learner);

    expect(shareTelegram()->preparedMessages)->toHaveCount(6)
        ->and(shareTelegram()->preparedMessages[3]['result']['caption'])->toContain('14')
        ->and(shareTelegram()->preparedMessages[5]['result']['caption'])->toContain('7');
});

it('keeps the id until the expiration minus the margin, capped at an hour, and prepares a new one after', function (): void {
    $learner = shareLearner();

    // Telegram says a day: the cap of an hour wins.
    shareTelegram()->preparedExpirationDate = now()->addDay()->getTimestamp();
    sharePrepare($learner);
    $this->travel(3599)->seconds();
    sharePrepare($learner);
    expect(shareTelegram()->preparedMessages)->toHaveCount(1);
    $this->travel(2)->seconds();
    sharePrepare($learner);
    expect(shareTelegram()->preparedMessages)->toHaveCount(2);

    // Telegram says 20 minutes: kept 15 (the margin is 5).
    $learner = shareLearner();
    shareTelegram()->preparedExpirationDate = now()->addMinutes(20)->getTimestamp();
    sharePrepare($learner);
    $this->travel(899)->seconds();
    sharePrepare($learner);
    expect(shareTelegram()->preparedMessages)->toHaveCount(3);
    $this->travel(2)->seconds();
    sharePrepare($learner);
    expect(shareTelegram()->preparedMessages)->toHaveCount(4);
});

it('hands out but does not keep a message that lives shorter than the margin', function (): void {
    $learner = shareLearner();
    shareTelegram()->preparedExpirationDate = now()->addMinutes(4)->getTimestamp();

    $first = sharePrepare($learner);
    $second = sharePrepare($learner);

    expect(shareTelegram()->preparedMessages)->toHaveCount(2)
        ->and($first->cached)->toBeFalse()
        ->and($second->cached)->toBeFalse()
        ->and($second->id)->not->toBe($first->id);
});

it('ignores a broken cache entry', function (): void {
    $learner = shareLearner();
    sharePrepare($learner);
    $key = shareCacheKey($learner);

    $later = now()->addHour()->getTimestamp();
    $past = now()->subMinute()->getTimestamp();
    foreach ([
        'garbage',
        ['id' => 5, 'expires_at' => $later, 'fresh_until' => $later],
        ['id' => 'x', 'expires_at' => 'x', 'fresh_until' => $later],
        ['id' => 'x', 'expires_at' => $later],
        ['id' => 'old', 'expires_at' => $past, 'fresh_until' => $later],
        ['id' => 'stale', 'expires_at' => $later, 'fresh_until' => $past],
    ] as $i => $broken) {
        Cache::put($key, $broken, 600);
        $prepared = sharePrepare($learner);
        expect($prepared->cached)->toBeFalse()->and(shareTelegram()->preparedMessages)->toHaveCount($i + 2);
    }
});

it('maps a refusal of Telegram to share_failed and caches nothing: the next tap calls again', function (): void {
    $learner = shareLearner();
    shareTelegram()->failInline = 1;

    $e = sharePrepareError($learner);

    expect($e?->errorCode)->toBe(ShareException::FAILED)
        ->and($e?->httpStatus())->toBe(503)
        ->and($e?->reason)->toBe('telegram_refused')
        ->and($e?->details())->toBe([])
        ->and(shareTelegram()->preparedMessages)->toHaveCount(0)
        ->and(ProductEvent::query()->where('name', ProductEventName::SharePrepared->value)->count())->toBe(0);

    expect(sharePrepare($learner)->cached)->toBeFalse()->and(shareTelegram()->preparedMessages)->toHaveCount(1);
});

it('maps a timeout to share_failed telegram_unreachable and a 429 to share_failed with retry_after', function (): void {
    $learner = shareLearner();

    shareTelegram()->failInline = 1;
    shareTelegram()->failInlineAsTransport = true;
    $timeout = sharePrepareError($learner);

    shareTelegram()->failInline = 1;
    shareTelegram()->failInlineRetryAfter = 7;
    $limited = sharePrepareError($learner);

    expect($timeout?->reason)->toBe('telegram_unreachable')
        ->and($timeout?->httpStatus())->toBe(503)
        ->and($limited?->reason)->toBe('telegram_rate_limited')
        ->and($limited?->httpStatus())->toBe(503)
        ->and($limited?->retryAfter)->toBe(7)
        ->and($limited?->details())->toBe(['retry_after' => 7]);
});

it('does not call Telegram while the bot is paused after a 429, but still serves a cached message', function (): void {
    $learner = shareLearner();
    $other = shareLearner();
    sharePrepare($learner);

    app(TelegramMessageRateLimiter::class)->pause(20);

    $cached = sharePrepare($learner);
    $e = sharePrepareError($other);

    expect($cached->cached)->toBeTrue()
        ->and($e?->reason)->toBe('telegram_paused')
        ->and($e?->retryAfter)->toBeGreaterThan(0)->toBeLessThanOrEqual(20)
        ->and(shareTelegram()->preparedMessages)->toHaveCount(1);
});

it('answers busy without a call while a parallel request holds the lock of the same message', function (): void {
    $learner = shareLearner();
    sharePrepare($learner);
    $key = shareCacheKey($learner);
    Cache::forget($key);
    $lock = Cache::lock($key.':lock', 30);
    expect($lock->get())->toBeTrue();

    $e = sharePrepareError($learner);
    $lock->release();

    expect($e?->reason)->toBe('busy')
        ->and($e?->httpStatus())->toBe(503)
        ->and(shareTelegram()->preparedMessages)->toHaveCount(1);

    // The lock released: the next tap prepares.
    expect(sharePrepare($learner)->cached)->toBeFalse()->and(shareTelegram()->preparedMessages)->toHaveCount(2);
});

it('refuses before any call: sharing off, a blocked learner, no Telegram id, a bot without a username', function (): void {
    $cases = [
        'disabled' => [fn () => config(['share.enabled' => false]), shareLearner()],
        'learner_blocked' => [fn () => null, shareLearner(['blocked_at' => now()])],
        'bot_username_missing' => [fn () => config(['telegram.bot_username' => ' @ ']), shareLearner()],
    ];

    foreach ($cases as $reason => [$arrange, $learner]) {
        config(['share.enabled' => true, 'telegram.bot_username' => 'MuhrlaBot']);
        $arrange();
        $e = sharePrepareError($learner);
        expect($e?->errorCode)->toBe(ShareException::UNAVAILABLE)
            ->and($e?->httpStatus())->toBe(403)
            ->and($e?->reason)->toBe($reason);
    }

    config(['share.enabled' => true, 'telegram.bot_username' => 'MuhrlaBot']);
    $noTelegram = shareLearner();
    $noTelegram->telegram_user_id = 0;
    expect(sharePrepareError($noTelegram)?->reason)->toBe('no_telegram_id');

    expect(shareTelegram()->preparedMessages)->toHaveCount(0)
        ->and(ProductEvent::query()->where('name', ProductEventName::SharePrepared->value)->count())->toBe(0);
});

it('answers not found for someone else\'s achievement without a call or an event', function (): void {
    $learner = shareLearner();
    $other = shareLearner();
    Achievement::factory()->key(AchievementKey::Streak7)->create(['learner_id' => $other->id]);

    expect(sharePrepareError($learner, ShareKind::Achievement, 'streak_7')?->errorCode)->toBe(ShareException::NOT_FOUND)
        ->and(shareTelegram()->preparedMessages)->toHaveCount(0)
        ->and(ProductEvent::query()->count())->toBe(0);
});
