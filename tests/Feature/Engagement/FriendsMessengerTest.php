<?php

declare(strict_types=1);

use App\Domain\Engagement\Enums\FriendDmKind;
use App\Domain\Engagement\Services\ChallengeService;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Exceptions\TelegramRateLimitedException;
use App\Domain\Telegram\Services\FriendsMessenger;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Models\Friendship;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\NotificationLog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeTelegramClient;

/*
 * The buttons of the friends and challenges messages (docs/36 §3а, N2): each kind has its own button and an address with its id —
 * a challenge accepted (the owner's example, both to the challenger and in the answer to the tap) -> "Open the challenge"
 * `/friends?challenge=<id>`; a challenge won / drawn -> "New challenge", lost -> "Rematch", a friend accepted -> "Challenge",
 * all three `/friends?friend=<friendship_id>`; "you are friends now" -> "Friends". The marker and the 403 behave as before.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['twa.base_url' => 'https://twa.test', 'telegram.bot_username' => 'muhrla_bot', 'friends.challenges' => ['days' => 7, 'pending_ttl_hours' => 72]]);
    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);
});

/** @return array{0: Learner, 1: Learner, 2: Friendship} */
function fmFriends(string $localeB = 'ru'): array
{
    $a = Learner::factory()->onboarded()->create(['telegram_user_id' => 9301, 'first_name' => 'Aziza', 'interface_locale' => 'ru', 'timezone' => 'Asia/Tashkent']);
    $b = Learner::factory()->onboarded()->create(['telegram_user_id' => 9302, 'first_name' => 'Bek', 'interface_locale' => $localeB, 'timezone' => 'Asia/Tashkent']);
    $friendship = Friendship::factory()->between($a, $b)->create();

    return [$a, $b, $friendship];
}

function fmTap(int $tgId, string $data, int $messageId = 100): void
{
    app(TelegramDispatcher::class)->dispatch(['callback_query' => [
        'id' => 'cb-'.$messageId.'-'.md5($data.$tgId),
        'from' => ['id' => $tgId, 'first_name' => 'Ali', 'language_code' => 'ru'],
        'message' => ['message_id' => $messageId, 'chat' => ['id' => $tgId, 'type' => 'private']],
        'data' => $data,
    ]]);
}

/**
 * The one web_app button of a message.
 *
 * @param  array{markup: array<string, mixed>|null}  $message
 * @return array{text: string, url: string}
 */
function fmButton(array $message): array
{
    $rows = $message['markup']['inline_keyboard'] ?? null;
    expect($rows)->toBeArray()->toHaveCount(1)
        ->and($rows[0])->toHaveCount(1);

    return ['text' => $rows[0][0]['text'], 'url' => $rows[0][0]['web_app']['url']];
}

/** @return list<array{chat_id: int|string, text: string, button: string|null, url: string|null, markup: array<string, mixed>|null}> */
function fmTo(int $chatId): array
{
    return array_values(array_filter(test()->tg->sent, static fn (array $m): bool => $m['chat_id'] === $chatId));
}

it('puts "Open the challenge" under "X accepted your challenge" and under the answer to the tap, both with the id of the challenge', function (): void {
    [$a, $b] = fmFriends();
    $c = app(ChallengeService::class)->propose($a, $b, 'reviews', now: CarbonImmutable::now());

    fmTap(9302, "fr1:cacc:{$c->id}", 70);

    $toChallenger = fmTo(9301);
    $toOpponent = fmTo(9302);
    expect($toChallenger)->toHaveCount(1)
        ->and($toChallenger[0]['text'])->toContain('принял(а) ваш вызов')
        ->and(fmButton($toChallenger[0]))->toBe(['text' => '🎯 Открыть вызов', 'url' => "https://twa.test/twa/friends?challenge={$c->id}&from=challenge_accepted"])
        ->and(end($toOpponent)['text'])->toContain('Вызов принят')
        ->and(fmButton(end($toOpponent)))->toBe(['text' => '🎯 Открыть вызов', 'url' => "https://twa.test/twa/friends?challenge={$c->id}&from=challenge_accepted"]);
});

