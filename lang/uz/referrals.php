<?php

declare(strict_types=1);

return [

    'blocked' => "Akkauntga kirish cheklangan. Qo'llab-quvvatlash xizmatiga murojaat qiling.",

    // Number fragments (one form in Uzbek: the number is not declined) shared by the texts below and by the "week of Pro" welcome.
    'days' => ':count kun',
    'friends_gen' => ":count ta do'stni",
    // One line of /pro for a learner on Free: the way to Pro that costs nothing.
    'hint' => "Do'stlaringizni taklif qiling — Pro'ni bepul oling: /invite",

    'invite' => [
        'text' => "Do'stlaringizni LexiFlow'ga taklif qiling — Pro'ni bepul oling.\n\n:rule\n\n:progress\n\nSizning havolangiz:\n:link",
        // The friend counts once they have started (the setup and :cards in a day); the inviter gets :reward of Pro at each of
        // the :milestones counted friends ("3 va 6").
        'rule' => "Do'stingiz boshlang'ich sozlamalarni tugatib, bir kun ichida :cards baholasa, taklifingiz hisobga olinadi.\nHisobga olingan do'stlaringiz soni :milestones taga yetganida har safar :reward davomida Pro tarifidan bepul foydalanish huquqini olasiz.",
        'rule_days' => "Do'stingiz boshlang'ich sozlamalarni tugatib, :days kun davomida har kuni kamida :cards baholasa, taklifingiz hisobga olinadi.\nHisobga olingan do'stlaringiz soni :milestones taga yetganida har safar :reward davomida Pro tarifidan bepul foydalanish huquqini olasiz.",
        'cards' => ':count ta kartochkani',
        'cards_min' => ':count ta kartochkani',
        'text_plain' => "Do'stlaringizni LexiFlow'ga taklif qiling.\n\nHozircha takliflar uchun mukofot berilmaydi.\n\nSizning havolangiz:\n:link",
        'and' => 'va',
        'no_link' => "kod :code (havola administrator bot nomini ko'rsatgach paydo bo'ladi)",
        'stats' => 'Havolangiz orqali kelganlar: :invited',
        'next' => "Hisobga olingan do'stlar: :counted / :target — +:days Pro'gacha yana :remaining ta do'st qoldi.",
        'owed' => "Hisobga olingan do'stlar: :counted — +:days Pro mukofoti rasmiylashtirilmoqda.",
        'done' => "Hisobga olingan do'stlar: :counted. Takliflar uchun barcha mukofotlar olindi — rahmat!",
        'share_button' => 'Havolani ulashish',
        'share_text' => "LexiFlow'da ingliz tilini o'rganyapman — qo'shiling, birinchi :trial Pro bepul!",
        'share_text_plain' => "LexiFlow'da ingliz tilini o'rganyapman — qo'shiling!",
    ],

    // DM to the inviter when a milestone is granted; `:days` is "7 kun".
    'milestone' => [
        'reached' => "🎉 :count ta do'stingiz o'rganishni boshladi — sizga +:days Pro (:date gacha).",
        'next' => "Keyingi mukofotgacha yana :count ta do'st qoldi.",
    ],

];
