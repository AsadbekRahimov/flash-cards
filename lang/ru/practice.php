<?php

declare(strict_types=1);

return [

    // The message of 403 `practice_limit_reached` (docs/18 G1): the Free limit of the day is used up. The count is the limit
    // itself (`:limit`); 0 = the kind is for Pro only. The app shows the paywall by the code, the text is the fallback.
    'limit_reached' => [
        'sessions' => '{0} Практика доступна в Pro.|{1} Лимит на сегодня исчерпан: на Free — :limit сессия практики в день. В Pro — без ограничений.|[2,4] Лимит на сегодня исчерпан: на Free — :limit сессии практики в день. В Pro — без ограничений.|[5,*] Лимит на сегодня исчерпан: на Free — :limit сессий практики в день. В Pro — без ограничений.',
        'battles' => '{0} Вызывать друзей на баттл можно в Pro. Принять вызов друга можно всегда.|{1} Лимит на сегодня исчерпан: на Free — :limit вызов на баттл в день. Принять вызов друга можно всегда.|[2,4] Лимит на сегодня исчерпан: на Free — :limit вызова на баттл в день. Принять вызов друга можно всегда.|[5,*] Лимит на сегодня исчерпан: на Free — :limit вызовов на баттл в день. Принять вызов друга можно всегда.',
    ],
];
