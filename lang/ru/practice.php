<?php

declare(strict_types=1);

return [

    // The message of 403 `practice_limit_reached` (docs/18 G1): the Free limit of the day is used up. The count is the limit
    // itself (`:limit`); 0 = the kind is for Pro only. The app shows the paywall by the code, the text is the fallback.
    // Terms as in the app: "практика" (not "тренировка" - those are the card sessions), "баттл" (not "вызов" - those are
    // the weekly challenges of friends).
    'limit_reached' => [
        'sessions' => '{0} Практика доступна в Pro.|{1} Бесплатная практика на сегодня закончилась: на Free — :limit сессия практики в день. В Pro — без ограничений.|[2,4] Бесплатная практика на сегодня закончилась: на Free — :limit сессии практики в день. В Pro — без ограничений.|[5,*] Бесплатная практика на сегодня закончилась: на Free — :limit сессий практики в день. В Pro — без ограничений.',
        'battles' => '{0} Звать друзей на баттл можно в Pro. Принять приглашение друга можно всегда.|{1} Бесплатные баттлы на сегодня закончились: на Free звать друга на баттл можно :limit раз в день. Принять приглашение друга можно всегда.|[2,4] Бесплатные баттлы на сегодня закончились: на Free звать друга на баттл можно :limit раза в день. Принять приглашение друга можно всегда.|[5,*] Бесплатные баттлы на сегодня закончились: на Free звать друга на баттл можно :limit раз в день. Принять приглашение друга можно всегда.',
    ],
];
