<?php

declare(strict_types=1);

use App\Domain\Learners\DTO\StartPayload;
use App\Domain\Organizations\Services\OrganizationInviteService;
use App\Domain\Organizations\Services\OrganizationMembershipService;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Services\OrganizationMessenger;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\OrganizationInvite;
use App\Models\OrganizationMember;
use App\Models\ProductEvent;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeTelegramClient;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['twa.base_url' => 'https://twa.test']);

    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);

    Course::factory()->published()->create(['slug' => 'general-a1', 'exam_tag' => 'general', 'level' => 'A1', 'is_featured' => true]);
});

/** A center that sponsors Pro with $seats seats (no subscription at all when $seats is null). */
function orgbCenter(string $name = 'Bright Center', ?int $seats = 30): Organization
{
    $org = Organization::factory()->create(['name' => $name]);
    if ($seats !== null) {
        Subscription::factory()->forOrganization($org)->create(['current_period_ends_at' => null, 'tier' => 'tutor', 'seats_limit' => $seats]);
    }

    return $org;
}

function orgbInvite(Organization $org, array $attributes = []): OrganizationInvite
{
    return OrganizationInvite::factory()->create(['organization_id' => $org->id, ...$attributes]);
}

/** @param array<string, mixed> $from */
function orgbSend(int $tgId, string $text, array $from = []): void
{
    app(TelegramDispatcher::class)->dispatch(['message' => [
        'chat' => ['id' => $tgId, 'type' => 'private'],
        'from' => ['id' => $tgId, 'first_name' => 'Ali', 'language_code' => 'ru', ...$from],
        'text' => $text,
    ]]);
}

function orgbTap(int $tgId, string $data, int $messageId = 100, string $chatType = 'private'): void
{
    app(TelegramDispatcher::class)->dispatch(['callback_query' => [
        'id' => 'cb-'.$messageId.'-'.md5($data),
        'from' => ['id' => $tgId, 'first_name' => 'Ali', 'language_code' => 'ru'],
        'message' => ['message_id' => $messageId, 'chat' => ['id' => $chatType === 'private' ? $tgId : -500, 'type' => $chatType]],
        'data' => $data,
    ]]);
}

/** Runs the five onboarding steps from the first one to `done` (Russian). */
function orgbOnboard(int $tgId): void
{
    orgbTap($tgId, 'onb1:locale:ru', 1);
    orgbTap($tgId, 'onb1:goal:general', 2);
    orgbTap($tgId, 'onb1:level:A1', 3);
    orgbTap($tgId, 'onb1:timezone:Asia/Tashkent', 4);
    orgbTap($tgId, 'onb1:reminder:1900', 5);
}

function orgbLearner(int $tgId): Learner
{
    return Learner::query()->where('telegram_user_id', $tgId)->firstOrFail();
}

function orgbOnboarded(int $tgId, string $locale = 'ru'): Learner
{
    return Learner::factory()->onboarded()->create(['telegram_user_id' => $tgId, 'interface_locale' => $locale]);
}

/** @return list<string> */
function orgbTexts(): array
{
    return array_column(test()->tg->sent, 'text');
}

/** Texts sent after the first $skip messages. */
function orgbTextsAfter(int $skip): array
{
    return array_slice(orgbTexts(), $skip);
}

// --- the happy path: link -> onboarding -> confirmation -> membership ----------

