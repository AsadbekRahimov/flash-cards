<?php

declare(strict_types=1);

use App\Domain\Organizations\Exceptions\OrgGroupLinkException;
use App\Domain\Organizations\Services\OrgGroupLinkService;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Models\AuditLog;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\TelegramGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Support\FakeTelegramClient;

/*
 * OrgGroupLinkService (docs/13 §6.2, §10): an owner links only a group where the bot already is
 * AND where Telegram confirms the owner is creator / administrator; the write is the shared linker
 * (observer creates memberships, audit with the acting user).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Config::set('telegram.bot_token', '777000111:AAEhBPsecretsecretsecretsecretsecret12');
    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);
    $this->org = Organization::factory()->create();
    $this->owner = User::factory()->create(['telegram_user_id' => 4242]);
    $this->group = TelegramGroup::factory()->create(['organization_id' => null, 'status' => 'active']);
    $this->service = app(OrgGroupLinkService::class);
});

it('links the group when the owner is creator or administrator of the chat, audits it and creates the memberships', function (): void {
    $learner = Learner::factory()->memberOf($this->group)->create();
    $this->tg->chatMemberStatus = 'creator';

    $linked = $this->service->link($this->org, $this->group->chat_id, $this->owner, '10.0.0.1');

    expect($linked->organization_id)->toBe($this->org->id)
        // First the owner's rights, then the bot's own status (the "make the bot an admin" courtesy of 2R.2).
        ->and($this->tg->chatMemberLookups)->toBe([['chat_id' => $this->group->chat_id, 'user_id' => 4242], ['chat_id' => $this->group->chat_id, 'user_id' => 777000111]])
        ->and(OrganizationMember::query()->where('organization_id', $this->org->id)->where('learner_id', $learner->id)->value('joined_via'))->toBe('group');

    $audit = AuditLog::query()->where('action', 'organization.linked_group')->firstOrFail();
    expect($audit->user_id)->toBe($this->owner->id)
        ->and($audit->ip)->toBe('10.0.0.1')
        ->and($audit->entity_id)->toBe($this->org->id);

    // Idempotent: already ours, no further Telegram call.
    $this->service->link($this->org, $this->group->chat_id, $this->owner);
    expect($this->tg->chatMemberLookups)->toHaveCount(2);
});

it('refuses a chat where the owner is not an administrator — the group stays unlinked', function (string $status): void {
    $this->tg->chatMemberStatus = $status;

    expect(fn () => $this->service->link($this->org, $this->group->chat_id, $this->owner))
        ->toThrow(OrgGroupLinkException::class, 'not an administrator');
    expect($this->group->fresh()?->organization_id)->toBeNull()
        ->and(AuditLog::query()->count())->toBe(0);
})->with(['member', 'restricted', 'left', 'kicked']);

it('requires the owner to have a linked Telegram account, before any Telegram call', function (): void {
    $owner = User::factory()->create(['telegram_user_id' => null]);

    expect(fn () => $this->service->link($this->org, $this->group->chat_id, $owner))
        ->toThrow(OrgGroupLinkException::class, 'no Telegram account');
    expect($this->tg->chatMemberLookups)->toBe([]);
});

it('refuses an unknown chat and a group of another organization without asking Telegram', function (): void {
    expect(fn () => $this->service->link($this->org, -100999, $this->owner))->toThrow(OrgGroupLinkException::class, 'known to the bot');

    $other = Organization::factory()->create();
    $this->group->update(['organization_id' => $other->id]);
    expect(fn () => $this->service->link($this->org, $this->group->chat_id, $this->owner))->toThrow(OrgGroupLinkException::class, 'another organization');

    expect($this->tg->chatMemberLookups)->toBe([])
        ->and($this->group->fresh()?->organization_id)->toBe($other->id);
});

it('reports Telegram outages as telegram_unavailable and changes nothing', function (): void {
    $this->tg->failChatMember = true;

    expect(fn () => $this->service->link($this->org, $this->group->chat_id, $this->owner))->toThrow(OrgGroupLinkException::class);
    expect($this->group->fresh()?->organization_id)->toBeNull();

    expect(OrgGroupLinkException::telegramUnavailable('x')->httpStatus())->toBe(502)
        ->and(OrgGroupLinkException::notChatAdmin(1, 'member')->httpStatus())->toBe(403)
        ->and(OrgGroupLinkException::groupNotFound(1)->httpStatus())->toBe(404)
        ->and(OrgGroupLinkException::groupTaken(1)->httpStatus())->toBe(409)
        ->and(OrgGroupLinkException::ownerTelegramMissing(1)->httpStatus())->toBe(409);
});

it('unlinks only a group of the organization', function (): void {
    $this->group->update(['organization_id' => $this->org->id]);
    $foreign = TelegramGroup::factory()->create(['organization_id' => Organization::factory()->create()->id]);

    expect(fn () => $this->service->unlink($this->org, $foreign, $this->owner))->toThrow(OrgGroupLinkException::class, 'not linked');

    $this->service->unlink($this->org, $this->group, $this->owner, '10.0.0.1');
    expect($this->group->fresh()?->organization_id)->toBeNull()
        ->and(AuditLog::query()->where('action', 'organization.unlinked_group')->where('user_id', $this->owner->id)->exists())->toBeTrue();
});
