<?php

declare(strict_types=1);

/*
 * POST /api/twa/share/prepare and POST /api/twa/share/events (docs/04 A.22, docs/36 H4, H7): the exact contract `{id, expires_at}`,
 * the codes the Mini App falls back on (401, 403, 404, 422, 429, 503), the Bot API `user_id` taken from the JWT only - never from the
 * body - and the client's analytics events.
 */

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Engagement\Enums\AchievementKey;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Twa\Services\JwtService;
use App\Models\Achievement;
use App\Models\Learner;
use App\Models\ProductEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
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
        'share.prepare.per_minute' => 20,
        'share.prepare.per_day' => 100,
    ]);
});

function twaShareTelegram(): FakeTelegramClient
{
    $telegram = app(TelegramClient::class);
    assert($telegram instanceof FakeTelegramClient);

    return $telegram;
}

/** @param  array<string, mixed>  $body */
function twaShare(Learner $learner, array $body, string $path = '/api/twa/share/prepare'): TestResponse
{
    $token = app(JwtService::class)->issue($learner->id)['token'];

    return test()->withHeaders(['Authorization' => 'Bearer '.$token])->postJson($path, $body);
}

it('requires a TWA token', function (): void {
    $this->postJson('/api/twa/share/prepare', ['kind' => 'invite'])->assertUnauthorized();
    $this->postJson('/api/twa/share/events', ['event' => 'sent', 'kind' => 'invite'])->assertUnauthorized();

    expect(twaShareTelegram()->preparedMessages)->toBe([]);
});

it('answers exactly {id, expires_at} for an own achievement', function (): void {
    $learner = Learner::factory()->create(['interface_locale' => 'ru']);
    Achievement::factory()->key(AchievementKey::Streak7)->create(['learner_id' => $learner->id]);
    $expires = now()->addDay()->startOfSecond();
    twaShareTelegram()->preparedExpirationDate = $expires->getTimestamp();

    twaShare($learner, ['kind' => 'achievement', 'subject' => 'streak_7'])
        ->assertOk()
        ->assertExactJson(['id' => 'prepared-1', 'expires_at' => $expires->toIso8601String()]);

    expect(ProductEvent::query()->where('name', ProductEventName::SharePrepared->value)->value('props'))
        ->toEqual(['kind' => 'achievement', 'subject' => 'streak_7', 'via' => 'mini_app', 'cached' => false, 'photo' => true]);
});

