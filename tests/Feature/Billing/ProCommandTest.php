<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Services\CheckoutService;
use App\Domain\Billing\Services\PaymentApplier;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Models\Learner;
use App\Models\Offer;
use App\Models\PaymentOrder;
use App\Models\ProviderTransaction;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeBillingProvider;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->sent = [];
    $api = Mockery::mock(TelegramClient::class);
    $api->shouldReceive('sendMessage')->andReturnUsing(function (int|string $chat, string $text, ?string $parse = null, ?array $markup = null): void {
        $this->sent[] = ['chat' => $chat, 'text' => $text, 'markup' => $markup];
    });
    $api->shouldReceive('answerCallbackQuery')->andReturnNull();
    $this->app->instance(TelegramClient::class, $api);

    $this->stars = FakeBillingProvider::register(SubscriptionProvider::TelegramStars);
    $this->payme = FakeBillingProvider::register(SubscriptionProvider::Payme);
    config()->set('billing.paywall.mode', 'purchase');
    config()->set('billing.paywall.contact', '@lexiflow_support');
    config()->set('app.url', 'https://lexiflow.test');
});

/** @return array<string, mixed> */
function proMessage(string $text, string $chatType = 'private', int $chatId = 555, string $lang = 'ru'): array
{
    return ['message' => [
        'chat' => ['id' => $chatId, 'type' => $chatType],
        'from' => ['id' => 555, 'first_name' => 'Alice', 'language_code' => $lang],
        'text' => $text,
    ]];
}

/** @return array<string, mixed> */
function proTap(string $data, string $chatType = 'private', int $chatId = 555, string $lang = 'ru'): array
{
    return ['callback_query' => [
        'id' => 'cb1',
        'from' => ['id' => 555, 'first_name' => 'Alice', 'language_code' => $lang],
        'message' => ['message_id' => 10, 'chat' => ['id' => $chatId, 'type' => $chatType]],
        'data' => $data,
    ]];
}

/** @return list<string> */
function callbacks(?array $markup): array
{
    return array_values(array_map(
        static fn (array $row): string => (string) ($row[0]['callback_data'] ?? ''),
        $markup['inline_keyboard'] ?? [],
    ));
}

it('/pro says Pro is coming, with the contact, while nothing is for sale', function (): void {
    config()->set('billing.paywall.mode', 'coming_soon');

    app(TelegramDispatcher::class)->dispatch(proMessage('/pro'));

    expect($this->sent)->toHaveCount(1)
        ->and($this->sent[0]['chat'])->toBe(555)
        ->and($this->sent[0]['text'])->toContain('Сейчас у вас тариф Free: 20 новых слов в день')
        ->and($this->sent[0]['text'])->toContain('LexiFlow Pro скоро откроется. Есть промокод? Отправьте /promo КОД.')
        ->and($this->sent[0]['text'])->toContain('@lexiflow_support')
        ->and($this->sent[0]['markup'])->toBeNull();
});

it('/pro offers a button per period and provider with the prices, none of them creating an order', function (): void {
    app(TelegramDispatcher::class)->dispatch(proMessage('/pro'));

    expect(callbacks($this->sent[0]['markup']))->toBe([
        'pro1:buy:month:telegram_stars', 'pro1:buy:month:payme', 'pro1:buy:year:telegram_stars', 'pro1:buy:year:payme',
    ])
        ->and($this->sent[0]['text'])->toContain('1 месяц — 150 ⭐ / 29 000 сум')
        ->and($this->sent[0]['text'])->toContain('1 год — 1 300 ⭐ / 249 000 сум')
        ->and($this->sent[0]['markup']['inline_keyboard'][0][0]['text'])->toBe('⭐ Stars · 1 месяц · 150 ⭐')
        ->and(PaymentOrder::query()->count())->toBe(0);
});

it('/pro answers in the learner\'s Uzbek locale', function (): void {
    Learner::factory()->create(['telegram_user_id' => 555, 'interface_locale' => 'uz']);

    app(TelegramDispatcher::class)->dispatch(proMessage('/pro', lang: 'uz'));

    expect($this->sent[0]['text'])->toContain("Muddat va to'lov usulini tanlang:")
        ->and($this->sent[0]['text'])->toContain("1 oy — 150 ⭐ / 29 000 so'm");
});

it('/pro falls back to "coming soon" when providers are bound but unpriced', function (): void {
    config()->set('billing.prices.pro', ['month' => ['XTR' => 0, 'UZS' => 0], 'year' => ['XTR' => 0, 'UZS' => 0]]);

    app(TelegramDispatcher::class)->dispatch(proMessage('/pro'));

    expect($this->sent[0]['text'])->toContain('скоро откроется')->and($this->sent[0]['markup'])->toBeNull();
});

