<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Providers\StarsPreCheckoutGuard;
use App\Domain\Billing\Services\CheckoutService;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Jobs\HandleTelegramUpdate;
use App\Models\Learner;
use App\Models\PaymentOrder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeTelegramClient;

/*
 * `pre_checkout_query` for Telegram Stars (docs/13 §0, §4.1): StarsPreCheckoutGuard decides from one
 * read, WebhookController answers synchronously — nothing goes to the queue.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-19 12:00:00', 'UTC'));
    Config::set('telegram.url_secret', 'url-secret-value-1234567890');
    Config::set('telegram.header_secret', 'header-secret-value-abcdef');
    config()->set('billing.providers.telegram_stars.enabled', true);
    config()->set('billing.paywall.mode', 'purchase');
    $this->telegram = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->telegram);
    $this->learner = Learner::factory()->create(['interface_locale' => 'uz']);
    $this->order = app(CheckoutService::class)->create($this->learner, Plan::Pro, 'month', SubscriptionProvider::TelegramStars, null, 'twa');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/** @return array<string, mixed> A Telegram `PreCheckoutQuery`. */
function preCheckoutQuery(PaymentOrder $order, int $fromId, ?int $amount = null, string $currency = 'XTR', ?string $payload = null, string $lang = 'ru'): array
{
    return [
        'id' => 'pcq-'.$order->id,
        'from' => ['id' => $fromId, 'first_name' => 'Alice', 'language_code' => $lang],
        'currency' => $currency,
        'total_amount' => $amount ?? $order->amount,
        'invoice_payload' => $payload ?? $order->token,
    ];
}

/** @return array<string, mixed> */
function preCheckoutUpdate(PaymentOrder $order, int $fromId, ?int $amount = null): array
{
    return ['update_id' => 4242, 'pre_checkout_query' => preCheckoutQuery($order, $fromId, $amount)];
}

it('approves the pending, unexpired order of the payer with the right amount and currency', function (): void {
    $verdict = app(StarsPreCheckoutGuard::class)->check(preCheckoutQuery($this->order, $this->learner->telegram_user_id));

    expect($verdict->ok)->toBeTrue()
        ->and($verdict->reason)->toBeNull()
        ->and($verdict->errorMessage)->toBeNull()
        ->and($verdict->paymentOrderId)->toBe($this->order->id)
        ->and($this->order->refresh()->status)->toBe('pending');
});

it('refuses a query from someone else than the order owner, on the payer language, without revealing the order', function (): void {
    Log::spy();

    $verdict = app(StarsPreCheckoutGuard::class)->check(preCheckoutQuery($this->order, $this->learner->telegram_user_id + 1, lang: 'ru'));

    expect($verdict->ok)->toBeFalse()
        ->and($verdict->reason)->toBe('foreign')
        ->and($verdict->errorMessage)->toBe('Этот счёт выставлен другому пользователю. Откройте /pro и создайте свой.');
    Log::shouldHaveReceived('warning')->withArgs(fn (string $m, array $ctx): bool => $m === 'billing.stars.pre_checkout_refused' && $ctx['reason'] === 'foreign')->once();
});

it('refuses an expired order (even before the scheduler flips its status) on the learner locale', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::instance($this->order->expires_at)->addSecond());

    $verdict = app(StarsPreCheckoutGuard::class)->check(preCheckoutQuery($this->order, $this->learner->telegram_user_id));

    expect($verdict->ok)->toBeFalse()
        ->and($verdict->reason)->toBe('order_expired')
        ->and($verdict->errorMessage)->toBe('Hisob muddati tugagan. /pro ni oching va yangisini yarating.');
});

it('refuses a paid, canceled or failed order', function (string $status): void {
    $this->order->update(['status' => $status]);

    $verdict = app(StarsPreCheckoutGuard::class)->check(preCheckoutQuery($this->order, $this->learner->telegram_user_id));

    expect($verdict->ok)->toBeFalse()->and($verdict->reason)->toBe('order_closed');
})->with(['paid', 'canceled', 'failed', 'expired']);

it('refuses a wrong amount or currency', function (): void {
    $guard = app(StarsPreCheckoutGuard::class);

    $less = $guard->check(preCheckoutQuery($this->order, $this->learner->telegram_user_id, amount: $this->order->amount - 1));
    $other = $guard->check(preCheckoutQuery($this->order, $this->learner->telegram_user_id, currency: 'USD'));

    expect($less->ok)->toBeFalse()->and($less->reason)->toBe('amount_mismatch')
        ->and($other->ok)->toBeFalse()->and($other->reason)->toBe('amount_mismatch')
        ->and($less->errorMessage)->toContain('/pro');
});

it('refuses an unknown or malformed payload, an order of another provider and a blocked or deleted learner', function (): void {
    $guard = app(StarsPreCheckoutGuard::class);
    $me = $this->learner->telegram_user_id;

    expect($guard->check(preCheckoutQuery($this->order, $me, payload: 'nope'))->reason)->toBe('order_not_found')
        ->and($guard->check(preCheckoutQuery($this->order, $me, payload: str_repeat('x', 33)))->reason)->toBe('order_not_found')
        ->and($guard->check(['id' => '1', 'from' => ['id' => $me], 'currency' => 'XTR', 'total_amount' => 1])->reason)->toBe('order_not_found');

    $payme = PaymentOrder::factory()->create(['subscriber_id' => $this->learner->id, 'provider' => 'payme', 'currency' => 'UZS', 'amount' => 2_900_000]);
    expect($guard->check(preCheckoutQuery($payme, $me))->reason)->toBe('order_not_found');

    $this->learner->update(['blocked_at' => now()]);
    expect($guard->check(preCheckoutQuery($this->order, $me))->reason)->toBe('blocked');

    $this->learner->update(['blocked_at' => null]);
    $this->learner->delete();
    expect($guard->check(preCheckoutQuery($this->order, $me))->reason)->toBe('blocked');
});

