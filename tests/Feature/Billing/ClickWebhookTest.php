<?php

declare(strict_types=1);

use App\Http\Controllers\Billing\ClickWebhookController;
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
use Tests\Support\ClickFixtures;

/*
 * `POST /billing/webhook/click/prepare` and `/complete` on the wire (docs/13 §4.3, §10; docs/04 D.2):
 * form-encoded, signed with the test secret, HTTP 200 + `error` on every protocol failure, size limit,
 * HTTP 500 + -7 on an infrastructure failure, the optional caller allowlist, no CSRF / session, and one
 * full Prepare -> Complete round trip from the fixtures. Also the `/billing/return` page for a Click order.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-19 12:00:00', 'UTC'));
    ClickFixtures::configure();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * POST a form to one of the Click endpoints.
 *
 * @param  array<string, mixed>  $form
 * @param  array<string, mixed>  $server
 * @return TestResponse
 */
function clickPost(string $action, array $form, array $server = [])
{
    return test()->call('POST', "/billing/webhook/click/{$action}", $form, [], [], array_merge(test()->transformHeadersToServerVars(['Accept' => 'application/json']), $server));
}

it('runs Prepare -> Complete from the fixtures over HTTP as form data, crediting the order once', function (): void {
    $order = PaymentOrder::factory()->click()->create();

    $prepare = clickPost('prepare', ClickFixtures::prepare($order))->assertOk()->assertJsonPath('error', 0)->assertJsonPath('error_note', 'Success')->json();
    $transaction = ProviderTransaction::query()->sole();
    expect($prepare['merchant_prepare_id'])->toBe($transaction->id)
        ->and($prepare['click_trans_id'])->toBe(1234567890)
        ->and($prepare['merchant_trans_id'])->toBe($order->token);

    $complete = clickPost('complete', ClickFixtures::complete($order, $transaction->id))->assertOk()->assertJsonPath('error', 0)->json();
    $payment = Payment::query()->sole();
    expect($complete['merchant_confirm_id'])->toBe($payment->id)
        ->and($order->refresh()->status)->toBe('paid');

    clickPost('complete', ClickFixtures::complete($order, $transaction->id))->assertOk()->assertExactJson($complete);
    expect(Payment::query()->count())->toBe(1);

    // Wrong signature on the wire: HTTP 200, -1, nothing stored.
    $other = PaymentOrder::factory()->click()->create();
    clickPost('prepare', ClickFixtures::prepare($other, after: ['sign_string' => md5('x')]))->assertOk()->assertExactJson([
        'click_trans_id' => 1234567890,
        'merchant_trans_id' => $other->token,
        'merchant_prepare_id' => null,
        'error' => -1,
        'error_note' => 'SIGN CHECK FAILED!',
    ]);
    expect(ProviderTransaction::query()->count())->toBe(1);
});

it('reads only the form body: a JSON body or query-string parameters are an error in the request', function (): void {
    $order = PaymentOrder::factory()->click()->create();
    $form = ClickFixtures::prepare($order);

    test()->call('POST', '/billing/webhook/click/prepare', [], [], [], array_merge(test()->transformHeadersToServerVars(['Accept' => 'application/json']), ['CONTENT_TYPE' => 'application/json']), json_encode($form, JSON_THROW_ON_ERROR))
        ->assertOk()->assertJsonPath('error', -8);
    test()->call('POST', '/billing/webhook/click/prepare?'.http_build_query($form), [], [], [], test()->transformHeadersToServerVars(['Accept' => 'application/json']))
        ->assertOk()->assertJsonPath('error', -8);

    // The size limit reads the raw body, so the oversized form is sent as raw content (as PHP-FPM would see it).
    $huge = test()->call('POST', '/billing/webhook/click/prepare', [], [], [], array_merge(test()->transformHeadersToServerVars(['Accept' => 'application/json']), ['CONTENT_TYPE' => 'application/x-www-form-urlencoded']), http_build_query([...$form, 'pad' => str_repeat('a', ClickWebhookController::MAX_BODY_BYTES)]));
    $huge->assertOk()->assertJsonPath('error', -8)->assertJsonPath('click_trans_id', null);

    expect(ProviderTransaction::query()->count())->toBe(0);
});

