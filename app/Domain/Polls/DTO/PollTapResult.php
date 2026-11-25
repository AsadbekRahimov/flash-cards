<?php

declare(strict_types=1);

namespace App\Domain\Polls\DTO;

use App\Domain\Polls\Enums\PollTapStatus;
use App\Domain\Polls\Services\PollVoteService;

/**
 * What {@see PollVoteService::tap()} decided (docs/35 §1.5). `selectedOptionIds` (poll_options ids,
 * ascending) is what the keyboard / the text should show: the draft for `Toggled`, `LimitReached`, `EmptySubmit` and `Changing`
 * of several answers, the recorded answer otherwise. `pollId`, `deliveryId`, `locale` and `isTest` are null / false only for
 * `Unavailable`. `changed` - a `Voted` that replaced an earlier answer (the `changed` of the event `poll.answered`).
 */
final readonly class PollTapResult
{
    /** @param list<int> $selectedOptionIds */
    public function __construct(
        public PollTapStatus $status,
        public array $selectedOptionIds = [],
        public bool $allowChange = false,
        public bool $allowsMultiple = false,
        public bool $changed = false,
        public ?int $pollId = null,
        public ?int $deliveryId = null,
        public ?string $locale = null,
        public bool $isTest = false,
    ) {}

    public static function unavailable(): self
    {
        return new self(PollTapStatus::Unavailable);
    }
}
