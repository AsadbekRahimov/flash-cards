<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Models\Learner;
use App\Models\PromoCode;
use App\Models\PromoRedemption;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->api = Mockery::mock(TelegramClient::class);
    $this->app->instance(TelegramClient::class, $this->api);
});

/** @return array<string, mixed> */
function promoUpdate(string $text, string $chatType = 'private', int $chatId = 555, string $lang = 'ru'): array
{
    return ['message' => [
        'chat' => ['id' => $chatId, 'type' => $chatType],
        'from' => ['id' => 555, 'first_name' => 'Alice', 'language_code' => $lang],
        'text' => $text,
    ]];
}

it('redeems the code in a DM, replies with the end date and grants Pro', function (): void {
    PromoCode::factory()->create(['code' => 'WELCOME30', 'duration_days' => 30]);
    $this->api->shouldReceive('sendMessage')->once()->withArgs(function (int $chat, string $text): bool {
        $until = now()->addDays(30)->setTimezone('Asia/Tashkent')->format('d.m.Y');

        return $chat === 555 && $text === "Готово! LexiFlow Pro активен до {$until}.";
    });

    app(TelegramDispatcher::class)->dispatch(promoUpdate('/promo welcome-30'));

    $learner = Learner::query()->where('telegram_user_id', 555)->firstOrFail();
    expect(app(EntitlementResolver::class)->for($learner)->plan)->toBe(Plan::Pro)
        ->and(PromoRedemption::query()->where('learner_id', $learner->id)->count())->toBe(1);
});

it('answers in the learner Uzbek locale', function (): void {
    PromoCode::factory()->create(['code' => 'UZBEK']);
    Learner::factory()->create(['telegram_user_id' => 555, 'interface_locale' => 'uz']);
    $this->api->shouldReceive('sendMessage')->once()->withArgs(
        fn (int $chat, string $text): bool => str_starts_with($text, 'Tayyor! LexiFlow Pro ') && str_ends_with($text, ' gacha faol.'),
    );

    app(TelegramDispatcher::class)->dispatch(promoUpdate('/promo UZBEK', lang: 'uz'));
});

it('refuses a code to a learner who already has a perpetual subscription', function (): void {
    $learner = Learner::factory()->create(['telegram_user_id' => 555]);
    Subscription::factory()->create(['subscriber_id' => $learner->id, 'current_period_ends_at' => null]);
    PromoCode::factory()->create(['code' => 'EXTRA']);
    $this->api->shouldReceive('sendMessage')->once()->with(555, 'У вас уже есть Pro без ограничения по сроку — промокод не нужен.');

    app(TelegramDispatcher::class)->dispatch(promoUpdate('/promo EXTRA'));
});

it('explains every promo error by code', function (string $code, string $message, ?Closure $arrange): void {
    if ($arrange !== null) {
        $arrange();
    }
    $this->api->shouldReceive('sendMessage')->once()->with(555, $message);

    app(TelegramDispatcher::class)->dispatch(promoUpdate('/promo '.$code));

    expect(Subscription::query()->count())->toBe(0);
})->with([
    'unknown' => ['NOPE', 'Промокод не найден. Проверьте, нет ли опечатки.', null],
    'expired' => ['OLD', 'Срок действия промокода истёк.', fn () => PromoCode::factory()->create(['code' => 'OLD', 'expires_at' => now()->subDay()])],
    'exhausted' => ['FULL', 'Этим промокодом уже воспользовалось максимальное число людей.', fn () => PromoCode::factory()->create(['code' => 'FULL', 'max_redemptions' => 1, 'redemptions_count' => 1])],
    'already redeemed' => ['TWICE', 'Вы уже активировали этот промокод.', function (): void {
        $learner = Learner::factory()->create(['telegram_user_id' => 555]);
        $promo = PromoCode::factory()->create(['code' => 'TWICE']);
        PromoRedemption::factory()->create(['promo_code_id' => $promo->id, 'learner_id' => $learner->id]);
    }],
]);

it('shows usage for a bare /promo and for /promo@bot without a code', function (string $text): void {
    $this->api->shouldReceive('sendMessage')->once()->with(555, 'Отправьте промокод так: /promo КОД');

    app(TelegramDispatcher::class)->dispatch(promoUpdate($text));
})->with(['/promo', '/promo@LexiFlowBot', '/promo   ']);

it('accepts /promo@bot CODE', function (): void {
    PromoCode::factory()->create(['code' => 'ATBOT']);
    $this->api->shouldReceive('sendMessage')->once()->withArgs(fn (int $chat, string $text): bool => str_starts_with($text, 'Готово!'));

    app(TelegramDispatcher::class)->dispatch(promoUpdate('/promo@LexiFlowBot ATBOT'));

    expect(PromoRedemption::query()->count())->toBe(1);
});

it('refuses a blocked learner without redeeming', function (): void {
    Learner::factory()->create(['telegram_user_id' => 555, 'blocked_at' => now()]);
    PromoCode::factory()->create(['code' => 'BLOCKED']);
    $this->api->shouldReceive('sendMessage')->once()->with(555, 'Доступ к аккаунту ограничен. Обратитесь в поддержку.');

    app(TelegramDispatcher::class)->dispatch(promoUpdate('/promo BLOCKED'));

    expect(PromoRedemption::query()->count())->toBe(0);
});

it('throttles code guessing: the 6th attempt in a minute is refused without touching the code', function (): void {
    $learner = Learner::factory()->create(['telegram_user_id' => 555]);
    RateLimiter::clear('bot-promo:'.$learner->id);
    PromoCode::factory()->create(['code' => 'REAL']);
    $this->api->shouldReceive('sendMessage')->times(5)->with(555, 'Промокод не найден. Проверьте, нет ли опечатки.');
    $this->api->shouldReceive('sendMessage')->once()->with(555, 'Слишком много попыток. Подождите минуту и повторите.');

    foreach (range(1, 5) as $_) {
        app(TelegramDispatcher::class)->dispatch(promoUpdate('/promo GUESS'));
    }
    app(TelegramDispatcher::class)->dispatch(promoUpdate('/promo REAL'));

    expect(PromoRedemption::query()->count())->toBe(0);
});

it('in an active group only points to the DM and does not redeem', function (): void {
    TelegramGroup::factory()->create(['chat_id' => -100777, 'status' => 'active']);
    PromoCode::factory()->create(['code' => 'LEAK']);
    $this->api->shouldReceive('sendMessage')->once()->withArgs(
        fn (int $chat, string $text): bool => $chat === -100777 && str_contains($text, 'личный чат'),
    );

    app(TelegramDispatcher::class)->dispatch(promoUpdate('/promo LEAK', 'supergroup', -100777));

    expect(PromoRedemption::query()->count())->toBe(0)
        ->and(Learner::query()->count())->toBe(0);
});

it('stays silent in a group that is not active (Group Lock)', function (): void {
    TelegramGroup::factory()->create(['chat_id' => -100888, 'status' => 'pending']);
    $this->api->shouldNotReceive('sendMessage');

    app(TelegramDispatcher::class)->dispatch(promoUpdate('/promo X', 'supergroup', -100888));
    app(TelegramDispatcher::class)->dispatch(promoUpdate('/promo X', 'group', -100999));
});
