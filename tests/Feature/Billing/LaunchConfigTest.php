<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Exceptions\CheckoutException;
use App\Domain\Billing\Services\CheckoutService;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Services\OrgBillingService;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Domain\Twa\Services\JwtService;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * The configuration of the launch (owner's decision of 03.11.2026): sales only through Telegram Stars, Payme and Click
 * off until the contracts, practice and battles open to everybody with the Free G1 limits. A learner is offered only what
 * can really be bought: Pro for Stars, never Pro for sums. The REAL adapters are bound (no fakes): the decision is config.
 */

beforeEach(function (): void {
    config()->set('billing.paywall.mode', 'purchase');
    config()->set('billing.paywall.contact', '@lexiflow_support');
    config()->set('billing.providers.telegram_stars.enabled', true);
    config()->set('billing.providers.payme.enabled', false);
    config()->set('billing.providers.click.enabled', false);
    config()->set('billing.prices.pro', [
        'month' => ['XTR' => 150, 'UZS' => 2_900_000],
        'year' => ['XTR' => 1_300, 'UZS' => 24_900_000],
    ]);
    config()->set('practice.access', 'all');
    config()->set('practice.plan', 'all');
    config()->set('practice.battle.enabled', true);
    config()->set('practice.free_limits', ['sessions_per_day' => 2, 'battles_per_day' => 1]);
    config()->set('telegram.bot_username', 'lexiflow_test_bot');
    config()->set('app.url', 'https://lexiflow.test');
});

// --- landing -----------------------------------------------------------------------

it('the landing shows the Stars price only, no sums, and the practice tick of Pro', function (string $url, string $price, string $practice): void {
    $html = html_entity_decode($this->get($url)->assertOk()->getContent(), ENT_QUOTES);
    $text = str_replace("\u{00A0}", ' ', $html);

    expect($text)->toContain($price)
        ->and($text)->toContain($practice)
        ->and($text)->not->toMatch('/\d\s*(сум|so\'m)/u')
        ->and($text)->not->toContain('Payme')
        ->and($text)->not->toContain('Click');
})->with([
    'ru' => ['/ru', 'от 150 ⭐ в месяц', 'Практика и баттлы без дневного лимита'],
]);

it('the landing quotes the Pro practice tick in Uzbek too, and nothing in sums', function (): void {
    $text = str_replace("\u{00A0}", ' ', html_entity_decode($this->get('/uz')->assertOk()->getContent(), ENT_QUOTES));

    expect($text)->toContain('150 ⭐')->and($text)->not->toContain("so'm")->and($text)->not->toContain('29 000');
});

// --- Mini App: /billing/catalog and /me.paywall -----------------------------------------

it('the Mini App catalog lists Stars only, with no sum prices', function (): void {
    $learner = Learner::factory()->create();
    $headers = ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];

    $this->withHeaders($headers)->getJson('/api/twa/billing/catalog')
        ->assertOk()
        ->assertJsonPath('providers', ['telegram_stars'])
        ->assertJsonPath('items.0.period', 'month')
        ->assertJsonPath('items.0.prices', ['XTR' => 150])
        ->assertJsonPath('items.0.list_prices', ['XTR' => 150])
        ->assertJsonPath('items.1.prices', ['XTR' => 1_300])
        ->assertJsonMissingPath('items.0.prices.UZS');

    $this->withHeaders($headers)->getJson('/api/twa/me')
        ->assertOk()
        ->assertJsonPath('paywall.mode', 'purchase')
        ->assertJsonPath('practice.available', true)
        ->assertJsonPath('practice.pro_promise', 'both')
        ->assertJsonPath('practice.limits.sessions.limit', 2)
        ->assertJsonPath('practice.limits.battles.limit', 1);
});

it('a Pro purchase for sums is refused while Payme and Click are off', function (SubscriptionProvider $provider): void {
    $learner = Learner::factory()->create();

    expect(fn () => app(CheckoutService::class)->create($learner, Plan::Pro, 'month', $provider, null, CheckoutService::VIA_TWA))
        ->toThrow(CheckoutException::class);
})->with([SubscriptionProvider::Payme, SubscriptionProvider::Click]);

it('the paywall falls back to coming_soon when only the sum prices are set (Stars unpriced)', function (): void {
    config()->set('billing.prices.pro', ['month' => ['XTR' => 0, 'UZS' => 2_900_000], 'year' => ['XTR' => 0, 'UZS' => 24_900_000]]);
    $learner = Learner::factory()->create();
    $headers = ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];

    $this->withHeaders($headers)->getJson('/api/twa/billing/catalog')->assertOk()->assertJsonPath('items', []);
    $this->withHeaders($headers)->getJson('/api/twa/me')->assertJsonPath('paywall.mode', 'coming_soon');
});

// --- bot: /pro ------------------------------------------------------------------------

it('/pro offers Stars buttons only, with no sum price', function (): void {
    $sent = [];
    $api = Mockery::mock(TelegramClient::class);
    $api->shouldReceive('sendMessage')->andReturnUsing(function (int|string $chat, string $text, ?string $parse = null, ?array $markup = null) use (&$sent): void {
        $sent[] = ['text' => $text, 'markup' => $markup];
    });
    $this->app->instance(TelegramClient::class, $api);

    app(TelegramDispatcher::class)->dispatch(['message' => [
        'chat' => ['id' => 555, 'type' => 'private'],
        'from' => ['id' => 555, 'first_name' => 'Alice', 'language_code' => 'ru'],
        'text' => '/pro',
    ]]);

    $callbacks = array_map(static fn (array $row): string => $row[0]['callback_data'], $sent[0]['markup']['inline_keyboard']);

    expect($callbacks)->toBe(['pro1:buy:month:telegram_stars', 'pro1:buy:year:telegram_stars'])
        ->and($sent[0]['text'])->toContain('1 месяц — 150 ⭐')
        ->and($sent[0]['text'])->not->toContain('сум');
});

// --- /org: Billing ---------------------------------------------------------------------

it('/org Billing has no online payment, but the owner can still request an invoice', function (): void {
    config()->set('billing.invoice.request_email', 'billing@lexiflow.test');
    $org = Organization::factory()->create();
    $owner = User::factory()->create(['is_active' => true]);
    OrganizationUser::factory()->create(['organization_id' => $org->id, 'user_id' => $owner->id, 'role' => OrganizationRole::Owner->value]);

    $page = app(OrgBillingService::class)->page($owner, $org);

    expect($page->providers)->toBe([])
        ->and($page->canRequestInvoice)->toBeTrue();
});
