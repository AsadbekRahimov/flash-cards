<?php

declare(strict_types=1);

namespace App\Domain\Billing\DTO;

use Carbon\CarbonImmutable;

/** Where the client goes to pay (docs/13 §2.1). */
final readonly class CheckoutLink
{
    /** Telegram Stars invoice link: TWA opens it with `openInvoice`. */
    public const KIND_INVOICE_LINK = 'invoice_link';

    /** Payme / Click payment page: TWA opens it with `openLink`, the bot sends a URL button. */
    public const KIND_REDIRECT = 'redirect';

    public function __construct(
        public string $url,
        public string $kind,
        public CarbonImmutable $expiresAt,
    ) {}

    /** @return array{url: string, kind: string, expires_at: string} */
    public function toArray(): array
    {
        return ['url' => $this->url, 'kind' => $this->kind, 'expires_at' => $this->expiresAt->toIso8601String()];
    }
}
