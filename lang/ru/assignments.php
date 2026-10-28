<?php

declare(strict_types=1);

/** Assignments of a center: messages the bot posts to a group chat (docs/13 §1.4) and shared labels. */
return [
    'announce' => "📚 Новое задание: :title\nМодуль :stage, урок :lesson · срок до :due\n\nВыполните его в приложении: нажмите кнопку ниже.",
    'remind' => "⏰ Напоминание: задание «:title» нужно выполнить до :due.\nМодуль :stage, урок :lesson",
    'open_button' => 'Открыть урок',
    'lesson_fallback' => 'Урок :number',
];
