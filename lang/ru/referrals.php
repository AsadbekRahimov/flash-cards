<?php

declare(strict_types=1);

return [

    'blocked' => 'Доступ к аккаунту ограничен. Обратитесь в поддержку.',

    // `/invite` (docs/13 §7.1): the link, the rule, the counts and the "Share" button.
    'invite' => [
        'text' => "Пригласите друга в LexiFlow — :rule\n\nВаша ссылка:\n:link",
        // Milestones (docs/13 §7.1 "2C.9"): the friend counts once they have started (the onboarding and :reviews cards in a day);
        // the inviter gets :reward days of Pro at each of the :milestones counted friends.
        'rule' => 'друг засчитывается, когда пройдёт знакомство и оценит :reviews карточек за день; за :milestones друзей вы получите по :reward дней Pro.',
        'no_link' => 'код :code (ссылка появится, когда администратор укажет имя бота)',
        'stats' => 'Пришли по вашей ссылке: :invited · засчитаны: :counted',
        'next' => 'до награды ещё :remaining (из :friends)',
        'share_button' => 'Поделиться ссылкой',
        'share_text' => 'Учу английский в LexiFlow — присоединяйся, получим Pro вместе!',
    ],

];
