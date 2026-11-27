<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Learners\Services\OnboardingService;
use App\Domain\Telegram\Services\FriendsMessenger;
use App\Domain\Telegram\Services\OrganizationMessenger;
use App\Jobs\Middleware\ThrottleTelegramOutbound;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * The invitation a learner opened the bot with (`org_<code>` → `meta.pending_org_invite`, `f_<code>` → `meta.pending_friend_invite`)
 * is offered by the bot right after the last step of ITS onboarding. A learner who finished the onboarding in the Mini App instead
 * (docs/40 §2.1, §5 п. 1) never reaches that step: this job makes the same two offers in the private chat once the Mini App
 * completed the onboarding ({@see OnboardingService::completeExternally()}). Each messenger pulls its
 * code under the row lock before sending, so a repeated job, a parallel `/start` (which offers them too) or two devices offer once.
 * A learner the bot cannot reach (blocked, DM closed) keeps the codes for a later `/start`.
 */
final class OfferPendingInvitesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $backoff = 60;

    public function __construct(public readonly int $learnerId) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [new ThrottleTelegramOutbound];
    }

    public function retryUntil(): DateTimeInterface
    {
        return CarbonImmutable::now()->addHours(6);
    }

    public function handle(OrganizationMessenger $organizations, FriendsMessenger $friends): void
    {
        $learner = Learner::query()->find($this->learnerId);
        if (! $learner instanceof Learner || $learner->blocked_at !== null || $learner->dm_blocked_at !== null || $learner->telegram_user_id <= 0) {
            return;
        }
        $chatId = $learner->telegram_user_id;

        // Each offer on its own: a failure of one does not swallow the other (the codes are pulled before the send, as in the bot).
        try {
            $organizations->offerPending($learner, $chatId);
        } catch (Throwable $e) {
            report($e);
        }

        try {
            $friends->offerPending($learner, $chatId);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