it('remembers the link, finishes the onboarding, asks to join and seats the learner on "Join"', function (): void {
    $org = orgbCenter();
    $invite = orgbInvite($org);

    orgbSend(8001, '/start org_'.$invite->code);

    $learner = orgbLearner(8001);
    expect($learner->acquisition_source)->toBe('org')
        ->and($learner->meta['pending_org_invite'])->toBe($invite->code)
        ->and($this->tg->sent)->toHaveCount(1) // just the first onboarding step
        ->and(OrganizationMember::query()->count())->toBe(0);

    orgbOnboard(8001);

    $offer = end($this->tg->sent);
    expect($offer['text'])->toContain('«Bright Center»')
        ->and($this->tg->lastCallbackData())->toBe(['org1:join:'.$invite->code, 'org1:skip'])
        ->and(OrganizationMember::query()->count())->toBe(0)
        ->and(orgbLearner(8001)->meta)->not->toHaveKey('pending_org_invite');

    orgbTap(8001, 'org1:join:'.$invite->code, 200);

    $member = OrganizationMember::query()->sole();
    expect($member->organization_id)->toBe($org->id)
        ->and($member->learner_id)->toBe($learner->id)
        ->and($member->status)->toBe('active')
        ->and($member->seat_state)->toBe('seated')
        ->and($member->joined_via)->toBe('invite')
        ->and($invite->refresh()->uses_count)->toBe(1)
        ->and(end($this->tg->edits)['message_id'])->toBe(200)
        ->and(end($this->tg->edits)['markup'])->toBeNull();

    // The DM comes from the OrganizationMemberJoined listener: Pro is on, with a button into the app.
    $dm = end($this->tg->sent);
    expect($dm['text'])->toBe('Вы в центре «Bright Center». Pro активен — занимайтесь без ограничений.')
        ->and($dm['button'])->toBe('Открыть LexiFlow')
        ->and($dm['url'])->toBe('https://twa.test/twa/?from=organization')
        ->and($dm['chat_id'])->toBe(8001);

    expect(ProductEvent::query()->where('name', 'org.member_joined')->count())->toBe(1);
});

it('says there are no seats yet when the center does not sponsor or is full', function (?int $seats): void {
    $org = orgbCenter(seats: $seats);
    $invite = orgbInvite($org);
    orgbOnboarded(8002);

    orgbSend(8002, '/start org_'.$invite->code);
    orgbTap(8002, 'org1:join:'.$invite->code);

    expect(OrganizationMember::query()->sole()->seat_state)->toBe('waitlisted')
        ->and(end($this->tg->sent)['text'])->toContain('свободных мест пока нет')
        ->and(end($this->tg->sent)['button'])->toBeNull();
})->with([
    'no subscription' => [null],
    'all seats taken' => [0],
]);

it('writes the confirmation in the learner\'s language', function (): void {
    $org = orgbCenter();
    $invite = orgbInvite($org);
    orgbOnboarded(8003, 'uz');

    orgbSend(8003, '/start org_'.$invite->code);
    expect(end($this->tg->sent)['text'])->toBe("«Bright Center» o'quv markazi sizni LexiFlow'ga taklif qilmoqda. Markaz beradigan Pro imkoniyatini olish uchun qo'shilasizmi?");

    orgbTap(8003, 'org1:join:'.$invite->code);
    expect(end($this->tg->sent)['text'])->toBe("Siz «Bright Center» markazidasiz. Pro faol — cheklovlarsiz shug'ullaning.");
});

// --- a learner who has finished the onboarding -------------------------------

it('asks at once instead of showing "Today" when the onboarding is already done', function (): void {
    $org = orgbCenter();
    $invite = orgbInvite($org);
    orgbOnboarded(8004);

    orgbSend(8004, '/start org_'.strtolower($invite->code));

    expect($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['text'])->toContain('«Bright Center»')
        ->and($this->tg->lastCallbackData())->toBe(['org1:join:'.$invite->code, 'org1:skip'])
        ->and(OrganizationMember::query()->count())->toBe(0);
});

it('keeps showing "Today" for a learner who has finished the onboarding and has no link', function (): void {
    orgbOnboarded(8005);

    orgbSend(8005, '/start');

    expect($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['button'])->toBe('Открыть LexiFlow');
});

// --- idempotency --------------------------------------------------------------

it('is idempotent: a repeated link, a double tap and a link after joining create one membership and one use', function (): void {
    $org = orgbCenter();
    $invite = orgbInvite($org);

    orgbSend(8006, '/start org_'.$invite->code);
    orgbSend(8006, '/start org_'.$invite->code); // mid-onboarding: the same pending code, the same step again
    expect(orgbLearner(8006)->meta['pending_org_invite'])->toBe($invite->code);

    orgbOnboard(8006);
    $offers = collect($this->tg->sent)->filter(fn (array $m): bool => str_contains($m['text'], 'приглашает'))->count();
    expect($offers)->toBe(1);

    orgbTap(8006, 'org1:join:'.$invite->code, 300);
    orgbTap(8006, 'org1:join:'.$invite->code, 300); // double tap
    expect(OrganizationMember::query()->count())->toBe(1)
        ->and($invite->refresh()->uses_count)->toBe(1);

    // The member again opens the link: no second question, just "you are already in".
    $before = count($this->tg->sent);
    orgbSend(8006, '/start org_'.$invite->code);

    expect(orgbTextsAfter($before))->toBe(['Вы уже в центре «Bright Center».'])
        ->and(end($this->tg->sent)['markup'])->toBeNull()
        ->and(OrganizationMember::query()->count())->toBe(1)
        ->and($invite->refresh()->uses_count)->toBe(1);
});

