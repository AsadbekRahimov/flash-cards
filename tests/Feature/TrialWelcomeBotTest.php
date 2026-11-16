<?php

declare(strict_types=1);

use App\Domain\Billing\Services\ProTrialService;
use App\Domain\Learners\Services\LearnerRegistrar;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Models\Learner;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeTelegramClient;

/*
 * The welcome trial in the bot (docs/13 §7.1 "2C.9"): one short DM "a week of Pro is yours — invite 3 friends for 7 more days",
 * once, after the onboarding (the language is chosen by then, no question is pending) and BEFORE its closing message; never for
 * a learner the trial was not granted to, never twice, never when the Mini App told them first.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['twa.base_url' => 'https://twa.test', 'billing.trial.days' => 7, 'referrals.milestones' => [3, 6], 'referrals.milestone_days' => 7]);

    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);
});

function twStart(int $tgId, string $lang = 'ru'): void
{
    app(TelegramDispatcher::class)->dispatch(['message' => [
        'chat' => ['id' => $tgId, 'type' => 'private'],
        'from' => ['id' => $tgId, 'first_name' => 'Ali', 'language_code' => $lang],
        'text' => '/start',
    ]]);
}

function twTap(int $tgId, string $data, int $messageId): void
{
    app(TelegramDispatcher::class)->dispatch(['callback_query' => [
        'id' => 'cb-'.$messageId.'-'.md5($data),
        'from' => ['id' => $tgId, 'first_name' => 'Ali', 'language_code' => 'ru'],
        'message' => ['message_id' => $messageId, 'chat' => ['id' => $tgId, 'type' => 'private']],
        'data' => $data,
    ]]);
}

function twOnboard(int $tgId, string $locale = 'ru'): void
{
    twTap($tgId, "onb1:locale:{$locale}", 1);
    twTap($tgId, 'onb1:goal:general', 2);
    twTap($tgId, 'onb1:level:A1', 3);
    twTap($tgId, 'onb1:timezone:Asia/Tashkent', 4);
    twTap($tgId, 'onb1:reminder:1900', 5);
}

/** @return list<string> */
function twTexts(object $tg): array
{
    return array_map(static fn (array $m): string => $m['text'], $tg->sent);
}

it('tells a new learner about the week of Pro right before the closing message, not between the first question and its buttons', function (): void {
    twStart(940_001);

    // The first message is the welcome and the language question in ONE message: no gift in it, none before it.
    expect($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['text'])->not->toContain('Pro');

    twOnboard(940_001);

    $until = Learner::query()->where('telegram_user_id', 940_001)->firstOrFail()->subscriptions()->firstOrFail()->current_period_ends_at;
    $texts = twTexts($this->tg);
    $gift = $texts[count($texts) - 2];
    $closing = $this->tg->sent[count($texts) - 1];

    expect($gift)->toBe('🎁 Вам открыт Pro на 7 дней — бесплатно, до '.$until->setTimezone('Asia/Tashkent')->format('d.m.Y').'. Пригласите 3 друзей — получите ещё 7 дней: /invite')
        ->and($closing['button'])->toBe('🎯 Начать первое занятие')
        ->and($closing['text'])->toContain('Пригласите друзей — получите Pro бесплатно: /invite')
        ->and(array_filter($texts, static fn (string $t): bool => str_contains($t, 'Вам открыт Pro')))->toHaveCount(1);
});

it('says it in Uzbek when the learner chose Uzbek, and takes the numbers from the config', function (): void {
    config(['billing.trial.days' => 14, 'referrals.milestones' => [2, 5], 'referrals.milestone_days' => 10]);

    twStart(940_002);
    twOnboard(940_002, 'uz');

    $texts = twTexts($this->tg);
    expect($texts[count($texts) - 2])->toContain('Sizga 14 kun Pro bepul ochildi')
        ->and($texts[count($texts) - 2])->toContain("2 ta do'stni taklif qiling — yana 10 kun Pro olasiz: /invite");
});

it('leaves the invitation out when no milestone is configured', function (): void {
    config(['referrals.milestones' => []]);

    twStart(940_003);
    twOnboard(940_003);

    $texts = twTexts($this->tg);
    expect($texts[count($texts) - 2])->toStartWith('🎁 Вам открыт Pro на 7 дней — бесплатно, до ')
        ->and($texts[count($texts) - 2])->not->toContain('/invite');
});

it('says nothing a second time: a repeated /start after the onboarding shows Today only', function (): void {
    twStart(940_004);
    twOnboard(940_004);
    $this->tg->sent = [];

    twStart(940_004);

    expect($this->tg->sent)->not->toBeEmpty()
        ->and(implode("\n", twTexts($this->tg)))->not->toContain('Вам открыт Pro');
});

