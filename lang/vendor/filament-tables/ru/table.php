<?php

declare(strict_types=1);

// Дополнение к русскому переводу Filament (пакет tables), см. lang/vendor/filament-forms/ru/components.php.
return [

    'column_manager' => [
        'position' => 'Позиция :position из :count.',
        'position_in_group' => 'Позиция :position из :count в группе «:group».',
        'reordered' => 'Столбец «:column» перемещён на позицию :position из :count.',
        'reordered_deferred' => 'Столбец «:column» перемещён на позицию :position из :count. Нажмите «Применить столбцы», чтобы сохранить изменения.',
        'reordered_in_group' => 'Столбец «:column» перемещён на позицию :position из :count в группе «:group».',
        'reordered_in_group_deferred' => 'Столбец «:column» перемещён на позицию :position из :count в группе «:group». Нажмите «Применить столбцы», чтобы сохранить изменения.',
        'actions' => [
            'move_up' => ['label' => 'Переместить «:column» вверх'],
            'move_down' => ['label' => 'Переместить «:column» вниз'],
            'reorder' => ['label' => 'Переместить столбец'],
        ],
    ],

    'columns' => [
        'editable' => [
            'saving' => 'Сохранение изменения...',
            'saved' => 'Изменение сохранено.',
            'failure' => 'Не удалось подтвердить сохранение. Обновите страницу и попробуйте ещё раз.',
            'unavailable' => 'Этот элемент больше нельзя редактировать.',
        ],
        'icon' => [
            'boolean' => [
                'true' => 'Да',
                'false' => 'Нет',
            ],
        ],
    ],

    'actions' => [
        'reorder_record' => ['label' => 'Переместить элемент :key'],
        'move_record_up' => ['label' => 'Переместить «:label» вверх'],
        'move_record_down' => ['label' => 'Переместить «:label» вниз'],
        'toggle_record_content' => ['label' => 'Развернуть или свернуть элемент :key'],
    ],

    'loading' => 'Загрузка...',

    'reordering' => [
        'position' => ':label, позиция :position из :count.',
        'moved' => ':label — новая позиция :position из :count.',
    ],

    // Явные диапазоны, без склонения существительного: «Результатов: 5».
    'result_count' => '{0} Результатов нет|[1,*] Результатов: :count',

];
