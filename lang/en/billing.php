<?php

declare(strict_types=1);

// Only the admin-panel report; every other billing string falls back to the default locale.
return [

    // Admin dashboard revenue widget: cash flow by event date, cohort net kept apart.
    'report' => [
        'heading' => 'Revenue, last 30 days',
        'net_cash' => 'Net cash movement :currency',
        'description' => "Charges: :charges, gross :gross · refunds (by refund date): :refund_count, :refunds · cohort net (this period's charges less their refunds): :cohort",
    ],

];