it('speaks Uzbek on the button of an accepted challenge', function (): void {
    [$a, $b] = fmFriends();
    $a->forceFill(['interface_locale' => 'uz'])->save();
    $c = app(ChallengeService::class)->propose($a, $b, 'reviews', now: CarbonImmutable::now());

    fmTap(9302, "fr1:cacc:{$c->id}", 70);

    expect(fmButton(fmTo(9301)[0])['text'])->toBe('🎯 Musobaqani ochish');
});

it('keeps Accept / Decline as the two buttons of a proposed challenge', function (): void {
    [$a, $b] = fmFriends();
    $c = app(ChallengeService::class)->propose($a, $b, 'reviews', now: CarbonImmutable::now());

    expect($this->tg->lastCallbackData())->toBe(["fr1:cacc:{$c->id}", "fr1:cdec:{$c->id}"]);
});

it('ends a challenge with "New challenge" for the winner and "Rematch" for the loser, to the same friend', function (): void {
    [$a, $b, $friendship] = fmFriends();
    $svc = app(ChallengeService::class);
    $now = CarbonImmutable::parse('2026-10-22 10:00:00', 'UTC');
    $c = $svc->propose($a, $b, 'reviews', now: $now);
    $svc->accept($b, $c->id, $now);
    LearnerDailyStats::factory()->create(['learner_id' => $a->id, 'date' => '2026-10-24', 'reviews_count' => 40]);
    LearnerDailyStats::factory()->create(['learner_id' => $b->id, 'date' => '2026-10-24', 'reviews_count' => 25]);
    $this->tg->sent = [];

    $svc->close($c->fresh() ?? $c, CarbonImmutable::parse('2026-11-01 00:00:00', 'UTC'));

    $url = "https://twa.test/twa/friends?friend={$friendship->id}&from=challenge_finished";
    expect(fmButton(fmTo(9301)[0]))->toBe(['text' => '🎯 Новый вызов', 'url' => $url])
        ->and(fmTo(9302)[0]['text'])->toContain('Реванш')
        ->and(fmButton(fmTo(9302)[0]))->toBe(['text' => '🔁 Реванш', 'url' => $url]);
});

it('ends a drawn challenge with "New challenge" for both', function (): void {
    [$a, $b, $friendship] = fmFriends('uz');
    $svc = app(ChallengeService::class);
    $now = CarbonImmutable::parse('2026-10-22 10:00:00', 'UTC');
    $c = $svc->propose($a, $b, 'reviews', now: $now);
    $svc->accept($b, $c->id, $now);
    $this->tg->sent = [];

    $svc->close($c->fresh() ?? $c, CarbonImmutable::parse('2026-11-01 00:00:00', 'UTC'));

    expect(fmTo(9301)[0]['text'])->toContain('вничью')
        ->and(fmButton(fmTo(9301)[0])['text'])->toBe('🎯 Новый вызов')
        ->and(fmButton(fmTo(9302)[0]))->toBe(['text' => '🎯 Yangi musobaqa', 'url' => "https://twa.test/twa/friends?friend={$friendship->id}&from=challenge_finished"]);
});

it('gives the result of a challenge no button once the two are no longer friends', function (): void {
    [$a, $b, $friendship] = fmFriends();
    $svc = app(ChallengeService::class);
    $now = CarbonImmutable::parse('2026-10-22 10:00:00', 'UTC');
    $c = $svc->propose($a, $b, 'reviews', now: $now);
    $svc->accept($b, $c->id, $now);
    $svc->close($c->fresh() ?? $c, CarbonImmutable::parse('2026-11-01 00:00:00', 'UTC'));
    $this->tg->sent = [];
    NotificationLog::query()->where('type', FriendsMessenger::NOTIFICATION_TYPE)->delete();
    $friendship->delete();

    app(FriendsMessenger::class)->notify(FriendDmKind::ChallengeFinished, $c->id, 'challenger');

    expect($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['text'])->toContain('завершён')
        ->and($this->tg->sent[0]['markup'])->toBeNull();
});

