<?php

declare(strict_types=1);

return [

    // The message of 403 `practice_limit_reached` (docs/18 G1): the Free limit of the day is used up (`:limit` = the limit;
    // 0 = the kind is for Pro only). English is the app locale outside a learner's request (console, tests); ru / uz are the learners'.
    'limit_reached' => [
        'sessions' => '{0} The practice is a Pro feature.|{1} The daily limit is used up: :limit practice session a day on Free. Pro has no limit.|[2,*] The daily limit is used up: :limit practice sessions a day on Free. Pro has no limit.',
        'battles' => '{0} Calling a friend to a battle is a Pro feature. Accepting a friend\'s call is always free.|{1} The daily limit is used up: :limit battle call a day on Free. Accepting a friend\'s call is always free.|[2,*] The daily limit is used up: :limit battle calls a day on Free. Accepting a friend\'s call is always free.',
    ],
];
