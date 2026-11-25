<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Billing\Services\TrialOfferSender;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Handlers\Contracts\UpdateHandler;
use App\Domain\Telegram\Services\ProPitch;
use App\Domain\Telegram\Support\CallbackAcknowledger;
use App\Models\Learner;

/**
 * "Do not send offers" under an offer after the welcome trial (`bill1:offers_off`, docs/34 §2д): the learner leaves the chain
 * ({@see TrialOfferSender::optOut()}). Answered with `billing.trial_offer.opted_out` for the tapper alone - the same answer on a second
 * tap, which writes nothing. Only the sender's own DM counts; an unknown account is not registered by a tap.
 */
final class BillingOffersCallbackHandler implements UpdateHandler
{
    public function __construct(
        private readonly CallbackAcknowledger $acknowledger,
        private readonly LocaleResolver $locales,
        private readonly TrialOfferSender $offers,
    ) {}

    /** @param array<string, mixed> $update */
    public function matches(array $update): bool
    {
        return (string) ($update['callback_query']['data'] ?? '') === ProPitch::OPT_OUT_DATA;
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
        $fromId = (int) ($cq['from']['id'] ?? 0);
        $message = is_array($cq['message'] ?? null) ? $cq['message'] : [];

        $learner = $fromId > 0
            && ($message['chat']['type'] ?? '') === 'private'
            && (int) ($message['chat']['id'] ?? 0) === $fromId
                ? Learner::query()->where('telegram_user_id', $fromId)->first()
                : null;

        if ($learner === null) {
            $this->acknowledger->acknowledge($update);

            return;
        }

        $this->offers->optOut($learner);

        $this->acknowledger->acknowledge($update, __('billing.trial_offer.opted_out', [], $this->locales->forLearner($learner)));
    }
}
