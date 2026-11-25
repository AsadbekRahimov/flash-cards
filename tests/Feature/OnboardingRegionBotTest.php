<?php

declare(strict_types=1);

/*
 * The region in the bot (docs/35 §2.3, R3): the step `region` of the onboarding with its two screens in one message, and `/settings` →
 * "Region" with the 30-day rule and the undo. The whole walk of the onboarding is in OnboardingFlowTest.
 */

use App\Domain\Learners\Services\OnboardingService;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Services\BotButtons;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Models\Learner;
use App\Models\ProductEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeTelegramClient;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['twa.base_url' => 'https://twa.test', 'learners.goals' => ['general']]);
    CarbonImmutable::setTestNow('2026-11-25 10:00:00');

    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function rgbSend(int $tgId, string $text, string $languageCode = 'ru'): void
{
    app(TelegramDispatcher::class)->dispatch(['message' => [
        'chat' => ['id' => $tgId, 'type' => 'private'],
        'from' => ['id' => $tgId, 'first_name' => 'Ali', 'language_code' => $languageCode],
        'text' => $text,
    ]]);
}

function rgbTap(int $tgId, string $data, int $messageId = 100): void
{
    app(TelegramDispatcher::class)->dispatch(['callback_query' => [
        'id' => 'cb-'.$messageId.'-'.md5($data).'-'.microtime(true),
        'from' => ['id' => $tgId, 'first_name' => 'Ali', 'language_code' => 'ru'],
        'message' => ['message_id' => $messageId, 'chat' => ['id' => $tgId, 'type' => 'private']],
        'data' => $data,
    ]]);
}

function rgbLearner(int $tgId): Learner
{
    return Learner::query()->where('telegram_user_id', $tgId)->firstOrFail();
}

it('asks the region right after the language, in the chosen language, with three buttons', function (): void {
    rgbSend(7401, '/start');
    rgbTap(7401, 'onb1:locale:uz', 1);

    expect(rgbLearner(7401)->onboarding_state)->toBe('region')
        ->and(end($this->tg->sent)['text'])->toBe("4 qadamdan 2-qadam\nQayerda yashaysiz? Bu hududingiz reytingi uchun kerak.")
        ->and($this->tg->lastCallbackData())->toBe(['onb1:region:uz', 'onb1:region:other', 'onb1:region:none'])
        ->and(array_column(end($this->tg->sent)['markup']['inline_keyboard'][0], 'text'))->toBe(["🇺🇿 O'zbekiston"]);
});

it('opens the 14 regions in the same message in two columns with Back alone, and comes back to the first screen', function (): void {
    rgbSend(7402, '/start');
    rgbTap(7402, 'onb1:locale:ru', 1);
    $sent = count($this->tg->sent);

    rgbTap(7402, 'onb1:region:uz', 2);

    $edit = $this->tg->lastTextEdit();
    $rows = $edit['markup']['inline_keyboard'] ?? [];
    expect($this->tg->sent)->toHaveCount($sent)
        ->and($edit['message_id'] ?? null)->toBe(2)
        ->and($edit['text'] ?? null)->toBe("Шаг 2 из 4\nВыберите ваш регион")
        ->and($rows)->toHaveCount(8)
        ->and($rows[0])->toBe([
            ['text' => 'город Ташкент', 'callback_data' => 'onb1:region:UZ-TK'],
            ['text' => 'Андижанская область', 'callback_data' => 'onb1:region:UZ-AN'],
        ])
        ->and($rows[7])->toBe([['text' => '← Назад', 'callback_data' => 'onb1:region:back']])
        ->and(rgbLearner(7402)->onboarding_state)->toBe('region')
        ->and(ProductEvent::query()->where('name', 'onboarding.step_completed')->count())->toBe(1);

    rgbTap(7402, 'onb1:region:back', 2);
    expect($this->tg->lastTextEdit()['markup']['inline_keyboard'] ?? [])->toHaveCount(3);
});

it('saves a region of Uzbekistan and goes on to the next step', function (): void {
    rgbSend(7403, '/start');
    rgbTap(7403, 'onb1:locale:ru', 1);
    rgbTap(7403, 'onb1:region:uz', 2);
    rgbTap(7403, 'onb1:region:UZ-QR', 2);

    $learner = rgbLearner(7403);
    expect($learner->region_code)->toBe('UZ-QR')
        ->and($learner->region_changed_at)->not->toBeNull()
        ->and($learner->onboarding_state)->toBe('level')
        ->and(ProductEvent::query()->where('name', 'region.set')->sole()->props)->toEqual(['from' => null, 'to' => 'UZ-QR', 'via' => 'onboarding']);
});

it('takes "Rather not say" as no region and passes the step', function (): void {
    rgbSend(7404, '/start');
    rgbTap(7404, 'onb1:locale:ru', 1);
    rgbTap(7404, 'onb1:region:none', 2);

    $learner = rgbLearner(7404);
    expect($learner->region_code)->toBeNull()
        ->and($learner->region_changed_at)->toBeNull()
        ->and($learner->onboarding_state)->toBe('level')
        ->and(ProductEvent::query()->where('name', 'region.set')->count())->toBe(0)
        ->and(ProductEvent::query()->where('name', 'onboarding.step_completed')->pluck('props')->last())->toBe(['step' => 'region']);
});

it('treats the old Uzbekistan button after the answer as a stale one: nothing changes, nothing is sent or edited', function (): void {
    rgbSend(7405, '/start');
    rgbTap(7405, 'onb1:locale:ru', 1);
    rgbTap(7405, 'onb1:region:other', 2);
    $sent = count($this->tg->sent);
    $events = ProductEvent::query()->count();

    rgbTap(7405, 'onb1:region:uz', 2);
    rgbTap(7405, 'onb1:region:back', 2);

    expect(rgbLearner(7405)->onboarding_state)->toBe('level')
        ->and(rgbLearner(7405)->region_code)->toBe('other')
        ->and($this->tg->sent)->toHaveCount($sent)
        ->and($this->tg->textEdits)->toBe([])
        ->and(ProductEvent::query()->count())->toBe($events);
});

