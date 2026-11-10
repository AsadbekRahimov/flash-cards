<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Services;

use App\Domain\Administration\Services\OrganizationGroupLinker;
use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Organizations\Exceptions\OrgGroupLinkException;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Services\GroupBotAccessChecker;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Organization;
use App\Models\TelegramGroup;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Linking a Telegram group to the organization BY ITS OWNER from the cabinet (docs/13 §6.2, §10):
 * unlike the superadmin, the owner must prove the group is theirs — `getChatMember(chat, owner)`
 * has to answer `creator` or `administrator`, so nobody can attach somebody else's class to their
 * center and read its activity. The write itself (row lock, Eloquent save so the observer creates
 * the memberships, audit `organization.linked_group` with the acting user) is the shared
 * {@see OrganizationGroupLinker}. The Telegram call runs OUTSIDE the transaction (docs/13 §3).
 *
 * The owner who links a group becomes its teacher (`teacher_groups`, same transaction as the link): the bot lets only
 * a teacher of the group run a training, and the owner has just proven the group is theirs. The group's course (needed for
 * assignments) is set by {@see self::setCourse()}.
 *
 * Lookups bypass the panel's tenancy global scope on purpose: a group to be linked is, by
 * definition, not yet the tenant's.
 */
final class OrgGroupLinkService
{
    public const AUDIT_COURSE_CHANGED = 'organization.group_course_changed';

    private const ADMIN_STATUSES = ['creator', 'administrator'];

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly OrganizationGroupLinker $linker,
        private readonly GroupBotAccessChecker $botAccess,
        private readonly CourseCatalogService $courses,
        private readonly OrgRequestMemo $memo,
    ) {}

    /**
     * @throws OrgGroupLinkException owner_telegram_missing | group_not_found | group_taken | not_chat_admin | telegram_unavailable
     */
    public function link(Organization $organization, int $chatId, User $owner, ?string $ip = null): TelegramGroup
    {
        if ($owner->telegram_user_id === null) {
            throw OrgGroupLinkException::ownerTelegramMissing($owner->id);
        }

        /** @var TelegramGroup|null $group */
        $group = TelegramGroup::query()->withoutGlobalScopes()->where('chat_id', $chatId)->first();

        if ($group === null) {
            throw OrgGroupLinkException::groupNotFound($chatId);
        }

        if ($group->organization_id === $organization->id) {
            return $group;
        }

        if ($group->organization_id !== null) {
            throw OrgGroupLinkException::groupTaken($group->id);
        }

        try {
            $status = $this->telegram->getChatMemberStatus($group->chat_id, $owner->telegram_user_id);
        } catch (TelegramApiException $e) {
            Log::warning('organizations.link_group_check_failed', ['chat_id' => $chatId, 'organization_id' => $organization->id, 'error' => $e->getMessage()]);

            throw OrgGroupLinkException::telegramUnavailable($e->getMessage());
        }

        if (! in_array($status, self::ADMIN_STATUSES, true)) {
            Log::info('organizations.link_group_refused', ['chat_id' => $chatId, 'organization_id' => $organization->id, 'user_id' => $owner->id, 'status' => $status]);

            throw OrgGroupLinkException::notChatAdmin($chatId, $status);
        }

        try {
            DB::transaction(function () use ($organization, $group, $owner, $ip): void {
                $this->linker->link($organization, $group, $owner, $ip);
                $this->assignTeacher($group, $owner);
            });
        } catch (DomainException) {
            // Taken between our read and the row lock.
            throw OrgGroupLinkException::groupTaken($group->id);
        }

        // Same courtesy as when the bot is added: a group where the bot is a plain member works half-way.
        $this->botAccess->warnIfNotAdmin($group);

        return $group->refresh();
    }

    /**
     * Courses a group of the center may be given, `id => title` in the center's language: the public published ones
     * (the catalog). {@see self::setCourse()} accepts the same and unlisted courses (the availability rule).
     *
     * @return array<int, string>
     */
    public function courseOptions(Organization $organization): array
    {
        $options = [];

        foreach ($this->courses->published($organization->locale) as $course) {
            $options[$course->id] = $course->title;
        }

        return $options;
    }

    /**
     * Sets the course of the organization's group (assignments need one). The course must be available
     * ({@see CourseCatalogService::isAvailable()}: published, not private); the group is read under a row lock and
     * must be the organization's own. Saved through Eloquent, audited with the acting user.
     *
     * @throws OrgGroupLinkException not_linked | course_unavailable
     */
    public function setCourse(Organization $organization, TelegramGroup $group, int $courseId, User $owner, ?string $ip = null): TelegramGroup
    {
        /** @var Course|null $course */
        $course = Course::query()->find($courseId);

        if ($course === null || ! $this->courses->isAvailable($course)) {
            throw OrgGroupLinkException::courseUnavailable($courseId);
        }

        return DB::transaction(function () use ($organization, $group, $course, $owner, $ip): TelegramGroup {
            /** @var TelegramGroup|null $current */
            $current = TelegramGroup::query()->withoutGlobalScopes()->whereKey($group->id)->lockForUpdate()->first();

            if ($current === null || $current->organization_id !== $organization->id) {
                throw OrgGroupLinkException::notLinked($group->id, $organization->id);
            }

            if ($current->course_id === $course->id) {
                return $current;
            }

            $from = $current->course_id;
            $current->course_id = $course->id;
            $current->save();

            AuditLog::create([
                'user_id' => $owner->id,
                'action' => self::AUDIT_COURSE_CHANGED,
                'entity_type' => Organization::class,
                'entity_id' => $organization->id,
                'ip' => $ip,
                'meta' => ['telegram_group_id' => $current->id, 'from_course_id' => $from, 'to_course_id' => $course->id],
            ]);

            return $current;
        });
    }

    /**
     * @throws OrgGroupLinkException not_linked
     */
    public function unlink(Organization $organization, TelegramGroup $group, User $owner, ?string $ip = null): void
    {
        if ($group->organization_id !== $organization->id) {
            throw OrgGroupLinkException::notLinked($group->id, $organization->id);
        }

        $this->linker->unlink($organization, $group, $owner, $ip);
    }

    /** Idempotent: the owner teaches the group they have just linked (the first group of a teacher is their primary one). */
    private function assignTeacher(TelegramGroup $group, User $owner): void
    {
        if (DB::table('teacher_groups')->where('user_id', $owner->id)->where('telegram_group_id', $group->id)->exists()) {
            return;
        }

        $now = now();

        DB::table('teacher_groups')->insert([
            'user_id' => $owner->id,
            'telegram_group_id' => $group->id,
            'is_primary' => ! DB::table('teacher_groups')->where('user_id', $owner->id)->exists(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Written with the query builder (no model events): the per-request memory of the cabinet is dropped by hand.
        $this->memo->flush();
    }
}