it('offers the pending center once even if the last onboarding answer is retried', function (): void {
    $org = orgbCenter();
    $invite = orgbInvite($org);

    orgbSend(8007, '/start org_'.$invite->code);
    orgbOnboard(8007);
    $before = count($this->tg->sent);

    // A stale tap on the last step's button (a double tap): the onboarding is done, the pending code is gone.
    orgbTap(8007, 'onb1:reminder:1900', 5);

    $questions = collect(array_slice($this->tg->sent, $before))->filter(fn (array $m): bool => str_contains($m['text'], 'приглашает'))->count();
    expect($questions)->toBe(0);
});

it('lets a member through a group take the link and keep the center after leaving the groups', function (): void {
    $org = orgbCenter();
    $invite = orgbInvite($org);
    $learner = Learner::factory()->onboarded()->seatedIn($org, 'seated', 'group')->create(['telegram_user_id' => 8008]);

    orgbSend(8008, '/start org_'.$invite->code);
    expect($this->tg->lastCallbackData())->toBe(['org1:join:'.$invite->code, 'org1:skip']);

    orgbTap(8008, 'org1:join:'.$invite->code);

    $member = OrganizationMember::query()->where('learner_id', $learner->id)->sole();
    expect($member->joined_via)->toBe('invite')
        ->and(end($this->tg->sent)['text'])->toBe('Вы уже в центре «Bright Center».');
});

// --- skip ------------------------------------------------------------------------

it('creates no membership on "Not now"', function (): void {
    $org = orgbCenter();
    $invite = orgbInvite($org);

    orgbSend(8009, '/start org_'.$invite->code);
    orgbOnboard(8009);
    orgbTap(8009, 'org1:skip', 400);

    expect(OrganizationMember::query()->count())->toBe(0)
        ->and($invite->refresh()->uses_count)->toBe(0)
        ->and(end($this->tg->sent)['text'])->toBe('Хорошо. Вступить можно в любой момент по ссылке центра.')
        ->and(end($this->tg->edits)['message_id'])->toBe(400);
});

// --- refused codes ---------------------------------------------------------------

it('explains a code that cannot be used and runs the ordinary onboarding', function (string $case, string $expected): void {
    $org = orgbCenter();
    $invite = match ($case) {
        'unknown' => orgbInvite($org, ['code' => 'ZZZZZZZZZZ']),
        'inactive' => orgbInvite($org, ['is_active' => false]),
        'expired' => orgbInvite($org, ['expires_at' => now()->subDay()]),
        'exhausted' => orgbInvite($org, ['max_uses' => 1, 'uses_count' => 1]),
        'suspended' => orgbInvite(Organization::factory()->create(['status' => 'suspended'])),
    };
    $code = $case === 'unknown' ? 'ABCDEFGHJK' : $invite->code;

    orgbSend(8010, '/start org_'.$code);

    $learner = orgbLearner(8010);
    expect($this->tg->sent)->toHaveCount(2)
        ->and($this->tg->sent[0]['text'])->toBe($expected)
        ->and($this->tg->sent[1]['text'])->toBe('Выберите язык интерфейса') // the ordinary first step
        ->and($learner->onboarding_state)->toBe('locale')
        ->and($learner->meta)->not->toHaveKey('pending_org_invite');

    orgbOnboard(8010);

    expect(OrganizationMember::query()->count())->toBe(0)
        ->and(collect($this->tg->sent)->filter(fn (array $m): bool => str_contains($m['text'], 'приглашает'))->count())->toBe(0)
        ->and(end($this->tg->sent)['button'])->not->toBeNull(); // the "start training" message ends it
})->with([
    'unknown code' => ['unknown', 'Ссылка центра недействительна или отключена. Попросите у администратора новую.'],
    'switched off' => ['inactive', 'Ссылка центра недействительна или отключена. Попросите у администратора новую.'],
    'expired' => ['expired', 'Срок действия ссылки центра истёк. Попросите у администратора новую.'],
    'exhausted' => ['exhausted', 'Эта ссылка центра уже использована максимальное число раз. Попросите у администратора новую.'],
    'suspended center' => ['suspended', 'Центр сейчас не принимает учеников. Обратитесь к администратору центра.'],
]);