it('does not take a learner in the middle of the onboarding back to the region step', function (string $state): void {
    $learner = Learner::factory()->create(['telegram_user_id' => 7406, 'onboarding_state' => $state, 'interface_locale' => 'ru', 'goal' => 'general', 'level' => 'A1']);

    rgbSend(7406, 'hello');

    expect($learner->fresh()->onboarding_state)->toBe($state)
        ->and($this->tg->lastCallbackData())->not->toContain('onb1:region:uz');

    // A region button of a learner who is past the step changes nothing.
    rgbTap(7406, 'onb1:region:UZ-TK', 3);
    expect($learner->fresh()->region_code)->toBeNull();
})->with(['level', 'reminder']);

it('keeps every region button within 64 bytes and makes it through BotButtons', function (): void {
    $service = app(OnboardingService::class);
    $buttons = app(BotButtons::class);

    foreach ([OnboardingService::REGION_BACK, OnboardingService::REGION_UZBEKISTAN] as $screen) {
        foreach ($service->regionStep($screen, 'uz')->options as $option) {
            foreach (['onb1', 'set1'] as $prefix) {
                expect($buttons->callback('x', "{$prefix}:region:{$option->value}")->value)->toHaveLength(strlen("{$prefix}:region:{$option->value}"));
            }
        }
    }

    // In Uzbek the regions follow the Uzbek alphabet.
    $codes = array_map(fn ($o) => $o->value, $service->regionStep(OnboardingService::REGION_UZBEKISTAN, 'uz')->options);
    expect($codes[0])->toBe('UZ-TK')->and($codes[3])->toBe('UZ-FA')->and(end($codes))->toBe('back');
});

// --- /settings ------------------------------------------------------------------------------------------------

it('offers "Region" in /settings and saves a region there with the undo button', function (): void {
    $learner = Learner::factory()->onboarded()->create(['telegram_user_id' => 7410, 'interface_locale' => 'ru']);

    rgbSend(7410, '/settings');
    expect($this->tg->lastCallbackData())->toContain('set1:region');

    rgbTap(7410, 'set1:region', 10);
    expect(end($this->tg->sent)['text'])->toBe('Где вы живёте? Это нужно для рейтинга вашего региона.')
        ->and($this->tg->lastCallbackData())->toBe(['set1:region:uz', 'set1:region:other', 'set1:region:none']);

    rgbTap(7410, 'set1:region:uz', 11);
    expect($this->tg->lastTextEdit()['text'] ?? null)->toBe('Выберите ваш регион');

    rgbTap(7410, 'set1:region:UZ-SA', 11);
    expect($learner->fresh()->region_code)->toBe('UZ-SA')
        ->and(end($this->tg->sent)['text'])->toBe('Регион: Самаркандская область. Сохранено.')
        ->and($this->tg->lastCallbackData())->toBe(['set1:rgundo'])
        ->and(ProductEvent::query()->where('name', 'region.set')->sole()->props)->toEqual(['from' => null, 'to' => 'UZ-SA', 'via' => 'settings']);

    // "Undo" within 10 minutes: the region is gone again.
    rgbTap(7410, 'set1:rgundo', 12);
    expect($learner->fresh()->region_code)->toBeNull()
        ->and(end($this->tg->sent)['text'])->toBe('Прежний регион возвращён.');

    rgbTap(7410, 'set1:rgundo', 12);
    expect(end($this->tg->sent)['text'])->toContain('Отменить смену региона уже нельзя');
});

it('says from which date the region can be changed in /settings, in Uzbek too', function (string $locale, string $text): void {
    $learner = Learner::factory()->onboarded()->create([
        'telegram_user_id' => 7411, 'interface_locale' => $locale, 'timezone' => 'Asia/Tashkent',
        'region_code' => 'UZ-TK', 'region_changed_at' => '2026-11-24 22:00:00',
    ]);

    rgbTap(7411, 'set1:region:other', 20);

    expect($learner->fresh()->region_code)->toBe('UZ-TK')
        ->and(end($this->tg->sent)['text'])->toBe($text);
})->with([
    ['ru', 'Регион можно менять раз в 30 дней. Сменить регион можно с 25.12.2026.'],
    ['uz', "Hududni 30 kunda bir marta o'zgartirish mumkin. Hududni 25.12.2026 dan boshlab o'zgartirishingiz mumkin."],
]);

it('lets "Rather not say" through /settings at any time', function (): void {
    $learner = Learner::factory()->onboarded()->create(['telegram_user_id' => 7412, 'interface_locale' => 'ru', 'region_code' => 'UZ-TK', 'region_changed_at' => '2026-11-24 10:00:00']);

    rgbTap(7412, 'set1:region:none', 30);

    expect($learner->fresh()->region_code)->toBeNull()
        ->and(end($this->tg->sent)['text'])->toBe('Регион: не указан. Сохранено.');
});

it('ignores tampered region buttons', function (string $data): void {
    $learner = Learner::factory()->onboarded()->create(['telegram_user_id' => 7413, 'interface_locale' => 'ru']);

    rgbTap(7413, $data, 40);

    expect($learner->fresh()->region_code)->toBeNull()
        ->and($this->tg->sent)->toBe([]);
})->with(['set1:region:UZ-XX', 'set1:region:uz-tk', 'set1:region:'.str_repeat('A', 41), 'set1:rgundo:x']);
