<?php

declare(strict_types=1);

return [

    'blocked' => 'Доступ к аккаунту ограничен. Обратитесь в поддержку.',

    // Plural fragments (ru: 1 | 2–4 | 5+) shared by the texts below and by the "week of Pro" welcome (TrialWelcomeMessenger).
    'days' => ':count день|:count дня|:count дней',
    // "Invite :friends" — the accusative of an animate noun is the genitive: 1 друга, 2 друзей, 5 друзей.
    'friends_gen' => ':count друга|:count друзей|:count друзей',
    // One line of /pro for a learner on Free: the way to Pro that costs nothing.
    'hint' => 'Пригласите друзей — получите Pro бесплатно: /invite',

    // `/invite` (docs/13 §7.1 "2C.9"): the rule, the progress, the link and the "Share" button.
    'invite' => [
        'text' => "Пригласите друзей в LexiFlow — получите Pro бесплатно.\n\n:rule\n\n:progress\n\nВаша ссылка:\n:link",
        // The friend counts once they have started (the setup and :cards in a day); the inviter gets :reward of Pro at each of
        // the :milestones counted friends ("3 и 6"). Nobody else is rewarded: the friend has the welcome trial of every newcomer.
        'rule' => "Друг засчитывается, когда пройдёт настройку и оценит :cards за день.\nЗа :milestones засчитанных друзей вы получите по +:reward Pro.",
        'cards' => ':count карточку|:count карточки|:count карточек',
        'and' => 'и',
        'no_link' => 'код :code (ссылка появится, когда администратор укажет имя бота)',
        'stats' => 'Пришли по вашей ссылке: :invited',
        'next' => 'Засчитано друзей: :counted из :target — до +:days Pro осталось :remaining.',
        'owed' => 'Засчитано друзей: :counted — награда +:days Pro уже оформляется.',
        'done' => 'Засчитано друзей: :counted. Все награды за приглашения получены — спасибо!',
        'share_button' => 'Поделиться ссылкой',
        // Shared with the friend: what they get is the welcome trial (`:trial` — its length), not a reward for either side.
        'share_text' => 'Учу английский в LexiFlow — присоединяйся, первые :trial Pro бесплатно!',
        'share_text_plain' => 'Учу английский в LexiFlow — присоединяйся!',
    ],

    // DM to the inviter when a milestone is granted (ReferralMilestoneSender): by the number of friends; `:days` is "7 дней".
    'milestone' => [
        'reached' => '🎉 :count ваш друг начал заниматься — вам +:days Pro (до :date).|🎉 :count ваших друга начали заниматься — вам +:days Pro (до :date).|🎉 :count ваших друзей начали заниматься — вам +:days Pro (до :date).',
        'next' => 'До следующей награды: ещё :count друг.|До следующей награды: ещё :count друга.|До следующей награды: ещё :count друзей.',
    ],

];
