<?php

declare(strict_types=1);

/*
 * The invitation a learner opened the bot with (`f_<code>`, `org_<code>`) when the onboarding ends in the Mini App instead of the bot
 * (docs/40 §2.1, §5 п. 1): the bot offers it once the Mini App completes the onboarding (OfferPendingInvitesJob), and - for a learner
 * the job could not reach - at the next `/start`. Offered once, whichever path comes first.
 */

use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Services\FriendsMessenger;
use App\Domain\Telegram\Services\OrganizationMessenger;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Domain\Twa\Services\JwtService;
use App\Jobs\OfferPendingInvitesJob;
use App\Models\Friendship;
use App\Models\Learner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeTelegramClient;
use Tests\Support\HotPathFixtures as Fx;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['telegram.bot_username' => 'LexiFlowBot', 'learners.goals' => ['general']]);
    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);
    Fx::course([1 => [1 => 3]], ['slug' => 'english-b1', 'exam_tag' => 'general', 'level' => 'B1']);
    Learner::factory()->create(['telegram_user_id' => 9001, 'first_name' => 'Dilnoza', 'referral_code' => 'NEWFRND2', 'onboarding_state' => 'done']);
});

/** @param  array<string, mixed>  $attributes */
function piLearner(array $attributes = []): Learner
{
    return Learner::factory()->create([
        'telegram_user_id' => 9312, 'first_name' => 'Ali', 'interface_locale' => 'ru', 'level' => 'B1', 'onboarding_state' => 'level',
        'meta' => ['pending_friend_invite' => 'NEWFRND2'],
        ...$attributes,
    ]);
}

function piComplete(Learner $learner): TestResponse
{
    $token = app(JwtService::class)->issue($learner->id)['token'];

    return test()->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/twa/onboarding/complete');
}

function piStart(int $tgId): void
{
    app(TelegramDispatcher::class)->dispatch(['message' => [
        'chat' => ['id' => $tgId, 'type' => 'private'],
        'from' => ['id' => $tgId, 'first_name' => 'Ali', 'language_code' => 'ru'],
        'text' => '/start',
    ]]);
}

/** @return list<array<string, mixed>> the messages with the "Add to friends" button */
function piOffers(FakeTelegramClient $tg): array
{
    return array_values(array_filter($tg->sent, fn (array $m): bool => ($m['markup']['inline_keyboard'][0][0]['callback_data'] ?? null) === 'fr1:snd:NEWFRND2'));
}

it('offers the friend invitation in the private chat right after the Mini App completes the onboarding, once', function (): void {
    $learner = piLearner();

    piComplete($learner)->assertOk()->assertJsonPath('completed', true);

    $offers = piOffers($this->tg);
    expect($offers)->toHaveCount(1)
        ->and($offers[0]['chat_id'])->toBe(9312)
        ->and($offers[0]['text'])->toContain('Dilnoza')
        ->and($offers[0]['markup']['inline_keyboard'][0][0]['text'])->toBe('Добавить в друзья')
        ->and(Friendship::query()->count())->toBe(0) // only the question: consent comes with the tap
        ->and($learner->fresh()?->meta)->not->toHaveKey('pending_friend_invite')
        ->and($learner->fresh()?->onboarding_state)->toBe('done');

    // A repeated completion and the next /start have nothing left to offer.
    piComplete($learner)->assertOk();
    piStart(9312);
    expect(piOffers($this->tg))->toHaveCount(1);
});

it('offers the center of a pending org link too, each offer on its own', function (): void {
    $learner = piLearner(['meta' => ['pending_org_invite' => 'NOSUCHCODE', 'pending_friend_invite' => 'NEWFRND2']]);

    piComplete($learner)->assertOk();

    // An unknown code is explained (the organization messenger's own text) and the friend offer still goes; both keys are gone.
    expect(count($this->tg->sent))->toBeGreaterThanOrEqual(2)
        ->and(piOffers($this->tg))->toHaveCount(1)
        ->and($learner->fresh()?->meta)->not->toHaveKey('pending_org_invite')->not->toHaveKey('pending_friend_invite');
});

it('queues nothing when no invitation waits, and nothing for the bot\'s own completion path', function (): void {
    Queue::fake();
    $plain = piLearner(['meta' => []]);

    piComplete($plain)->assertOk()->assertJsonPath('completed', true);
    Queue::assertNotPushed(OfferPendingInvitesJob::class);

    $pending = piLearner(['telegram_user_id' => 9313]);
    piComplete($pending)->assertOk();
    Queue::assertPushed(OfferPendingInvitesJob::class, fn (OfferPendingInvitesJob $job): bool => $job->learnerId === $pending->id);
    Queue::assertPushed(OfferPendingInvitesJob::class, 1);
});

it('keeps the code for a learner the bot cannot reach, and /start offers it later', function (): void {
    $learner = piLearner(['dm_blocked_at' => now()]);

    piComplete($learner)->assertOk();
    expect(piOffers($this->tg))->toBe([])
        ->and($learner->fresh()?->meta['pending_friend_invite'] ?? null)->toBe('NEWFRND2');

    // The learner opens the bot again: "Today", then the question - once.
    $learner->update(['dm_blocked_at' => null]);
    piStart(9312);
    $offers = piOffers($this->tg);
    expect($offers)->toHaveCount(1)
        ->and(end($this->tg->sent))->toBe($offers[0])
        ->and($learner->fresh()?->meta)->not->toHaveKey('pending_friend_invite');

    piStart(9312);
    expect(piOffers($this->tg))->toHaveCount(1);
});

it('does nothing for a blocked or missing learner when the job runs', function (): void {
    $blocked = piLearner(['blocked_at' => now(), 'onboarding_state' => 'done']);

    (new OfferPendingInvitesJob($blocked->id))->handle(app(OrganizationMessenger::class), app(FriendsMessenger::class));
    (new OfferPendingInvitesJob(999_999))->handle(app(OrganizationMessenger::class), app(FriendsMessenger::class));

    expect($this->tg->sent)->toBe([])
        ->and($blocked->fresh()?->meta['pending_friend_invite'] ?? null)->toBe('NEWFRND2');
});
