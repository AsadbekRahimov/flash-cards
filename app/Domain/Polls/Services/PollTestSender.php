<?php

declare(strict_types=1);

namespace App\Domain\Polls\Services;

use App\Domain\Polls\Enums\PollDeliveryStatus;
use App\Domain\Polls\Enums\PollStatus;
use App\Domain\Polls\Exceptions\PollLaunchException;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Services\DmReachability;
use App\Domain\Telegram\Services\PollMessenger;
use App\Domain\Telegram\Services\TelegramMessageRateLimiter;
use App\Models\Poll;
use App\Models\PollDelivery;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * The test send of `/admin` (docs/35 §1.5): the poll goes to the administrator's own Telegram (`users.telegram_user_id`) as a delivery
 * with `is_test = true` and no learner - the whole path (photo, buttons, vote, change, closing) is checked live, and the first upload
 * stores `image_file_id`. A launch needs at least one test delivery that reached (`sent`).
 *
 * Same message and the same at-most-once rule as the broadcast: the row is written `sending` before the Telegram call and finished
 * after it. It answers an administrator's click, so it does not wait for the bot's budget, it is only counted in it
 * ({@see TelegramMessageRateLimiter::consumeGlobal()}). A Telegram refusal does not throw: the returned delivery carries the status
 * (`sent`, `forbidden` - the administrator has to open the bot -, `failed` with `error_code`, `unknown` after a timeout) for the
 * notification of O6.
 */
final class PollTestSender
{
    public function __construct(
        private readonly PollDeliverySender $sender,
        private readonly PollMessenger $messenger,
        private readonly TelegramMessageRateLimiter $outbound,
        private readonly DmReachability $reachability,
    ) {}

    /** @throws PollLaunchException no linked Telegram, a finished poll, options or text that cannot go out, the picture missing */
    public function send(Poll $poll, User $admin, string $locale = 'ru', ?CarbonImmutable $now = null): PollDelivery
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $chatId = $admin->telegram_user_id;

        if ($chatId === null || $chatId <= 0) {
            throw PollLaunchException::because(PollLaunchException::NO_TELEGRAM);
        }

        if (in_array($poll->status, [PollStatus::Closed, PollStatus::Cancelled], true)) {
            throw PollLaunchException::because(PollLaunchException::FINISHED);
        }

        $options = $poll->options()->get();
        if ($options->count() < 2 || $options->count() > 10) {
            throw PollLaunchException::because(PollLaunchException::OPTIONS);
        }

        if (! $this->messenger->fits($poll, $options)) {
            throw PollLaunchException::because(PollLaunchException::TOO_LONG);
        }

        if ($poll->image_file_id === null && ! PollDeliverySender::pictureReadable($poll)) {
            throw PollLaunchException::because(PollLaunchException::IMAGE_MISSING);
        }

        $delivery = PollDelivery::query()->create([
            'poll_id' => $poll->id,
            'learner_id' => null,
            'telegram_user_id' => $chatId,
            'is_test' => true,
            'token' => Str::random(12),
            'locale' => PollMessenger::locale($locale),
            'status' => PollDeliveryStatus::Sending,
            'claimed_at' => $now,
        ]);

        $this->outbound->consumeGlobal();

        try {
            $sent = $this->sender->transmit($poll, $options, $chatId, $delivery->token, $delivery->locale);
        } catch (PollLaunchException $e) {
            // Telegram refused the stored file_id and the file is gone: nothing went out.
            $delivery->update(['status' => PollDeliveryStatus::Failed, 'error_code' => $e->reason]);

            throw $e;
        } catch (TelegramForbiddenException $e) {
            $delivery->update(['status' => PollDeliveryStatus::Forbidden, 'error_code' => $e->reason]);
            $this->reachability->markUnreachable($chatId, $e->reason, $now);

            return $delivery;
        } catch (TelegramApiException $e) {
            $delivery->update($e->isApiResponse()
                ? ['status' => PollDeliveryStatus::Failed, 'error_code' => 'http_'.$e->getCode()]
                : ['status' => PollDeliveryStatus::Unknown, 'error_code' => 'transport']);

            return $delivery;
        }

        $delivery->update(['status' => PollDeliveryStatus::Sent, 'message_id' => $sent->messageId, 'sent_at' => $now, 'error_code' => null]);
        $this->sender->rememberFileId($poll, $sent);

        return $delivery;
    }
}
