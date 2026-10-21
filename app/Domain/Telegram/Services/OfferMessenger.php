<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use App\Domain\Billing\Services\OfferService;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Support\DmLearnerResolver;
use App\Models\Learner;
use App\Models\Offer;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * DM with the post-organization discount (docs/13 §6.5): "You left center X. Personal Pro with 30 % off the
 * first month until <date>" and the pay buttons of `/pro` (`pro1:buy:<period>:<provider>`, priced with the
 * offer by the catalog — the order is created when a button is tapped, see {@see ProScreen}). Sent once per
 * offer (`meta.dm_sent_at`), never to a blocked learner or a closed DM (a 403 closes it), and only while there
 * is something to pay with — the offer itself stays in the catalog either way.
 */
final class OfferMessenger
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly LocaleResolver $locales,
        private readonly ProScreen $screen,
        private readonly OfferService $offers,
        private readonly DmLearnerResolver $dm,
    ) {}

    public function notifyPostOrgDiscount(Offer $offer): void
    {
        $offer = Offer::query()->find($offer->id);

        if ($offer === null || ! $offer->isOpenAt(CarbonImmutable::now()) || isset($offer->meta['dm_sent_at'])) {
            return;
        }

        // A soft-deleted learner is not found.
        $learner = Learner::query()->find($offer->learner_id);

        if ($learner === null || $learner->blocked_at !== null || $learner->dm_blocked_at !== null) {
            return;
        }

        $rows = $this->screen->buyRows($learner);
        if ($rows === []) {
            return;
        }

        $organization = $offer->source_organization_id === null ? null : Organization::query()->find($offer->source_organization_id);
        $locale = $this->locales->forLearner($learner);
        $params = [
            'organization' => $organization === null ? '' : $organization->name,
            'pct' => $offer->discount_pct,
            'date' => $this->screen->date(CarbonImmutable::instance($offer->expires_at), $learner),
        ];
        $text = __($organization === null ? 'billing.offer_dm.text_generic' : 'billing.offer_dm.text', $params, $locale);

        try {
            $this->telegram->sendMessage($learner->telegram_user_id, $text, replyMarkup: ['inline_keyboard' => $rows]);
        } catch (TelegramForbiddenException $e) {
            // The person cannot be reached: they leave the DM selection (as with reminders), the job ends.
            $this->dm->markBlocked($learner->telegram_user_id);

            Log::warning('telegram.offer_dm_forbidden', ['learner_id' => $learner->id, 'reason' => $e->reason]);

            return;
        }

        $this->offers->markAnnounced($offer);
    }
}
