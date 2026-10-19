<?php

declare(strict_types=1);

return [

    // `/start org_<code>` (docs/13 §6.2): the confirmation shown after the onboarding (or at once for a learner who has finished it).
    'offer' => [
        'text' => 'Учебный центр «:organization» приглашает вас в LexiFlow. Вступить, чтобы получить Pro от центра?',
        'join' => 'Вступить',
        'skip' => 'Не сейчас',
    ],

    'already' => 'Вы уже в центре «:organization».',

    'skipped' => 'Хорошо. Вступить можно в любой момент по ссылке центра.',

    // DM after joining (invite / added by an administrator) and when a waiting place becomes free.
    'joined' => [
        'seated' => 'Вы в центре «:organization». Pro активен — занимайтесь без ограничений.',
        'waitlisted' => 'Вы в центре «:organization», но свободных мест пока нет. Сообщите администратору центра — как только место освободится, Pro включится автоматически.',
        'button' => 'Открыть LexiFlow',
    ],

    'seated' => [
        'text' => 'В центре «:organization» освободилось место для вас. Pro активен!',
        'button' => 'Открыть LexiFlow',
    ],

    // Why a link / button was refused, by OrganizationMembershipException::$errorCode.
    'errors' => [
        'invite_invalid' => 'Ссылка центра недействительна или отключена. Попросите у администратора новую.',
        'invite_expired' => 'Срок действия ссылки центра истёк. Попросите у администратора новую.',
        'invite_exhausted' => 'Эта ссылка центра уже использована максимальное число раз. Попросите у администратора новую.',
        'organization_suspended' => 'Центр сейчас не принимает учеников. Обратитесь к администратору центра.',
        'not_a_member' => 'Вы уже не состоите в этом центре.',
        'membership_not_found' => 'Вы уже не состоите в этом центре.',
        'membership_via_group' => 'Вы в центре «:organization» через группу: покинуть его можно, выйдя из группы центра в Telegram.',
        'generic' => 'Не получилось. Попробуйте ещё раз позже.',
    ],

    // `/settings` -> "My centers".
    'settings_button' => 'Мои центры',

    'list' => [
        'title' => 'Ваши центры:',
        'empty' => 'Вы не состоите ни в одном центре.',
        'line' => '• «:organization» — :state',
        'state' => [
            'seated' => 'Pro активен',
            'waitlisted' => 'в очереди на место',
        ],
        'group_note' => 'Центры, в которых вы через группу, отсюда не покинуть: выйдите из группы центра в Telegram.',
        'leave' => 'Покинуть «:organization»',
    ],

    'leave' => [
        'confirm' => 'Покинуть центр «:organization»? Pro от центра отключится, личная подписка (если есть) останется.',
        'yes' => 'Да, покинуть',
        'no' => 'Отмена',
        'done' => 'Вы покинули центр «:organization».',
        'cancelled' => 'Хорошо, ничего не изменилось.',
    ],

];
