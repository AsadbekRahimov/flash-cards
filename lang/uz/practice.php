<?php

declare(strict_types=1);

return [

    // The message of 403 `practice_limit_reached` (docs/18 G1): the Free limit of the day is used up. The count is the limit
    // itself (`:limit`); 0 = the kind is for Pro only. The app shows the paywall by the code, the text is the fallback.
    // Terms as in the app: "amaliyot" (the practice section), "bellashuv" (the battle, as in battle.* and the bot).
    'limit_reached' => [
        'sessions' => "{0} Amaliyot Pro'da mavjud.|[1,*] Bugungi bepul amaliyot tugadi: Free'da kuniga :limit marta amaliyot. Pro'da cheklov yo'q.",
        'battles' => "{0} Do'stni bellashuvga chaqirish Pro'da mavjud. Do'stning taklifini qabul qilish har doim mumkin.|[1,*] Bugungi bepul bellashuvlar tugadi: Free'da do'stni kuniga :limit marta bellashuvga chaqirish mumkin. Do'stning taklifini qabul qilish har doim mumkin.",
    ],
];
