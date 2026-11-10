<?php

declare(strict_types=1);

use App\Domain\Administration\Services\OrganizationGroupLinker;
use App\Domain\Learning\Services\TeacherAuthority;
use App\Domain\Organizations\Enums\GroupLinkResult;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Exceptions\OrgGroupLinkException;
use App\Domain\Organizations\Services\OrgGroupLinkService;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\DTO\IssuedLink;
use App\Domain\Telegram\Enums\LinkTokenKind;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Models\AuditLog;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\OrganizationUser;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use App\Models\TelegramLinkToken;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeTelegramClient;

/*
 * Linking a group to a center FROM THE CHAT (docs/13 §6.2; audit 2026-11-10 H1 / P1 / L8): the cabinet issues
 * `t.me/<bot>?startgroup=grp_<token>`, the bot links the group only when the sender is the owner's VERIFIED Telegram
 * account, the token is theirs, Telegram says they are creator / administrator of the chat, and the chat is nobody else's.
 * Every refusal in the chat is one neutral text; the reason is kept for the owner's cabinet.
 */

uses(RefreshDatabase::class);

const OGL_OWNER_TG = 4242;
const OGL_CHAT = -1001234;

beforeEach(function (): void {
    Config::set('telegram.bot_token', '777000111:AAEhBPsecretsecretsecretsecretsecret12');
    Config::set('telegram.bot_username', 'LexiFlowBot');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-10 12:00:00', 'UTC'));

    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);
    $this->org = Organization::factory()->create(['name' => 'Bright Center', 'locale' => 'ru']);
    $this->owner = User::factory()->create(['telegram_user_id' => OGL_OWNER_TG, 'telegram_verified_at' => now(), 'is_active' => true]);
    OrganizationUser::factory()->create(['organization_id' => $this->org->id, 'user_id' => $this->owner->id, 'role' => OrganizationRole::Owner->value]);
    $this->group = TelegramGroup::factory()->create(['chat_id' => OGL_CHAT, 'organization_id' => null, 'status' => 'pending', 'title' => 'Class 7B']);
    $this->service = app(OrgGroupLinkService::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function oglLink(): IssuedLink
{
    return test()->service->issueLink(test()->org, test()->owner);
}

/** `/link <token>` (or any $text) posted in the group by Telegram user $fromId. */
function oglSend(string $text, int $fromId = OGL_OWNER_TG, int $chatId = OGL_CHAT, string $title = 'Class 7B'): void
{
    app(TelegramDispatcher::class)->dispatch(['message' => [
        'message_id' => 1,
        'chat' => ['id' => $chatId, 'type' => 'supergroup', 'title' => $title],
        'from' => ['id' => $fromId, 'first_name' => 'Owner'],
        'text' => $text,
    ]]);
}

/** The `my_chat_member` update of the bot being added to the group. */
function oglBotAdded(int $chatId = OGL_CHAT, string $title = 'Class 7B'): void
{
    app(TelegramDispatcher::class)->dispatch(['my_chat_member' => [
        'chat' => ['id' => $chatId, 'type' => 'supergroup', 'title' => $title],
        'from' => ['id' => OGL_OWNER_TG],
        'old_chat_member' => ['status' => 'left'],
        'new_chat_member' => ['status' => 'member', 'user' => ['id' => 777000111, 'is_bot' => true]],
    ]]);
}

function oglLastText(): string
{
    return (string) end(test()->tg->sent)['text'];
}

const OGL_REFUSED = 'Не удалось привязать группу. Откройте кабинет центра → Группы → «Привязать группу»: там видна причина, и можно получить новую ссылку.';

// --- issuing -----------------------------------------------------------------------------------------

it('issues a startgroup deep link and a /link fallback for a verified owner; the token is stored hashed, 30 minutes', function (): void {
    $link = oglLink();

    expect($link->kind)->toBe(LinkTokenKind::Group)
        ->and($link->url)->toBe('https://t.me/LexiFlowBot?startgroup=grp_'.$link->code)
        ->and($link->command())->toBe('/link '.$link->code)
        ->and($link->expiresAt->toIso8601String())->toBe('2026-11-10T12:30:00+00:00');

    $row = TelegramLinkToken::query()->sole();
    expect($row->kind)->toBe('group')
        ->and($row->organization_id)->toBe($this->org->id)
        ->and($row->user_id)->toBe($this->owner->id)
        ->and($row->token_hash)->toBe(hash('sha256', $link->code));
});

it('refuses to issue a link to a teacher, to an owner without a VERIFIED Telegram, and for a suspended center', function (): void {
    $teacher = User::factory()->create(['telegram_user_id' => 5151, 'telegram_verified_at' => now()]);
    OrganizationUser::factory()->create(['organization_id' => $this->org->id, 'user_id' => $teacher->id, 'role' => OrganizationRole::Teacher->value]);
    expect(fn () => $this->service->issueLink($this->org, $teacher))->toThrow(OrgGroupLinkException::class, 'not an owner');

    // A hand-typed id (legacy / `/admin`) is not proof: H1 — the owner could have typed somebody else's id.
    $this->owner->forceFill(['telegram_verified_at' => null])->save();
    expect(fn () => $this->service->issueLink($this->org, $this->owner->fresh() ?? $this->owner))->toThrow(OrgGroupLinkException::class, 'verified');

    $this->owner->forceFill(['telegram_verified_at' => now()])->save();
    $this->org->update(['status' => 'suspended']);
    expect(fn () => $this->service->issueLink($this->org->fresh() ?? $this->org, $this->owner->fresh() ?? $this->owner))->toThrow(OrgGroupLinkException::class, 'not an owner');

    expect(TelegramLinkToken::query()->count())->toBe(0);
});

// --- the happy path ------------------------------------------------------------------------------------

it('links the group on /link <token> from the owner who is an administrator of the chat: linked, audited, the owner teaches it, memberships follow', function (): void {
    $learner = Learner::factory()->memberOf($this->group)->create();
    $this->tg->chatMemberStatus = 'administrator';
    $link = oglLink();

    oglSend('/link '.$link->code);

    expect($this->group->fresh()?->organization_id)->toBe($this->org->id)
        // The sender's rights in the chat, then the bot's own status (the "make the bot an admin" courtesy).
        ->and($this->tg->chatMemberLookups[0])->toBe(['chat_id' => OGL_CHAT, 'user_id' => OGL_OWNER_TG])
        ->and(app(TeacherAuthority::class)->canTeach($this->owner, $this->group->fresh() ?? $this->group))->toBeTrue()
        ->and(OrganizationMember::query()->where('organization_id', $this->org->id)->where('learner_id', $learner->id)->value('joined_via'))->toBe('group')
        ->and(TelegramLinkToken::query()->sole()->used_at)->not->toBeNull()
        ->and(oglLastText())->toStartWith('✅ Группа «Class 7B» привязана к центру «Bright Center».');

    $audit = AuditLog::query()->where('action', OrganizationGroupLinker::ACTION_LINKED)->sole();
    expect($audit->user_id)->toBe($this->owner->id)->and($audit->entity_id)->toBe($this->org->id);
});

it('accepts the /start grp_<token> that Telegram posts after the startgroup deep link, with or without the bot mention', function (string $text): void {
    $link = oglLink();

    oglSend(str_replace('{code}', $link->code, $text));

    expect($this->group->fresh()?->organization_id)->toBe($this->org->id);
})->with([
    'plain' => ['/start grp_{code}'],
    'mentioned' => ['/start@LexiFlowBot grp_{code}'],
    'link with prefix' => ['/link grp_{code}'],
    'link mentioned' => ['/link@LexiFlowBot {code}'],
]);

it('creates the group row itself when the /start grp_ arrives before my_chat_member, and the later welcome is not sent twice', function (): void {
    $this->group->delete();
    $link = oglLink();

    oglSend('/start grp_'.$link->code, chatId: -1009999, title: 'Brand-new class');

    $group = TelegramGroup::query()->where('chat_id', -1009999)->sole();
    expect($group->organization_id)->toBe($this->org->id)
        ->and($group->title)->toBe('Brand-new class')
        ->and($group->meta['type'])->toBe('supergroup');

    $sent = count($this->tg->sent);
    oglBotAdded(-1009999, 'Brand-new class');

    // The bot-added update still records the event but sends no "group number for the cabinet" welcome: the link's own answer was the message.
    expect(count($this->tg->sent))->toBe($sent)
        ->and(collect($this->tg->sent)->filter(fn (array $m): bool => str_contains($m['text'], 'LexiFlow добавлен'))->count())->toBe(0);
});

it('welcomes a group that is not linked (the bot added first) and then confirms the link — two different messages, no repeat', function (): void {
    $this->group->delete();
    $this->tg->chatMemberStatus = 'administrator';
    oglBotAdded();
    expect(oglLastText())->toContain('LexiFlow добавлен')->toContain('Привяжите её из кабинета центра')->not->toContain('-1001234');

    $link = oglLink();
    oglSend('/link '.$link->code);

    expect(oglLastText())->toStartWith('✅ Группа «Class 7B» привязана')
        ->and(collect($this->tg->sent)->filter(fn (array $m): bool => str_contains($m['text'], 'LexiFlow добавлен'))->count())->toBe(1);
});

it('is idempotent for a group already linked to this center: says linked again, asks Telegram nothing, spends the token', function (): void {
    $this->group->update(['organization_id' => $this->org->id]);
    $link = oglLink();

    oglSend('/link '.$link->code);

    expect(oglLastText())->toStartWith('✅ Группа «Class 7B» привязана')
        ->and($this->tg->chatMemberLookups)->toHaveCount(2) // the owner's rights are still checked, then the bot's own status; nothing else
        ->and(TelegramLinkToken::query()->sole()->used_at)->not->toBeNull();
});

// --- auto-activation (audit §4 B1) ----------------------------------------------------------------------

it('activates the group at once for a center with a live subscription, remembers why, and puts it back to pending on unlink', function (): void {
    Subscription::factory()->forOrganization($this->org)->create(['current_period_ends_at' => null, 'tier' => 'tutor', 'seats_limit' => 30]);
    $link = oglLink();

    oglSend('/link '.$link->code);

    $group = $this->group->fresh();
    expect($group?->status)->toBe('active')
        ->and($group?->meta[OrgGroupLinkService::META_ACTIVATED_VIA] ?? null)->toBe('org_link')
        ->and(oglLastText())->toContain('Группа активна: команды учителя работают')
        ->and(AuditLog::query()->where('action', OrgGroupLinkService::AUDIT_GROUP_ACTIVATED)->where('user_id', $this->owner->id)->exists())->toBeTrue();

    $this->service->unlink($this->org, $group ?? $this->group, $this->owner);

    $group = $this->group->fresh();
    expect($group?->organization_id)->toBeNull()
        ->and($group?->status)->toBe('pending')
        ->and($group?->meta ?? [])->not->toHaveKey(OrgGroupLinkService::META_ACTIVATED_VIA)
        ->and(DB::table('teacher_groups')->where('user_id', $this->owner->id)->count())->toBe(0);
});

it('leaves the group pending for a center without a live subscription, or when the flag is off, and never touches an admin-activated group', function (): void {
    $link = oglLink();
    oglSend('/link '.$link->code);
    expect($this->group->fresh()?->status)->toBe('pending')
        ->and(oglLastText())->toContain('ждёт активации администратором LexiFlow');

    // Flag off: a sponsoring center still waits for the platform.
    $this->group->refresh()->update(['organization_id' => null]);
    Subscription::factory()->forOrganization($this->org)->create(['current_period_ends_at' => null, 'tier' => 'tutor', 'seats_limit' => 30]);
    Config::set('organizations.auto_activate_linked_groups', false);
    oglSend('/link '.oglLink()->code);
    expect($this->group->fresh()?->status)->toBe('pending');

    // A group the platform activated by hand keeps its status through link and unlink.
    Config::set('organizations.auto_activate_linked_groups', true);
    $this->group->refresh()->update(['organization_id' => null, 'status' => 'active']);
    oglSend('/link '.oglLink()->code);
    expect($this->group->fresh()?->status)->toBe('active')->and($this->group->fresh()?->meta ?? [])->not->toHaveKey('activated_via');
    $this->service->unlink($this->org, $this->group->fresh() ?? $this->group, $this->owner);
    expect($this->group->fresh()?->status)->toBe('active');
});

// --- refusals: one neutral answer in the chat, the reason for the owner -----------------------------

it('refuses a sender who is not a verified staff account — before any Telegram call, with nothing recorded', function (string $case): void {
    $link = oglLink();
    $fromId = match ($case) {
        'stranger' => 9999,
        'hand-typed id of the owner' => OGL_OWNER_TG,
        'deactivated owner' => OGL_OWNER_TG,
        'a learner' => Learner::factory()->create(['telegram_user_id' => 8888])->telegram_user_id,
    };
    if ($case === 'hand-typed id of the owner') {
        $this->owner->forceFill(['telegram_verified_at' => null])->save();
    }
    if ($case === 'deactivated owner') {
        $this->owner->forceFill(['is_active' => false])->save();
    }

    oglSend('/link '.$link->code, fromId: (int) $fromId);

    expect($this->group->fresh()?->organization_id)->toBeNull()
        ->and($this->tg->chatMemberLookups)->toBe([])
        ->and(oglLastText())->toBe(OGL_REFUSED)
        ->and(TelegramLinkToken::query()->sole()->last_result)->toBeNull()
        ->and(TelegramLinkToken::query()->sole()->used_at)->toBeNull();
})->with(['stranger', 'hand-typed id of the owner', 'deactivated owner', 'a learner']);

it('refuses an unknown, malformed, expired or spent token with the same neutral answer', function (): void {
    $link = oglLink();
    oglSend('/link '.$link->code); // spent

    $this->group->refresh()->update(['organization_id' => null]);
    $expired = TelegramLinkToken::factory()->create(['kind' => 'group', 'user_id' => $this->owner->id, 'organization_id' => $this->org->id, 'token_hash' => hash('sha256', str_repeat('E', 32)), 'expires_at' => now()->subMinute()]);

    foreach ([str_repeat('A', 32), 'abc', '', str_repeat('E', 32), $link->code] as $code) {
        $before = count($this->tg->sent);
        oglSend(rtrim('/link '.$code));
        expect(oglLastText())->toBe(OGL_REFUSED)->and(count($this->tg->sent))->toBe($before + 1);
    }

    expect($this->group->fresh()?->organization_id)->toBeNull()
        ->and($this->tg->chatMemberLookups)->toHaveCount(2, 'only the successful first link asked Telegram (sender + bot)')
        ->and($expired->fresh()?->last_result)->toBeNull();
});

it('refuses the owner\'s token when another verified staff account opens it, and tells the owner so in the cabinet', function (): void {
    $other = User::factory()->create(['telegram_user_id' => 6161, 'telegram_verified_at' => now()]);
    OrganizationUser::factory()->create(['organization_id' => Organization::factory()->create()->id, 'user_id' => $other->id]);
    $link = oglLink();

    oglSend('/link '.$link->code, fromId: 6161);

    expect($this->group->fresh()?->organization_id)->toBeNull()
        ->and(oglLastText())->toBe(OGL_REFUSED)
        ->and($this->tg->chatMemberLookups)->toBe([])
        ->and($this->service->lastAttempt($this->org, $this->owner)?->last_result)->toBe(GroupLinkResult::SenderMismatch->value)
        ->and(TelegramLinkToken::query()->sole()->used_at)->toBeNull();

    // Still live for its owner.
    oglSend('/link '.$link->code);
    expect($this->group->fresh()?->organization_id)->toBe($this->org->id);
});

it('refuses when the sender is not creator / administrator of the chat; the token stays live for a retry after promotion', function (string $status): void {
    $this->tg->chatMemberStatus = $status;
    $link = oglLink();

    oglSend('/link '.$link->code);

    expect($this->group->fresh()?->organization_id)->toBeNull()
        ->and(oglLastText())->toBe(OGL_REFUSED)
        ->and($this->service->lastAttempt($this->org, $this->owner)?->last_result)->toBe('not_chat_admin')
        ->and(DB::table('teacher_groups')->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);

    $this->tg->chatMemberStatus = 'administrator';
    oglSend('/link '.$link->code);
    expect($this->group->fresh()?->organization_id)->toBe($this->org->id);
})->with(['member', 'restricted', 'left', 'kicked']);

it('refuses a group of another center without asking Telegram, and one that lost its owner role or was suspended meanwhile', function (): void {
    $other = Organization::factory()->create();
    $this->group->update(['organization_id' => $other->id]);
    $link = oglLink();

    oglSend('/link '.$link->code);
    expect(oglLastText())->toBe(OGL_REFUSED)
        ->and($this->tg->chatMemberLookups)->toBe([])
        ->and($this->group->fresh()?->organization_id)->toBe($other->id)
        ->and($this->service->lastAttempt($this->org, $this->owner)?->last_result)->toBe('group_taken');

    $this->group->update(['organization_id' => null]);
    OrganizationUser::query()->where('user_id', $this->owner->id)->update(['role' => OrganizationRole::Teacher->value]);
    oglSend('/link '.$link->code);
    expect($this->group->fresh()?->organization_id)->toBeNull()
        ->and($this->service->lastAttempt($this->org, $this->owner)?->last_result)->toBe('not_owner');

    OrganizationUser::query()->where('user_id', $this->owner->id)->update(['role' => OrganizationRole::Owner->value]);
    $this->org->update(['status' => 'suspended']);
    oglSend('/link '.$link->code);
    expect($this->group->fresh()?->organization_id)->toBeNull()
        ->and($this->service->lastAttempt($this->org, $this->owner)?->last_result)->toBe('not_owner');
});

it('reports a Telegram outage to the owner only and changes nothing; the token stays live', function (): void {
    $this->tg->failChatMember = true;
    $link = oglLink();

    oglSend('/link '.$link->code);

    expect(oglLastText())->toBe(OGL_REFUSED)
        ->and($this->group->fresh()?->organization_id)->toBeNull()
        ->and($this->service->lastAttempt($this->org, $this->owner)?->last_result)->toBe('telegram_unavailable')
        ->and(TelegramLinkToken::query()->sole()->used_at)->toBeNull();
});

it('refuses /link in a private chat (and says where it goes), /link with no code, and a forged payload with foreign characters — neutrally', function (): void {
    $link = oglLink();

    app(TelegramDispatcher::class)->dispatch(['message' => [
        'chat' => ['id' => OGL_OWNER_TG, 'type' => 'private'],
        'from' => ['id' => OGL_OWNER_TG, 'language_code' => 'ru'],
        'text' => '/link '.$link->code,
    ]]);
    expect($this->group->fresh()?->organization_id)->toBeNull()
        ->and(TelegramLinkToken::query()->sole()->used_at)->toBeNull()
        ->and(oglLastText())->toBe('Эту команду отправляют в группе, которую нужно привязать.')
        ->and(end($this->tg->sent)['chat_id'])->toBe(OGL_OWNER_TG);

    oglSend('/link');
    expect(oglLastText())->toBe(OGL_REFUSED);

    oglSend('/link '.substr($link->code, 0, 31).'%');
    expect(oglLastText())->toBe(OGL_REFUSED)
        ->and($this->group->fresh()?->organization_id)->toBeNull();

    oglSend('/start grp_'.$link->code.' extra');
    expect($this->group->fresh()?->organization_id)->toBeNull();
});

it('drops attempts beyond the per-sender limit without an answer', function (): void {
    Config::set('organizations.telegram_link.attempts_per_minute', 2);
    oglSend('/link '.str_repeat('A', 32));
    oglSend('/link '.str_repeat('B', 32));
    $before = count($this->tg->sent);

    oglSend('/link '.oglLink()->code);

    expect(count($this->tg->sent))->toBe($before)
        ->and($this->group->fresh()?->organization_id)->toBeNull();
});

it('drops attempts beyond the per-chat limit too: several members cannot flood one group with the bot\'s answers', function (): void {
    Config::set('organizations.telegram_link.attempts_per_minute', 2);
    oglSend('/link', fromId: 41);
    oglSend('/link', fromId: 42);
    $before = count($this->tg->sent);

    oglSend('/link', fromId: 43);
    expect(count($this->tg->sent))->toBe($before);

    // Another chat has its own bucket; the owner's real attempt there is answered.
    oglSend('/link', fromId: 44, chatId: OGL_CHAT - 1);
    expect(count($this->tg->sent))->toBe($before + 1);
});

it('asks an anonymous administrator to send the command as themselves, and says nothing about the token', function (): void {
    $link = oglLink();

    app(TelegramDispatcher::class)->dispatch(['message' => [
        'message_id' => 1,
        'chat' => ['id' => OGL_CHAT, 'type' => 'supergroup', 'title' => 'Class 7B'],
        'from' => ['id' => 1087968824, 'is_bot' => true, 'first_name' => 'Group', 'username' => 'GroupAnonymousBot'],
        'sender_chat' => ['id' => OGL_CHAT, 'type' => 'supergroup', 'title' => 'Class 7B'],
        'text' => '/link '.$link->code,
    ]]);

    expect(oglLastText())->toBe('Отправьте команду от своего имени, не анонимно: бот должен узнать, кто её отправил.')
        ->and($this->group->fresh()?->organization_id)->toBeNull()
        ->and(TelegramLinkToken::query()->sole()->used_at)->toBeNull()
        ->and(TelegramLinkToken::query()->sole()->last_result)->toBeNull();
});

// --- races --------------------------------------------------------------------------------------------

it('loses the race to a link that took the group between the read and the row lock: refused as taken, token kept', function (): void {
    $other = Organization::factory()->create();
    $link = oglLink();
    $this->tg->onChatMemberLookup = function (): void {
        // "Meanwhile" another center linked the chat.
        TelegramGroup::query()->where('chat_id', OGL_CHAT)->update(['organization_id' => Organization::query()->where('id', '!=', $this->org->id)->value('id')]);
    };

    oglSend('/link '.$link->code);

    expect($this->group->fresh()?->organization_id)->toBe($other->id)
        ->and(oglLastText())->toBe(OGL_REFUSED)
        ->and($this->service->lastAttempt($this->org, $this->owner)?->last_result)->toBe('group_taken')
        ->and(TelegramLinkToken::query()->sole()->used_at)->toBeNull()
        ->and(DB::table('teacher_groups')->count())->toBe(0);
});

it('spends the token once when the same link is posted in two groups: the second group stays unlinked', function (): void {
    $second = TelegramGroup::factory()->create(['chat_id' => -1005555, 'organization_id' => null, 'status' => 'pending']);
    $link = oglLink();
    $this->tg->onChatMemberLookup = function (int|string $chatId, int $userId) use ($link): void {
        // "Meanwhile" the same token wins in the other group (first lookup only — the inner call must not recurse).
        if ((int) $chatId === OGL_CHAT && $userId === OGL_OWNER_TG && $this->tg->onChatMemberLookup !== null) {
            $this->tg->onChatMemberLookup = null;
            oglSend('/link '.$link->code, chatId: -1005555, title: 'Other');
        }
    };

    oglSend('/link '.$link->code);

    expect($second->fresh()?->organization_id)->toBe($this->org->id)
        ->and($this->group->fresh()?->organization_id)->toBeNull()
        ->and(TelegramLinkToken::query()->sole()->used_at)->not->toBeNull()
        ->and(oglLastText())->toBe(OGL_REFUSED);
});

// --- the rest of the service ---------------------------------------------------------------------------

it('unlinks only a group of the organization and takes it from the center staff', function (): void {
    $this->group->update(['organization_id' => $this->org->id]);
    DB::table('teacher_groups')->insert(['user_id' => $this->owner->id, 'telegram_group_id' => $this->group->id, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
    $foreign = TelegramGroup::factory()->create(['organization_id' => Organization::factory()->create()->id]);

    expect(fn () => $this->service->unlink($this->org, $foreign, $this->owner))->toThrow(OrgGroupLinkException::class, 'not linked');

    $this->service->unlink($this->org, $this->group->fresh() ?? $this->group, $this->owner, '10.0.0.1');
    expect($this->group->fresh()?->organization_id)->toBeNull()
        ->and(DB::table('teacher_groups')->where('user_id', $this->owner->id)->count())->toBe(0)
        ->and(AuditLog::query()->where('action', OrganizationGroupLinker::ACTION_UNLINKED)->where('user_id', $this->owner->id)->exists())->toBeTrue();
});

it('maps the cabinet error codes to HTTP statuses', function (): void {
    expect(OrgGroupLinkException::notOwner(1, 1)->httpStatus())->toBe(403)
        ->and(OrgGroupLinkException::ownerTelegramUnverified(1)->httpStatus())->toBe(409)
        ->and(OrgGroupLinkException::rateLimited(5)->httpStatus())->toBe(429)
        ->and(OrgGroupLinkException::notLinked(1, 1)->httpStatus())->toBe(404)
        ->and(OrgGroupLinkException::courseUnavailable(1)->httpStatus())->toBe(422);
});