it('explains a refused code to a learner who has finished the onboarding, in their language', function (): void {
    $invite = orgbInvite(orgbCenter(), ['expires_at' => now()->subHour()]);
    orgbOnboarded(8011, 'uz');

    orgbSend(8011, '/start org_'.$invite->code);

    expect($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['text'])->toBe("Markaz havolasining amal qilish muddati tugagan. Administratordan yangisini so'rang.");
});

it('refuses at the tap when the code was used up after the offer', function (): void {
    $org = orgbCenter();
    $invite = orgbInvite($org, ['max_uses' => 1]);
    orgbOnboarded(8012);

    orgbSend(8012, '/start org_'.$invite->code);
    $invite->update(['uses_count' => 1]); // somebody else took the last use

    orgbTap(8012, 'org1:join:'.$invite->code);

    expect(OrganizationMember::query()->count())->toBe(0)
        ->and(end($this->tg->sent)['text'])->toBe('Эта ссылка центра уже использована максимальное число раз. Попросите у администратора новую.');
});

it('rechecks the code after the onboarding: a link that expired in between is explained', function (): void {
    $invite = orgbInvite(orgbCenter(), ['expires_at' => now()->addHour()]);

    orgbSend(8013, '/start org_'.$invite->code);
    $this->travel(2)->hours();
    orgbOnboard(8013);

    expect(OrganizationMember::query()->count())->toBe(0)
        ->and(end($this->tg->sent)['text'])->toBe('Срок действия ссылки центра истёк. Попросите у администратора новую.');
});

it('ignores a payload that is not a valid link', function (): void {
    orgbSend(8014, '/start org_'.str_repeat('A', 40)); // longer than a code can be

    expect(orgbLearner(8014)->meta)->not->toHaveKey('pending_org_invite')
        ->and($this->tg->sent)->toHaveCount(1);
});

// --- callbacks are not trusted ---------------------------------------------------

it('ignores "Join" from a group chat, from a blocked learner and before the onboarding is done', function (): void {
    $org = orgbCenter();
    $invite = orgbInvite($org);
    orgbOnboarded(8015);
    Learner::factory()->create(['telegram_user_id' => 8016, 'blocked_at' => now(), 'onboarding_state' => 'done']);
    Learner::factory()->create(['telegram_user_id' => 8017, 'onboarding_state' => 'goal']);

    orgbTap(8015, 'org1:join:'.$invite->code, 100, 'supergroup');
    orgbTap(8016, 'org1:join:'.$invite->code);
    orgbTap(8017, 'org1:join:'.$invite->code);
    orgbTap(8015, 'org1:join:bad code!');
    orgbTap(8015, 'org1:join');

    expect(OrganizationMember::query()->count())->toBe(0)
        ->and($this->tg->sent)->toBe([])
        ->and($this->tg->answered)->toHaveCount(5);
});

// --- DMs: seat granted later, nobody for a group, closed DM ------------------------

it('tells a waiting member, and only an invite or admin one, when a seat is freed', function (): void {
    $org = orgbCenter(seats: 1);
    $holder = Learner::factory()->seatedIn($org)->create();
    $viaInvite = Learner::factory()->seatedIn($org, 'waitlisted', 'invite')->create(['telegram_user_id' => 8020]);
    Learner::factory()->seatedIn($org, 'waitlisted', 'group')->create(['telegram_user_id' => 8021]);

    app(OrganizationMembershipService::class)->leave($holder, $org);

    expect(OrganizationMember::query()->where('learner_id', $viaInvite->id)->sole()->seat_state)->toBe('seated')
        ->and($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['chat_id'])->toBe(8020)
        ->and($this->tg->sent[0]['text'])->toBe('В центре «Bright Center» освободилось место для вас. Pro активен!')
        ->and($this->tg->sent[0]['button'])->toBe('Открыть LexiFlow');
});

