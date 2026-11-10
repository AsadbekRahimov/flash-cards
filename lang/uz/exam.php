<?php

declare(strict_types=1);

return [

    // HTML. `:seconds` — time for one question.
    'started' => "🏁 <b>Imtihon boshlandi!</b> :stage-modul · :lesson-dars · :minutes daq.\nTugmani bosing — LexiFlow to'g'ridan-to'g'ri Telegramning ichida ochiladi. Har bir savolga :seconds soniya beriladi; vaqt tugagach, natijalar shu yerda e'lon qilinadi.",
    // `/start_exam` without arguments: the lesson, then the duration.
    'picker' => [
        'prompt' => 'Imtihon uchun darsni tanlang:',
        'duration_prompt' => ':stage-modul · :lesson-dars. Imtihon necha daqiqa davom etadi?',
        'minutes_button' => ':minutes daq',
    ],
    'open_button' => '🏁 Imtihonni ochish',

    'leaderboard' => [
        'title' => "🏁 <b>Imtihon natijalari</b>\n:lesson-dars",
        'nobody' => 'Hech kim javob bermadi 😅',
    ],

];
