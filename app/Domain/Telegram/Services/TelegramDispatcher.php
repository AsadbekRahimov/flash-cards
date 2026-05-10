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
use App\Domain\Telegram\Handlers\StartTrainingCallbackHandler;
use App\Domain\Telegram\Handlers\StartTrainingHandler;
use App\Domain\Telegram\Handlers\StatsCommandHandler;

final class TelegramDispatcher
{
    /** @var list<UpdateHandler> */
    private array $messageHandlers;

    /** @var list<UpdateHandler> */
    private array $callbackHandlers;

    public function __construct(
        StartCommandHandler $start,
        StartTrainingHandler $startTraining,
        StartTrainingCallbackHandler $startTrainingCallback,
        StartExamHandler $startExam,
        CloseExamHandler $closeExam,
        StatsCommandHandler $stats,
        HelpCommandHandler $help,
        MyChatMemberHandler $myChatMember,
        NewMembersHandler $newMembers,
    ) {
        // Order matters: more specific commands first (e.g. /start_training
        // must win over the generic /start catch-all).
        $this->messageHandlers = [
            $myChatMember,
            $newMembers,
            $startTraining,
            $startExam,
            $closeExam,
            $stats,
            $start,
            $help,
        ];

        $this->callbackHandlers = [
            $startTrainingCallback,
        ];
    }

    /** @param array<string, mixed> $update */
    public function dispatch(array $update): void
    {
        $handlers = isset($update['callback_query'])
            ? $this->callbackHandlers
            : $this->messageHandlers;

        foreach ($handlers as $handler) {
            if ($handler->matches($update)) {
                $handler->handle($update);

                return;
            }
        }
    }
}