it('answers an unexpected failure with HTTP 500 and -7 so that Click retries', function (): void {
    Log::spy();
    $this->withoutExceptionHandling();
    $order = PaymentOrder::factory()->click()->create();
    $form = ClickFixtures::complete($order, 1);
    // An infrastructure failure, not a protocol one: the table is gone (rolled back with the test transaction).
    Schema::rename('provider_transactions', 'provider_transactions_gone');

    clickPost('complete', $form)
        ->assertStatus(500)
        ->assertJsonPath('error', -7)
        ->assertJsonPath('error_note', 'Failed to update user')
        ->assertJsonPath('click_trans_id', 1234567890)
        ->assertJsonPath('merchant_trans_id', $order->token)
        ->assertJsonPath('merchant_confirm_id', null);
    Log::shouldHaveReceived('error')->with('billing.click.webhook_failed', Mockery::type('array'))->once();
});

it('is stateless (no CSRF, no session) and throttled per IP behind the optional allowlist', function (): void {
    foreach (['prepare', 'complete'] as $action) {
        $route = Route::getRoutes()->getByName("billing.webhook.click.{$action}");

        expect($route)->not->toBeNull()
            ->and($route?->methods())->toBe(['POST'])
            ->and($route?->uri())->toBe("billing/webhook/click/{$action}")
            ->and($route?->gatherMiddleware())->toContain('throttle:billing-webhook')
            ->and($route?->gatherMiddleware())->toContain(BillingIpAllowlist::class.':click')
            ->and($route?->gatherMiddleware())->not->toContain(StartSession::class)
            ->and($route?->gatherMiddleware())->not->toContain(VerifyCsrfToken::class);
    }
});

it('refuses callers outside CLICK_IP_ALLOWLIST with 403 before the signature, and everyone passes when the list is empty', function (): void {
    $order = PaymentOrder::factory()->click()->create();
    $form = ClickFixtures::prepare($order);

    config()->set('billing.providers.click.ip_allowlist', '213.230.96.0/24, 10.0.0.5');
    expect(BillingIpAllowlist::cidrs('click'))->toBe(['213.230.96.0/24', '10.0.0.5']);

    clickPost('prepare', $form, ['REMOTE_ADDR' => '203.0.113.9'])->assertForbidden();
    clickPost('complete', ClickFixtures::complete($order, 1), ['REMOTE_ADDR' => '203.0.113.9'])->assertForbidden();
    clickPost('prepare', ClickFixtures::prepare($order, after: ['sign_string' => md5('x')]), ['REMOTE_ADDR' => '203.0.113.9'])->assertForbidden();
    clickPost('prepare', $form, ['REMOTE_ADDR' => '213.230.96.7'])->assertOk()->assertJsonPath('error', 0);
    clickPost('prepare', $form, ['REMOTE_ADDR' => '10.0.0.5'])->assertOk()->assertJsonPath('error', 0);

    config()->set('billing.providers.click.ip_allowlist', '');
    clickPost('prepare', $form, ['REMOTE_ADDR' => '203.0.113.9'])->assertOk()->assertJsonPath('error', 0);
    expect(ProviderTransaction::query()->count())->toBe(1);
});

it('shows a Click order on the return page in the order language, without naming the provider', function (): void {
    config()->set('telegram.bot_username', 'lexiflow_bot');
    $pending = PaymentOrder::factory()->click()->create(['meta' => ['learner_locale' => 'uz']]);
    $paid = PaymentOrder::factory()->click()->paid()->create();
    $canceled = PaymentOrder::factory()->click()->create(['status' => PaymentOrder::STATUS_CANCELED]);

    $this->get('/billing/return?order='.$pending->token)->assertOk()->assertSee('<html lang="uz">', false)->assertSee('data-status="pending"', false)->assertDontSee('Payme')->assertDontSee($pending->token);
    $this->get('/billing/return?order='.$paid->token.'&lang=ru')->assertOk()->assertSee('Оплата получена')->assertDontSee('Payme');
    $this->get('/billing/return?order='.$canceled->token.'&lang=ru')->assertOk()->assertSee('Счёт отменён');
});
