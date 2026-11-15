<?php

declare(strict_types=1);

return [

    // `:support` — the line with the support contact (BotSupportContact::line), empty without a contact.
    'no_groups' => "Hozircha sizga guruh biriktirilmagan.\nBotni sinf guruhiga qo'shing, so'ng qo'llab-quvvatlash xizmatiga yozing — guruhni faollashtiramiz.:support",
    'title' => "📊 LexiFlow statistikasi (so'nggi 7 kun)",
    'groups' => 'Guruhlar: :count',
    'learners' => "Faol o'quvchilar: :count",
    'reviews' => 'Takrorlangan kartochkalar: :count',
    'exams' => 'Boshlangan imtihonlar: :count',
    'accuracy' => "Imtihonlardagi to'g'ri javoblar: :percent%",
    // No exam answers in the period: 0% would read as "all wrong".
    'accuracy_none' => "Imtihonlardagi to'g'ri javoblar: —",

];
