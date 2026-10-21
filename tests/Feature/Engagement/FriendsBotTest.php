<?php

declare(strict_types=1);

use App\Domain\Engagement\Services\ChallengeService;
use App\Domain\Engagement\Services\FriendshipService;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Jobs\AnnounceAssignmentJob;
use App\Jobs\PostLeaderboardJob;
use App\Jobs\SendFriendDmJob;
use App\Jobs\SendLearnerReminderJob;
use App\Jobs\SendTelegramMessageJob;
use App\Models\Challenge;
use App\Models\Friendship;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\NotificationLog;
use App\Models\Referral;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeTelegramClient;

/*
 * The bot side of friends and challenges (docs/13 §7.5): `/start f_<code>` and `ref_<code>` of an existing
 * learner offer a friendship, the buttons answer it, and the background DMs (accepted, challenge proposed /
 * accepted / finished) go to the right person in their language. The queue is `sync` in tests.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['telegram.bot_username' => 'LexiFlowBot', 'friends.challenges' => ['days' => 7, 'pending_ttl_hours' => 72]]);
    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);
});

/** @param array<string, mixed> $from */
function frSend(int $tgId, string $text, array $from = []): void
{
    app(TelegramDispatcher::class)->dispatch(['message' => [
        'chat' => ['id' => $tgId, 'type' => 'private'],
        'from' => ['id' => $tgId, 'first_name' => 'Ali', 'language_code' => 'ru', ...$from],
        'text' => $text,
    ]]);
}

function frTap(int $tgId, string $data, int $messageId = 100, string $chatType = 'private', ?int $chatId = null): void
{
    app(TelegramDispatcher::class)->dispatch(['callback_query' => [
        'id' => 'cb-'.$messageId.'-'.md5($data.$tgId),
        'from' => ['id' => $tgId, 'first_name' => 'Ali', 'language_code' => 'ru'],
        'message' => ['message_id' => $messageId, 'chat' => ['id' => $chatId ?? ($chatType === 'private' ? $tgId : -500), 'type' => $chatType]],
        'data' => $data,
    ]]);
}

function frInviter(int $tgId = 9001, string $code = 'FRIEND23', string $name = 'Dilnoza'): Learner
{
    return Learner::factory()->onboarded()->create(['telegram_user_id' => $tgId, 'first_name' => $name, 'referral_code' => $code, 'interface_locale' => 'ru']);
}

function frOnboarded(int $tgId, string $locale = 'ru'): Learner
{
    return Learner::factory()->onboarded()->create(['telegram_user_id' => $tgId, 'first_name' => 'Ali', 'interface_locale' => $locale]);
}

/** @return list<string> */
function frTexts(): array
{
    return array_column(test()->tg->sent, 'text');
}

it('asks on /start f_<code> and writes NOTHING until the learner agrees', function (): void {
    frInviter();
    $me = frOnboarded(9101);

    frSend(9101, '/start f_FRIEND23');

    expect(Friendship::query()->count())->toBe(0)
        ->and($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['text'])->toContain('Отправить Dilnoza запрос в друзья?')
        ->and($this->tg->lastCallbackData())->toBe(['fr1:snd:FRIEND23', 'fr1:no:FRIEND23'])
        ->and(Referral::query()->count())->toBe(0);

    // A repeated /start asks again and still writes nothing.
    frSend(9101, '/start f_FRIEND23');
    expect(Friendship::query()->count())->toBe(0)->and($this->tg->sent)->toHaveCount(2);
    // First touch is attributed as for any payload.
    expect($me->fresh()?->acquisition_source)->toBe('friend')->and($me->fresh()?->referrer_learner_id)->toBeNull();
});

