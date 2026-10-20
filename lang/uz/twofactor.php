<?php

declare(strict_types=1);

return [

    'confirm_button' => 'Tasdiqlash',

    'setup' => [
        'title' => '2FA sozlash',
        'heading' => 'Ikki bosqichli autentifikatsiyani yoqing',
        'intro' => "QR-kodni Google Authenticator, 1Password yoki Authy ilovasida skanerlang, so'ng tasdiqlash uchun 6 xonali kodni kiriting.",
        'manual_key' => "Yoki kalitni qo'lda kiriting:",
        'code_label' => 'Ilovadagi kod',
    ],

    'challenge' => [
        'title' => 'Ikki bosqichli tekshiruv',
        'heading' => 'Kodni kiriting',
        'intro' => 'Autentifikator ilovasini oching va 6 xonali kodni kiriting. Yoki tiklash kodidan foydalaning.',
        'code_label' => 'Kod',
    ],

    'recovery' => [
        'title' => 'Tiklash kodlari',
        'heading' => "Tiklash kodlarini saqlab qo'ying",
        // `:emphasis` is replaced in the view by an escaped, <strong>-wrapped `intro_emphasis`.
        'intro' => "Har bir kod :emphasis amal qiladi. Telefoningizni yo'qotsangiz, ulardan foydalaning. Kodlarni parollar menejerida saqlang.",
        'intro_emphasis' => 'faqat bir marta',
        'to_admin' => "→ Admin panelga o'tish",
        'to_org' => "→ Markaz kabinetiga o'tish",
    ],

    'errors' => [
        'invalid_code' => "Kod noto'g'ri. Qayta urinib ko'ring.",
        'invalid_code_or_recovery' => "Kod yoki tiklash kodi noto'g'ri.",
    ],

];
