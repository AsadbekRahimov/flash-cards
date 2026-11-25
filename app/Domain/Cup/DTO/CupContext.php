<?php

declare(strict_types=1);

namespace App\Domain\Cup\DTO;

use App\Domain\Cup\Services\CupReader;
use App\Models\Cup;
use App\Models\CupParticipant;

/**
 * A cup as a request of the Mini App finds it (docs/33 §7.2, K7): the row with its group loaded, the learner's own participation (null
 * - not a participant) and, for the invitation, the number of participants - all read by ONE statement ({@see CupReader}).
 */
final readonly class CupContext
{
    /**
     * @param  array{state: 'not_started'|'active'|'done'|'not_available', day: int|null}|null  $today  the round of today of the
     *                                                                                                  participant ("my cups" only)
     */
    public function __construct(
        public Cup $cup,
        public ?CupParticipant $participant,
        public ?int $participantsCount = null,
        public ?array $today = null,
    ) {}
}