it('sends the request on agreement: opener -> inviter, the inviter is DMed with buttons and answers it himself', function (): void {
    $inviter = frInviter();
    $inviter->forceFill(['interface_locale' => 'uz'])->save();
    $me = frOnboarded(9102);
    frSend(9102, '/start f_FRIEND23');

    frTap(9102, 'fr1:snd:FRIEND23', 55);

    $row = Friendship::query()->sole();
    expect($row->status)->toBe('pending')
        ->and($row->requested_by_learner_id)->toBe($me->id)
        ->and($this->tg->edits)->toHaveCount(1)
        ->and($this->tg->edits[0]['message_id'])->toBe(55);
    $toInviter = array_values(array_filter($this->tg->sent, fn (array $m): bool => $m['chat_id'] === 9001));
    expect($toInviter)->toHaveCount(1)
        ->and($toInviter[0]['text'])->toBe("Ali sizni LexiFlow'da do'st sifatida qo'shmoqchi.")
        ->and($toInviter[0]['markup']['inline_keyboard'][0][0]['callback_data'])->toBe("fr1:acc:{$row->id}")
        ->and(end($this->tg->sent)['text'])->toContain('Запрос отправлен');

    // Tapping "send" again: the same row, no second DM to the inviter.
    frTap(9102, 'fr1:snd:FRIEND23', 55);
    expect(Friendship::query()->count())->toBe(1)
        ->and(array_values(array_filter($this->tg->sent, fn (array $m): bool => $m['chat_id'] === 9001)))->toHaveCount(1);

    // The opener cannot accept their own request; the inviter can.
    frTap(9102, "fr1:acc:{$row->id}");
    expect($row->fresh()?->status)->toBe('pending');
    frTap(9001, "fr1:acc:{$row->id}");
    expect($row->fresh()?->status)->toBe('accepted');
    $toMe = array_values(array_filter($this->tg->sent, fn (array $m): bool => $m['chat_id'] === 9102 && str_contains($m['text'], 'принял(а) ваше приглашение')));
    expect($toMe)->toHaveCount(1);

    // And /start of the same link now says so.
    frSend(9102, '/start f_FRIEND23');
    expect(end($this->tg->sent)['text'])->toContain('уже друзья');
});

it('does nothing when the learner answers "not now"', function (): void {
    frInviter();
    frOnboarded(9103);
    frSend(9103, '/start f_FRIEND23');

    frTap(9103, 'fr1:no:FRIEND23', 60);

    expect(Friendship::query()->count())->toBe(0)
        ->and($this->tg->edits)->toHaveCount(1)
        ->and(end($this->tg->sent)['text'])->toBe('Хорошо, запрос не отправлен.');
});

it('lets the inviter decline: the request is gone and may be sent again', function (): void {
    frInviter();
    frOnboarded(9104);
    frSend(9104, '/start f_FRIEND23');
    frTap(9104, 'fr1:snd:FRIEND23');
    $row = Friendship::query()->sole();

    frTap(9001, "fr1:dec:{$row->id}");

    expect(Friendship::query()->count())->toBe(0)
        ->and(end($this->tg->sent)['text'])->toContain('отклонено');

    frTap(9104, 'fr1:snd:FRIEND23');
    expect(Friendship::query()->sole()->status)->toBe('pending');
});

it('shows the existing inbound request when the inviter had already asked this learner, and says so for the other states', function (): void {
    $inviter = frInviter();
    $me = frOnboarded(9105);
    $row = Friendship::factory()->pending()->between($inviter, $me, $inviter)->create();

    frSend(9105, '/start f_FRIEND23');
    expect($this->tg->sent[0]['text'])->toContain('Dilnoza хочет добавить вас в друзья')
        ->and($this->tg->lastCallbackData())->toBe(["fr1:acc:{$row->id}", "fr1:dec:{$row->id}"]);

    $row->forceFill(['requested_by_learner_id' => $me->id])->save();
    frSend(9105, '/start f_FRIEND23');
    expect(end($this->tg->sent)['text'])->toBe('Вы уже отправили запрос Dilnoza — ждём ответа.');
});

it('does not let anybody answer someone else\'s request', function (): void {
    $inviter = frInviter();
    $me = frOnboarded(9106);
    frSend(9106, '/start f_FRIEND23');
    frTap(9106, 'fr1:snd:FRIEND23');
    $row = Friendship::query()->sole();
    frOnboarded(9107); // a stranger who got hold of the callback data
    $before = count($this->tg->sent);

    frTap(9107, "fr1:acc:{$row->id}");
    frTap(9106, "fr1:acc:{$row->id}"); // the sender cannot accept their own request

    expect($row->fresh()?->status)->toBe('pending')
        ->and(array_slice(frTexts(), $before))->toBe([
            'Это приглашение больше не действует.',
            'Ответить на приглашение может только тот, кого пригласили.',
        ]);
    expect($inviter->fresh()?->id)->toBe($inviter->id)->and($me->fresh()?->id)->toBe($me->id);
});

