<?php

declare(strict_types=1);

use App\Domain\Billing\Services\ProTrialService;
use App\Domain\Learners\Services\LearnerRegistrar;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Models\Learner;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeTelegramClient;

/*
 * The welcome trial in the bot (docs/13 §7.1 "2C.9", docs/36 §4б W2): one DM "Pro for 14 days is yours — until DATE; what Pro gives:
 * four lines; invite 3 friends for 7 more days", once, after the onboarding (the language is chosen by then, no question is pending)
 * and BEFORE its closing message - without a button of its own there (the closing message has one); on `/start` of a learner who has
 * finished the onboarding, with "▶️ Start a lesson". Never for a learner the trial was not granted to, never twice, never when the
 * Mini App told them first, never for a stale notice.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['twa.base_url' => 'https://twa.test', 'billing.trial.days' => 7, 'referrals.milestones' => [3, 6], 'referrals.milestone_days' => 7]);
    // Practice and the game closed: the four lines of "what Pro gives" are the ones every learner gets.
    config(['practice.access' => 'off', 'game.access' => 'off']);

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
    twTap($tgId, 'onb1:region:none', 2);
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

it('tells a new learner about the Pro of the trial right before the closing message, not between the first question and its buttons', function (): void {
    config(['billing.trial.days' => 14]);
    // Late in the evening of Tashkent: the end of the trial is already the next day there, not in UTC.
    $this->travelTo(CarbonImmutable::parse('2026-11-25 20:30:00', 'UTC'));

    twStart(940_001);

    // The first message is the welcome and the language question in ONE message: no gift in it, none before it.
    expect($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['text'])->not->toContain('Pro');

    twOnboard(940_001);

    $texts = twTexts($this->tg);
    $gift = $this->tg->sent[count($texts) - 2];
    $closing = $this->tg->sent[count($texts) - 1];

    expect($gift['text'])->toBe("🎁 Вам открыт Pro на 14 дней — бесплатно, до 10.12.2026.\n\n"
        ."Что даёт Pro:\n"
        ."• Новые слова без дневного лимита\n"
        ."• Свои колоды слов\n"
        ."• Заморозка серии: пропуск дня не обрывает серию\n"
        ."• Упражнения «Написание слова» и «Пропуск в примере»\n\n"
        .'Пригласите 3 друзей — получите ещё 7 дней Pro: /invite')
        // The closing message right after it has the button: the notice has none (one main button).
        ->and($gift['markup'])->toBeNull()
        ->and($gift['button'])->toBeNull()
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
        ->and($texts[count($texts) - 2])->toContain("Pro nima beradi:\n• Yangi so'zlar — kunlik limitsiz\n• O'z so'z to'plamlaringiz\n")
        ->and(substr_count($texts[count($texts) - 2], '• '))->toBe(4)
        ->and($texts[count($texts) - 2])->toContain("2 ta do'stni taklif qiling — yana 10 kun Pro olasiz: /invite");
});

it('leaves the invitation out when no milestone is configured', function (): void {
    config(['referrals.milestones' => []]);

    twStart(940_003);
    twOnboard(940_003);

    $texts = twTexts($this->tg);
    expect($texts[count($texts) - 2])->toStartWith('🎁 Вам открыт Pro на 7 дней — бесплатно, до ')
        ->and($texts[count($texts) - 2])->toContain("Что даёт Pro:\n• Новые слова без дневного лимита")
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

it('tells a learner who finished the onboarding in the Mini App on their first /start, once, with the button of a lesson', function (): void {
    // Created by the Mini App path (the registrar leaves the trial notice), onboarding done there.
    $learner = app(LearnerRegistrar::class)->fromTelegramUser(['id' => 940_007, 'first_name' => 'Ali']);
    $learner->forceFill(['onboarding_state' => 'done', 'interface_locale' => 'ru'])->save();

    twStart(940_007);
    twStart(940_007);

    $gifts = array_values(array_filter($this->tg->sent, static fn (array $m): bool => str_contains($m['text'], 'Вам открыт Pro на 7 дней')));
    expect($gifts)->toHaveCount(1)
        ->and($gifts[0]['markup'])->toBe(['inline_keyboard' => [[
            ['text' => '▶️ Начать занятие', 'web_app' => ['url' => 'https://twa.test/twa/?from=trial_welcome']],
        ]]]);
});

it('names practice and battles among the four lines only while practice is open to the learner', function (): void {
    config(['practice.access' => 'all', 'practice.plan' => 'all', 'practice.battle.enabled' => true,
        'practice.free_limits.sessions_per_day' => 3, 'practice.free_limits.battles_per_day' => 1]);
    $learner = app(LearnerRegistrar::class)->fromTelegramUser(['id' => 940_013, 'first_name' => 'Ali']);
    $learner->forceFill(['onboarding_state' => 'done', 'interface_locale' => 'ru'])->save();

    twStart(940_013);

    $gift = collect($this->tg->sent)->first(static fn (array $m): bool => str_contains($m['text'], 'Вам открыт Pro'));
    expect($gift['text'])->toContain("Что даёт Pro:\n"
        ."• Новые слова без дневного лимита\n"
        ."• Практика и баттлы без дневного лимита\n"
        ."• Свои колоды слов\n"
        ."• Заморозка серии: пропуск дня не обрывает серию\n\n");
});

it('does not lose the closing message when the notice cannot be delivered', function (): void {
    twStart(940_008);
    twTap(940_008, 'onb1:locale:ru', 1);
    twTap(940_008, 'onb1:region:none', 2);
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

it('names the length of the trial that was granted, not the current setting: a 7-day trial stays "7 days" after the setting became 14', function (): void {
    config(['billing.trial.days' => 7]);
    $this->travelTo(CarbonImmutable::parse('2026-11-25 10:00:00', 'UTC'));
    twStart(940_014);

    // The owner raises the trial to 14 days before this learner finishes the onboarding.
    config(['billing.trial.days' => 14]);
    $this->travel(2)->hours();
    twOnboard(940_014);

    $gift = collect($this->tg->sent)->first(static fn (array $m): bool => str_contains($m['text'], 'Вам открыт Pro'));
    expect($gift['text'])->toStartWith('🎁 Вам открыт Pro на 7 дней — бесплатно, до 02.12.2026.');
});

it('rounds the length of the trial to whole days', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-25 10:00:00', 'UTC'));
    $learner = Learner::factory()->create(['telegram_user_id' => 940_015]);
    $trial = Subscription::factory()->create([
        'subscriber_id' => $learner->id,
        'provider' => 'trial',
        'starts_at' => CarbonImmutable::parse('2026-11-25 10:00:00', 'UTC'),
        'current_period_ends_at' => CarbonImmutable::parse('2026-12-09 09:59:30', 'UTC'),
    ]);

    expect(ProTrialService::noticeDays($trial, CarbonImmutable::parse('2026-12-09 09:59:30', 'UTC')))->toBe(14)
        ->and(ProTrialService::noticeDays($trial, CarbonImmutable::parse('2026-11-25 10:30:00', 'UTC')))->toBe(1);
});
