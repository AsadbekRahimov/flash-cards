<?php

declare(strict_types=1);

namespace App\Domain\Organizations\DTO;

/**
 * The first steps of a center on the `/org` dashboard (audit 2026-11-10 §4 P1), each done or not:
 *  1. the staff member's own Telegram is connected (verified through the bot);
 *  2. a group is linked;
 *  3. a linked group has a course;
 *  4. a learner is in the center (or at least offered);
 *  5. a group training was run by the bot.
 */
final readonly class OrgChecklist
{
    public function __construct(
        public bool $telegramConnected,
        public bool $groupLinked,
        public bool $courseChosen,
        public bool $learnersInvited,
        public bool $trainingStarted,
    ) {}

    /** @return array<string, bool> step key => done, in the order of the list */
    public function steps(): array
    {
        return [
            'telegram' => $this->telegramConnected,
            'group' => $this->groupLinked,
            'course' => $this->courseChosen,
            'learners' => $this->learnersInvited,
            'training' => $this->trainingStarted,
        ];
    }

    public function isComplete(): bool
    {
        return ! in_array(false, $this->steps(), true);
    }

    public function doneCount(): int
    {
        return count(array_filter($this->steps()));
    }
}
