<?php

declare(strict_types=1);

use App\Domain\Billing\Providers\Payme\PaymeAuth;
use App\Http\Controllers\Billing\PaymeWebhookController;
use App\Http\Middleware\BillingIpAllowlist;
use App\Models\Payment;
use App\Models\PaymentOrder;
use App\Models\ProviderTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Support\PaymeFixtures;

/*
 * `POST /billing/webhook/payme` on the wire (docs/13 §4.2, §10; docs/04 D.1): Basic auth with the
 * production / test key (`hash_equals`), HTTP 200 + JSON-RPC error on every protocol failure,
 * parse / size limits, the optional caller allowlist, no CSRF / session, and one full
 * Create -> Perform -> Check round trip from the fixtures. Also the public `/billing/return` page.
 */

uses(RefreshDatabase::class);

const PAYME_LIVE_KEY = 'live-merchant-key-0123456789abcdef';
const PAYME_TEST_KEY = 'test-merchant-key-fedcba9876543210';

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-19 12:00:00', 'UTC'));
    config()->set('billing.providers.payme.enabled', true);
    config()->set('billing.providers.payme.key', PAYME_LIVE_KEY);
    config()->set('billing.providers.payme.test_key', PAYME_TEST_KEY);
    config()->set('billing.providers.payme.ip_allowlist', '');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * POST the JSON body to the Payme webhook; `$authorization` null = no header, 'LIVE' = the production key.
 *
 * @return TestResponse
 */
function paymePost(array $body, ?string $authorization = 'LIVE', array $server = [])
{
    $headers = ['Accept' => 'application/json'];
    if ($authorization !== null) {
        $headers['Authorization'] = $authorization === 'LIVE' ? PaymeFixtures::basic(PAYME_LIVE_KEY) : $authorization;
    }

    return test()->call('POST', '/billing/webhook/payme', [], [], [], array_merge(test()->transformHeadersToServerVars($headers), ['CONTENT_TYPE' => 'application/json'], $server), json_encode($body, JSON_THROW_ON_ERROR));
}

// --- authentication ------------------------------------------------------------------------------

it('answers a missing, malformed or wrong Basic header with HTTP 200 and -32504, echoing the request id', function (?string $authorization): void {
    Log::spy();
    $order = PaymentOrder::factory()->payme()->create();

    $response = paymePost(PaymeFixtures::for('CheckPerformTransaction', $order), $authorization);

    $response->assertOk()->assertExactJson([
        'jsonrpc' => '2.0',
        'id' => 1,
        'error' => ['code' => -32504, 'message' => ['ru' => 'Недостаточно привилегий для выполнения метода.', 'uz' => 'Metodni bajarish uchun huquq yetarli emas.', 'en' => 'Insufficient privileges to perform this method.']],
    ]);
    expect(ProviderTransaction::query()->count())->toBe(0);
    Log::shouldHaveReceived('warning')->with('billing.payme.auth_failed', Mockery::type('array'))->once();
})->with([
    'no header' => [null],
    'bearer' => ['Bearer '.PAYME_LIVE_KEY],
    'not base64' => ['Basic %%%'],
    'no colon' => ['Basic '.base64_encode('Paycom')],
    'wrong login' => [PaymeFixtures::basic(PAYME_LIVE_KEY, 'Admin')],
    'wrong key' => [PaymeFixtures::basic('wrong-key')],
    'empty key' => [PaymeFixtures::basic('')],
    'key as login' => [PaymeFixtures::basic('Paycom', PAYME_LIVE_KEY)],
]);

