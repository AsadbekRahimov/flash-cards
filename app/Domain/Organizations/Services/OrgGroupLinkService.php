<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Services;

use App\Domain\Administration\Services\OrganizationGroupLinker;
use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Organizations\DTO\GroupLinkOutcome;
use App\Domain\Organizations\Enums\GroupLinkResult;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Exceptions\OrgGroupLinkException;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\DTO\IssuedLink;
use App\Domain\Telegram\Enums\LinkTokenKind;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TelegramLinkException;
use App\Domain\Telegram\Services\GroupBotAccessChecker;
use App\Domain\Telegram\Services\TelegramLinkTokens;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\TelegramGroup;
use App\Models\TelegramLinkToken;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Linking a Telegram group to a center BY ITS OWNER (docs/13 §6.2, §10; audit 2026-11-10 H1 / P1 / L8).
 *
 * The cabinet no longer takes a `chat_id`: the owner gets a one-time link `t.me/<bot>?startgroup=grp_<token>` (Telegram adds the bot
 * to a group of their choice and posts `/start grp_<token>` there) or types `/link <token>` in a group where the bot already is
 * ({@see issueLink()}). The bot then links the group ({@see linkFromChat()}) only when ALL of these hold:
 *
 *  1. the sender of the message is a staff account whose Telegram was VERIFIED through the bot (`users.telegram_verified_at`) — a
 *     hand-typed id proves nothing (H1);
 *  2. the token is live and was issued to that very user, who is still an owner of the (active) organization it names;
 *  3. Telegram confirms the sender is `creator` / `administrator` of that chat (`getChatMember`);
 *  4. the chat is not linked to another center.
 *
 * In the chat every refusal gets ONE neutral answer (L8); the reason is kept on the token row for the owner's cabinet
 * ({@see lastAttempt()}). The write is the shared {@see OrganizationGroupLinker} (row lock, Eloquent save so the observer creates
 * the memberships, audit with the acting user) plus `teacher_groups` for the owner (they have just proven the group is theirs) and —
 * for a center with a live subscription, `organizations.auto_activate_linked_groups` — the activation of the group, which the
 * platform's administrator used to do by hand. The Telegram call runs OUTSIDE the transaction (docs/13 §3).
 *
 * Lookups bypass the panel's tenancy global scope on purpose: a group to be linked is, by definition, not yet the tenant's.
 */
final class OrgGroupLinkService
{
    public const AUDIT_COURSE_CHANGED = 'organization.group_course_changed';

    public const AUDIT_GROUP_ACTIVATED = 'organization.group_activated';

    /** `telegram_groups.meta` key: the group was activated by the link of a center, not by `/admin`; the unlink puts it back to `pending`. */
    public const META_ACTIVATED_VIA = 'activated_via';

    public const ACTIVATED_VIA_ORG_LINK = 'org_link';

