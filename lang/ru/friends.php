<?php

declare(strict_types=1);

return [

    'anonymous' => 'Ваш знакомый',

    // `/start f_<code>`, docs/13 §7.5: the link owner is only an address, the opener is asked first.
    'offer' => [
        'text' => "Вы открыли ссылку-приглашение от :name. Отправить :name запрос в друзья?\n\nДрузья видят серию и число выученных слов друг друга и могут бросать вызовы: кто больше повторит за неделю.",
        'send' => 'Отправить запрос',
        'add' => 'Добавить в друзья',
        'skip' => 'Не сейчас',
    ],

    // A request addressed to the learner (a DM, or a pending request met by /start f_<code>).
    'incoming' => [
        'text' => ':name хочет добавить вас в друзья в LexiFlow.',
        'accept' => 'Принять',
        'decline' => 'Отклонить',
    ],

    'request_sent' => 'Запрос отправлен: :name. Мы сообщим, когда его примут.',
    'already_sent' => 'Вы уже отправили запрос :name — ждём ответа.',
    'skipped' => 'Хорошо, запрос не отправлен.',
    'accepted' => 'Готово: вы с :name теперь друзья. Список друзей — в приложении LexiFlow.',
    'declined' => 'Хорошо, приглашение отклонено.',
    'already' => 'Вы с :name уже друзья.',

    // Why a link / button was refused, by FriendshipException::$errorCode.
    'errors' => [
        'invalid_link' => 'Ссылка недействительна или устарела.',
        'friendship_self' => 'Это ваша собственная ссылка. Отправьте её другу.',
        'friend_unavailable' => 'Эта ссылка недоступна.',
        'friend_blocked' => 'Эта ссылка недоступна.',
        'friendship_not_found' => 'Это приглашение больше не действует.',
        'not_recipient' => 'Ответить на приглашение может только тот, кого пригласили.',
        'friend_limit' => 'У вас уже максимальное число друзей.',
        'friend_limit_other' => 'У вашего друга уже максимальное число друзей.',
        'generic' => 'Не получилось. Попробуйте позже.',
    ],

    'metric' => [
        'reviews' => 'больше повторит карточек',
        'new_words' => 'выучит больше новых слов',
    ],

    // DMs (background): a friend accepted, a challenge proposed / accepted / finished.
    'dm' => [
        'friend_accepted' => ':name принял(а) ваше приглашение: теперь вы друзья!',
        'challenge_proposed' => ':name бросает вам вызов: кто :metric за :days дн.? Если примете, вызов начнётся завтра.',
        'challenge_accepted' => ':name принял(а) ваш вызов! Старт — :starts, финиш — :ends.',
        'challenge_won' => 'Вызов с :name завершён: вы победили! Счёт :mine : :theirs.',
        'challenge_lost' => 'Вызов с :name завершён: победил(а) :name. Счёт :mine : :theirs. Реванш — в приложении!',
        'challenge_draw' => 'Вызов с :name завершён вничью: :mine : :theirs.',
        'button' => 'Открыть LexiFlow',
    ],

    'challenge' => [
        'accept' => 'Принять вызов',
        'decline' => 'Отклонить',
        'accepted' => 'Вызов принят! Старт — :starts, финиш — :ends.',
        'declined' => 'Хорошо, вызов отклонён.',
        'errors' => [
            'challenge_not_found' => 'Этот вызов больше не действует.',
            'challenge_closed' => 'Этот вызов больше не действует.',
            'not_opponent' => 'Ответить на вызов может только тот, кому он адресован.',
            'not_friends' => 'Вызов можно бросить только другу.',
            'generic' => 'Не получилось. Попробуйте позже.',
        ],
    ],

];