it('accepts the production key and the test key, and nothing when no key is configured', function (): void {
    $order = PaymentOrder::factory()->payme()->create();

    paymePost(PaymeFixtures::for('CheckPerformTransaction', $order), PaymeFixtures::basic(PAYME_LIVE_KEY))->assertOk()->assertJsonPath('result.allow', true);
    paymePost(PaymeFixtures::for('CheckPerformTransaction', $order), PaymeFixtures::basic(PAYME_TEST_KEY))->assertOk()->assertJsonPath('result.allow', true);

    config()->set('billing.providers.payme.test_key', '');
    paymePost(PaymeFixtures::for('CheckPerformTransaction', $order), PaymeFixtures::basic(PAYME_TEST_KEY))->assertOk()->assertJsonPath('error.code', -32504);

    config()->set('billing.providers.payme.key', '');
    paymePost(PaymeFixtures::for('CheckPerformTransaction', $order), PaymeFixtures::basic(PAYME_LIVE_KEY))->assertOk()->assertJsonPath('error.code', -32504);
    paymePost(PaymeFixtures::for('CheckPerformTransaction', $order), PaymeFixtures::basic(''))->assertOk()->assertJsonPath('error.code', -32504);
});

it('tells the live key from the test key with hash_equals semantics', function (): void {
    $auth = new PaymeAuth(PAYME_LIVE_KEY, PAYME_TEST_KEY);

    expect($auth->verify(PaymeFixtures::basic(PAYME_LIVE_KEY)))->toBe('live')
        ->and($auth->verify(PaymeFixtures::basic(PAYME_TEST_KEY)))->toBe('test')
        ->and($auth->verify('basic '.base64_encode('Paycom:'.PAYME_LIVE_KEY)))->toBe('live')
        ->and($auth->verify(PaymeFixtures::basic(substr(PAYME_LIVE_KEY, 0, -1))))->toBeNull()
        ->and($auth->verify(PaymeFixtures::basic(PAYME_LIVE_KEY.'x')))->toBeNull()
        ->and($auth->verify(PaymeFixtures::basic(PAYME_LIVE_KEY, 'paycom')))->toBeNull()
        ->and($auth->verify(null))->toBeNull()
        ->and((new PaymeAuth('', ''))->verify(PaymeFixtures::basic('')))->toBeNull();
});

// --- protocol ------------------------------------------------------------------------------------

it('answers invalid JSON with -32700, an oversized body with -32600 and an unknown method with -32601, all HTTP 200', function (): void {
    $bad = test()->call('POST', '/billing/webhook/payme', [], [], [], array_merge(test()->transformHeadersToServerVars(['Authorization' => PaymeFixtures::basic(PAYME_LIVE_KEY), 'Accept' => 'application/json']), ['CONTENT_TYPE' => 'application/json']), '{"jsonrpc": "2.0", "id": 5, "method": ');
    $bad->assertOk()->assertJsonPath('error.code', -32700)->assertJsonPath('id', null);

    $huge = test()->call('POST', '/billing/webhook/payme', [], [], [], array_merge(test()->transformHeadersToServerVars(['Authorization' => PaymeFixtures::basic(PAYME_LIVE_KEY), 'Accept' => 'application/json']), ['CONTENT_TYPE' => 'application/json']), json_encode(['jsonrpc' => '2.0', 'id' => 6, 'method' => 'CheckTransaction', 'params' => ['id' => 'x', 'pad' => str_repeat('a', PaymeWebhookController::MAX_BODY_BYTES)]], JSON_THROW_ON_ERROR));
    $huge->assertOk()->assertJsonPath('error.code', -32600);

    paymePost(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'SetFiscalData', 'params' => []])->assertOk()->assertJsonPath('error.code', -32601)->assertJsonPath('id', 7);

    // Unauthenticated callers learn nothing about the body: auth is checked before parsing.
    test()->call('POST', '/billing/webhook/payme', [], [], [], array_merge(test()->transformHeadersToServerVars(['Accept' => 'application/json']), ['CONTENT_TYPE' => 'application/json']), 'not json')
        ->assertOk()->assertJsonPath('error.code', -32504);
});

