<?php

declare(strict_types=1);

namespace App\Domain\Organizations\DTO;

use Carbon\CarbonImmutable;

/**
 * Everything the owner's Billing page shows (docs/13 §6.5): the current subscription, the seats
 * (with the excess after a downgrade) and the catalog of tariffs with the ways to pay.
 *
 * `tiers[].prices` are minor units (tiyin) per period; a tier without prices (center_l) is
 * `purchasable = false` — "contact us / activated by the platform". `below_seated` marks a tier
 * whose limit is lower than the seats taken now: choosing it unseats nobody, new members wait.
 * `providers` are the enabled providers that serve organizations (Payme / Click), possibly empty.
 */
final readonly class OrgBillingPage
{
    /**
     * @param  list<array{key: string, seats: int|null, prices: array<string, int>, purchasable: bool, below_seated: bool}>  $tiers
     * @param  list<string>  $periods  `month` | `year`
     * @param  list<string>  $providers
     */
    public function __construct(
        public ?string $tier,
        public ?string $status,
        public ?CarbonImmutable $endsAt,
        public bool $perpetual,
        public SeatsSummary $seats,
        public int $overBy,
        public array $tiers,
        public array $periods,
        public array $providers,
        public bool $canRequestInvoice,
    ) {}
}
