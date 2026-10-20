<?php

declare(strict_types=1);

use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Models\Learner;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeTelegramClient;

/*
 * `/invite` (docs/04 B.3, docs/13 §7.1): the link with the learner's lazily generated code, the rule, the
 * counts and a "Share" button; in the learner's language; nothing for teachers, groups and blocked learners.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['telegram.bot_username' => 'LexiFlowBot', 'referrals.reward_days' => 7]);
    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);
});

/** @param array<string, mixed> $from */
function inviteSend(int $tgId, string $text = '/invite', string $chatType = 'private', array $from = []): void
{
    app(TelegramDispatcher::class)->dispatch(['message' => [
        'chat' => ['id' => $chatType === 'private' ? $tgId : -1000, 'type' => $chatType],
        'from' => ['id' => $tgId, 'first_name' => 'Ali', 'language_code' => 'ru', ...$from],
        'text' => $text,
    ]]);
}

it('answers /invite with the link, the rule, the counts and a share button', function (): void {
    $learner = Learner::factory()->create(['telegram_user_id' => 8101, 'onboarding_state' => 'done', 'interface_locale' => 'ru']);
    $referee = Learner::factory()->create();
    Referral::factory()->rewarded()->create(['referrer_learner_id' => $learner->id, 'referee_learner_id' => $referee->id]);

    inviteSend(8101);

    $code = $learner->fresh()?->referral_code;
    expect($code)->toMatch('/^[A-Z2-9]{8}$/')
        ->and($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['text'])->toContain("https://t.me/LexiFlowBot?start=ref_{$code}")
        ->and($this->tg->sent[0]['text'])->toContain('3 дня')
        ->and($this->tg->sent[0]['text'])->toContain('7 дней Pro')
        ->and($this->tg->sent[0]['text'])->toContain('Пришли по вашей ссылке: 1 · занимаются: 1 · награждены: 1')
        ->and($this->tg->sent[0]['markup']['inline_keyboard'][0][0]['text'])->toBe('Поделиться ссылкой')
        ->and($this->tg->sent[0]['markup']['inline_keyboard'][0][0]['url'])->toStartWith('https://t.me/share/url?url=')
        ->and($this->tg->sent[0]['markup']['inline_keyboard'][0][0]['url'])->toContain(urlencode("https://t.me/LexiFlowBot?start=ref_{$code}"));

    // The code is stable.
    inviteSend(8101, '/invite@LexiFlowBot');
    expect($this->tg->sent[1]['text'])->toContain("ref_{$code}");
});

it('speaks Uzbek to an uz learner and shows the code only while the bot username is unknown', function (): void {
    config(['telegram.bot_username' => '']);
    Learner::factory()->create(['telegram_user_id' => 8102, 'onboarding_state' => 'done', 'interface_locale' => 'uz', 'referral_code' => 'UZCODE23']);

    inviteSend(8102);

    expect($this->tg->sent[0]['text'])->toContain('kod UZCODE23')
        ->and($this->tg->sent[0]['text'])->toContain("Do'stingizni")
        ->and($this->tg->sent[0]['markup'])->toBeNull();
});

it('stays silent for a teacher and in a group, and tells a blocked learner so', function (): void {
    User::factory()->create(['telegram_user_id' => 8103]);
    inviteSend(8103);
    inviteSend(8104, '/invite', 'group');
    expect($this->tg->sent)->toHaveCount(0)
        ->and(Learner::query()->where('telegram_user_id', 8103)->exists())->toBeFalse();

    Learner::factory()->create(['telegram_user_id' => 8105, 'blocked_at' => now()]);
    inviteSend(8105);
    expect($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['text'])->toContain('ограничен')
        ->and(Learner::query()->where('telegram_user_id', 8105)->value('referral_code'))->toBeNull();
});

it('mentions /invite in the private help', function (): void {
    inviteSend(8106, '/help');

    expect($this->tg->sent[0]['text'])->toContain('/invite');
});
