<?php

declare(strict_types=1);

namespace App\Domain\Game\DTO;

/**
 * Whether the game is open to a learner (docs/18 §12.7.4): `available`; `off` (`GAME_ACCESS` is off); `not_in_pilot`
 * (`GAME_ACCESS=pilot` and the learner is not in the pilot of the practice). The endpoints answer both refusals with the same 403
 * `game_unavailable` (the existence of a pilot is not revealed). There is no `pro_required` state: the free part is the product decision.
 */
final readonly class GameAccessState
{
    public const AVAILABLE = 'available';

    public const OFF = 'off';

    public const NOT_IN_PILOT = 'not_in_pilot';

    private function __construct(public string $code) {}

    public static function available(): self
    {
        return new self(self::AVAILABLE);
    }

    public static function off(): self
    {
        return new self(self::OFF);
    }

    public static function notInPilot(): self
    {
        return new self(self::NOT_IN_PILOT);
    }

    public function isAvailable(): bool
    {
        return $this->code === self::AVAILABLE;
    }
}
