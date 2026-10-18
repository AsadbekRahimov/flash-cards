<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Public site (landing, offer, privacy policy)
    |--------------------------------------------------------------------------
    |
    | `support_contact` is shown in the footer / pricing block only when set:
    | an e-mail, a Telegram handle (@name), a https:// URL or plain text. It is
    | the same value as the Pro paywall contact (docs/12 §3.4), hence the shared
    | env name. Empty -> the contact line is not rendered at all.
    |
    */

    'support_contact' => env('BILLING_SUPPORT_CONTACT', ''),

];