it('says nothing about a join through a group', function (): void {
    $org = orgbCenter();
    $learner = Learner::factory()->create(['telegram_user_id' => 8022]);
    $group = TelegramGroup::factory()->create(['organization_id' => $org->id]);

    app(OrganizationMembershipService::class)->joinFromGroup($learner, $group);

    expect(OrganizationMember::query()->count())->toBe(1)
        ->and($this->tg->sent)->toBe([]);
});

it('messages a learner added by an administrator', function (): void {
    $org = orgbCenter();
    $learner = Learner::factory()->create(['telegram_user_id' => 8023]);

    app(OrganizationMembershipService::class)->joinByAdmin($learner, $org, User::factory()->create());

    expect($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['chat_id'])->toBe(8023)
        ->and($this->tg->sent[0]['text'])->toContain('Pro активен');
});

it('does not message a learner whose DM is closed or who is blocked', function (): void {
    $org = orgbCenter();
    $closed = Learner::factory()->create(['telegram_user_id' => 8024, 'dm_blocked_at' => now()]);
    $blocked = Learner::factory()->create(['telegram_user_id' => 8025, 'blocked_at' => now()]);
    $service = app(OrganizationMembershipService::class);
    $admin = User::factory()->create();

    $service->joinByAdmin($closed, $org, $admin);
    $service->joinByAdmin($blocked, $org, $admin);

    expect(OrganizationMember::query()->count())->toBe(2)
        ->and($this->tg->sent)->toBe([]);
});

it('marks the DM as closed when Telegram answers 403 and does not fail the join', function (): void {
    $org = orgbCenter();
    $learner = Learner::factory()->create(['telegram_user_id' => 8026]);

    $client = Mockery::mock(TelegramClient::class);
    $client->shouldReceive('sendWebAppButton')->once()->andThrow(new TelegramForbiddenException(TelegramForbiddenException::REASON_BOT_BLOCKED));
    $this->app->instance(TelegramClient::class, $client);

    app(OrganizationMembershipService::class)->joinByAdmin($learner, $org, User::factory()->create());

    expect(OrganizationMember::query()->sole()->seat_state)->toBe('seated')
        ->and($learner->refresh()->dm_blocked_at)->not->toBeNull();
});

it('does not promise a seat the member has already lost', function (): void {
    $org = orgbCenter();
    $learner = Learner::factory()->create(['telegram_user_id' => 8027]);
    $member = app(OrganizationMembershipService::class)->joinByAdmin($learner, $org, User::factory()->create());
    $this->tg->sent = [];

    app(OrganizationMembershipService::class)->leave($learner, $org);
    app(OrganizationMessenger::class)->notifyJoined($member->id); // the queued message arrives late

    expect($this->tg->sent)->toBe([]);
});

// --- /settings: my centers, leave --------------------------------------------------

it('shows "My centers" in /settings only to a member', function (): void {
    $org = orgbCenter();
    orgbOnboarded(8030);
    Learner::factory()->onboarded()->seatedIn($org)->create(['telegram_user_id' => 8031]);

    orgbSend(8030, '/settings');
    expect($this->tg->lastCallbackData())->not->toContain('org1:list');

    orgbSend(8031, '/settings');
    expect($this->tg->lastCallbackData())->toContain('org1:list')
        ->and(end($this->tg->sent)['markup']['inline_keyboard'][3][0]['text'])->toBe('Мои центры');
});

