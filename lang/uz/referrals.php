<?php

declare(strict_types=1);

return [

    'blocked' => "Akkauntga kirish cheklangan. Qo'llab-quvvatlash xizmatiga murojaat qiling.",

    'invite' => [
        'text' => "Do'stingizni LexiFlow'ga taklif qiling — :rule\n\nSizning havolangiz:\n:link",
        // Milestones (docs/13 §7.1 "2C.9"): the friend counts once they have started (the onboarding and :reviews cards in a day);
        // the inviter gets :reward days of Pro at each of the :milestones counted friends.
        'rule' => "do'stingiz tanishuvdan o'tib bir kunda :reviews ta kartochkani baholagach hisobga olinadi; :milestones do'st uchun har safar :reward kunlik Pro olasiz.",
        'no_link' => "kod :code (havola administrator bot nomini ko'rsatgach paydo bo'ladi)",
        'stats' => 'Havolangiz orqali kelganlar: :invited · hisobga olinganlar: :counted',
        'next' => "mukofotga yana :remaining ta do'st kerak (:friends tadan)",
        'share_button' => 'Havolani ulashish',
        'share_text' => "LexiFlow'da ingliz tilini o'rganyapman — qo'shiling, Pro'ni birga olamiz!",
    ],

];