it('ignores malformed, foreign-chat and unfinished-onboarding taps', function (): void {
    frInviter();
    frOnboarded(9108);
    $row = Friendship::factory()->pending()->between(Learner::query()->where('telegram_user_id', 9001)->firstOrFail(), Learner::query()->where('telegram_user_id', 9108)->firstOrFail(), Learner::query()->where('telegram_user_id', 9001)->firstOrFail())->create();
    $sent = count($this->tg->sent);

    frTap(9108, 'fr1:acc:abc');
    frTap(9108, "fr1:acc:{$row->id};DROP");
    frTap(9108, "fr1:zzz:{$row->id}");
    frTap(9108, 'fr1:snd:123'); // not a referral code
    frTap(9108, "fr1:acc:{$row->id}", chatType: 'group');
    frTap(9108, "fr1:acc:{$row->id}", chatId: 1234);
    frTap(9108, 'fr1:snd:FRIEND23', chatType: 'group');
    Learner::factory()->create(['telegram_user_id' => 9109, 'onboarding_state' => 'goal']);
    frTap(9109, "fr1:acc:{$row->id}");
    frTap(9109, 'fr1:snd:FRIEND23');

    expect($row->fresh()?->status)->toBe('pending')
        ->and(Friendship::query()->count())->toBe(1)
        ->and($this->tg->sent)->toHaveCount($sent);
});

it('offers NO friendship on ref_<code> of an existing learner: the referral stays rejected, the learner gets "Today"', function (): void {
    frInviter(9001, 'REFFRND2');
    $me = frOnboarded(9110);

    frSend(9110, '/start ref_REFFRND2');

    $referral = Referral::query()->sole();
    expect($referral->status)->toBe('rejected')
        ->and($referral->reject_reason)->toBe('existing_learner')
        ->and($referral->referee_learner_id)->toBe($me->id)
        ->and(Friendship::query()->count())->toBe(0)
        ->and(implode("\n", frTexts()))->not->toContain('в друзья')
        ->and(array_filter($this->tg->sent, fn (array $m): bool => str_contains((string) json_encode($m['markup'] ?? []), 'fr1:')))->toBeEmpty();
});

it('keeps ref_<code> of a NEW learner a plain referral: no friendship, ordinary onboarding', function (): void {
    frInviter(9001, 'REFNEW23');

    frSend(9111, '/start ref_REFNEW23');

    expect(Referral::query()->sole()->status)->toBe('pending')
        ->and(Friendship::query()->count())->toBe(0)
        ->and($this->tg->sent[0]['text'])->not->toContain('в друзья');
});

it('writes nothing for f_<code> of a NEW learner: no consent yet, the onboarding goes on undisturbed', function (): void {
    frInviter(9001, 'NEWFRND2');

    frSend(9112, '/start f_NEWFRND2');

    $me = Learner::query()->where('telegram_user_id', 9112)->firstOrFail();
    expect($me->acquisition_source)->toBe('friend')
        ->and($me->start_payload)->toBe('f_NEWFRND2')
        ->and($me->referrer_learner_id)->toBeNull()
        ->and(Referral::query()->count())->toBe(0)
        ->and(Friendship::query()->count())->toBe(0)
        ->and($this->tg->sent[0]['text'])->not->toContain('в друзья');
});

it('explains an own link, an unknown code and a blocked pair, and writes nothing', function (): void {
    $me = frInviter(9111, 'OWNCODE2', 'Self');
    frSend(9111, '/start f_OWNCODE2');
    frSend(9111, '/start f_UNKNOWN2');
    expect(frTexts())->toBe([
        'Это ваша собственная ссылка. Отправьте её другу.',
        'Ссылка недействительна или устарела.',
    ]);

    $inviter = frInviter(9001);
    $other = frOnboarded(9112);
    app(FriendshipService::class)->block($other, $inviter);
    frSend(9112, '/start f_FRIEND23');
    expect(end($this->tg->sent)['text'])->toBe('Эта ссылка недоступна.')
        ->and(Friendship::query()->sole()->status)->toBe('blocked');
    expect($me->fresh()?->id)->toBe($me->id);
});

it('speaks Uzbek in the offer', function (): void {
    frInviter();
    frOnboarded(9113, 'uz');

    frSend(9113, '/start f_FRIEND23');

    expect($this->tg->sent[0]['text'])->toContain("Dilnoza'ga do'stlik so'rovini yuboraylikmi?")
        ->and($this->tg->sent[0]['markup']['inline_keyboard'][0][0]['text'])->toBe("So'rov yuborish");
});

// --- challenges: the DMs and their buttons ------------------------------------------------------

/** @return array{0: Learner, 1: Learner} two friends with their Telegram ids 9201 / 9202 */
function frFriends(string $localeB = 'ru'): array
{
    $a = Learner::factory()->onboarded()->create(['telegram_user_id' => 9201, 'first_name' => 'Aziza', 'interface_locale' => 'ru', 'timezone' => 'Asia/Tashkent']);
    $b = Learner::factory()->onboarded()->create(['telegram_user_id' => 9202, 'first_name' => 'Bek', 'interface_locale' => $localeB, 'timezone' => 'Asia/Tashkent']);
    Friendship::factory()->between($a, $b)->create();

    return [$a, $b];
}

