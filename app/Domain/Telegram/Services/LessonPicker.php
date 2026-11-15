<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Models\Lesson;
use App\Models\TelegramGroup;

/**
 * The menu of lessons a teacher picks from in a group (`/start_training`, `/start_exam`): one button per lesson of the
 * group's course, `callback_data` = `<prefix><stage>:<lesson>` (the prefix is the caller's, so each command has its own callback).
 */
final class LessonPicker
{
    /** Max lessons shown in the picker keyboard. */
    private const MAX_ROWS = 20;

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly CourseCatalogService $catalog,
    ) {}

    public function send(int $chatId, TelegramGroup $group, string $locale, string $callbackPrefix, string $promptKey): void
    {
        // Only the lessons of the group's course: the buttons carry stage/lesson numbers,
        // which are resolved in that same course when pressed.
        $lessons = $this->catalog->lessons($this->catalog->courseForGroup($group), self::MAX_ROWS);

        if ($lessons->isEmpty()) {
            $this->telegram->sendMessage($chatId, __('training.picker.empty', [], $locale));

            return;
        }

        $rows = $lessons->map(function (Lesson $lesson) use ($locale, $callbackPrefix): array {
            $stageNum = $lesson->stage->number ?? '?';
            $lessonNum = $lesson->number;

            return [[
                'text' => __('training.picker.button', ['stage' => $stageNum, 'lesson' => $lessonNum], $locale),
                'callback_data' => $callbackPrefix."{$stageNum}:{$lessonNum}",
            ]];
        })->values()->all();

        $this->telegram->sendMessage(
            chatId: $chatId,
            text: __($promptKey, [], $locale),
            replyMarkup: ['inline_keyboard' => $rows],
        );
    }
}
