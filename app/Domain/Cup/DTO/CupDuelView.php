<?php

declare(strict_types=1);

namespace App\Domain\Cup\DTO;

use App\Domain\Cup\Services\CupProEmoji;
use App\Models\CupDuel;
use Carbon\CarbonImmutable;

/**
 * A duel of a cup as a participant sees it in the Mini App (docs/04 A.23d, D2): the row, the names of the two players in the tournament
 * and their emoji of Pro (from the cache of the table, {@see CupProEmoji}), and who looks at it - his
 * `participant_id` makes the role. No learner id, no Telegram id, no `request_id` ever leaves the server.
 */
final readonly class CupDuelView
{
    public function __construct(
        public CupDuel $duel,
        public string $challengerName,
        public ?string $opponentName,
        public ?string $challengerEmoji,
        public ?string $opponentEmoji,
        public int $viewerParticipantId,
        public CarbonImmutable $now,
        public ?bool $ratedForMe = null,
    ) {}

    /**
     * The kind of the duel for the one who looks (docs/04 A.23d `rated_for_me`): what an acceptance by him now would be (an open
     * challenge, read with the slots of today), else what was decided (`rated`) or offered (`rated_offered`). The `expect_rated` of
     * "Accept".
     */
    public function ratedForViewer(): bool
    {
        return $this->ratedForMe ?? $this->duel->rated ?? $this->duel->rated_offered;
    }

    /** `challenger` / `opponent` - a player of this duel; null - somebody who may accept an open challenge. */
    public function role(): ?string
    {
        return match ($this->viewerParticipantId) {
            $this->duel->challenger_participant_id => 'challenger',
            $this->duel->opponent_participant_id => 'opponent',
            default => null,
        };
    }
}
