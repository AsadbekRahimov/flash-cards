<?php

declare(strict_types=1);

return [

    'blocked' => 'Доступ к аккаунту ограничен. Обратитесь в поддержку.',

    // `/invite` (docs/13 §7.1): the link, the rule, the counts and the "Share" button.
    'invite' => [
        'text' => "Пригласите друга в LexiFlow — :rule\n\nВаша ссылка:\n:link",
        'rule' => 'когда друг позанимается :days дня (от :reviews карточек в день), вы оба получите :reward дней Pro.',
        'no_link' => 'код :code (ссылка появится, когда администратор укажет имя бота)',
        'stats' => 'Пришли по вашей ссылке: :invited · занимаются: :activated · награждены: :rewarded',
        'share_button' => 'Поделиться ссылкой',
        'share_text' => 'Учу английский в LexiFlow — присоединяйся, получим Pro вместе!',
    ],

];