it('DMs the challenged friend with Accept / Decline, and the challenger when it is accepted', function (): void {
    [$a, $b] = frFriends();
    $c = app(ChallengeService::class)->propose($a, $b, 'reviews', now: CarbonImmutable::now());

    expect($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['chat_id'])->toBe(9202)
        ->and($this->tg->sent[0]['text'])->toBe('Aziza бросает вам вызов: кто больше повторит карточек за 7 дн.? Если примете, вызов начнётся завтра.')
        ->and($this->tg->lastCallbackData())->toBe(["fr1:cacc:{$c->id}", "fr1:cdec:{$c->id}"]);

    frTap(9202, "fr1:cacc:{$c->id}", 70);

    $c->refresh();
    expect($c->status)->toBe('active')
        ->and($this->tg->sent)->toHaveCount(3);
    $toChallenger = array_values(array_filter($this->tg->sent, fn (array $m): bool => $m['chat_id'] === 9201));
    $toOpponent = array_values(array_filter(array_slice($this->tg->sent, 1), fn (array $m): bool => $m['chat_id'] === 9202));
    expect($toChallenger)->toHaveCount(1)
        ->and($toChallenger[0]['text'])->toContain('Ali принял(а) ваш вызов')
        ->and($toOpponent)->toHaveCount(1)
        ->and($toOpponent[0]['text'])->toContain('Вызов принят');

    // The challenger cannot accept their own challenge; a stranger cannot either.
    frTap(9201, "fr1:cacc:{$c->id}");
    expect(end($this->tg->sent)['text'])->toBe('Ответить на вызов может только тот, кому он адресован.');
    frOnboarded(9203);
    frTap(9203, "fr1:cdec:{$c->id}");
    expect(end($this->tg->sent)['text'])->toBe('Этот вызов больше не действует.')
        ->and($c->fresh()?->status)->toBe('active');
});

it('declines a challenge on the button', function (): void {
    [$a, $b] = frFriends();
    $c = app(ChallengeService::class)->propose($a, $b, 'new_words', now: CarbonImmutable::now());

    frTap(9202, "fr1:cdec:{$c->id}");

    expect($c->fresh()?->status)->toBe('declined')
        ->and(end($this->tg->sent)['text'])->toContain('отклонён');
});

it('sends each side its own result, once, in its own language', function (): void {
    [$a, $b] = frFriends('uz');
    $svc = app(ChallengeService::class);
    $now = CarbonImmutable::parse('2026-10-22 10:00:00', 'UTC');
    $c = $svc->propose($a, $b, 'reviews', now: $now);
    $svc->accept($b, $c->id, $now);
    LearnerDailyStats::factory()->create(['learner_id' => $a->id, 'date' => '2026-10-24', 'reviews_count' => 40]);
    LearnerDailyStats::factory()->create(['learner_id' => $b->id, 'date' => '2026-10-24', 'reviews_count' => 25]);
    $this->tg->sent = [];

    $svc->close($c->fresh() ?? $c, CarbonImmutable::parse('2026-11-01 00:00:00', 'UTC'));
    $svc->close($c->fresh() ?? $c, CarbonImmutable::parse('2026-11-02 00:00:00', 'UTC'));

    expect($this->tg->sent)->toHaveCount(2)
        ->and($this->tg->sent[0]['chat_id'])->toBe(9201)
        ->and($this->tg->sent[0]['text'])->toBe('Вызов с Bek завершён: вы победили! Счёт 40 : 25.')
        ->and($this->tg->sent[1]['chat_id'])->toBe(9202)
        ->and($this->tg->sent[1]['text'])->toBe("Aziza bilan musobaqa yakunlandi: Aziza g'alaba qozondi. Hisob 25 : 40. Qayta bellashuv — ilovada!");
});

it('sends nothing to a learner whose DM is closed or who is blocked', function (): void {
    [$a, $b] = frFriends();
    $b->forceFill(['dm_blocked_at' => now()])->save();
    $c = app(ChallengeService::class)->propose($a, $b, 'reviews', now: CarbonImmutable::now());
    expect($this->tg->sent)->toHaveCount(0);

    // Reachable again, the same pending challenge would be announced...
    $b->forceFill(['dm_blocked_at' => null])->save();
    SendFriendDmJob::dispatchSync('challenge_proposed', $c->id);
    expect($this->tg->sent)->toHaveCount(1);

    // ...but not to an account an administrator blocked.
    $this->tg->sent = [];
    $b->forceFill(['blocked_at' => now()])->save();
    SendFriendDmJob::dispatchSync('challenge_proposed', $c->id);
    expect($this->tg->sent)->toHaveCount(0);
});

