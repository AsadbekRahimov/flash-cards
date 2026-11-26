<?php

declare(strict_types=1);

/*
 * The emoji of Pro (docs/31 §5а, E1): the set of config/pro_emoji.php (single emoji only), the choice through
 * `PATCH /me/settings` `pro_emoji` (Free 403 `pro_emoji_pro_required`, Pro 200, unknown code 422), `learner.pro_emoji` of `GET /me`
 * only with a live Pro, and the choice kept while Pro is away.
 */

use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Learners\Exceptions\ProEmojiProRequiredException;
use App\Domain\Learners\Services\LearnerSettingsService;
use App\Domain\Learners\Services\ProEmojiService;
use App\Domain\Twa\Services\JwtService;
use App\Models\Learner;
use App\Models\ProductEvent;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\QueryLog;

uses(RefreshDatabase::class);

beforeEach(fn () => CarbonImmutable::setTestNow('2026-11-25 10:00:00'));

afterEach(fn () => CarbonImmutable::setTestNow());

function pemLearner(array $attributes = []): Learner
{
    return Learner::factory()->onboarded()->create(['timezone' => 'Asia/Tashkent', ...$attributes]);
}

/** Pro of the learner's own until `$until`. */
function pemPro(Learner $learner, CarbonImmutable $until): Subscription
{
    $subscription = Subscription::factory()->create([
        'subscriber_id' => $learner->id,
        'provider' => 'trial',
        'status' => 'trialing',
        'starts_at' => $until->subDays(14),
        'current_period_ends_at' => $until,
    ]);
    app(EntitlementResolver::class)->forget($learner);

    return $subscription;
}

/** @param  array<string, mixed>  $body */
function pemPatch(object $test, Learner $learner, array $body): TestResponse
{
    return $test->withHeaders(['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']])
        ->patchJson('/api/twa/me/settings', $body);
}

function pemMe(object $test, Learner $learner): TestResponse
{
    return $test->withHeaders(['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']])->getJson('/api/twa/me');
}

it('keeps 30-40 single emoji in the set: one code point each, no sequence, no variation selector, no flag', function (): void {
    $set = app(ProEmojiService::class)->set();

    expect(count($set))->toBeGreaterThanOrEqual(30)->toBeLessThanOrEqual(40)
        ->and(array_unique($set))->toHaveCount(count($set));

    foreach ($set as $code => $emoji) {
        expect($code)->toMatch('/^[a-z][a-z_]{1,31}$/')
            ->and(mb_strlen($emoji, 'UTF-8'))->toBe(1, "{$code} is not one code point")
            ->and(preg_match('/\p{Extended_Pictographic}/u', $emoji))->toBe(1, "{$code} is not an emoji")
            ->and(preg_match('/[\x{FE0F}\x{200D}\x{1F1E6}-\x{1F1FF}\x{1F3FB}-\x{1F3FF}]/u', $emoji))->toBe(0);
    }
});

it('shows the set and the stored choice to a Free learner, locked, and no emoji next to the name', function (): void {
    $learner = pemLearner(['pro_emoji' => 'fire']);

    $me = pemMe($this, $learner)->assertOk();

    expect($me->json('learner.pro_emoji'))->toBeNull()
        ->and($me->json('settings.pro_emoji_choice'))->toBe('fire')
        ->and($me->json('settings.options.pro_emoji_locked'))->toBeTrue()
        ->and($me->json('settings.options.pro_emojis.0'))->toBe(['code' => 'fire', 'emoji' => '🔥']);
});

it('refuses the choice of a Free learner with 403 pro_emoji_pro_required and applies nothing of the request', function (): void {
    $learner = pemLearner(['reminder_time' => '19:00']);

    pemPatch($this, $learner, ['pro_emoji' => 'fire', 'reminder_time' => '08:00'])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'pro_emoji_pro_required');

    expect($learner->fresh()->pro_emoji)->toBeNull()
        ->and(substr((string) $learner->fresh()->reminder_time, 0, 5))->toBe('19:00')
        ->and(ProductEvent::query()->where('name', 'settings.changed')->count())->toBe(0);
});

