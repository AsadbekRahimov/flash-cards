<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use App\Domain\Telegram\Handlers\ChatMemberHandler;
use App\Domain\Telegram\Handlers\CloseExamHandler;
use App\Domain\Telegram\Handlers\Contracts\UpdateHandler;
use App\Domain\Telegram\Handlers\FriendsCallbackHandler;
use App\Domain\Telegram\Handlers\HelpCommandHandler;
use App\Domain\Telegram\Handlers\InviteCommandHandler;
use App\Domain\Telegram\Handlers\LearnerDmHandler;
use App\Domain\Telegram\Handlers\MyChatMemberHandler;
use App\Domain\Telegram\Handlers\NewMembersHandler;
use App\Domain\Telegram\Handlers\OnboardingCallbackHandler;
use App\Domain\Telegram\Handlers\OrganizationCallbackHandler;
use App\Domain\Telegram\Handlers\OrgCommandHandler;
use App\Domain\Telegram\Handlers\PaySupportCommandHandler;
use App\Domain\Telegram\Handlers\ProCallbackHandler;
use App\Domain\Telegram\Handlers\ProCommandHandler;
use App\Domain\Telegram\Handlers\PromoCommandHandler;
use App\Domain\Telegram\Handlers\RefundedPaymentHandler;
use App\Domain\Telegram\Handlers\SettingsCommandHandler;
use App\Domain\Telegram\Handlers\StartCommandHandler;
use App\Domain\Telegram\Handlers\StartExamHandler;
use App\Domain\Telegram\Handlers\StartTrainingCallbackHandler;
use App\Domain\Telegram\Handlers\StartTrainingHandler;
use App\Domain\Telegram\Handlers\StatsCommandHandler;
use App\Domain\Telegram\Handlers\SuccessfulPaymentHandler;
use App\Domain\Telegram\Handlers\TodayCommandHandler;
use App\Domain\Telegram\Support\CallbackAcknowledger;
use App\Domain\Telegram\Support\DmLearnerResolver;
use App\Models\TelegramGroup;
use Illuminate\Support\Facades\RateLimiter;

final class TelegramDispatcher
{
    /** @var list<UpdateHandler> */
    private array $messageHandlers;

    /** @var list<UpdateHandler> */
    private array $callbackHandlers;

    public function __construct(
        private readonly CallbackAcknowledger $acknowledger,
        private readonly DmLearnerResolver $dmLearners,
        SuccessfulPaymentHandler $successfulPayment,
        RefundedPaymentHandler $refundedPayment,
        StartCommandHandler $start,
        StartTrainingHandler $startTraining,
        StartTrainingCallbackHandler $startTrainingCallback,
        StartExamHandler $startExam,
        CloseExamHandler $closeExam,
        StatsCommandHandler $stats,
        PromoCommandHandler $promo,
        ProCommandHandler $pro,
        InviteCommandHandler $invite,
        PaySupportCommandHandler $paySupport,
        OrgCommandHandler $org,
        HelpCommandHandler $help,
        MyChatMemberHandler $myChatMember,
        NewMembersHandler $newMembers,
        ChatMemberHandler $chatMember,
        TodayCommandHandler $today,
        SettingsCommandHandler $settings,
        LearnerDmHandler $learnerDm,
        OnboardingCallbackHandler $onboardingCallback,
        ProCallbackHandler $proCallback,
        OrganizationCallbackHandler $organizationCallback,
        FriendsCallbackHandler $friendsCallback,
    ) {
        // Order matters: more specific commands first (e.g. /start_training
        // must win over the generic /start catch-all).
        $this->messageHandlers = [
            // Money first (docs/13 §4.1): a Stars payment / refund message is never a DM command.
            $successfulPayment,
            $refundedPayment,
            $myChatMember,
            $chatMember,
            $newMembers,
            $startTraining,
            $startExam,
            $closeExam,
            $stats,
            $promo,
            $pro,
            $invite,
            $paySupport,
            $org,
            $start,
            $today,
            $settings,
            $help,
            // Catch-all for the rest of a learner's DM: must stay last.
            $learnerDm,
        ];

        $this->callbackHandlers = [
            $startTrainingCallback,
            $onboardingCallback,
            $proCallback,
            $organizationCallback,
            $friendsCallback,
        ];
    }