it('puts "Challenge" under "X accepted your invitation" and "Friends" under the answer to the tap', function (): void {
    [$a, $b] = fmFriends();
    $row = Friendship::query()->sole();
    $row->forceFill(['status' => 'pending', 'accepted_at' => null, 'requested_by_learner_id' => $a->id])->save();

    fmTap(9302, "fr1:acc:{$row->id}", 80);

    expect($row->fresh()?->status)->toBe('accepted')
        ->and(fmTo(9301))->toHaveCount(1)
        ->and(fmTo(9301)[0]['text'])->toContain('принял(а) ваше приглашение')
        ->and(fmButton(fmTo(9301)[0]))->toBe(['text' => '⚔️ Бросить вызов', 'url' => "https://twa.test/twa/friends?friend={$row->id}&from=friend_accepted"])
        ->and(end(test()->tg->sent)['text'])->toContain('теперь друзья')
        ->and(fmButton(end(test()->tg->sent)))->toBe(['text' => '👥 Друзья', 'url' => 'https://twa.test/twa/friends?from=friends']);
});

it('puts "Friends" under "you are friends now" when the request meets one the other side had sent', function (): void {
    $inviter = Learner::factory()->onboarded()->create(['telegram_user_id' => 9311, 'first_name' => 'Dilnoza', 'referral_code' => 'FRIEND99', 'interface_locale' => 'ru']);
    $me = Learner::factory()->onboarded()->create(['telegram_user_id' => 9312, 'first_name' => 'Ali', 'interface_locale' => 'ru']);
    Friendship::factory()->pending()->between($inviter, $me, $inviter)->create();

    fmTap(9312, 'fr1:snd:FRIEND99', 55);

    expect(end($this->tg->sent)['text'])->toContain('теперь друзья')
        ->and(fmButton(end($this->tg->sent)))->toBe(['text' => '👥 Друзья', 'url' => 'https://twa.test/twa/friends?from=friends']);
});

it('leaves "request sent" without a button: there is nothing to do but wait', function (): void {
    Learner::factory()->onboarded()->create(['telegram_user_id' => 9321, 'first_name' => 'Dilnoza', 'referral_code' => 'FRIEND77', 'interface_locale' => 'ru']);
    Learner::factory()->onboarded()->create(['telegram_user_id' => 9322, 'first_name' => 'Ali', 'interface_locale' => 'ru']);

    fmTap(9322, 'fr1:snd:FRIEND77', 55);

    $mine = fmTo(9322);
    expect(end($mine)['text'])->toContain('Запрос отправлен')
        ->and(end($mine)['markup'])->toBeNull();
});

it('keeps the marker rules with a button: a 403 drops it and closes the DM, a 429 drops it and rethrows, the retry sends once', function (): void {
    [$a, $b] = fmFriends();
    $c = app(ChallengeService::class)->propose($a, $b, 'reviews', now: CarbonImmutable::now());
    app(ChallengeService::class)->accept($b, $c->id, CarbonImmutable::now());
    $this->tg->sent = [];
    NotificationLog::query()->where('type', FriendsMessenger::NOTIFICATION_TYPE)->delete();

    $this->tg->failSends = 1;
    $this->tg->failSendsRetryAfter = 3;
    expect(fn () => app(FriendsMessenger::class)->notify(FriendDmKind::ChallengeAccepted, $c->id))->toThrow(TelegramRateLimitedException::class)
        ->and(NotificationLog::query()->where('type', FriendsMessenger::NOTIFICATION_TYPE)->count())->toBe(0);

    app(FriendsMessenger::class)->notify(FriendDmKind::ChallengeAccepted, $c->id);
    app(FriendsMessenger::class)->notify(FriendDmKind::ChallengeAccepted, $c->id);
    expect($this->tg->sent)->toHaveCount(1)
        ->and(fmButton($this->tg->sent[0])['url'])->toContain("challenge={$c->id}");

    NotificationLog::query()->where('type', FriendsMessenger::NOTIFICATION_TYPE)->delete();
    $this->tg->failSendsRetryAfter = null;
    $this->tg->failSends = 1;
    $this->tg->failSendsForbidden = TelegramForbiddenException::REASON_BOT_BLOCKED;

    app(FriendsMessenger::class)->notify(FriendDmKind::ChallengeAccepted, $c->id);

    expect(NotificationLog::query()->where('type', FriendsMessenger::NOTIFICATION_TYPE)->count())->toBe(0)
        ->and($a->fresh()?->dm_blocked_at)->not->toBeNull();
});
