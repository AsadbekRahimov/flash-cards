<?php

declare(strict_types=1);

namespace App\Domain\Practice\DTO;

/**
 * How much suitable material a mode has for a scope (docs/16 §1.2, §4.3). docs/16 names two fields `available`
 * (a flag and a count); here the flag is {@see $isAvailable} and the count is {@see $available}, the one that goes into
 * the `details` of the 422 and into the hub (`"available": 14, "required": 3`).
 *
 * `reason` says why there is not enough (null when there is): see the REASON_* constants.
 */
final readonly class ModeAvailability
{
    /** Fewer introduced words than the minimum. */
    public const REASON_NO_WORDS = 'no_words';

    /** Words are enough, but after the ones with a clashing translation are left out, they are not. */
    public const REASON_AMBIGUOUS = 'ambiguous';

    /** Fewer published tasks than the minimum, and the ones that exist would not make the number even if their words were learned. */
    public const REASON_NO_ITEMS = 'no_items';

    /** The tasks are there and would be enough, but their target words are not introduced to the learner yet: learn the lesson. */
    public const REASON_ITEMS_LOCKED = 'items_locked';

    /** Tasks are enough, but the audio of too many of them is not ready yet. */
    public const REASON_AUDIO_PENDING = 'audio_pending';

    /** A dictation of words: no word with a ready pre-generated audio. */
    public const REASON_NO_AUDIO_WORDS = 'no_audio_words';

    public function __construct(
        public bool $isAvailable,
        public int $available,
        public int $required,
        public ?string $reason = null,
    ) {}

    public static function enough(int $available, int $required): self
    {
        return new self(true, $available, $required);
    }

    public static function notEnough(int $available, int $required, string $reason): self
    {
        return new self(false, $available, $required, $reason);
    }

    /** What the mode reports before it has an implementation (18.1): nothing to play, the minimum from the config. */
    public static function unavailable(int $required): self
    {
        return new self(false, 0, $required);
    }

    /** @return array{available: int, required: int, reason: string|null} */
    public function toArray(): array
    {
        return ['available' => $this->available, 'required' => $this->required, 'reason' => $this->reason];
    }
}
