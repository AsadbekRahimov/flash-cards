<?php

declare(strict_types=1);

namespace App\Domain\Billing\Support;

/**
 * The public status page a redirect provider (Payme, Click) sends the payer back to:
 * `/billing/return?order=<token>&lang=<lang>` on APP_URL — never on the Host of a request
 * (docs/07 §2, docs/13 §4.2 / §4.3).
 */
final class BillingReturnUrl
{
    public static function for(string $token, string $lang): string
    {
        return rtrim((string) config('app.url'), '/').'/billing/return?'.http_build_query(['order' => $token, 'lang' => $lang]);
    }
}