    private const ADMIN_STATUSES = ['creator', 'administrator'];

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly OrganizationGroupLinker $linker,
        private readonly GroupBotAccessChecker $botAccess,
        private readonly CourseCatalogService $courses,
        private readonly TeacherGroupAssignments $assignments,
        private readonly TelegramLinkTokens $tokens,
        private readonly OrgOverviewService $overview,
    ) {}

    /**
     * A one-time group link for an owner whose Telegram is verified. The organization must be active.
     *
     * @throws OrgGroupLinkException not_owner | owner_telegram_unverified | rate_limited
     */
    public function issueLink(Organization $organization, User $owner, ?CarbonImmutable $now = null): IssuedLink
    {
        if (! $organization->isActive() || ! $this->isOwner($owner->id, $organization->id)) {
            throw OrgGroupLinkException::notOwner($owner->id, $organization->id);
        }

        if (! $owner->hasVerifiedTelegram()) {
            throw OrgGroupLinkException::ownerTelegramUnverified($owner->id);
        }

        try {
            return $this->tokens->issueGroupLink($owner, $organization, $now);
        } catch (TelegramLinkException $e) {
            throw OrgGroupLinkException::rateLimited($e->retryAfter);
        }
    }

    /**
     * `/start grp_<token>` or `/link <token>` posted in a group / supergroup.
     *
     * @param  array<string, mixed>  $chat  Telegram `Chat` of the message
     * @param  array<string, mixed>  $from  Telegram `User` of the sender
     */
    public function linkFromChat(string $token, array $chat, array $from, ?CarbonImmutable $now = null): GroupLinkOutcome
    {
        $now ??= CarbonImmutable::now();
        $chatId = (int) ($chat['id'] ?? 0);
        $chatType = (string) ($chat['type'] ?? '');
        $senderId = (int) ($from['id'] ?? 0);

        if ($chatId === 0 || $senderId <= 0 || ! in_array($chatType, ['group', 'supergroup'], true)) {
            return GroupLinkOutcome::refused(GroupLinkResult::TokenInvalid);
        }

        // 1. The sender first — a stranger's attempt costs no Telegram call and learns nothing about the token.
        /** @var User|null $sender */
        $sender = User::query()
            ->where('telegram_user_id', $senderId)
            ->whereNotNull('telegram_verified_at')
            ->where('is_active', true)
            ->first();

        if ($sender === null) {
            return GroupLinkOutcome::refused(GroupLinkResult::SenderUnknown);
        }

        // 2. The token: live and issued to this very user.
        $row = $this->tokens->find(LinkTokenKind::Group, $token);
        if ($row === null || ! $row->isLiveAt($now) || $row->organization_id === null) {
            return GroupLinkOutcome::refused(GroupLinkResult::TokenInvalid);
        }

        if ($row->user_id !== $sender->id) {
            // Another verified staff account opened the owner's link: the owner is told in the cabinet, the chat is not.
            return $this->refuse($row, GroupLinkResult::SenderMismatch, $now);
        }

        /** @var Organization|null $organization */
        $organization = Organization::query()->find($row->organization_id);
        if ($organization === null || ! $organization->isActive() || ! $this->isOwner($sender->id, $organization->id)) {
            return $this->refuse($row, GroupLinkResult::NotOwner, $now);
        }

        // 4 (early). Already ours — nothing to prove again; somebody else's — no Telegram call either.
        /** @var TelegramGroup|null $group */
        $group = TelegramGroup::query()->withoutGlobalScopes()->where('chat_id', $chatId)->first();

        if ($group !== null && $group->organization_id !== null && $group->organization_id !== $organization->id) {
            return $this->refuse($row, GroupLinkResult::GroupTaken, $now);
        }

        // 3. Telegram: the sender must be creator / administrator of the chat.
        try {
            $status = $this->telegram->getChatMemberStatus($chatId, $senderId);
        } catch (TelegramApiException $e) {
            Log::warning('organizations.link_group_check_failed', ['chat_id' => $chatId, 'organization_id' => $organization->id, 'error' => $e->getMessage()]);

            return $this->refuse($row, GroupLinkResult::TelegramUnavailable, $now);
        }

        if (! in_array($status, self::ADMIN_STATUSES, true)) {
            Log::info('organizations.link_group_refused', ['chat_id' => $chatId, 'organization_id' => $organization->id, 'user_id' => $sender->id, 'status' => $status]);

            return $this->refuse($row, GroupLinkResult::NotChatAdmin, $now);
        }

        $title = (string) ($chat['title'] ?? '');

        try {
            $outcome = DB::transaction(function () use ($row, $organization, $sender, $chatId, $chatType, $title, $now): GroupLinkOutcome {
                /** @var TelegramLinkToken|null $locked */
                $locked = TelegramLinkToken::query()->whereKey($row->id)->lockForUpdate()->first();
                if ($locked === null || ! $locked->isLiveAt($now) || $locked->user_id !== $sender->id) {
                    return GroupLinkOutcome::refused(GroupLinkResult::TokenInvalid);
                }

                $group = $this->groupRow($chatId, $chatType, $title);

                try {
                    $this->linker->link($organization, $group, $sender);
                } catch (DomainException) {
                    // Taken between our read and the row lock.
                    $this->tokens->recordRefusal($locked, GroupLinkResult::GroupTaken->value, $now);

                    return GroupLinkOutcome::refused(GroupLinkResult::GroupTaken);
                }

                $this->assignments->attach($sender->id, [$group->id]);
                $activated = $this->activateIfSponsoring($organization, $group, $sender, $now);

                if (! $this->tokens->consume($locked, $now)) {
                    // Cannot happen under the lock; the exception rolls the link back rather than leaving a link without a spent token.
                    throw new DomainException('Group link token was spent concurrently.');
                }

                return new GroupLinkOutcome(GroupLinkResult::Linked, $group->refresh(), $organization, $activated);
            });
        } catch (DomainException $e) {
            Log::warning('organizations.link_group_token_race', ['token_id' => $row->id, 'error' => $e->getMessage()]);

            return GroupLinkOutcome::refused(GroupLinkResult::TokenInvalid);
        }

        if ($outcome->result->isSuccess() && $outcome->group !== null) {
            // Same courtesy as when the bot is added: a group where the bot is a plain member works half-way.
            $this->botAccess->warnIfNotAdmin($outcome->group);
        }

        return $outcome;
    }

    /** The owner's newest group-link token of this organization: whether a link is still live and what the last attempt said. */
    public function lastAttempt(Organization $organization, User $owner): ?TelegramLinkToken
    {
        return $this->tokens->latest(LinkTokenKind::Group, $owner, $organization);
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

    // ---------------------------------------------------------------------------------------------

    private function refuse(TelegramLinkToken $token, GroupLinkResult $result, CarbonImmutable $now): GroupLinkOutcome
    {
        $this->tokens->recordRefusal($token, $result->value, $now);

        return GroupLinkOutcome::refused($result);
    }

    /** Read from the table, not from the per-request memory: the decision is taken under a lock / for a token. */
    private function isOwner(int $userId, int $organizationId): bool
    {
        return OrganizationUser::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->where('role', OrganizationRole::Owner->value)
            ->exists();
    }

    /**
     * The group's row, created as `pending` when the bot has not reported being added yet (the `/start grp_…` of a `startgroup`
     * deep link may arrive before `my_chat_member`; the bot is evidently in the chat — it received the message).
     */
    private function groupRow(int $chatId, string $chatType, string $title): TelegramGroup
    {
        /** @var TelegramGroup|null $group */
        $group = TelegramGroup::query()->withoutGlobalScopes()->where('chat_id', $chatId)->first();
        if ($group !== null) {
            return $group;
        }

        try {
            return TelegramGroup::query()->create([
                'chat_id' => $chatId,
                'title' => $title !== '' ? $title : __('bot.group.untitled'),
                'status' => 'pending',
                'meta' => ['type' => $chatType],
            ]);
        } catch (UniqueConstraintViolationException) {
            /** @var TelegramGroup $group */
            $group = TelegramGroup::query()->withoutGlobalScopes()->where('chat_id', $chatId)->firstOrFail();

            return $group;
        }
    }

    /**
     * A center with a live subscription gets its group active at once (`organizations.auto_activate_linked_groups`); the group
     * remembers it came active this way so the unlink can put it back to `pending`. Through Eloquent: the observer drops the
     * `/admin` badge and the members' entitlements. Returns whether the status changed.
     */
    private function activateIfSponsoring(Organization $organization, TelegramGroup $group, User $by, CarbonImmutable $now): bool
    {
        if (! (bool) config('organizations.auto_activate_linked_groups', true)) {
            return false;
        }

        /** @var TelegramGroup $current */
        $current = TelegramGroup::query()->withoutGlobalScopes()->whereKey($group->id)->lockForUpdate()->firstOrFail();

        if ($current->status !== 'pending' || $this->overview->subscription($organization, $now) === null) {
            return false;
        }

        $current->status = 'active';
        $current->meta = array_merge($current->meta ?? [], [self::META_ACTIVATED_VIA => self::ACTIVATED_VIA_ORG_LINK]);
        $current->save();

        AuditLog::create([
            'user_id' => $by->id,
            'action' => self::AUDIT_GROUP_ACTIVATED,
            'entity_type' => Organization::class,
            'entity_id' => $organization->id,
            'ip' => null,
            'meta' => ['telegram_group_id' => $current->id, 'via' => self::ACTIVATED_VIA_ORG_LINK],
        ]);

        return true;
    }
}
