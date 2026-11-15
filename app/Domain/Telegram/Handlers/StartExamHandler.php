<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Learning\Services\TeacherAuthority;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Handlers\Contracts\GroupUpdateHandler;
use App\Domain\Telegram\Services\ExamLauncher;
use App\Domain\Telegram\Services\LessonPicker;
use App\Models\TelegramGroup;
use App\Models\User;

/**
 * Handles `/start_exam [stage] [lesson] [minutes]` from a teacher in a group.
 *
 * With arguments the exam opens at once (a missing lesson is 1, missing minutes are 10). Without
 * arguments the teacher gets the lesson picker, then the duration row: see StartExamCallbackHandler.
 */
final class StartExamHandler implements GroupUpdateHandler
{
    /** Callback data prefix shared with StartExamCallbackHandler: `estart:<stage>:<lesson>[:<minutes>]`. */
    public const CALLBACK_PREFIX = 'estart:';

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly LocaleResolver $locales,
        private readonly ExamLauncher $launcher,
        private readonly LessonPicker $picker,
        private readonly TeacherAuthority $authority,
    ) {}

    /** @param array<string, mixed> $update */
    public function matches(array $update): bool
    {
        $message = $update['message'] ?? null;
        if (! is_array($message)) {
            return false;
        }

        $chatType = (string) ($message['chat']['type'] ?? '');
        $text = (string) ($message['text'] ?? '');

        return in_array($chatType, ['group', 'supergroup'], true)
            && preg_match('~^/start_exam(?:@\w+)?(\s|$)~', $text) === 1;
    }

    public function requiresActiveGroup(): bool
    {
        return true;
    }

    /** @param array<string, mixed> $update */
    public function handle(array $update, ?TelegramGroup $group = null): void
    {
        /** @var array<string, mixed> $message */
        $message = $update['message'];
        $chatId = (int) $message['chat']['id'];
        $fromId = (int) ($message['from']['id'] ?? 0);
        $text = (string) ($message['text'] ?? '');

        if ($fromId === 0) {
            return;
        }

        /** @var TelegramGroup|null $group */
        $group ??= TelegramGroup::query()->where('chat_id', $chatId)->first();
        if ($group === null) {
            return;
        }

        $locale = $this->locales->forGroup($group);

        /** @var User|null $teacher */
        $teacher = User::query()->where('telegram_user_id', $fromId)->first();
        if ($teacher === null) {
            $this->telegram->sendMessage($chatId, __('errors.teacher_not_linked', [], $locale));

            return;
        }

        $args = $this->parseArgs($text);

        if ($args === null) {
            // The picker is a menu of actions: show it to the group's teachers only.
            if (! $this->authority->canTeach($teacher, $group)) {
                $this->telegram->sendMessage($chatId, __('errors.exam.not_teacher', [], $locale));

                return;
            }

            $this->picker->send($chatId, $group, $locale, self::CALLBACK_PREFIX, 'exam.picker.prompt');

            return;
        }

        [$stage, $lesson, $minutes] = $args;

        $this->launcher->launch($chatId, $group, $teacher, $stage, $lesson, $minutes, $locale);
    }

    /**
     * @return array{0:int, 1:int, 2:int}|null null when no arguments were provided
     */
    private function parseArgs(string $text): ?array
    {
        if (preg_match('~^/start_exam(?:@\w+)?\s+(\d+)\s+(\d+)\s+(\d+)~', $text, $m) === 1) {
            return [(int) $m[1], (int) $m[2], (int) $m[3]];
        }
        if (preg_match('~^/start_exam(?:@\w+)?\s+(\d+)\s+(\d+)~', $text, $m) === 1) {
            return [(int) $m[1], (int) $m[2], 10];
        }
        if (preg_match('~^/start_exam(?:@\w+)?\s+(\d+)~', $text, $m) === 1) {
            return [(int) $m[1], 1, 10];
        }

        return null;
    }
}
