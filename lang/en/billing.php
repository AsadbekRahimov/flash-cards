<?php

declare(strict_types=1);

// Only the admin-panel report (`report.*`); every other billing string is missing here and falls back to
// `app.fallback_locale` (ru).
return [

    // Admin dashboard revenue widget: cash flow by event date, cohort net kept apart.
    'report' => [
        'heading' => 'Revenue, last 30 days',
        'net_cash' => 'Net cash movement :currency',
        'description' => "Charges: :charges, gross :gross · refunds (by refund date): :refund_count, :refunds · cohort net (this period's charges less their refunds): :cohort",
    ],

];
