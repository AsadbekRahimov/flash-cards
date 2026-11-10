<?php

declare(strict_types=1);

use App\Domain\Administration\Services\OrganizationGroupLinker;
use App\Domain\Learning\Services\TeacherAuthority;
use App\Domain\Organizations\Exceptions\OrgGroupLinkException;
use App\Domain\Organizations\Services\OrgGroupLinkService;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Models\AuditLog;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\OrganizationUser;
use App\Models\TelegramGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
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

it('makes the owner who linked the group its teacher at once, so the bot lets them run a training (idempotent, first group is primary)', function (): void {
    $this->tg->chatMemberStatus = 'administrator';
    $second = TelegramGroup::factory()->create(['organization_id' => null, 'status' => 'active']);

    $this->service->link($this->org, $this->group->chat_id, $this->owner);
    $this->service->link($this->org, $second->chat_id, $this->owner);

    $rows = DB::table('teacher_groups')->where('user_id', $this->owner->id)->orderBy('telegram_group_id')->get();

    expect($rows)->toHaveCount(2)
        ->and((bool) $rows[0]->is_primary)->toBeTrue()
        ->and((bool) $rows[1]->is_primary)->toBeFalse()
        ->and(app(TeacherAuthority::class)->canTeach($this->owner, $this->group))->toBeTrue();

    // Linked again: no second row.
    $this->service->link($this->org, $this->group->chat_id, $this->owner);
    expect(DB::table('teacher_groups')->where('user_id', $this->owner->id)->count())->toBe(2);
});

it('does not make anybody a teacher when the link is refused', function (): void {
    $this->tg->chatMemberStatus = 'member';

    expect(fn () => $this->service->link($this->org, $this->group->chat_id, $this->owner))->toThrow(OrgGroupLinkException::class);
    expect(DB::table('teacher_groups')->count())->toBe(0);
});

it('takes the group from the center staff when it is unlinked, the owner who linked it included', function (): void {
    OrganizationUser::factory()->create(['organization_id' => $this->org->id, 'user_id' => $this->owner->id]);
    $teacher = User::factory()->create(['telegram_user_id' => 5151]);
    OrganizationUser::factory()->teacher()->create(['organization_id' => $this->org->id, 'user_id' => $teacher->id]);
    $outsider = User::factory()->create(['telegram_user_id' => 6161]);
    $otherOrg = Organization::factory()->create();
    $otherTeacher = User::factory()->create(['telegram_user_id' => 7171]);
    OrganizationUser::factory()->teacher()->create(['organization_id' => $otherOrg->id, 'user_id' => $otherTeacher->id]);

    $this->tg->chatMemberStatus = 'creator';
    $this->service->link($this->org, $this->group->chat_id, $this->owner);
    $otherGroup = TelegramGroup::factory()->create(['organization_id' => $this->org->id]);
    DB::table('teacher_groups')->insert([
        ['user_id' => $teacher->id, 'telegram_group_id' => $this->group->id, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()],
        ['user_id' => $teacher->id, 'telegram_group_id' => $otherGroup->id, 'is_primary' => false, 'created_at' => now(), 'updated_at' => now()],
        ['user_id' => $outsider->id, 'telegram_group_id' => $this->group->id, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()],
        ['user_id' => $otherTeacher->id, 'telegram_group_id' => $this->group->id, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);
    $authority = app(TeacherAuthority::class);
    expect($authority->canTeach($this->owner, $this->group))->toBeTrue();

    $this->service->unlink($this->org, $this->group->fresh(), $this->owner);

    $group = $this->group->fresh();
    expect($authority->canTeach($this->owner->fresh(), $group))->toBeFalse()
        ->and($authority->canTeach($teacher, $group))->toBeFalse()
        // Their other group of the center stays.
        ->and($authority->canTeach($teacher, $otherGroup))->toBeTrue()
        // Not staff of this center / staff of another center: untouched.
        ->and($authority->canTeach($outsider, $group))->toBeTrue()
        ->and($authority->canTeach($otherTeacher, $group))->toBeTrue();
});

it('takes the group from the former owner when the superadmin unlinks it from the admin panel', function (): void {
    OrganizationUser::factory()->create(['organization_id' => $this->org->id, 'user_id' => $this->owner->id]);
    $this->tg->chatMemberStatus = 'administrator';
    $this->service->link($this->org, $this->group->chat_id, $this->owner);

    app(OrganizationGroupLinker::class)->unlink($this->org, $this->group, User::factory()->create());

    expect(app(TeacherAuthority::class)->canTeach($this->owner, $this->group->fresh()))->toBeFalse()
        ->and(DB::table('teacher_groups')->where('telegram_group_id', $this->group->id)->count())->toBe(0);
});

it('does not touch the teachers of a group that is not linked to the center on unlink', function (): void {
    OrganizationUser::factory()->create(['organization_id' => $this->org->id, 'user_id' => $this->owner->id]);
    DB::table('teacher_groups')->insert(['user_id' => $this->owner->id, 'telegram_group_id' => $this->group->id, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);

    // The group belongs to nobody: the linker has nothing to unlink, the teacher row stays.
    app(OrganizationGroupLinker::class)->unlink($this->org, $this->group, $this->owner);

    expect(DB::table('teacher_groups')->where('telegram_group_id', $this->group->id)->count())->toBe(1);
});

it('keeps one primary group when the owner links several groups', function (): void {
    OrganizationUser::factory()->create(['organization_id' => $this->org->id, 'user_id' => $this->owner->id]);
    $second = TelegramGroup::factory()->create(['organization_id' => null, 'status' => 'active']);
    $this->tg->chatMemberStatus = 'creator';

    $this->service->link($this->org, $this->group->chat_id, $this->owner);
    $this->service->link($this->org, $second->chat_id, $this->owner);

    expect(DB::table('teacher_groups')->where('user_id', $this->owner->id)->count())->toBe(2)
        ->and(DB::table('teacher_groups')->where('user_id', $this->owner->id)->where('is_primary', true)->count())->toBe(1);
});
