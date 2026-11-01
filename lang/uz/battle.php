<?php

declare(strict_types=1);

return [

    'anonymous' => "Do'stingiz",

    // The formats of a battle, in the words of the app (resources/twa/src/i18n: practice.mode.*.title).
    'mode' => [
        'pairs' => 'Juftlarni toping',
        'collocation' => "So'z birikmasini tanlang",
        'sentence' => 'Gap tuzing',
        'dictation' => 'Eshiting va yozing',
    ],

    // The DM that invites a friend to a battle (docs/16 §8.7): who, which format, which course and lesson, how long it is open.
    'dm' => [
        'invited' => ':name sizni bellashuvga chaqiryapti: :format, :place. :validity',
        'validity' => 'Taklif :count daqiqa amal qiladi.',
        'open' => 'Bellashuvni ochish',
        'lesson' => ':stage-modul · :number-dars',
        'lesson_no_stage' => ':number-dars',
    ],
];