it('says nothing to a learner who was there before the trial', function (): void {
    $learner = Learner::factory()->create(['telegram_user_id' => 940_005, 'onboarding_state' => 'done', 'interface_locale' => 'ru']);

    twStart(940_005);

    expect(implode("\n", twTexts($this->tg)))->not->toContain('Вам открыт Pro')
        ->and($learner->fresh()?->subscriptions()->count())->toBe(0);
});

it('says nothing when the Mini App has told the learner first, and the bot does not spend the notice before the end of the onboarding', function (): void {
    twStart(940_006);
    $learner = Learner::query()->where('telegram_user_id', 940_006)->firstOrFail();

    // The first message of /start did not take the notice: it is still there for whoever shows it first.
    expect(app(ProTrialService::class)->pendingNotice($learner->fresh() ?? $learner))->not->toBeNull();

    // The Mini App shows it (and takes it) before the onboarding in the bot is over.
    expect(app(ProTrialService::class)->consumeNotice($learner))->not->toBeNull();

    twOnboard(940_006);

    expect(implode("\n", twTexts($this->tg)))->not->toContain('Вам открыт Pro')
        ->and(end($this->tg->sent)['button'])->toBe('🎯 Начать первое занятие');
});

it('tells a learner who finished the onboarding in the Mini App on their first /start, once', function (): void {
    // Created by the Mini App path (the registrar leaves the trial notice), onboarding done there.
    $learner = app(LearnerRegistrar::class)->fromTelegramUser(['id' => 940_007, 'first_name' => 'Ali']);
    $learner->forceFill(['onboarding_state' => 'done', 'interface_locale' => 'ru'])->save();

    twStart(940_007);
    twStart(940_007);

    $gifts = array_filter(twTexts($this->tg), static fn (string $t): bool => str_contains($t, 'Вам открыт Pro на 7 дней'));
    expect($gifts)->toHaveCount(1);
});

it('does not lose the closing message when the notice cannot be delivered', function (): void {
    twStart(940_008);
    twTap(940_008, 'onb1:locale:ru', 1);
    twTap(940_008, 'onb1:goal:general', 2);
    twTap(940_008, 'onb1:level:A1', 3);
    twTap(940_008, 'onb1:timezone:Asia/Tashkent', 4);

    $this->tg->failSends = 1; // the notice
    twTap(940_008, 'onb1:reminder:1900', 5);

    expect(end($this->tg->sent)['button'])->toBe('🎯 Начать первое занятие')
        ->and(Learner::query()->where('telegram_user_id', 940_008)->value('onboarding_state'))->toBe('done');
});

it('puts the way to free Pro into /pro for a learner on Free, and not for one who has Pro', function (): void {
    Learner::factory()->create(['telegram_user_id' => 940_009, 'onboarding_state' => 'done', 'interface_locale' => 'ru']);
    $pro = Learner::factory()->create(['telegram_user_id' => 940_010, 'onboarding_state' => 'done', 'interface_locale' => 'ru']);
    Subscription::factory()->create(['subscriber_id' => $pro->id]);

    foreach ([940_009, 940_010] as $tgId) {
        app(TelegramDispatcher::class)->dispatch(['message' => [
            'chat' => ['id' => $tgId, 'type' => 'private'],
            'from' => ['id' => $tgId, 'first_name' => 'Ali', 'language_code' => 'ru'],
            'text' => '/pro',
        ]]);
    }

    expect($this->tg->sent[0]['text'])->toContain('Пригласите друзей — получите Pro бесплатно: /invite')
        ->and($this->tg->sent[1]['text'])->not->toContain('/invite');
});

it('takes the notice silently and sends nothing when the trial is over by the time the learner comes back', function (): void {
    twStart(940_011);
    $learner = Learner::query()->where('telegram_user_id', 940_011)->firstOrFail();
    $this->tg->sent = [];

    $this->travel(10)->days();
    twOnboard(940_011);

    expect(implode('
', twTexts($this->tg)))->not->toContain('Вам открыт Pro')
        ->and($learner->fresh()?->meta)->not->toHaveKey(ProTrialService::META_NOTICE);

    $this->tg->sent = [];
    twStart(940_011);
    expect(implode('
', twTexts($this->tg)))->not->toContain('Вам открыт Pro');
});

it('sends nothing when a paid subscription replaced the trial before the notice', function (): void {
    twStart(940_012);
    $learner = Learner::query()->where('telegram_user_id', 940_012)->firstOrFail();
    $learner->subscriptions()->update(['provider' => 'payme']);
    $this->tg->sent = [];

    twOnboard(940_012);

    expect(implode("\n", twTexts($this->tg)))->not->toContain('Вам открыт Pro')
        ->and($learner->fresh()?->meta)->not->toHaveKey(ProTrialService::META_NOTICE);
});
