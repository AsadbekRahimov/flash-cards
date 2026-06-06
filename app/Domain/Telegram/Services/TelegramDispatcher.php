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
use App\Models\TelegramGroup;

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
            if (! $handler->matches($update)) {
                continue;
            }

            // Group Lock (FR-BOT-01): handlers that require an active group are
            // dropped silently when the update comes from a group chat that is
            // not whitelisted/active. Private chats and lifecycle updates pass.
            if ($handler->requiresActiveGroup()
                && $this->isFromGroupChat($update)
                && ! $this->isActiveGroup($update)
            ) {
                return;
            }

            $handler->handle($update);

            return;
        }
    }

    /** @param array<string, mixed> $update */
    private function isFromGroupChat(array $update): bool
    {
        return in_array($this->chatType($update), ['group', 'supergroup'], true);
    }

    /** @param array<string, mixed> $update */
    private function isActiveGroup(array $update): bool
    {
        $chatId = $this->chatId($update);

        if ($chatId === null) {
            return false;
        }

        return TelegramGroup::query()
            ->where('chat_id', $chatId)
            ->where('status', 'active')
            ->exists();
    }

    /** @param array<string, mixed> $update */
    private function chatType(array $update): string
    {
        $chat = $this->chat($update);

        return is_array($chat) ? (string) ($chat['type'] ?? '') : '';
    }

    /** @param array<string, mixed> $update */
    private function chatId(array $update): ?int
    {
        $chat = $this->chat($update);

        if (! is_array($chat) || ! isset($chat['id'])) {
            return null;
        }

        return (int) $chat['id'];
    }

    /**
     * @param  array<string, mixed>  $update
     * @return array<string, mixed>|null
     */
    private function chat(array $update): ?array
    {
        $message = $update['callback_query']['message'] ?? $update['message'] ?? null;
        $chat = is_array($message) ? ($message['chat'] ?? null) : null;

        return is_array($chat) ? $chat : null;
    }
}