    /** @param array<string, mixed> $update */
    public function dispatch(array $update): void
    {
        if (! $this->withinInboundLimit($update)) {
            // Flood: no handler, no database write, no reply. A tapped button is still
            // acknowledged (best effort) so the client does not spin until timeout.
            $this->acknowledgeCallback($update);

            return;
        }

        // Any message or button tap in a private chat proves the DM is open: a learner who
        // got a Telegram 403 on a reminder becomes reachable again (docs/12 §7.5). Before
        // the handlers, so every DM command counts — not only those that resolve a learner.
        if ($this->chatType($update) === 'private') {
            $this->dmLearners->markReachable($this->fromId($update));
        }

        $handlers = isset($update['callback_query'])
            ? $this->callbackHandlers
            : $this->messageHandlers;

        foreach ($handlers as $handler) {
            if (! $handler->matches($update)) {
                continue;
            }

            // Group Lock (FR-BOT-01): handlers that require an active group are
            // dropped silently when the update comes from a group chat that is
            // not whitelisted/active. Private chats and lifecycle updates pass.
            if ($handler->requiresActiveGroup()
                && $this->isFromGroupChat($update)
                && ! $this->isActiveGroup($update)
            ) {
                // No chat message is sent (the group stays silent), but a tapped
                // inline button must still be acknowledged so the user's client
                // dismisses its loading spinner instead of hanging until timeout.
                $this->acknowledgeCallback($update);

                return;
            }

            $handler->handle($update);

            return;
        }
    }

    /** @param array<string, mixed> $update */
    private function acknowledgeCallback(array $update): void
    {
        if (isset($update['callback_query'])) {
            $this->acknowledger->acknowledge($update);
        }
    }

    /**
     * More than `telegram.inbound_rate_limit.max_messages` messages / button taps from one
     * Telegram user per window are ignored (docs/12 §8). Updates without a sender (channel posts,
     * ...) are not limited here. Own counter, separate from the `bot-promo:` one of `/promo`.
     *
     * @param  array<string, mixed>  $update
     */
    private function withinInboundLimit(array $update): bool
    {
        $fromId = $this->fromId($update);

        if ($fromId <= 0) {
            return true;
        }

        // Money is never dropped: Telegram delivers `successful_payment` / `refunded_payment` once we
        // answer 200, and PaymentApplier is idempotent anyway (docs/13 §4.1).
        if (isset($update['message']['successful_payment']) || isset($update['message']['refunded_payment'])) {
            return true;
        }

        return RateLimiter::attempt(
            'bot-in:'.$fromId,
            max(1, (int) config('telegram.inbound_rate_limit.max_messages', 30)),
            static fn (): bool => true,
            max(1, (int) config('telegram.inbound_rate_limit.window_seconds', 60)),
        );
    }

    /** @param array<string, mixed> $update */
    private function fromId(array $update): int
    {
        return (int) ($update['callback_query']['from']['id'] ?? $update['message']['from']['id'] ?? 0);
    }

    /** @param array<string, mixed> $update */
    private function isFromGroupChat(array $update): bool
    {
        return in_array($this->chatType($update), ['group', 'supergroup'], true);
    }

    /** @param array<string, mixed> $update */
    private function isActiveGroup(array $update): bool
    {
        $chatId = $this->chatId($update);

        if ($chatId === null) {
            return false;
        }

        return TelegramGroup::query()
            ->where('chat_id', $chatId)
            ->where('status', 'active')
            ->exists();
    }

    /** @param array<string, mixed> $update */
    private function chatType(array $update): string
    {
        $chat = $this->chat($update);

        return is_array($chat) ? (string) ($chat['type'] ?? '') : '';
    }

    /** @param array<string, mixed> $update */
    private function chatId(array $update): ?int
    {
        $chat = $this->chat($update);

        if (! is_array($chat) || ! isset($chat['id'])) {
            return null;
        }

        return (int) $chat['id'];
    }

    /**
     * @param  array<string, mixed>  $update
     * @return array<string, mixed>|null
     */
    private function chat(array $update): ?array
    {
        $message = $update['callback_query']['message'] ?? $update['message'] ?? null;
        $chat = is_array($message) ? ($message['chat'] ?? null) : null;

        return is_array($chat) ? $chat : null;
    }
}