it('refuses when Stars were switched off after the checkout', function (): void {
    config()->set('billing.providers.telegram_stars.enabled', false);

    $verdict = app(StarsPreCheckoutGuard::class)->check(preCheckoutQuery($this->order, $this->learner->telegram_user_id));

    expect($verdict->ok)->toBeFalse()->and($verdict->reason)->toBe('provider_disabled');
});

it('reads one row and writes nothing', function (): void {
    DB::enableQueryLog();

    app(StarsPreCheckoutGuard::class)->check(preCheckoutQuery($this->order, $this->learner->telegram_user_id));

    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($queries)->toHaveCount(1)
        ->and(strtolower((string) $queries[0]['query']))->toStartWith('select');
});

// --- the synchronous webhook branch ------------------------------------------------------------

it('answers a pre_checkout_query synchronously from the webhook and queues nothing', function (): void {
    Queue::fake();

    $this->postJson(
        '/telegram/webhook/url-secret-value-1234567890',
        preCheckoutUpdate($this->order, $this->learner->telegram_user_id),
        ['X-Telegram-Bot-Api-Secret-Token' => 'header-secret-value-abcdef'],
    )->assertOk()->assertJson(['ok' => true]);

    Queue::assertNothingPushed();
    expect($this->telegram->preCheckoutAnswers)->toBe([['id' => 'pcq-'.$this->order->id, 'ok' => true, 'error_message' => null]]);
});

it('answers ok=false with the localized reason for a foreign payer or a wrong amount', function (): void {
    Queue::fake();

    $this->postJson('/telegram/webhook/url-secret-value-1234567890', preCheckoutUpdate($this->order, 1), ['X-Telegram-Bot-Api-Secret-Token' => 'header-secret-value-abcdef'])->assertOk();
    $this->postJson('/telegram/webhook/url-secret-value-1234567890', preCheckoutUpdate($this->order, $this->learner->telegram_user_id, $this->order->amount + 5), ['X-Telegram-Bot-Api-Secret-Token' => 'header-secret-value-abcdef'])->assertOk();

    Queue::assertNothingPushed();
    expect($this->telegram->preCheckoutAnswers)->toHaveCount(2)
        ->and($this->telegram->preCheckoutAnswers[0]['ok'])->toBeFalse()
        ->and($this->telegram->preCheckoutAnswers[0]['error_message'])->toBe('Этот счёт выставлен другому пользователю. Откройте /pro и создайте свой.')
        ->and($this->telegram->preCheckoutAnswers[1]['ok'])->toBeFalse()
        ->and($this->telegram->preCheckoutAnswers[1]['error_message'])->toBe('Hisob summasi buyurtma bilan mos kelmaydi. /pro ni oching va yangisini yarating.');
});

it('still requires both webhook secrets for a pre_checkout_query', function (): void {
    Queue::fake();

    $this->postJson('/telegram/webhook/wrong-secret-with-enough-length', preCheckoutUpdate($this->order, $this->learner->telegram_user_id))->assertNotFound();
    $this->postJson('/telegram/webhook/url-secret-value-1234567890', preCheckoutUpdate($this->order, $this->learner->telegram_user_id), ['X-Telegram-Bot-Api-Secret-Token' => 'bogus'])->assertStatus(403);

    expect($this->telegram->preCheckoutAnswers)->toBe([]);
    Queue::assertNothingPushed();
});

it('refuses with a generic text when the check itself fails, and stays 200 when Telegram does not take the answer', function (): void {
    Queue::fake();
    Log::spy();
    // The guard's single read fails (database down): the first query on payment_orders blows up.
    $failed = false;
    DB::listen(function ($query) use (&$failed): void {
        if (! $failed && str_contains((string) $query->sql, 'payment_orders')) {
            $failed = true;
            throw new RuntimeException('db down');
        }
    });

    $this->postJson('/telegram/webhook/url-secret-value-1234567890', preCheckoutUpdate($this->order, $this->learner->telegram_user_id), ['X-Telegram-Bot-Api-Secret-Token' => 'header-secret-value-abcdef'])->assertOk();

    expect($this->telegram->preCheckoutAnswers)->toBe([['id' => 'pcq-'.$this->order->id, 'ok' => false, 'error_message' => 'Сервис временно недоступен. Попробуйте через минуту.']]);
    Log::shouldHaveReceived('error')->withArgs(fn (string $m): bool => $m === 'billing.stars.pre_checkout_failed')->once();

    $this->telegram->failStars = true;
    $this->postJson('/telegram/webhook/url-secret-value-1234567890', preCheckoutUpdate($this->order, $this->learner->telegram_user_id), ['X-Telegram-Bot-Api-Secret-Token' => 'header-secret-value-abcdef'])->assertOk();
    Log::shouldHaveReceived('warning')->withArgs(fn (string $m): bool => $m === 'billing.stars.pre_checkout_unanswered')->once();
    Queue::assertNothingPushed();
});

it('keeps queueing every other update, a payment message included', function (): void {
    Queue::fake();

    $this->postJson(
        '/telegram/webhook/url-secret-value-1234567890',
        ['update_id' => 1, 'message' => ['chat' => ['id' => 5, 'type' => 'private'], 'from' => ['id' => 5], 'successful_payment' => ['telegram_payment_charge_id' => 'x']]],
        ['X-Telegram-Bot-Api-Secret-Token' => 'header-secret-value-abcdef'],
    )->assertOk();

    Queue::assertPushedOn('high', HandleTelegramUpdate::class);
    expect($this->telegram->preCheckoutAnswers)->toBe([]);
});
