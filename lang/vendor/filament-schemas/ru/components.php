<?php

declare(strict_types=1);

// Дополнение к русскому переводу Filament (пакет schemas), см. lang/vendor/filament-forms/ru/components.php.
return [

    'callout' => [
        'statuses' => [
            'danger' => 'Ошибка:',
            'info' => 'Примечание:',
            'success' => 'Успешно:',
            'warning' => 'Внимание:',
        ],
    ],

    'js_component' => [
        'failed_to_load' => 'Не удалось загрузить этот компонент. Перезагрузите страницу и попробуйте ещё раз.',
    ],

    'section' => [
        'actions' => [
            'collapse' => ['label' => 'Свернуть раздел'],
            'expand' => ['label' => 'Развернуть раздел'],
        ],
    ],

    'tabs' => [
        'actions' => [
            'more' => ['label' => 'Другие вкладки'],
        ],
    ],

    'wizard' => [
        'header' => [
            'step' => [
                'statuses' => [
                    'completed' => 'Выполнен',
                    'upcoming' => 'Не выполнен',
                ],
            ],
        ],
    ],

];
