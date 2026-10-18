<?php

declare(strict_types=1);

return [

    'start' => [
        'not_linked' => "Bu Telegram akkaunt LexiFlow'dagi o'qituvchiga biriktirilmagan. Administratordan Telegram ID'ngizni kiritishini so'rang: :id.",
        'greeting' => "Salom, :name! Siz LexiFlow o'qituvchisi sifatida tizimga kirdingiz. Guruhdagi buyruqlar: /help",
    ],

    'help' => [
        'private' => "LexiFlow — ingliz tilini o'rganish yordamchisi.\n\n".
            "O'qituvchilar uchun: botni guruhga qo'shing, administrator uni faollashtiradi.\n".
            "Guruhdagi mavjud buyruqlar: /start_training, /start_exam, /close_exam, /help\n".
            'Shaxsiy hisobot: /stats',
        'group' => "LexiFlow Bot guruhda.\n\nO'qituvchi buyruqlari:\n".
            "/start_training — mashg'ulotni boshlash\n".
            "/start_exam — imtihonni boshlash\n".
            "/close_exam — imtihonni yopish\n".
            "/stats — bot bilan shaxsiy chatda hisobot\n".
            '/help — ushbu yordam',
    ],

    'stats' => [
        'not_linked' => "Bu Telegram akkaunt LexiFlow o'qituvchisiga biriktirilmagan.\nSizning Telegram ID'ngiz: :id",
    ],

];