it('a tap on a buy button creates the order for the learner and answers with a pay link', function (): void {
    $learner = Learner::factory()->create(['telegram_user_id' => 555]);

    app(TelegramDispatcher::class)->dispatch(proTap('pro1:buy:month:telegram_stars'));

    $order = PaymentOrder::query()->sole();
    expect($order->subscriber_id)->toBe($learner->id)
        ->and($order->provider)->toBe('telegram_stars')
        ->and($order->amount)->toBe(150)
        ->and($order->created_via)->toBe('bot')
        ->and($this->sent)->toHaveCount(1)
        ->and($this->sent[0]['text'])->toContain('Счёт создан: 1 месяц, 150 ⭐.')
        ->and($this->sent[0]['markup']['inline_keyboard'][0][0])->toBe(['text' => 'Оплатить', 'url' => "https://pay.test/{$order->token}"]);
});

it('a tap cannot choose the amount or an unknown provider', function (string $data): void {
    Learner::factory()->create(['telegram_user_id' => 555]);

    app(TelegramDispatcher::class)->dispatch(proTap($data));

    expect(PaymentOrder::query()->count())->toBe(0)->and($this->sent)->toBe([]);
})->with([
    'unknown provider' => ['pro1:buy:month:manual'],
    'garbage provider' => ['pro1:buy:month:evil'],
    'extra field' => ['pro1:buy:month:payme:1'],
    'unknown action' => ['pro1:refund'],
]);

it('explains a refused purchase by its error code', function (string $data, string $expected, ?Closure $arrange): void {
    $learner = Learner::factory()->create(['telegram_user_id' => 555]);
    if ($arrange !== null) {
        $arrange($learner, $this);
    }

    app(TelegramDispatcher::class)->dispatch(proTap($data));

    expect($this->sent)->toHaveCount(1)->and($this->sent[0]['text'])->toBe($expected);
})->with([
    'provider switched off' => [
        'pro1:buy:month:payme', 'Этот способ оплаты сейчас недоступен. Выберите другой.',
        fn () => FakeBillingProvider::register(SubscriptionProvider::Payme, enabled: false),
    ],
    'invalid period' => ['pro1:buy:week:payme', 'Такого варианта оплаты нет.', null],
    'provider outage' => [
        'pro1:buy:month:telegram_stars', 'Платёжный сервис не отвечает. Попробуйте через несколько минут.',
        fn ($l, $t) => $t->stars->failCheckoutWith = new RuntimeException('down'),
    ],
    'auto-renewal blocks another provider' => [
        'pro1:buy:month:payme', 'У вас включено автопродление Telegram Stars. Отключите его в /pro, прежде чем оформлять новую оплату.',
        function (Learner $l, $t): void {
            $order = app(CheckoutService::class)->create($l, Plan::Pro, 'month', SubscriptionProvider::TelegramStars, null, 'twa');
            app(PaymentApplier::class)->applyCharge($t->stars->emitCharge($order));
        },
    ],
]);

it('tells the learner the discounted payment is still being processed (offer_busy) and creates no order', function (string $lang, string $expected): void {
    $learner = Learner::factory()->create(['telegram_user_id' => 555, 'interface_locale' => $lang]);
    Offer::factory()->create(['learner_id' => $learner->id, 'discount_pct' => 30, 'period' => 'month']);
    $holder = app(CheckoutService::class)->create($learner, Plan::Pro, 'month', SubscriptionProvider::Payme, null, 'twa');
    ProviderTransaction::factory()->create(['payment_order_id' => $holder->id, 'amount' => $holder->amount]);

    app(TelegramDispatcher::class)->dispatch(proTap('pro1:buy:month:telegram_stars', lang: $lang));

    expect($this->sent)->toHaveCount(1)
        ->and($this->sent[0]['text'])->toBe($expected)
        ->and(PaymentOrder::query()->count())->toBe(1)
        ->and($holder->refresh()->status)->toBe(PaymentOrder::STATUS_PENDING);
})->with([
    'ru' => ['ru', 'Предыдущая оплата со скидкой ещё обрабатывается — попробуйте через минуту.'],
    'uz' => ['uz', "Chegirmali oldingi to'lov hali qayta ishlanmoqda — bir daqiqadan so'ng urinib ko'ring."],
]);

it('limits the taps of one learner per minute', function (): void {
    Learner::factory()->create(['telegram_user_id' => 555]);
    FakeBillingProvider::register(SubscriptionProvider::Payme, enabled: false);

    foreach (range(1, 11) as $_) {
        app(TelegramDispatcher::class)->dispatch(proTap('pro1:buy:month:payme'));
    }

    expect($this->sent)->toHaveCount(11)
        ->and($this->sent[10]['text'])->toBe('Слишком много попыток. Подождите минуту и повторите.');
});

