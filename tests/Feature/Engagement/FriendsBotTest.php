<?php

declare(strict_types=1);

use App\Domain\Engagement\Services\ChallengeService;
use App\Domain\Engagement\Services\FriendshipService;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Jobs\SendFriendDmJob;
use App\Models\Challenge;
use App\Models\Friendship;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
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

it('offers a friendship on /start f_<code> to a learner and records the inviter\'s pending request', function (): void {
    $inviter = frInviter();
    $me = frOnboarded(9101);

    frSend(9101, '/start f_FRIEND23');

    $row = Friendship::query()->sole();
    expect($row->status)->toBe('pending')
        ->and($row->requested_by_learner_id)->toBe($inviter->id)
        ->and($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['text'])->toContain('Dilnoza приглашает вас в друзья')
        ->and($this->tg->lastCallbackData())->toBe(["fr1:acc:{$row->id}", "fr1:dec:{$row->id}"])
        ->and(Referral::query()->count())->toBe(0);

    // A repeated /start is the same row and the same question.
    frSend(9101, '/start f_FRIEND23');
    expect(Friendship::query()->count())->toBe(1)->and($this->tg->sent)->toHaveCount(2);
    // First touch is attributed as for any payload (the inviter's pending request is not a referral).
    expect($me->fresh()?->acquisition_source)->toBe('friend')->and($me->fresh()?->referrer_learner_id)->toBeNull();
});

it('accepts on the button: friends, the keyboard is cleared, the inviter is told in their language', function (): void {
    $inviter = frInviter();
    $inviter->forceFill(['interface_locale' => 'uz'])->save();
    $me = frOnboarded(9102);
    frSend(9102, '/start f_FRIEND23');
    $row = Friendship::query()->sole();

    frTap(9102, "fr1:acc:{$row->id}", 55);

    expect($row->fresh()?->status)->toBe('accepted')
        ->and($this->tg->edits)->toHaveCount(1)
        ->and($this->tg->edits[0]['message_id'])->toBe(55)
        ->and($this->tg->sent)->toHaveCount(3);

    // The queue is sync: the inviter's DM goes out inside the service call, before the reply to the tapper.
    $toInviter = array_values(array_filter($this->tg->sent, fn (array $m): bool => $m['chat_id'] === 9001));
    $toMe = array_values(array_filter($this->tg->sent, fn (array $m): bool => $m['chat_id'] === 9102 && str_contains($m['text'], 'теперь друзья')));
    expect($toInviter)->toHaveCount(1)
        ->and($toInviter[0]['text'])->toBe("Ali taklifingizni qabul qildi: endi siz do'stsiz!")
        ->and($toMe)->toHaveCount(1);

    // Tapping again is harmless: still one friendship, no second DM to the inviter.
    frTap(9102, "fr1:acc:{$row->id}", 55);
    expect(Friendship::query()->count())->toBe(1)
        ->and(array_values(array_filter($this->tg->sent, fn (array $m): bool => $m['chat_id'] === 9001)))->toHaveCount(1);

    // And /start of the same link now says so.
    frSend(9102, '/start f_FRIEND23');
    expect(end($this->tg->sent)['text'])->toContain('уже друзья');
});

it('declines on the button: the request is gone and may be sent again', function (): void {
    frInviter();
    frOnboarded(9103);
    frSend(9103, '/start f_FRIEND23');
    $row = Friendship::query()->sole();

    frTap(9103, "fr1:dec:{$row->id}");

    expect(Friendship::query()->count())->toBe(0)
        ->and(end($this->tg->sent)['text'])->toContain('отклонено');

    frSend(9103, '/start f_FRIEND23');
    expect(Friendship::query()->sole()->status)->toBe('pending');
});

it('does not let anybody answer someone else\'s invitation', function (): void {
    $inviter = frInviter();
    frOnboarded(9104);
    frSend(9104, '/start f_FRIEND23');
    $row = Friendship::query()->sole();
    frOnboarded(9105); // a stranger who got hold of the callback data
    $before = count($this->tg->sent);

    frTap(9105, "fr1:acc:{$row->id}");
    frTap(9001, "fr1:acc:{$row->id}"); // the inviter cannot accept their own request

    expect($row->fresh()?->status)->toBe('pending')
        ->and(array_slice(frTexts(), $before))->toBe([
            'Это приглашение больше не действует.',
            'Ответить на приглашение может только тот, кого пригласили.',
        ]);
    expect($inviter->fresh()?->id)->toBe($inviter->id);
});

