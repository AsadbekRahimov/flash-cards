<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Learners\Services\OnboardingService;
use App\Domain\Telegram\Exceptions\TelegramOutboundBusyException;
use App\Domain\Telegram\Exceptions\TelegramRateLimitedException;
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
 * (docs/40 §2.1, §5 п. 1) never reaches that step: once the Mini App completed the onboarding
 * ({@see OnboardingService::completeExternally()}) one job per waiting invitation (`$kind`) makes the same offer in the private chat -
 * one message, one slot of the outbound budget ({@see ThrottleTelegramOutbound}).
 *
 * The messenger takes the code under the row lock before the send, so a repeated job, a parallel `/start` (which offers it too) or two
 * devices offer once. A 429 or no slot of the budget (the message was not sent): the messenger puts the code back and the exception
 * goes up to the middleware, which releases the job for `retry_after` - the retry offers it. Any other failure is reported and the
 * job ends (the code is gone, as in the bot). A learner the bot cannot reach (blocked, DM closed) keeps the code for a later `/start`.
 */
final class OfferPendingInvitesJob implements ShouldQueue
{
    use Queueable;

    public const ORGANIZATION = 'organization';

    public const FRIEND = 'friend';

    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $backoff = 60;

    /** @param  self::ORGANIZATION|self::FRIEND|string  $kind */
    public function __construct(public readonly int $learnerId, public readonly string $kind) {}

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

        try {
            match ($this->kind) {
                self::ORGANIZATION => $organizations->offerPending($learner, $chatId),
                self::FRIEND => $friends->offerPending($learner, $chatId),
                default => null,
            };
        } catch (TelegramRateLimitedException|TelegramOutboundBusyException $e) {
            // Not sent, the code is back: the middleware puts the job back for `retry_after`.
            throw $e;
        } catch (Throwable $e) {
            report($e);
        }
    }
}
