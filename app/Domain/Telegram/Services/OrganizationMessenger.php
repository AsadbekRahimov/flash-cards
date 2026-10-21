<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use App\Domain\Learners\DTO\StartPayload;
use App\Domain\Learners\Enums\StartPayloadKind;
use App\Domain\Learners\Services\OnboardingService;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Organizations\Enums\JoinedVia;
use App\Domain\Organizations\Exceptions\OrganizationMembershipException;
use App\Domain\Organizations\Services\LearnerOrganizationsService;
use App\Domain\Organizations\Services\OrganizationMembershipService;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Support\DmLearnerResolver;
use App\Models\Learner;
use App\Models\OrganizationInvite;
use App\Models\OrganizationMember;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Everything the bot says about centers (docs/13 §6.2): the "Join center X?" confirmation of an
 * `org_<code>` link, the DM after joining / getting a seat, and the "My centers" list of `/settings`
 * with "Leave". Texts are `lang/{ru,uz}/organizations.php` in the learner's language. The rules are
 * {@see OrganizationMembershipService} / {@see LearnerOrganizationsService}; this class only talks to Telegram.
 *
 * `callback_data` (≤ 64 bytes; the prefix carries the format version, as with `onb1` / `set1`):
 *   org1:join:<code>   — confirm joining (the code is checked again by `joinByInvite`);
 *   org1:skip          — decline, nothing is created;
 *   org1:list          — `/settings` -> "My centers";
 *   org1:leave:<id>    — ask "Leave the center?" (`<id>` = organization_members.id);
 *   org1:leaveyes:<id> — leave; org1:leaveno — change of mind.
 *
 * The confirmation after "Join" is not sent here: joining raises OrganizationMemberJoined and its
 * listener sends the DM ({@see self::notifyJoined()}), the same one an administrator's "add" gets.
 */
