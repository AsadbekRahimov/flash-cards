<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Services;

use App\Domain\Administration\Services\OrganizationGroupLinker;
use App\Domain\Organizations\Exceptions\OrgGroupLinkException;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Services\GroupBotAccessChecker;
use App\Models\Organization;
use App\Models\TelegramGroup;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\Log;

/**
 * Linking a Telegram group to the organization BY ITS OWNER from the cabinet (docs/13 §6.2, §10):
 * unlike the superadmin, the owner must prove the group is theirs — `getChatMember(chat, owner)`
 * has to answer `creator` or `administrator`, so nobody can attach somebody else's class to their
 * center and read its activity. The write itself (row lock, Eloquent save so the observer creates
 * the memberships, audit `organization.linked_group` with the acting user) is the shared
 * {@see OrganizationGroupLinker}. The Telegram call runs OUTSIDE the transaction (docs/13 §3).
 *
 * Lookups bypass the panel's tenancy global scope on purpose: a group to be linked is, by
 * definition, not yet the tenant's.
 */
final class OrgGroupLinkService
{
    private const ADMIN_STATUSES = ['creator', 'administrator'];

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly OrganizationGroupLinker $linker,
        private readonly GroupBotAccessChecker $botAccess,
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
            $this->linker->link($organization, $group, $owner, $ip);
        } catch (DomainException) {
            // Taken between our read and the row lock.
            throw OrgGroupLinkException::groupTaken($group->id);
        }

        // Same courtesy as when the bot is added: a group where the bot is a plain member works half-way.
        $this->botAccess->warnIfNotAdmin($group);

        return $group->refresh();
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
}
