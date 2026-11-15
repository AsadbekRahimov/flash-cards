<?php

declare(strict_types=1);

return [

    'confirm_button' => 'Tasdiqlash',

    'setup' => [
        'title' => 'Kirishni ilovadagi kod bilan himoyalash',
        'heading' => 'Ikki bosqichli autentifikatsiyani yoqing',
        'intro' => "Telefoningizga bepul Google Authenticator ilovasini (yoki kirish kodlari uchun boshqa istalgan ilovani) o'rnating, undagi «+» tugmasini bosing va kamerani QR-kodga qarating. Keyin ilovadagi 6 ta raqamni kiriting.",
        'manual_key' => "Yoki kalitni qo'lda kiriting:",
        'code_label' => 'Ilovadagi kod',
    ],

    'challenge' => [
        'title' => 'Ikki bosqichli tekshiruv',
        'heading' => 'Kodni kiriting',
        'intro' => 'Autentifikator ilovasini oching va 6 xonali kodni kiriting. Yoki zaxira koddan foydalaning.',
        'code_label' => 'Kod',
        'lost' => "Telefon ham, kodlar ham yo'qoldimi? Qo'llab-quvvatlash xizmatiga yozing — tekshiruvdan so'ng kirish huquqini tiklaymiz.",
    ],

    'recovery' => [
        'title' => 'Zaxira kodlar',
        'heading' => "Zaxira kodlarni saqlab qo'ying",
        // `:emphasis` is replaced in the view by an escaped, <strong>-wrapped `intro_emphasis`.
        'intro' => "Telefoningizni yo'qotsangiz, ulardan biri kirishga yordam beradi. Har bir kod :emphasis ishlaydi. Kodlarni parollar menejerida saqlang.",
        'intro_emphasis' => 'faqat bir marta',
        'to_admin' => "→ Admin panelga o'tish",
        'to_org' => "→ Markaz kabinetiga o'tish",
    ],

    'errors' => [
        'invalid_code' => "Kod noto'g'ri. Qayta urinib ko'ring.",
        'invalid_code_or_recovery' => "Kod yoki zaxira kod noto'g'ri.",
        'too_many_attempts' => "Juda ko'p urinish. :seconds soniyadan keyin qayta urinib ko'ring.",
    ],

];
