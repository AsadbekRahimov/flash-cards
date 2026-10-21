<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Learners\Services\OnboardingService;
use App\Domain\Telegram\Handlers\Contracts\UpdateHandler;
use App\Domain\Telegram\Services\FriendsMessenger;
use App\Domain\Telegram\Support\CallbackAcknowledger;
use App\Domain\Telegram\Support\DmLearnerResolver;

/**
 * Buttons about friends (`fr1:…`, see {@see FriendsMessenger}): accept / decline a friend invite and a challenge.
 *
 * `callback_data` is not signed, so it is matched strictly and nothing in it is trusted: the id is looked up
 * by the services among the sender's OWN friendships / challenges (someone else's id is "no longer valid",
 * never an action on it). The query is answered at once; a button of a message in any chat but the sender's
 * own DM, or of a learner who has not finished the onboarding or is blocked, is ignored.
 */
final class FriendsCallbackHandler implements UpdateHandler
{
    private const FORMAT = '~^fr1:(acc|dec|cacc|cdec):([0-9]{1,18})$~';

    public function __construct(
        private readonly CallbackAcknowledger $acknowledger,
        private readonly DmLearnerResolver $learners,
        private readonly FriendsMessenger $messenger,
    ) {}

    /** @param array<string, mixed> $update */
    public function matches(array $update): bool
    {
        return str_starts_with((string) ($update['callback_query']['data'] ?? ''), FriendsMessenger::PREFIX.':');
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

        $id = (int) $m[2];

        match ($m[1]) {
            'acc' => $this->messenger->acceptFriend($learner, $fromId, $messageId, $id),
            'dec' => $this->messenger->declineFriend($learner, $fromId, $messageId, $id),
            'cacc' => $this->messenger->acceptChallenge($learner, $fromId, $messageId, $id),
            default => $this->messenger->declineChallenge($learner, $fromId, $messageId, $id),
        };
    }
}