it('ignores malformed, foreign-chat and unfinished-onboarding taps', function (): void {
    frInviter();
    frOnboarded(9106);
    frSend(9106, '/start f_FRIEND23');
    $row = Friendship::query()->sole();
    $sent = count($this->tg->sent);

    frTap(9106, 'fr1:acc:abc');
    frTap(9106, "fr1:acc:{$row->id};DROP");
    frTap(9106, "fr1:zzz:{$row->id}");
    frTap(9106, "fr1:acc:{$row->id}", chatType: 'group');
    frTap(9106, "fr1:acc:{$row->id}", chatId: 1234);
    Learner::factory()->create(['telegram_user_id' => 9107, 'onboarding_state' => 'goal']);
    frTap(9107, "fr1:acc:{$row->id}");

    expect($row->fresh()?->status)->toBe('pending')
        ->and($this->tg->sent)->toHaveCount($sent);
});

it('turns ref_<code> of an EXISTING learner into a friend offer, while the referral stays rejected', function (): void {
    $inviter = frInviter(9001, 'REFFRND2');
    $me = frOnboarded(9108);

    frSend(9108, '/start ref_REFFRND2');

    $referral = Referral::query()->sole();
    expect($referral->status)->toBe('rejected')
        ->and($referral->reject_reason)->toBe('existing_learner')
        ->and($referral->referee_learner_id)->toBe($me->id)
        ->and(Friendship::query()->sole()->requested_by_learner_id)->toBe($inviter->id)
        ->and($this->tg->sent[0]['text'])->toContain('приглашает вас в друзья');
});

it('keeps ref_<code> of a NEW learner a plain referral: no friendship, ordinary onboarding', function (): void {
    frInviter(9001, 'REFNEW23');

    frSend(9109, '/start ref_REFNEW23');

    expect(Referral::query()->sole()->status)->toBe('pending')
        ->and(Friendship::query()->count())->toBe(0)
        ->and($this->tg->sent[0]['text'])->not->toContain('в друзья');
});

it('remembers f_<code> of a NEW learner as a pending request for the app and goes on with the onboarding', function (): void {
    $inviter = frInviter(9001, 'NEWFRND2');

    frSend(9110, '/start f_NEWFRND2');

    $me = Learner::query()->where('telegram_user_id', 9110)->firstOrFail();
    $row = Friendship::query()->sole();
    expect($me->acquisition_source)->toBe('friend')
        ->and($me->start_payload)->toBe('f_NEWFRND2')
        ->and($me->referrer_learner_id)->toBeNull()
        ->and(Referral::query()->count())->toBe(0)
        ->and($row->status)->toBe('pending')
        ->and($row->requested_by_learner_id)->toBe($inviter->id)
        ->and($this->tg->sent[0]['text'])->not->toContain('в друзья');

    $overview = app(FriendshipService::class)->overview($me);
    expect($overview->incoming)->toHaveCount(1);
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

    expect($this->tg->sent[0]['text'])->toContain("Dilnoza sizni LexiFlow'da do'st bo'lishga taklif qilmoqda")
        ->and($this->tg->sent[0]['markup']['inline_keyboard'][0][0]['text'])->toBe('Qabul qilish');
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

it('does not announce a state that no longer holds', function (): void {
    [$a, $b] = frFriends();
    $c = Challenge::factory()->create(['challenger_id' => $a->id, 'opponent_id' => $b->id, 'status' => 'declined']);

    SendFriendDmJob::dispatchSync('challenge_proposed', $c->id);
    SendFriendDmJob::dispatchSync('challenge_finished', $c->id, 'challenger');
    SendFriendDmJob::dispatchSync('friend_accepted', 999999);
    SendFriendDmJob::dispatchSync('nonsense', $c->id);

    expect($this->tg->sent)->toHaveCount(0);
});
