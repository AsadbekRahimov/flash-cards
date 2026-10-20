<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Learners\Services\OnboardingService;
use App\Domain\Telegram\Handlers\Contracts\UpdateHandler;
use App\Domain\Telegram\Services\OrganizationMessenger;
use App\Domain\Telegram\Support\CallbackAcknowledger;
use App\Domain\Telegram\Support\DmLearnerResolver;

/**
 * Buttons about centers (`org1:…`, see {@see OrganizationMessenger}): "Join center X?" of an `org_<code>` link
 * and "My centers" / "Leave" of `/settings`.
 *
 * `callback_data` is not signed, so it is matched strictly and nothing in it is trusted: a code is checked
 * by the membership service again, a membership id is looked up among the sender's OWN active memberships
 * (someone else's id is "not a member", never an action on it). The query is answered at once; a button of a
 * message in any chat but the sender's own DM, or of a learner who has not finished the onboarding or is
 * blocked, is ignored.
 */
final class OrganizationCallbackHandler implements UpdateHandler
{
    private const FORMAT = '~^org1:(?:(join):([A-Za-z0-9_-]{1,32})|(skip|list|leaveno)|(leave|leaveyes):([0-9]{1,18}))$~';

    public function __construct(
        private readonly CallbackAcknowledger $acknowledger,
        private readonly DmLearnerResolver $learners,
        private readonly OrganizationMessenger $messenger,
    ) {}

    /** @param array<string, mixed> $update */
    public function matches(array $update): bool
    {
        return str_starts_with((string) ($update['callback_query']['data'] ?? ''), OrganizationMessenger::PREFIX.':');
    }

    // Private-chat only; the Group Lock does not apply.
    public function requiresActiveGroup(): bool
    {
        return false;
    }

    /** @param array<string, mixed> $update */
    public function handle(array $update): void
    {
        /** @var array<string, mixed> $cq */
        $cq = $update['callback_query'];

        $this->acknowledger->acknowledge($update);

        $fromId = (int) ($cq['from']['id'] ?? 0);
        $message = is_array($cq['message'] ?? null) ? $cq['message'] : [];
        $messageId = (int) ($message['message_id'] ?? 0);

        if (($message['chat']['type'] ?? '') !== 'private'
            || $fromId <= 0
            || (int) ($message['chat']['id'] ?? 0) !== $fromId
            || preg_match(self::FORMAT, (string) ($cq['data'] ?? ''), $m) !== 1
        ) {
            return;
        }

        $learner = $this->learners->resolve(is_array($cq['from'] ?? null) ? $cq['from'] : []);
        if ($learner === null || $learner->blocked_at !== null || $learner->onboarding_state !== OnboardingService::DONE) {
            return;
        }

        $chatId = $fromId;

        // Group 1: join + code; 3: skip | list | leaveno; 4: leave | leaveyes + membership id.
        $action = $m[1] === 'join' ? 'join' : ($m[3] !== '' ? $m[3] : $m[4]);

        $memberId = (int) ($m[5] ?? 0);

        match ($action) {
            'join' => $this->messenger->join($learner, $chatId, $messageId, $m[2]),
            'skip' => $this->messenger->skip($learner, $chatId, $messageId),
            'list' => $this->messenger->sendList($learner, $chatId),
            'leave' => $this->messenger->confirmLeave($learner, $chatId, $memberId),
            'leaveyes' => $this->messenger->leave($learner, $chatId, $messageId, $memberId),
            default => $this->messenger->cancelLeave($learner, $chatId, $messageId),
        };
    }
}
