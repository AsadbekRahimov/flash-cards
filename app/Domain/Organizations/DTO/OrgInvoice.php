<?php

declare(strict_types=1);

namespace App\Domain\Organizations\DTO;

use Carbon\CarbonImmutable;

/**
 * Data of the printable invoice of an organization order (docs/13 §6.5): the seller from
 * `config('site.legal')` (empty fields dropped), the payer, the tariff and the amount in sums.
 */
final readonly class OrgInvoice
{
    /**
     * @param  array<string, string>  $seller  non-empty `site.legal` fields
     * @param  array<string, string>  $payer  organization name and the contact the owner entered
     */
    public function __construct(
        public string $number,
        public CarbonImmutable $issuedAt,
        public string $status,
        public array $seller,
        public array $payer,
        public string $tier,
        public ?int $seats,
        public string $period,
        public int $periodDays,
        public int $amount,
        public string $currency,
        public string $provider,
    ) {}
}