it('lists the centers with the state of the seat and offers "Leave" only where it is possible', function (): void {
    $invited = orgbCenter('Invited Center');
    $grouped = orgbCenter('Grouped Center');
    $learner = Learner::factory()->onboarded()->seatedIn($invited, 'waitlisted', 'invite')->seatedIn($grouped, 'seated', 'group')->create(['telegram_user_id' => 8032]);
    $inviteMember = OrganizationMember::query()->where('learner_id', $learner->id)->where('organization_id', $invited->id)->sole();

    orgbTap(8032, 'org1:list');

    $text = end($this->tg->sent)['text'];
    expect($text)->toContain('• «Invited Center» — в очереди на место')
        ->and($text)->toContain('• «Grouped Center» — Pro активен')
        ->and($text)->toContain('выйдите из группы центра в Telegram')
        ->and($this->tg->lastCallbackData())->toBe(['org1:leave:'.$inviteMember->id]);
});

it('leaves a center after the confirmation', function (): void {
    $org = orgbCenter();
    $learner = Learner::factory()->onboarded()->seatedIn($org)->create(['telegram_user_id' => 8033]);
    $member = OrganizationMember::query()->where('learner_id', $learner->id)->sole();

    orgbTap(8033, 'org1:leave:'.$member->id);
    expect(end($this->tg->sent)['text'])->toContain('Покинуть центр «Bright Center»?')
        ->and($this->tg->lastCallbackData())->toBe(['org1:leaveyes:'.$member->id, 'org1:leaveno'])
        ->and($member->refresh()->status)->toBe('active'); // asking changes nothing

    orgbTap(8033, 'org1:leaveyes:'.$member->id, 500);

    expect($member->refresh()->status)->toBe('left')
        ->and($member->seat_state)->toBe('none')
        ->and(end($this->tg->sent)['text'])->toBe('Вы покинули центр «Bright Center».')
        ->and(end($this->tg->edits)['message_id'])->toBe(500);

    orgbTap(8033, 'org1:leaveyes:'.$member->id, 501); // double tap
    expect(end($this->tg->sent)['text'])->toBe('Вы уже не состоите в этом центре.');
});

it('keeps the membership when the learner changes their mind', function (): void {
    $learner = Learner::factory()->onboarded()->seatedIn(orgbCenter())->create(['telegram_user_id' => 8034]);
    $member = OrganizationMember::query()->where('learner_id', $learner->id)->sole();

    orgbTap(8034, 'org1:leaveno', 600);

    expect($member->refresh()->status)->toBe('active')
        ->and(end($this->tg->sent)['text'])->toBe('Хорошо, ничего не изменилось.');
});

it('explains why a group membership cannot be left and does not touch it', function (): void {
    $learner = Learner::factory()->onboarded()->seatedIn(orgbCenter(), 'seated', 'group')->create(['telegram_user_id' => 8035]);
    $member = OrganizationMember::query()->where('learner_id', $learner->id)->sole();

    orgbTap(8035, 'org1:leave:'.$member->id);
    orgbTap(8035, 'org1:leaveyes:'.$member->id);

    expect($this->tg->sent)->toHaveCount(2)
        ->and($this->tg->sent[0]['text'])->toContain('через группу')
        ->and($this->tg->sent[1]['text'])->toContain('через группу')
        ->and($member->refresh()->status)->toBe('active');
});

it('never lets a learner act on somebody else\'s membership', function (): void {
    $org = orgbCenter();
    $owner = Learner::factory()->seatedIn($org)->create();
    orgbOnboarded(8036);
    $member = OrganizationMember::query()->where('learner_id', $owner->id)->sole();

    orgbTap(8036, 'org1:leave:'.$member->id);
    orgbTap(8036, 'org1:leaveyes:'.$member->id);

    expect($member->refresh()->status)->toBe('active')
        ->and(array_unique(orgbTexts()))->toBe(['Вы уже не состоите в этом центре.']);
});

it('answers politely when the list is empty', function (): void {
    orgbOnboarded(8037);

    orgbTap(8037, 'org1:list');

    expect(end($this->tg->sent)['text'])->toBe('Вы не состоите ни в одном центре.');
});

it('is part of the invite service: the link the administrator copies is what the bot parses', function (): void {
    config(['telegram.bot_username' => 'lexiflow_bot']);
    $invite = orgbInvite(orgbCenter());

    $link = app(OrganizationInviteService::class)->link($invite);

    expect($link)->toBe('https://t.me/lexiflow_bot?start=org_'.$invite->code);
    parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
    expect(StartPayload::parse($query['start'])->value)->toBe($invite->code);
});