it('takes the Telegram user only from the JWT and the text, photo and button only from the server', function (): void {
    $learner = Learner::factory()->create(['telegram_user_id' => 555001, 'interface_locale' => 'ru']);
    $victim = Learner::factory()->create(['telegram_user_id' => 555002]);

    // Unknown fields are not read: no user, no text, no URL of the client can reach the Bot API.
    twaShare($learner, [
        'kind' => 'invite',
        'user_id' => $victim->telegram_user_id,
        'telegram_user_id' => $victim->telegram_user_id,
        'learner_id' => $victim->id,
        'text' => 'Buy cheap followers http://evil.example',
        'caption' => 'evil',
        'photo_url' => 'https://evil.example/x.jpg',
        'url' => 'https://evil.example',
        'locale' => 'uz',
    ])->assertOk();

    $call = twaShareTelegram()->preparedMessages[0];
    $encoded = json_encode($call['result'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    expect($call['user_id'])->toBe(555001)
        ->and($encoded)->not->toContain('evil')
        ->and($call['result']['caption'])->toBe('Учу английский в Muhrla — присоединяйся, первые 14 дней Pro бесплатно!')
        ->and($call['result']['photo_url'])->toStartWith('https://muhrla.test/storage/share-cards/invite-ru-')
        ->and($call['result']['reply_markup']['inline_keyboard'][0][0]['url'])->toBe('https://t.me/MuhrlaBot?start=ref_'.$learner->fresh()?->referral_code);
});

it('answers 404 share_subject_not_found alike for someone else\'s, a locked and a missing achievement and city', function (): void {
    $learner = Learner::factory()->create();
    $other = Learner::factory()->create();
    Achievement::factory()->key(AchievementKey::Streak30)->create(['learner_id' => $other->id]);

    $bodies = [
        ['kind' => 'achievement', 'subject' => 'streak_30'],
        ['kind' => 'achievement', 'subject' => 'streak_7'],
        ['kind' => 'achievement', 'subject' => 'no_such_key'],
        ['kind' => 'game_city', 'subject' => '999999'],
    ];
    $answers = [];
    foreach ($bodies as $body) {
        $answers[] = twaShare($learner, $body)->assertNotFound()->json();
    }

    expect(array_unique(array_map('json_encode', $answers)))->toHaveCount(1)
        ->and($answers[0])->toBe(['error' => ['code' => 'share_subject_not_found', 'message' => 'Nothing to share.']])
        ->and(twaShareTelegram()->preparedMessages)->toBe([]);
});

it('answers 403 share_unavailable when sharing is off or the bot has no username, and 401 for a blocked learner', function (): void {
    $learner = Learner::factory()->create();

    config(['telegram.bot_username' => '']);
    twaShare($learner, ['kind' => 'invite'])->assertForbidden()->assertExactJson(['error' => ['code' => 'share_unavailable', 'message' => 'Sharing is unavailable.']]);

    config(['telegram.bot_username' => 'MuhrlaBot', 'share.enabled' => false]);
    twaShare($learner, ['kind' => 'invite'])->assertForbidden()->assertJsonPath('error.code', 'share_unavailable');

    // A platform ban is refused by TwaAuth before the controller (the service refuses it too: SharePreparerTest).
    config(['share.enabled' => true]);
    $learner->update(['blocked_at' => now()]);
    twaShare($learner, ['kind' => 'invite'])->assertUnauthorized()->assertJsonPath('error.code', 'learner_blocked');

    expect(twaShareTelegram()->preparedMessages)->toBe([]);
});

it('answers 422 for an unknown kind, a kind not available yet, and a missing, forbidden or malformed subject', function (array $body, string $field): void {
    $learner = Learner::factory()->create();

    twaShare($learner, $body)->assertUnprocessable()->assertJsonValidationErrors([$field]);

    expect(twaShareTelegram()->preparedMessages)->toBe([]);
})->with([
    'no kind' => [[], 'kind'],
    'unknown kind' => [['kind' => 'photo'], 'kind'],
    'kind as array' => [['kind' => ['invite']], 'kind'],
    'league_week (later)' => [['kind' => 'league_week', 'subject' => '1'], 'kind'],
    'month_place (later)' => [['kind' => 'month_place', 'subject' => '1'], 'kind'],
    'achievement without subject' => [['kind' => 'achievement'], 'subject'],
    'achievement, bad subject' => [['kind' => 'achievement', 'subject' => '../etc/passwd'], 'subject'],
    'achievement, long subject' => [['kind' => 'achievement', 'subject' => str_repeat('a', 41)], 'subject'],
    'invite with a subject' => [['kind' => 'invite', 'subject' => 'streak_7'], 'subject'],
    'friend invite with a subject' => [['kind' => 'friend_invite', 'subject' => '1'], 'subject'],
]);

it('answers 503 share_failed when Telegram refuses or does not answer, with Retry-After on its 429', function (): void {
    $learner = Learner::factory()->create();

    twaShareTelegram()->failInline = 1;
    twaShare($learner, ['kind' => 'invite'])->assertStatus(503)
        ->assertExactJson(['error' => ['code' => 'share_failed', 'message' => 'Could not prepare the message.']])
        ->assertHeaderMissing('Retry-After');

    twaShareTelegram()->failInline = 1;
    twaShareTelegram()->failInlineAsTransport = true;
    twaShare($learner, ['kind' => 'invite'])->assertStatus(503)->assertJsonPath('error.code', 'share_failed');

    twaShareTelegram()->failInline = 1;
    twaShareTelegram()->failInlineRetryAfter = 9;
    twaShare($learner, ['kind' => 'invite'])->assertStatus(503)
        ->assertJsonPath('error.details', ['retry_after' => 9])
        ->assertHeader('Retry-After', '9');

    // Nothing was cached: the next tap goes through.
    twaShare($learner, ['kind' => 'invite'])->assertOk();
    expect(twaShareTelegram()->preparedMessages)->toHaveCount(1);
});

it('answers the second tap from the cache: the same id, one Bot API call', function (): void {
    $learner = Learner::factory()->create();

    $first = twaShare($learner, ['kind' => 'friend_invite'])->assertOk()->json();
    $second = twaShare($learner, ['kind' => 'friend_invite'])->assertOk()->json();

    expect($second)->toBe($first)->and(twaShareTelegram()->preparedMessages)->toHaveCount(1);
});

it('limits a learner to share.prepare.per_minute a minute, cached answers included, apart from other learners', function (): void {
    config(['share.prepare.per_minute' => 3]);
    $learner = Learner::factory()->create();
    $other = Learner::factory()->create();

    foreach (range(1, 3) as $_) {
        twaShare($learner, ['kind' => 'invite'])->assertOk();
    }
    twaShare($learner, ['kind' => 'invite'])->assertTooManyRequests()->assertHeader('Retry-After');
    twaShare($other, ['kind' => 'invite'])->assertOk();

    $this->travel(61)->seconds();
    twaShare($learner, ['kind' => 'invite'])->assertOk();
});

it('limits a learner to share.prepare.per_day a day', function (): void {
    config(['share.prepare.per_day' => 2]);
    $learner = Learner::factory()->create();

    twaShare($learner, ['kind' => 'invite'])->assertOk();
    $this->travel(2)->minutes();
    twaShare($learner, ['kind' => 'invite'])->assertOk();
    $this->travel(2)->minutes();
    twaShare($learner, ['kind' => 'invite'])->assertTooManyRequests();
});

it('records share.sent and share.fallback {reason} from the client, 204', function (): void {
    $learner = Learner::factory()->create();

    twaShare($learner, ['event' => 'sent', 'kind' => 'achievement'], '/api/twa/share/events')->assertNoContent();
    twaShare($learner, ['event' => 'fallback', 'kind' => 'invite', 'reason' => 'old_client'], '/api/twa/share/events')->assertNoContent();
    twaShare($learner, ['event' => 'fallback', 'kind' => 'game_city', 'reason' => 'error'], '/api/twa/share/events')->assertNoContent();

    $events = ProductEvent::query()->whereIn('name', ['share.sent', 'share.fallback'])->orderBy('id')->get();
    expect($events->map(fn (ProductEvent $e): array => [$e->name, $e->learner_id, $e->props])->all())->toEqual([
        ['share.sent', $learner->id, ['kind' => 'achievement']],
        ['share.fallback', $learner->id, ['kind' => 'invite', 'reason' => 'old_client']],
        ['share.fallback', $learner->id, ['kind' => 'game_city', 'reason' => 'error']],
    ]);
});

it('refuses a malformed client event with 422 and records nothing', function (array $body, string $field): void {
    $learner = Learner::factory()->create();

    twaShare($learner, $body, '/api/twa/share/events')->assertUnprocessable()->assertJsonValidationErrors([$field]);

    expect(ProductEvent::query()->whereIn('name', ['share.sent', 'share.fallback'])->count())->toBe(0);
})->with([
    'unknown event' => [['event' => 'opened', 'kind' => 'invite'], 'event'],
    'unknown kind' => [['event' => 'sent', 'kind' => 'league_week'], 'kind'],
    'fallback without reason' => [['event' => 'fallback', 'kind' => 'invite'], 'reason'],
    'fallback, unknown reason' => [['event' => 'fallback', 'kind' => 'invite', 'reason' => 'spam'], 'reason'],
    'sent with a reason' => [['event' => 'sent', 'kind' => 'invite', 'reason' => 'error'], 'reason'],
]);

it('limits the client events per learner', function (): void {
    config(['share.events_per_minute' => 2]);
    $learner = Learner::factory()->create();

    twaShare($learner, ['event' => 'sent', 'kind' => 'invite'], '/api/twa/share/events')->assertNoContent();
    twaShare($learner, ['event' => 'sent', 'kind' => 'invite'], '/api/twa/share/events')->assertNoContent();
    twaShare($learner, ['event' => 'sent', 'kind' => 'invite'], '/api/twa/share/events')->assertTooManyRequests();
});
