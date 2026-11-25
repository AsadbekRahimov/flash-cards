<?php

declare(strict_types=1);

use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Models\Learner;
use App\Models\Referral;
use App\Models\ReferralMilestone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeTelegramClient;

/*
 * `/invite` (docs/04 B.3, docs/13 §7.1): the link with the learner's lazily generated code, the rule, the
 * counts and a "Share" button; in the learner's language; nothing for teachers, groups and blocked learners.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['telegram.bot_username' => 'LexiFlowBot']);
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

it('answers /invite with the link, the milestone rule, the counts and a share button', function (): void {
    config(['referrals.milestones' => [3, 6], 'referrals.milestone_days' => 7, 'referrals.activation.reviews_per_day' => 10]);
    $learner = Learner::factory()->create(['telegram_user_id' => 8101, 'onboarding_state' => 'done', 'interface_locale' => 'ru']);
    $referee = Learner::factory()->create();
    Referral::factory()->rewarded()->create(['referrer_learner_id' => $learner->id, 'referee_learner_id' => $referee->id]);
    Referral::factory()->create(['referrer_learner_id' => $learner->id]); // pending: came, not counted yet

    inviteSend(8101);

    $code = $learner->fresh()?->referral_code;
    expect($code)->toMatch('/^[A-Z2-9]{8}$/')
        ->and($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['text'])->toContain("https://t.me/LexiFlowBot?start=ref_{$code}")
        ->and($this->tg->sent[0]['text'])->toContain('10 карточек')
        ->and($this->tg->sent[0]['text'])->toContain('За 3 и 6 засчитанных друзей вы получите по +7 дней Pro')
        ->and($this->tg->sent[0]['text'])->toContain('Пришли по вашей ссылке: 2')
        ->and($this->tg->sent[0]['text'])->toContain('Засчитано друзей: 1 из 3 — до +7 дней Pro осталось 2.')
        ->and($this->tg->sent[0]['text'])->not->toContain('вам и другу')
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
        ->and($this->tg->sent[0]['text'])->toContain("Do'stlaringizni Muhrla'ga taklif qiling")
        ->and($this->tg->sent[0]['markup'])->toBeNull();
});

it('points a teacher to their own commands, stays silent in a group, and tells a blocked learner so', function (): void {
    User::factory()->create(['telegram_user_id' => 8103]);
    inviteSend(8103);
    inviteSend(8104, '/invite', 'group');
    expect($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['text'])->toBe('Эта команда — для учеников. Ваши команды учителя: /help')
        ->and(Learner::query()->where('telegram_user_id', 8103)->exists())->toBeFalse();
    $this->tg->sent = [];

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

it('shows the progress to the next reward at 0, 2, 3 and 6 counted friends, in the new scheme only', function (int $counted, array $reached, string $expected): void {
    config(['referrals.milestones' => [3, 6], 'referrals.milestone_days' => 7]);
    $learner = Learner::factory()->create(['telegram_user_id' => 8110 + $counted, 'onboarding_state' => 'done', 'interface_locale' => 'ru']);
    Referral::factory()->count($counted)->rewarded()->create(['referrer_learner_id' => $learner->id]);
    foreach ($reached as $milestone) {
        ReferralMilestone::factory()->granted()->create(['referrer_learner_id' => $learner->id, 'milestone' => $milestone]);
    }

    inviteSend(8110 + $counted);

    $text = $this->tg->sent[0]['text'];
    expect($text)->toContain($expected)
        ->and($text)->toContain('Пригласите друзей в Muhrla')
        ->and($text)->not->toContain('вам и другу')
        ->and($text)->not->toContain('обоим');
})->with([
    'nobody yet' => [0, [], 'Засчитано друзей: 0 из 3 — до +7 дней Pro осталось 3.'],
    'two' => [2, [], 'Засчитано друзей: 2 из 3 — до +7 дней Pro осталось 1.'],
    'three: the first reward is paid, six is next' => [3, [3], 'Засчитано друзей: 3 из 6 — до +7 дней Pro осталось 3.'],
    'six: nothing left to earn' => [6, [3, 6], 'Засчитано друзей: 6. Все награды за приглашения получены — спасибо!'],
    'three, the reward is still being settled' => [3, [], 'Засчитано друзей: 3 — награда +7 дней Pro уже оформляется.'],
]);

it('shows the progress in Uzbek and takes the days and the milestones from the config', function (): void {
    config(['referrals.milestones' => [2, 4, 8], 'referrals.milestone_days' => 10]);
    $learner = Learner::factory()->create(['telegram_user_id' => 8120, 'onboarding_state' => 'done', 'interface_locale' => 'uz']);
    Referral::factory()->count(1)->rewarded()->create(['referrer_learner_id' => $learner->id]);

    inviteSend(8120);

    expect($this->tg->sent[0]['text'])->toContain("Hisobga olingan do'stlaringiz soni 2, 4 va 8 taga yetganida har safar 10 kun davomida Pro tarifidan bepul foydalanish huquqini olasiz")
        ->and($this->tg->sent[0]['text'])->toContain("Hisobga olingan do'stlar: 1 / 2 — +10 kun Pro'gacha yana 1 ta do'st qoldi.");
});

it('names the welcome trial in the text the learner shares, and only while there is one', function (): void {
    Learner::factory()->create(['telegram_user_id' => 8121, 'onboarding_state' => 'done', 'interface_locale' => 'ru']);
    config(['billing.trial.days' => 7]);
    inviteSend(8121);

    parse_str((string) parse_url($this->tg->sent[0]['markup']['inline_keyboard'][0][0]['url'], PHP_URL_QUERY), $query);
    expect($query['text'])->toBe('Учу английский в Muhrla — присоединяйся, первые 7 дней Pro бесплатно!');

    config(['billing.trial.days' => 0]);
    inviteSend(8121);
    parse_str((string) parse_url($this->tg->sent[1]['markup']['inline_keyboard'][0][0]['url'], PHP_URL_QUERY), $query);
    expect($query['text'])->toBe('Учу английский в Muhrla — присоединяйся!');
});

it('points to /invite in /help, in both languages, and to /language', function (string $lang, string $line, string $language): void {
    Learner::factory()->create(['telegram_user_id' => 8130, 'onboarding_state' => 'done', 'interface_locale' => $lang]);

    inviteSend(8130, '/help');

    expect($this->tg->sent[0]['text'])->toContain($line)->toContain($language)
        ->and($this->tg->sent[0]['text'])->not->toContain('birga')
        ->and($this->tg->sent[0]['text'])->not->toContain('вместе получить');
})->with([
    'ru' => ['ru', 'Пригласите друзей — получите Pro бесплатно: /invite', '/language'],
    'uz' => ['uz', "Do'stlaringizni taklif qiling — Pro'ni bepul oling: /invite", '/language'],
]);

it('says "on N days" when the friend has to study on several days, and "in a day" when one is enough', function (): void {
    config(['referrals.milestones' => [3, 6], 'referrals.activation.reviews_per_day' => 10, 'referrals.activation.days' => 3, 'referrals.activation.window_days' => 7]);
    Learner::factory()->create(['telegram_user_id' => 8120, 'onboarding_state' => 'done', 'interface_locale' => 'ru']);
    Learner::factory()->create(['telegram_user_id' => 8121, 'onboarding_state' => 'done', 'interface_locale' => 'uz']);

    inviteSend(8120);
    inviteSend(8121, from: ['language_code' => 'uz']);

    expect($this->tg->sent[0]['text'])->toContain('будет заниматься 3 дн., оценивая не менее 10 карточек в день')
        ->and($this->tg->sent[0]['text'])->not->toContain('за день')
        ->and($this->tg->sent[1]['text'])->toContain('3 kun davomida har kuni kamida 10 ta kartochkani');

    config(['referrals.activation.days' => 1]);
    inviteSend(8120);
    expect($this->tg->sent[2]['text'])->toContain('оценит 10 карточек за день');
});

it('promises no reward and shows no progress when no milestone is configured', function (): void {
    config(['referrals.milestones' => []]);
    $learner = Learner::factory()->create(['telegram_user_id' => 8122, 'onboarding_state' => 'done', 'interface_locale' => 'ru']);

    inviteSend(8122);

    expect($this->tg->sent[0]['text'])->toContain("ref_{$learner->fresh()?->referral_code}")
        ->and($this->tg->sent[0]['text'])->not->toContain('За  ')
        ->and($this->tg->sent[0]['text'])->not->toContain('Pro бесплатно')
        ->and($this->tg->sent[0]['text'])->not->toContain('Засчитано друзей')
        ->and($this->tg->sent[0]['text'])->toContain('награды за приглашения не выдаются');
});
