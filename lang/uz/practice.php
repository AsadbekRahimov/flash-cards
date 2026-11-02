<?php

declare(strict_types=1);

return [

    // The message of 403 `practice_limit_reached` (docs/18 G1): the Free limit of the day is used up. The count is the limit
    // itself (`:limit`); 0 = the kind is for Pro only. The app shows the paywall by the code, the text is the fallback.
    'limit_reached' => [
        'sessions' => "{0} Mashq Pro'da mavjud.|[1,*] Bugungi limit tugadi: Free'da kuniga :limit ta mashq seansi. Pro'da cheklov yo'q.",
        'battles' => "{0} Do'stni jangga chaqirish Pro'da mavjud. Do'st chaqiruvini qabul qilish har doim mumkin.|[1,*] Bugungi limit tugadi: Free'da kuniga :limit ta jang chaqiruvi. Do'st chaqiruvini qabul qilish har doim mumkin.",
    ],
];
