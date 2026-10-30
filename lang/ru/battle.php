<?php

declare(strict_types=1);

return [

    'anonymous' => 'Ваш друг',

    // The formats of a battle, in the words of the app (resources/twa/src/i18n: practice.mode.*.title).
    'mode' => [
        'pairs' => 'Найди пары',
        'collocation' => 'Подбери сочетание',
        'sentence' => 'Собери предложение',
        'dictation' => 'Слушай и пиши',
    ],

    // The DM that invites a friend to a battle (docs/16 §8.7): who, which format, which course and lesson, how long it is open.
    'dm' => [
        'invited' => ':name зовёт вас на баттл: :format, :place. :validity',
        'validity' => '{1} Приглашение действует :count минуту.|[2,4] Приглашение действует :count минуты.|[5,*] Приглашение действует :count минут.',
        'open' => 'Открыть баттл',
        'lesson' => 'Модуль :stage · Урок :number',
        'lesson_no_stage' => 'Урок :number',
    ],
];