it('marks the DM as closed when Telegram answers 403', function (): void {
    [$a, $b] = frFriends();
    $mock = Mockery::mock(TelegramClient::class);
    $mock->shouldReceive('sendMessage')->once()->andThrow(new TelegramForbiddenException(TelegramForbiddenException::REASON_BOT_BLOCKED));
    $this->app->instance(TelegramClient::class, $mock);

    $c = Challenge::factory()->create(['challenger_id' => $a->id, 'opponent_id' => $b->id, 'open_pair' => 'x']);
    SendFriendDmJob::dispatchSync('challenge_proposed', $c->id);

    expect($b->fresh()?->dm_blocked_at)->not->toBeNull();
});

it('does not repeat a DM that was already delivered when its job runs again', function (): void {
    [$a, $b] = frFriends();
    $c = Challenge::factory()->create(['challenger_id' => $a->id, 'opponent_id' => $b->id, 'open_pair' => 'x']);
    $friendship = Friendship::query()->sole(); // accepted: "friend_accepted" goes to the requester
    $friendship->forceFill(['requested_by_learner_id' => $a->id])->save();

    foreach (range(1, 3) as $_) {
        SendFriendDmJob::dispatchSync('challenge_proposed', $c->id);
        SendFriendDmJob::dispatchSync('friend_accepted', $friendship->id);
    }

    expect($this->tg->sent)->toHaveCount(2)
        ->and(NotificationLog::query()->where('type', 'friend_dm')->count())->toBe(2);

    // A finished challenge: each side once, independently.
    $c->forceFill(['status' => 'finished', 'scores' => ['challenger' => 3, 'opponent' => 1], 'winner_learner_id' => $a->id])->save();
    $this->tg->sent = [];
    foreach (range(1, 2) as $_) {
        SendFriendDmJob::dispatchSync('challenge_finished', $c->id, 'challenger');
        SendFriendDmJob::dispatchSync('challenge_finished', $c->id, 'opponent');
    }
    expect($this->tg->sent)->toHaveCount(2);
});

it('lets a DM be retried after a 403 (nothing was delivered) and keeps the marker after other failures', function (): void {
    [$a, $b] = frFriends();
    $c = Challenge::factory()->create(['challenger_id' => $a->id, 'opponent_id' => $b->id, 'open_pair' => 'x']);
    $mock = Mockery::mock(TelegramClient::class);
    $mock->shouldReceive('sendMessage')->once()->andThrow(new TelegramForbiddenException(TelegramForbiddenException::REASON_BOT_BLOCKED));
    $this->app->instance(TelegramClient::class, $mock);

    SendFriendDmJob::dispatchSync('challenge_proposed', $c->id);

    expect(NotificationLog::query()->where('type', 'friend_dm')->count())->toBe(0);
});

it('gives every job that waits for the bot-wide budget a finite retry window', function (): void {
    $jobs = [
        new SendFriendDmJob('friend_accepted', 1),
        new SendTelegramMessageJob(1, 'x'),
        new SendLearnerReminderJob(1, CarbonImmutable::now()),
        new AnnounceAssignmentJob(1),
        new PostLeaderboardJob(1),
    ];

    foreach ($jobs as $job) {
        expect($job->tries)->toBe(0)
            ->and(method_exists($job, 'retryUntil'))->toBeTrue()
            ->and($job->retryUntil()->getTimestamp())->toBeGreaterThan(time())
            ->and($job->retryUntil()->getTimestamp())->toBeLessThanOrEqual(time() + 6 * 3600 + 5);
    }
});

it('does not announce a state that no longer holds', function (): void {
    [$a, $b] = frFriends();
    $c = Challenge::factory()->create(['challenger_id' => $a->id, 'opponent_id' => $b->id, 'status' => 'declined']);

    SendFriendDmJob::dispatchSync('challenge_proposed', $c->id);
    SendFriendDmJob::dispatchSync('challenge_finished', $c->id, 'challenger');
    SendFriendDmJob::dispatchSync('friend_accepted', 999999);
    SendFriendDmJob::dispatchSync('nonsense', $c->id);

    expect($this->tg->sent)->toHaveCount(0);
});
