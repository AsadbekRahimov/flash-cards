<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Enums;

/**
 * `organization_members.seat_state` (docs/13 §1.3, §6.1).
 *
 * A seat is `status = active AND seat_state = seated`: only seated members of an active
 * organization with a live subscription get the sponsored Pro.
 */
enum SeatState: string
{
    case Seated = 'seated';

    /** Active member without a seat (the limit is reached); first in, first seated when one frees up. */
    case Waitlisted = 'waitlisted';

    /** The membership is not active. */
    case None = 'none';
}
