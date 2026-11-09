<?php

declare(strict_types=1);

return [

    // The message of 403 `practice_limit_reached` (docs/18 G1): the Free limit of the day is used up. The count is the limit
    // itself (`:limit`); 0 = the kind is for Pro only. The app shows the paywall by the code, the text is the fallback.
    // Terms as in the app: "mashqlar" (the practice section), "bellashuv" (the battle, as in battle.* and the bot).
    'limit_reached' => [
        'sessions' => "{0} Mashqlar faqat Pro'da.|[1,*] Bugungi bepul mashqlar tugadi: bepul tarifda kuniga :limit marta mashq qilish mumkin. Pro'da cheklov yo'q.",
        'battles' => "{0} Do'stni bellashuvga faqat Pro'da chaqirish mumkin. Do'stingiz chaqirsa, har doim qatnashsa bo'ladi.|[1,*] Bugungi bepul bellashuvlar tugadi: bepul tarifda do'stni kuniga :limit marta chaqirish mumkin. Do'stingiz chaqirsa, har doim qatnashsa bo'ladi.",
    ],
];
