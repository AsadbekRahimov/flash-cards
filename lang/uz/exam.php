<?php

declare(strict_types=1);

return [

    // HTML. `:seconds` — time for one question.
    'started' => "🏁 <b>Imtihon boshlandi!</b> :stage-modul · :lesson-dars · :minutes daq.\nQatnashish uchun quyidagi tugmani bosing. Har bir savolga javob berish uchun :seconds soniya bor. Imtihon tugagach, natijalar shu guruhda e'lon qilinadi.",
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