it('answers an unexpected failure with HTTP 500 and -32400 so that Payme retries', function (): void {
    Log::spy();
    $this->withoutExceptionHandling();
    // An infrastructure failure, not a protocol one: the table is gone (rolled back with the test transaction; a rename
    // keeps the FK from `payments` intact on PostgreSQL).
    Schema::rename('provider_transactions', 'provider_transactions_gone');

    paymePost(PaymeFixtures::rpc('CheckTransaction', ['id' => 'any']))
        ->assertStatus(500)
        ->assertJsonPath('error.code', -32400)
        ->assertJsonPath('id', 5);
    Log::shouldHaveReceived('error')->with('billing.payme.rpc_failed', Mockery::type('array'))->once();
});

// --- end to end ----------------------------------------------------------------------------------

it('runs CheckPerform -> Create -> Perform -> Check -> GetStatement from the fixtures over HTTP, crediting the order once', function (): void {
    $order = PaymentOrder::factory()->payme()->create();
    $time = CarbonImmutable::now()->subSeconds(10)->getTimestampMs();

    paymePost(PaymeFixtures::for('CheckPerformTransaction', $order))->assertOk()->assertExactJson(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['allow' => true]]);

    $create = paymePost(PaymeFixtures::for('CreateTransaction', $order, ['time' => $time]))->assertOk()->assertJsonPath('result.state', 1)->assertJsonPath('result.create_time', $time)->json('result');

    $perform = paymePost(PaymeFixtures::for('PerformTransaction', $order))->assertOk()->assertJsonPath('result.state', 2)->assertJsonPath('result.transaction', $create['transaction'])->json('result');
    paymePost(PaymeFixtures::for('PerformTransaction', $order))->assertOk()->assertJsonPath('result.perform_time', $perform['perform_time']);

    paymePost(PaymeFixtures::for('CheckTransaction', $order))->assertOk()->assertExactJson(['jsonrpc' => '2.0', 'id' => 5, 'result' => [
        'create_time' => $time, 'perform_time' => $perform['perform_time'], 'cancel_time' => 0, 'transaction' => $create['transaction'], 'state' => 2, 'reason' => null,
    ]]);

    paymePost(PaymeFixtures::rpc('GetStatement', ['from' => $time - 1, 'to' => $time + 1]))->assertOk()->assertJsonCount(1, 'result.transactions')->assertJsonPath('result.transactions.0.id', PaymeFixtures::TRANSACTION_ID);

    expect(Payment::query()->where('status', 'succeeded')->count())->toBe(1)
        ->and($order->refresh()->status)->toBe('paid');

    // Wrong amount on the wire: -31001 and nothing stored.
    $other = PaymentOrder::factory()->payme()->create();
    paymePost(PaymeFixtures::for('CreateTransaction', $other, ['amount' => $other->amount + 1], 'wire-tx-2'))->assertOk()->assertJsonPath('error.code', -31001);
    expect(ProviderTransaction::query()->count())->toBe(1);
});

// --- transport protections -----------------------------------------------------------------------

it('is stateless (no CSRF, no session) and throttled per IP behind the optional allowlist', function (): void {
    $route = Route::getRoutes()->getByName('billing.webhook.payme');

    expect($route)->not->toBeNull()
        ->and($route?->methods())->toBe(['POST'])
        ->and($route?->uri())->toBe('billing/webhook/payme')
        ->and($route?->gatherMiddleware())->toContain('throttle:billing-webhook')
        ->and($route?->gatherMiddleware())->toContain(BillingIpAllowlist::class.':payme')
        ->and($route?->gatherMiddleware())->not->toContain(StartSession::class)
        ->and($route?->gatherMiddleware())->not->toContain(VerifyCsrfToken::class);

    $return = Route::getRoutes()->getByName('billing.return');
    expect($return?->gatherMiddleware())->toContain('throttle:billing-return')
        ->and($return?->gatherMiddleware())->not->toContain(StartSession::class);
});

