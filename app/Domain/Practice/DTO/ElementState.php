<?php

declare(strict_types=1);

namespace App\Domain\Practice\DTO;

use App\Domain\Practice\Enums\Verdict;

/**
 * The state of ONE element of a session as a mode handler sees it (docs/16 §1.2, §6.2).
 *
 * Stored (`practice_sessions.state`): `elements[id] = {status: answered, verdict, first, hints, answer, answered_at}`
 * for an element that is done, and, for a mode that keeps more, `pairs[id] = {...}` - the {@see $extra} block (pairs:
 * `matched {left: right}`, `mismatches`, `wrong_left`). An element nobody answered has no entry: {@see open()}.
 *
 * Not stored, filled by the service for `project()` (the handler cannot know them): {@see $audioUrl}, the URL of the
 * audio of the element, and {@see $replacementsLeft}, how many swaps of the session are left (docs/16 §7.2).
 */
final readonly class ElementState
{
    public const STATUS_ANSWERED = 'answered';

    /**
     * @param  array<string, mixed>|null  $answer  the answer as stored (after the validation of its shape)
     * @param  array<string, mixed>  $extra  the mode-specific block (pairs)
     */
    public function __construct(
        public bool $answered = false,
        public ?Verdict $verdict = null,
        public bool $first = true,
        public int $hints = 0,
        public ?array $answer = null,
        public ?string $answeredAt = null,
        public array $extra = [],
        public ?string $audioUrl = null,
        public int $replacementsLeft = 0,
    ) {}

    /** An element nobody has answered. */
    public static function open(): self
    {
        return new self;
    }

    /**
     * @param  array<string, mixed>|null  $stored  `state.elements[id]`
     * @param  array<string, mixed>  $extra  `state.pairs[id]`
     */
    public static function fromStored(?array $stored, array $extra = []): self
    {
        if ($stored === null) {
            return new self(extra: $extra);
        }

        $answer = $stored['answer'] ?? null;

        return new self(
            answered: ($stored['status'] ?? null) === self::STATUS_ANSWERED,
            verdict: Verdict::tryFrom((string) ($stored['verdict'] ?? '')),
            first: (bool) ($stored['first'] ?? true),
            hints: (int) ($stored['hints'] ?? 0),
            answer: is_array($answer) ? $answer : null,
            answeredAt: isset($stored['answered_at']) ? (string) $stored['answered_at'] : null,
            extra: $extra,
        );
    }

    /** @return array<string, mixed> the `state.elements[id]` block */
    public function toStored(): array
    {
        return [
            'status' => $this->answered ? self::STATUS_ANSWERED : 'open',
            'verdict' => $this->verdict?->value,
            'first' => $this->first,
            'hints' => $this->hints,
            'answer' => $this->answer,
            'answered_at' => $this->answeredAt,
        ];
    }

    /**
     * The element goes to the list of mistakes of the summary: answered with a verdict that is not "correct", or - pairs -
     * with words in `wrong_left` (a round can end "correct" and still have had a wrong first try).
     */
    public function isMistake(): bool
    {
        if (! $this->answered) {
            return false;
        }

        return $this->verdict?->isMistake() === true || ($this->extra['wrong_left'] ?? []) !== [];
    }

    /** The same state with what `project()` needs besides it. */
    public function withContext(?string $audioUrl, int $replacementsLeft): self
    {
        return new self($this->answered, $this->verdict, $this->first, $this->hints, $this->answer, $this->answeredAt, $this->extra, $audioUrl, $replacementsLeft);
    }
}
