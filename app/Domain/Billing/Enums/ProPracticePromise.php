<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/**
 * What the copy of Pro may say about practice (docs/18 §3, G1), as decided by `ProPromises` - for the landing and the Stars invoice
 * (`ProPromises::publicPractice()`) and for the Mini App, which gets the case in `practice.pro_promise` of `/me` (the `value` of
 * this enum):
 *
 *  - `None`: nothing (practice is not open to the reader, or Free has no daily limit there for Pro to lift);
 *  - `Solo`: "practice without a daily limit" - the battles are switched off (`PRACTICE_BATTLE=false`) or Free has no limit on
 *    them, so none are sold; practice behind Pro as a whole (`PRACTICE_PLAN=pro`) with the battles off says it too;
 *  - `Both`: "practice and battles without a daily limit".
 *
 * The same two sentences the Mini App has (`benefits.practice` / `benefits.practice_solo`). The Mini App picks its sentence by the
 * case it was sent, the services choose their text by it ({@see landingKey()}, {@see invoiceSuffix()}); no template decides.
 */
enum ProPracticePromise: string
{
    case None = 'none';
    case Solo = 'solo';
    case Both = 'both';

    /** The line of the Pro card of the landing (`lang/{locale}/landing.php`), or null when there is nothing to say. */
    public function landingKey(): ?string
    {
        return match ($this) {
            self::None => null,
            self::Solo => 'landing.limits.pro_practice_solo',
            self::Both => 'landing.limits.pro_practice',
        };
    }

    /** Appended to `billing.stars.invoice.description_month` / `description_year` to pick the text of the Stars invoice. */
    public function invoiceSuffix(): string
    {
        return match ($this) {
            self::None => '',
            self::Solo => '_practice_solo',
            self::Both => '_practice',
        };
    }
}