it('refuses callers outside PAYME_IP_ALLOWLIST with 403 before authentication, and everyone when the list is empty passes', function (): void {
    $order = PaymentOrder::factory()->payme()->create();
    $body = PaymeFixtures::for('CheckPerformTransaction', $order);

    config()->set('billing.providers.payme.ip_allowlist', '185.8.212.0/24, 10.0.0.5');
    expect(BillingIpAllowlist::cidrs('payme'))->toBe(['185.8.212.0/24', '10.0.0.5']);

    paymePost($body, PaymeFixtures::basic(PAYME_LIVE_KEY), ['REMOTE_ADDR' => '203.0.113.9'])->assertForbidden();
    paymePost($body, null, ['REMOTE_ADDR' => '203.0.113.9'])->assertForbidden();
    paymePost($body, PaymeFixtures::basic(PAYME_LIVE_KEY), ['REMOTE_ADDR' => '185.8.212.77'])->assertOk()->assertJsonPath('result.allow', true);
    paymePost($body, PaymeFixtures::basic(PAYME_LIVE_KEY), ['REMOTE_ADDR' => '10.0.0.5'])->assertOk()->assertJsonPath('result.allow', true);

    config()->set('billing.providers.payme.ip_allowlist', '');
    paymePost($body, PaymeFixtures::basic(PAYME_LIVE_KEY), ['REMOTE_ADDR' => '203.0.113.9'])->assertOk()->assertJsonPath('result.allow', true);
});

// --- /billing/return -----------------------------------------------------------------------------

it('shows the order status on the return page in the requested or the order language, without caching or indexing', function (): void {
    config()->set('telegram.bot_username', 'lexiflow_bot');
    $paid = PaymentOrder::factory()->payme()->paid()->create(['meta' => ['learner_locale' => 'uz']]);
    $pending = PaymentOrder::factory()->payme()->create(['meta' => ['learner_locale' => 'ru']]);
    $expired = PaymentOrder::factory()->payme()->expired()->create();
    $canceled = PaymentOrder::factory()->payme()->create(['status' => PaymentOrder::STATUS_CANCELED]);
    $failed = PaymentOrder::factory()->payme()->create(['status' => PaymentOrder::STATUS_FAILED]);

    // Explicit lang wins.
    $this->get('/billing/return?order='.$paid->token.'&lang=ru')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('X-Robots-Tag', 'noindex')
        ->assertSee('<html lang="ru">', false)
        ->assertSee('Оплата получена')
        ->assertSee('https://t.me/lexiflow_bot')
        ->assertSee('data-status="paid"', false)
        ->assertDontSee($paid->token);

    // Otherwise the order's learner locale.
    $this->get('/billing/return?order='.$paid->token)->assertOk()->assertSee('<html lang="uz">', false)->assertSee("To'lov qabul qilindi");
    $this->get('/billing/return?order='.$pending->token)->assertOk()->assertSee('Ждём подтверждение оплаты')->assertSee('data-status="pending"', false);
    $this->get('/billing/return?order='.$expired->token.'&lang=uz')->assertOk()->assertSee("Hisob muddati o'tdi");
    $this->get('/billing/return?order='.$canceled->token.'&lang=ru')->assertOk()->assertSee('Счёт отменён');
    $this->get('/billing/return?order='.$failed->token.'&lang=ru')->assertOk()->assertSee('data-status="unknown"', false);

    // Unknown / missing token: a neutral page, Accept-Language decides the language.
    $this->get('/billing/return?order=nope', ['Accept-Language' => 'uz-UZ,ru;q=0.8'])->assertOk()->assertSee("To'lov holati noma'lum");
    $this->get('/billing/return', ['Accept-Language' => 'en'])->assertOk()->assertSee('Статус оплаты неизвестен');
    $this->get('/billing/return?order[]=x&lang[]=y')->assertOk()->assertSee('data-status="unknown"', false);
});
