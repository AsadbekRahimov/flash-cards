<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use App\Domain\Telegram\Handlers\CloseExamHandler;
use App\Domain\Telegram\Handlers\Contracts\UpdateHandler;
use App\Domain\Telegram\Handlers\HelpCommandHandler;
use App\Domain\Telegram\Handlers\MyChatMemberHandler;
use App\Domain\Telegram\Handlers\NewMembersHandler;
use App\Domain\Telegram\Handlers\StartCommandHandler;
use App\Domain\Telegram\Handlers\StartExamHandler;
use App\Domain\Telegram\Handlers\StartTrainingHandler;

final class TelegramDispatcher
{
    /** @var list<UpdateHandler> */
    private array $handlers;

    public function __construct(
        StartCommandHandler $start,
        StartTrainingHandler $startTraining,
        StartExamHandler $startExam,
        CloseExamHandler $closeExam,
        HelpCommandHandler $help,
        MyChatMemberHandler $myChatMember,
        NewMembersHandler $newMembers,
    ) {
        // Order matters: more specific commands first (e.g. /start_training
        // must win over the generic /start catch-all).
        $this->handlers = [
            $myChatMember,
            $newMembers,
            $startTraining,
            $startExam,
            $closeExam,
            $start,
            $help,
        ];
    }

    /** @param array<string, mixed> $update */
    public function dispatch(array $update): void
    {
        foreach ($this->handlers as $handler) {
            if ($handler->matches($update)) {
                $handler->handle($update);

                return;
            }
        }
    }
}
