<?php

declare(strict_types=1);

return [

    'confirm_button' => 'Подтвердить',

    'setup' => [
        'title' => 'Настройка 2FA',
        'heading' => 'Включите двухфакторную аутентификацию',
        'intro' => 'Отсканируйте QR-код в Google Authenticator, 1Password или Authy, затем введите 6-значный код для подтверждения.',
        'manual_key' => 'Или введите ключ вручную:',
        'code_label' => 'Код из приложения',
    ],

    'challenge' => [
        'title' => 'Двухфакторная проверка',
        'heading' => 'Введите код',
        'intro' => 'Откройте приложение-аутентификатор и введите 6-значный код. Либо используйте recovery-код.',
        'code_label' => 'Код',
    ],

    'recovery' => [
        'title' => 'Recovery-коды',
        'heading' => 'Сохраните recovery-коды',
        // `:emphasis` is replaced in the view by an escaped, <strong>-wrapped `intro_emphasis`.
        'intro' => 'Каждый код действителен :emphasis. Используйте их, если потеряете телефон. Храните в менеджере паролей.',
        'intro_emphasis' => 'один раз',
        'to_admin' => '→ Перейти в админку',
        'to_org' => '→ Перейти в кабинет центра',
    ],

    'errors' => [
        'invalid_code' => 'Неверный код. Попробуйте ещё раз.',
        'invalid_code_or_recovery' => 'Неверный код или recovery-код.',
    ],

];