it('ignores taps from a group chat and /pro typed in a group', function (): void {
    Learner::factory()->create(['telegram_user_id' => 555]);
    TelegramGroup::factory()->create(['chat_id' => -100, 'status' => 'active']);

    app(TelegramDispatcher::class)->dispatch(proTap('pro1:buy:month:payme', 'supergroup', -100));
    app(TelegramDispatcher::class)->dispatch(proMessage('/pro', 'supergroup', -100));

    expect($this->sent)->toBe([])->and(PaymentOrder::query()->count())->toBe(0);
});

it('stays silent for a linked teacher', function (): void {
    User::factory()->create(['telegram_user_id' => 555]);

    app(TelegramDispatcher::class)->dispatch(proMessage('/pro'));
    expect($this->sent)->toBe([]);
});

it('shows the renewal state and only the cancel button while the auto-renewal runs, then resume', function (): void {
    $learner = Learner::factory()->create(['telegram_user_id' => 555]);
    $order = app(CheckoutService::class)->create($learner, Plan::Pro, 'month', SubscriptionProvider::TelegramStars, null, 'twa');
    app(PaymentApplier::class)->applyCharge($this->stars->emitCharge($order));
    $subId = (int) $order->refresh()->subscription_id;

    app(TelegramDispatcher::class)->dispatch(proMessage('/pro'));
    expect($this->sent[0]['text'])->toContain('У вас LexiFlow Pro до')
        ->and($this->sent[0]['text'])->toContain('Автопродление включено')
        ->and(callbacks($this->sent[0]['markup']))->toBe(['pro1:cancel']);

    app(TelegramDispatcher::class)->dispatch(proTap('pro1:cancel'));
    $ends = Subscription::query()->findOrFail($subId)->current_period_ends_at->setTimezone($learner->timezone)->format('d.m.Y');
    expect($this->stars->canceled)->toBe([$subId])
        ->and($this->sent[1]['text'])->toBe("Автопродление отключено. Pro действует до {$ends}.");

    app(TelegramDispatcher::class)->dispatch(proMessage('/pro'));
    expect($this->sent[2]['text'])->toContain('Автопродление отключено — Pro действует до конца оплаченного периода.')
        ->and(callbacks($this->sent[2]['markup']))->toContain('pro1:resume');

    app(TelegramDispatcher::class)->dispatch(proTap('pro1:resume'));
    expect($this->stars->resumed)->toBe([$subId])
        ->and($this->sent[3]['text'])->toBe('Автопродление снова включено.')
        ->and(Subscription::query()->findOrFail($subId)->status)->toBe('active');
});

it('refuses to cancel what does not renew', function (): void {
    $learner = Learner::factory()->create(['telegram_user_id' => 555]);
    Subscription::factory()->create(['subscriber_id' => $learner->id, 'provider' => 'promo']);

    app(TelegramDispatcher::class)->dispatch(proTap('pro1:cancel'));

    expect($this->sent[0]['text'])->toBe('Автопродление сейчас нечего отключать.');
});

// --- /paysupport ---------------------------------------------------------------

it('/paysupport gives the support contact and the offer link in a DM', function (): void {
    app(TelegramDispatcher::class)->dispatch(proMessage('/paysupport'));

    expect($this->sent)->toHaveCount(1)
        ->and($this->sent[0]['chat'])->toBe(555)
        ->and($this->sent[0]['text'])->toContain('Платёжная поддержка LexiFlow.')
        ->and($this->sent[0]['text'])->toContain('@lexiflow_support')
        ->and($this->sent[0]['text'])->toContain('https://lexiflow.test/ru/offer');
});

it('/paysupport follows the learner\'s locale, or the Telegram language of a stranger', function (): void {
    app(TelegramDispatcher::class)->dispatch(proMessage('/paysupport', lang: 'uz'));
    expect($this->sent[0]['text'])->toContain("LexiFlow to'lovlar bo'yicha yordam.")
        ->and($this->sent[0]['text'])->toContain('https://lexiflow.test/uz/offer');

    Learner::factory()->create(['telegram_user_id' => 555, 'interface_locale' => 'ru']);
    app(TelegramDispatcher::class)->dispatch(proMessage('/paysupport', lang: 'uz'));
    expect($this->sent[1]['text'])->toContain('Платёжная поддержка LexiFlow.');
});

it('/paysupport works without a configured contact', function (): void {
    config()->set('billing.paywall.contact', '');

    app(TelegramDispatcher::class)->dispatch(proMessage('/paysupport'));

    expect($this->sent[0]['text'])->toContain('Контакт поддержки пока не указан')->and($this->sent[0]['text'])->toContain('/offer');
});

it('/paysupport answers in an active group and stays silent in an unknown one', function (): void {
    TelegramGroup::factory()->create(['chat_id' => -100, 'status' => 'active']);

    app(TelegramDispatcher::class)->dispatch(proMessage('/paysupport', 'supergroup', -100));
    app(TelegramDispatcher::class)->dispatch(proMessage('/paysupport', 'supergroup', -200));

    expect($this->sent)->toHaveCount(1)->and($this->sent[0]['chat'])->toBe(-100);
});