it('saves the choice of a Pro learner and shows the emoji next to the name', function (): void {
    $learner = pemLearner();
    pemPro($learner, CarbonImmutable::now()->addDays(10));

    $me = pemPatch($this, $learner, ['pro_emoji' => 'rocket'])->assertOk();

    expect($learner->fresh()->pro_emoji)->toBe('rocket')
        ->and($me->json('learner.pro_emoji'))->toBe('🚀')
        ->and($me->json('settings.pro_emoji_choice'))->toBe('rocket')
        ->and($me->json('settings.options.pro_emoji_locked'))->toBeFalse()
        ->and(ProductEvent::query()->where('name', 'settings.changed')->sole()->props)->toBe(['fields' => 'pro_emoji']);
});

it('refuses an unknown code, the emoji itself and free text with 422', function (mixed $value): void {
    $learner = pemLearner();
    pemPro($learner, CarbonImmutable::now()->addDays(10));

    pemPatch($this, $learner, ['pro_emoji' => $value])->assertStatus(422);

    expect($learner->fresh()->pro_emoji)->toBeNull();
})->with([
    'unknown code' => ['crocodile'],
    'the emoji itself' => ['🔥'],
    'a sequence' => ['👨‍👩‍👧'],
    'text' => ['<b>VIP</b>'],
    'a number' => [7],
]);

it('lets anybody take the emoji back with null', function (): void {
    $learner = pemLearner(['pro_emoji' => 'fire']);

    pemPatch($this, $learner, ['pro_emoji' => null])->assertOk()->assertJsonPath('settings.pro_emoji_choice', null);

    expect($learner->fresh()->pro_emoji)->toBeNull();
});

it('hides the emoji when Pro ends, keeps the choice, and shows it again when Pro comes back', function (): void {
    $learner = pemLearner();
    $subscription = pemPro($learner, CarbonImmutable::now()->addDays(2));
    pemPatch($this, $learner, ['pro_emoji' => 'owl'])->assertOk()->assertJsonPath('learner.pro_emoji', '🦉');

    CarbonImmutable::setTestNow('2026-11-28 10:00:00');
    $this->travelTo(CarbonImmutable::now());
    app(EntitlementResolver::class)->forget($learner);

    pemMe($this, $learner)->assertOk()
        ->assertJsonPath('learner.pro_emoji', null)
        ->assertJsonPath('settings.pro_emoji_choice', 'owl');
    expect($learner->fresh()->pro_emoji)->toBe('owl');

    $subscription->update(['status' => 'active', 'current_period_ends_at' => CarbonImmutable::now()->addMonth()]);
    app(EntitlementResolver::class)->forget($learner);

    pemMe($this, $learner)->assertOk()->assertJsonPath('learner.pro_emoji', '🦉');
});

it('reads a code that left the set as no choice', function (): void {
    $learner = pemLearner(['pro_emoji' => 'retired_code']);
    pemPro($learner, CarbonImmutable::now()->addDays(10));

    pemMe($this, $learner)->assertOk()
        ->assertJsonPath('learner.pro_emoji', null)
        ->assertJsonPath('settings.pro_emoji_choice', null);
});

it('checks the plan in the service too: a Free learner cannot choose through any caller', function (): void {
    $learner = pemLearner();

    expect(fn () => app(LearnerSettingsService::class)->update($learner, ['pro_emoji' => 'fire']))
        ->toThrow(ProEmojiProRequiredException::class);
    expect($learner->fresh()->pro_emoji)->toBeNull();
});

it('gives the emoji of a whole page of learners in one statement: only a live Pro with a choice in the set', function (): void {
    $pro = pemLearner(['pro_emoji' => 'fire']);
    pemPro($pro, CarbonImmutable::now()->addDays(10));
    $proWithout = pemLearner(['pro_emoji' => null]);
    pemPro($proWithout, CarbonImmutable::now()->addDays(10));
    $lapsed = pemLearner(['pro_emoji' => 'star']);
    pemPro($lapsed, CarbonImmutable::now()->subDay());
    $free = pemLearner(['pro_emoji' => 'owl']);
    $retired = pemLearner(['pro_emoji' => 'retired_code']);
    pemPro($retired, CarbonImmutable::now()->addDays(10));

    $ids = [$pro->id, $proWithout->id, $lapsed->id, $free->id, $retired->id];
    $queries = QueryLog::during(fn () => app(ProEmojiService::class)->visibleForMany($ids));

    expect($queries)->toHaveCount(1)
        ->and(app(ProEmojiService::class)->visibleForMany($ids))->toBe([$pro->id => '🔥'])
        ->and(app(ProEmojiService::class)->visibleForMany([]))->toBe([]);
});
