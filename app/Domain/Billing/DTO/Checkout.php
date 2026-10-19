<?php

declare(strict_types=1);

namespace App\Domain\Billing\DTO;

use App\Models\PaymentOrder;

/** A started purchase: the pending order and where the learner pays it (docs/13 §5 POST /billing/checkout). */
final readonly class Checkout
{
    public function __construct(
        public PaymentOrder $order,
        public CheckoutLink $link,
    ) {}
}
