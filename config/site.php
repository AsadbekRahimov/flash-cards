<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Legal entity of the operator
    |--------------------------------------------------------------------------
    |
    | The seller's details printed on the invoices of learning centers
    | (`/org/{center}/invoice/{order}`, docs/13 §6.5) and in the last section of the public offer. Provided by the owner of the service;
    | an empty value is simply not printed.
    */
    'legal' => [
        // The "draft" notice on /offer and /privacy (they are also noindex while it is on). The owner switches it off
        // once the lawyer has approved the texts (docs/DEVOPS_GUIDE.md §16).
        'draft' => (bool) env('SITE_LEGAL_DRAFT', true),
        'name' => env('SITE_LEGAL_NAME', ''),
        'inn' => env('SITE_LEGAL_INN', ''),
        'address' => env('SITE_LEGAL_ADDRESS', ''),
        'bank' => env('SITE_LEGAL_BANK', ''),
        'bank_account' => env('SITE_LEGAL_BANK_ACCOUNT', ''),
        'mfo' => env('SITE_LEGAL_MFO', ''),
        'phone' => env('SITE_LEGAL_PHONE', ''),
        'email' => env('SITE_LEGAL_EMAIL', ''),
    ],

];