final class OrganizationMessenger
{
    public const PREFIX = 'org1';

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly LocaleResolver $locales,
        private readonly OnboardingService $onboarding,
        private readonly OnboardingMessenger $onboardingMessenger,
        private readonly OrganizationMembershipService $memberships,
        private readonly LearnerOrganizationsService $organizations,
        private readonly DmLearnerResolver $dm,
    ) {}

    /**
     * `/start org_<code>` BEFORE the onboarding is done: whether the code may be remembered in
     * `meta.pending_org_invite`. A refused code (unknown, off, expired, used up, suspended center) or a
     * center the learner is already in is explained here and `false` returned — the onboarding then
     * runs as if there were no payload.
     */
    public function acceptsPayload(Learner $learner, int|string $chatId, StartPayload $payload): bool
    {
        if ($payload->kind !== StartPayloadKind::Org || $payload->value === null) {
            return true;
        }

        return $this->usableInvite($learner, $chatId, $payload->value) !== null;
    }

    /** "Join center X?" for a code (a learner who has finished the onboarding); a refusal is explained instead. */
    public function offer(Learner $learner, int|string $chatId, string $code): void
    {
        $invite = $this->usableInvite($learner, $chatId, $code);
        if ($invite === null) {
            return;
        }

        $locale = $this->locales->forLearner($learner);

        $this->telegram->sendMessage(
            $chatId,
            __('organizations.offer.text', ['organization' => $invite->organization->name], $locale),
            replyMarkup: ['inline_keyboard' => [[
                ['text' => __('organizations.offer.join', [], $locale), 'callback_data' => self::PREFIX.':join:'.$invite->code],
                ['text' => __('organizations.offer.skip', [], $locale), 'callback_data' => self::PREFIX.':skip'],
            ]]],
        );
    }

    /** The end of the onboarding: offers the center of the remembered `org_<code>` link, once. */
    public function offerPending(Learner $learner, int|string $chatId): void
    {
        $code = $this->onboarding->pullPendingOrgInvite($learner);
        if ($code === null) {
            return;
        }

        $this->offer($learner, $chatId, $code);
    }

    /** "Join" tapped: joins (idempotent); the DM comes from OrganizationMemberJoined. */
    public function join(Learner $learner, int|string $chatId, int $messageId, string $code): void
    {
        $this->onboardingMessenger->clearKeyboard($chatId, $messageId);

        $invite = $this->usableInvite($learner, $chatId, $code);
        if ($invite === null) {
            return;
        }

        // A member through a group of the center is offered the link too: the membership then survives leaving the groups.
        $viaGroup = $this->memberships->activeMembership($learner, $invite->organization) !== null;

        try {
            $this->memberships->joinByInvite($learner, $code);
        } catch (OrganizationMembershipException $e) {
            // Used up / switched off between the offer and the tap.
            $this->sendError($learner, $chatId, $e->errorCode);

            return;
        }

        if ($viaGroup) {
            // No join event for an existing member, so nobody else answers.
            $this->sendAlready($learner, $chatId, $invite);
        }
    }

    public function skip(Learner $learner, int|string $chatId, int $messageId): void
    {
        $this->onboardingMessenger->clearKeyboard($chatId, $messageId);

        $this->telegram->sendMessage($chatId, __('organizations.skipped', [], $this->locales->forLearner($learner)));
    }

    /** `/settings` -> "My centers": state of every center, "Leave" for the ones the learner may leave. */
    public function sendList(Learner $learner, int|string $chatId): void
    {
        $locale = $this->locales->forLearner($learner);
        $memberships = $this->organizations->memberships($learner);

        if ($memberships->isEmpty()) {
            $this->telegram->sendMessage($chatId, __('organizations.list.empty', [], $locale));

            return;
        }

        $lines = [__('organizations.list.title', [], $locale)];
        $buttons = [];
        $hasGroupOnes = false;

        foreach ($memberships as $member) {
            $name = $member->organization->name;
            $lines[] = __('organizations.list.line', [
                'organization' => $name,
                'state' => __('organizations.list.state.'.($member->isSeated() ? 'seated' : 'waitlisted'), [], $locale),
            ], $locale);

            if ($member->isLeavableByLearner()) {
                $buttons[] = [[
                    'text' => __('organizations.list.leave', ['organization' => Str::limit($name, 40)], $locale),
                    'callback_data' => self::PREFIX.':leave:'.$member->id,
                ]];
            } else {
                $hasGroupOnes = true;
            }
        }

        if ($hasGroupOnes) {
            $lines[] = '';
            $lines[] = __('organizations.list.group_note', [], $locale);
        }

        $this->telegram->sendMessage(
            $chatId,
            implode("\n", $lines),
            replyMarkup: $buttons === [] ? null : ['inline_keyboard' => $buttons],
        );
    }

    /** "Leave" tapped: asks for confirmation (a group membership is explained instead). */
    public function confirmLeave(Learner $learner, int|string $chatId, int $memberId): void
    {
        $locale = $this->locales->forLearner($learner);

        try {
            $member = $this->organizations->find($learner, $memberId);
        } catch (OrganizationMembershipException $e) {
            $this->sendError($learner, $chatId, $e->errorCode);

            return;
        }

        if (! $member->isLeavableByLearner()) {
            $this->sendError($learner, $chatId, OrganizationMembershipException::MEMBERSHIP_VIA_GROUP, $member->organization->name);

            return;
        }

        $this->telegram->sendMessage(
            $chatId,
            __('organizations.leave.confirm', ['organization' => $member->organization->name], $locale),
            replyMarkup: ['inline_keyboard' => [[
                ['text' => __('organizations.leave.yes', [], $locale), 'callback_data' => self::PREFIX.':leaveyes:'.$member->id],
                ['text' => __('organizations.leave.no', [], $locale), 'callback_data' => self::PREFIX.':leaveno'],
            ]]],
        );
    }

    public function leave(Learner $learner, int|string $chatId, int $messageId, int $memberId): void
    {
        $this->onboardingMessenger->clearKeyboard($chatId, $messageId);

        try {
            $member = $this->organizations->leave($learner, $memberId);
        } catch (OrganizationMembershipException $e) {
            $this->sendError($learner, $chatId, $e->errorCode);

            return;
        }

        $this->telegram->sendMessage(
            $chatId,
            __('organizations.leave.done', ['organization' => $member->organization->name], $this->locales->forLearner($learner)),
        );
    }

    public function cancelLeave(Learner $learner, int|string $chatId, int $messageId): void
    {
        $this->onboardingMessenger->clearKeyboard($chatId, $messageId);

        $this->telegram->sendMessage($chatId, __('organizations.leave.cancelled', [], $this->locales->forLearner($learner)));
    }

    /**
     * DM after a join through an invite link or an administrator ("Pro is on" / "no seats yet"), sent by the
     * OrganizationMemberJoined listener. Joins through a group are silent (the person only entered a chat; the
     * hourly `organizations:sync-members` would otherwise message a whole center). The state is read again: a queued
     * message must not promise a seat the member has lost, or deny one they have since received.
     */
    public function notifyJoined(int $memberId): void
    {
        $target = $this->notifiable($memberId);
        if ($target === null) {
            return;
        }

        [$member, $learner] = $target;
        $locale = $this->locales->forLearner($learner);
        $params = ['organization' => $member->organization->name];

        $this->deliver($learner, fn () => $member->isSeated()
            ? $this->telegram->sendWebAppButton(
                chatId: $learner->telegram_user_id,
                text: __('organizations.joined.seated', $params, $locale),
                buttonText: __('organizations.joined.button', [], $locale),
                url: $this->twaUrl(),
            )
            : $this->telegram->sendMessage($learner->telegram_user_id, __('organizations.joined.waitlisted', $params, $locale)));
    }

    /** DM to a member who was waiting and now has a seat (FIFO after a leave or a tariff upgrade). */
    public function notifySeated(int $memberId): void
    {
        $target = $this->notifiable($memberId);
        if ($target === null || ! $target[0]->isSeated()) {
            return;
        }

        [$member, $learner] = $target;
        $locale = $this->locales->forLearner($learner);

        $this->deliver($learner, fn () => $this->telegram->sendWebAppButton(
            chatId: $learner->telegram_user_id,
            text: __('organizations.seated.text', ['organization' => $member->organization->name], $locale),
            buttonText: __('organizations.seated.button', [], $locale),
            url: $this->twaUrl(),
        ));
    }

    // ---------------------------------------------------------------------------------------------

    /**
     * The code's invite, or null after explaining why not: a refused code, or a center the learner
     * is already in ("You are already in center X"; a member through a group may still take the link).
     */
    private function usableInvite(Learner $learner, int|string $chatId, string $code): ?OrganizationInvite
    {
        try {
            $invite = $this->memberships->previewInvite($code);
        } catch (OrganizationMembershipException $e) {
            $this->sendError($learner, $chatId, $e->errorCode);

            return null;
        }

        $existing = $this->memberships->activeMembership($learner, $invite->organization);
        if ($existing !== null && $existing->joined_via !== JoinedVia::Group->value) {
            $this->sendAlready($learner, $chatId, $invite);

            return null;
        }

        return $invite;
    }

    private function sendAlready(Learner $learner, int|string $chatId, OrganizationInvite $invite): void
    {
        $this->telegram->sendMessage(
            $chatId,
            __('organizations.already', ['organization' => $invite->organization->name], $this->locales->forLearner($learner)),
        );
    }

    private function sendError(Learner $learner, int|string $chatId, string $errorCode, string $organization = ''): void
    {
        $locale = $this->locales->forLearner($learner);
        $key = 'organizations.errors.'.$errorCode;

        $this->telegram->sendMessage(
            $chatId,
            __(Lang::has($key, $locale) ? $key : 'organizations.errors.generic', ['organization' => $organization], $locale),
        );
    }

    /**
     * The member to message and their learner: still active, joined through an invite / by an
     * administrator, learner reachable.
     *
     * @return array{0: OrganizationMember, 1: Learner}|null
     */
    private function notifiable(int $memberId): ?array
    {
        /** @var OrganizationMember|null $member */
        $member = OrganizationMember::query()->with('organization')->find($memberId);

        if ($member === null
            || ! $member->isActive()
            || ! in_array($member->joined_via, [JoinedVia::Invite->value, JoinedVia::Admin->value], true)
        ) {
            return null;
        }

        // A soft-deleted learner is not found.
        $learner = Learner::query()->find($member->learner_id);

        if ($learner === null || $learner->blocked_at !== null || $learner->dm_blocked_at !== null) {
            return null;
        }

        return [$member, $learner];
    }

    /** A 403 means the person cannot be reached: they leave the DM selection (as with reminders) and the job ends. */
    private function deliver(Learner $learner, callable $send): void
    {
        try {
            $send();
        } catch (TelegramForbiddenException $e) {
            $this->dm->markBlocked($learner->telegram_user_id);

            Log::warning('telegram.organization_dm_forbidden', ['learner_id' => $learner->id, 'reason' => $e->reason]);
        }
    }

    private function twaUrl(): string
    {
        return rtrim((string) config('twa.base_url'), '/').'/twa/?from=organization';
    }
}
