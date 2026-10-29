<?php

declare(strict_types=1);

namespace App\Domain\Practice\DTO;

/**
 * Whether the practice is open to a learner (docs/16 §3.2): `available`; `off` (the flag is off);
 * `not_in_pilot` (`PRACTICE_ACCESS=pilot` and the learner is not in it); `pro_required` (`PRACTICE_PLAN=pro` and
 * the learner is not on Pro). The endpoints answer `off` and `not_in_pilot` with the same 403 `practice_unavailable`
 * (the existence of a pilot is not revealed).
 */
final readonly class PracticeAccessState
{
    public const AVAILABLE = 'available';

    public const OFF = 'off';

    public const NOT_IN_PILOT = 'not_in_pilot';

    public const PRO_REQUIRED = 'pro_required';

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

    public static function proRequired(): self
    {
        return new self(self::PRO_REQUIRED);
    }

    public function isAvailable(): bool
    {
        return $this->code === self::AVAILABLE;
    }

    public function isProRequired(): bool
    {
        return $this->code === self::PRO_REQUIRED;
    }

    /** The entries of the practice are shown: to everyone who can play and to a Free learner (with a Pro chip). */
    public function showsEntries(): bool
    {
        return $this->isAvailable() || $this->isProRequired();
    }
}
