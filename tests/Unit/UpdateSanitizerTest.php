<?php

declare(strict_types=1);

use App\Domain\Telegram\Support\UpdateSanitizer;

it('masks PII fields but keeps structure', function (): void {
    $update = [
        'update_id' => 1,
        'message' => [
            'chat' => ['id' => 42, 'type' => 'private'],
            'from' => ['id' => 7, 'first_name' => 'Alexander', 'last_name' => 'Pushkin', 'username' => 'sasha'],
            'text' => 'Hello, world!',
        ],
    ];

    $cleaned = UpdateSanitizer::forLog($update);

    expect($cleaned['message']['from']['first_name'])->toBe('Al***');
    expect($cleaned['message']['from']['last_name'])->toBe('Pu***');
    expect($cleaned['message']['from']['username'])->toBe('sa***');
    expect($cleaned['message']['text'])->toBe('He***');
    expect($cleaned['update_id'])->toBe(1);
    expect($cleaned['message']['chat']['id'])->toBe(42);
});
