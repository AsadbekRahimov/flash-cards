<?php

declare(strict_types=1);

return [

    // `:support` — the line with the support contact (BotSupportContact::line), empty without a contact.
    'no_groups' => "У вас пока нет привязанных групп.\nДобавьте бота в группу класса, затем напишите в поддержку — активируем группу.:support",
    'title' => '📊 Статистика LexiFlow за 7 дней',
    'groups' => 'Групп: :count',
    'learners' => 'Активных учеников: :count',
    'reviews' => 'Повторений карточек: :count',
    'exams' => 'Экзаменов запущено: :count',
    'accuracy' => 'Точность экзаменов: :percent%',
    // No exam answers in the period: 0% would read as "all wrong".
    'accuracy_none' => 'Точность экзаменов: —',

];
