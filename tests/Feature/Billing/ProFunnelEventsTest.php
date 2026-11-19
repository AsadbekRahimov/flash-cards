<?php

declare(strict_types=1);

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Domain\Twa\Services\JwtService;
use App\Models\Learner;
use App\Models\PaymentOrder;
use App\Models\ProductEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeTelegramClient;

/*
 * The props of the Pro funnel on the money events (docs/13 §5.1), Telegram Stars end to end: the TWA checkout with the real Stars
 * provider (FakeTelegramClient for the Bot API), then `successful_payment` through the bot. `checkout.started` carries the learner's
 * `locale` and the optional `placement`; `payment.succeeded` carries the `locale`.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-19 12:00:00', 'UTC'));
    config()->set('billing.providers.telegram_stars.enabled', true);
    config()->set('billing.paywall.mode', 'purchase');
    config()->set('twa.base_url', 'https://lexiflow.test');
    $this->telegram = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->telegram);
    $this->learner = Learner::factory()->create(['interface_locale' => 'uz', 'timezone' => 'Asia/Tashkent']);
    $this->headers = ['Authorization' => 'Bearer '.app(JwtService::class)->issue($this->learner->id)['token']];
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/** @return array<string, mixed> `successful_payment` of the first charge of a Stars subscription (Bot API field names). */
function funnelStarsPaid(PaymentOrder $order, int $fromId): array
{
    return ['update_id' => 7, 'message' => [
        'message_id' => 907,
        'date' => CarbonImmutable::now()->getTimestamp(),
        'chat' => ['id' => $fromId, 'type' => 'private'],
        'from' => ['id' => $fromId, 'first_name' => 'Alice', 'language_code' => 'uz'],
        'successful_payment' => [
            'currency' => 'XTR',
            'total_amount' => $order->amount,
            'invoice_payload' => $order->token,
            'telegram_payment_charge_id' => 'ch_funnel',
            'provider_payment_charge_id' => 'prov_ch_funnel',
            'subscription_expiration_date' => CarbonImmutable::now()->addDays(30)->getTimestamp(),
            'is_recurring' => true,
            'is_first_recurring' => true,
        ],
    ]];
}

function funnelEvent(ProductEventName $name): ProductEvent
{
    return ProductEvent::query()->where('name', $name->value)->sole();
}

it('writes the locale and the placement into checkout.started of a Stars checkout, then the locale into payment.succeeded', function (): void {
    $token = $this->withHeaders($this->headers)
        ->postJson('/api/twa/billing/checkout', ['plan' => 'pro', 'period' => 'month', 'provider' => 'telegram_stars', 'placement' => 'paywall_quota'])
        ->assertCreated()
        ->json('order.token');

    $started = funnelEvent(ProductEventName::CheckoutStarted);
    expect($started->learner_id)->toBe($this->learner->id)
        ->and($started->props)->toMatchArray(['provider' => 'telegram_stars', 'via' => 'twa', 'locale' => 'uz', 'placement' => 'paywall_quota']);

    // The learner switches the language between the start and the payment: each event keeps its own snapshot.
    $this->learner->forceFill(['interface_locale' => 'ru'])->save();
    $order = PaymentOrder::query()->where('token', $token)->sole();
    app(TelegramDispatcher::class)->dispatch(funnelStarsPaid($order, (int) $this->learner->telegram_user_id));

    $paid = funnelEvent(ProductEventName::PaymentSucceeded);
    expect($order->refresh()->status)->toBe('paid')
        ->and($paid->learner_id)->toBe($this->learner->id)
        ->and($paid->props)->toMatchArray(['provider' => 'telegram_stars', 'kind' => 'charge', 'payment_order_id' => $order->id, 'locale' => 'ru'])
        ->and($started->refresh()->props['locale'])->toBe('uz');

    // No PII on either event.
    foreach ([$started, $paid] as $event) {
        expect(array_keys($event->props))->not->toContain('first_name', 'username', 'telegram_user_id', 'payer_name');
    }
});

it('leaves the placement null when the client names none (an older client, the bot)', function (): void {
    $this->withHeaders($this->headers)
        ->postJson('/api/twa/billing/checkout', ['plan' => 'pro', 'period' => 'month', 'provider' => 'telegram_stars'])
        ->assertCreated();

    $props = funnelEvent(ProductEventName::CheckoutStarted)->props;
    expect($props)->toHaveKey('placement')
        ->and($props['placement'])->toBeNull()
        ->and($props['locale'])->toBe('uz');
});

it('refuses a placement outside the closed list with 422 and starts nothing', function (mixed $placement): void {
    $this->withHeaders($this->headers)
        ->postJson('/api/twa/billing/checkout', ['plan' => 'pro', 'period' => 'month', 'provider' => 'telegram_stars', 'placement' => $placement])
        ->assertStatus(422)
        ->assertJsonValidationErrors('placement');

    expect(PaymentOrder::query()->count())->toBe(0)
        ->and(ProductEvent::query()->where('name', ProductEventName::CheckoutStarted->value)->count())->toBe(0);
})->with(['unknown' => ['somewhere'], 'array' => [['paywall_quota']], 'number' => [5]]);
