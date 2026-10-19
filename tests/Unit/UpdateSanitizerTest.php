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

it('masks group titles and shared phone numbers', function (): void {
    $update = [
        'message' => [
            'chat' => ['id' => -100, 'type' => 'supergroup', 'title' => 'Class 7B Parents'],
            'contact' => ['phone_number' => '+998901234567', 'user_id' => 7],
        ],
    ];

    $cleaned = UpdateSanitizer::forLog($update);

    expect($cleaned['message']['chat']['title'])->toBe('Cl***')
        ->and($cleaned['message']['chat']['id'])->toBe(-100)
        ->and($cleaned['message']['contact']['phone_number'])->toBe('+9***')
        ->and($cleaned['message']['contact']['user_id'])->toBe(7);
});

it('masks the vcard of a shared contact', function (): void {
    $update = [
        'message' => [
            'contact' => [
                'phone_number' => '+998901234567',
                'first_name' => 'Alexander',
                'user_id' => 7,
                'vcard' => "BEGIN:VCARD\nVERSION:3.0\nFN:Alexander\nTEL:+998901234567\nEND:VCARD",
            ],
        ],
    ];

    $cleaned = UpdateSanitizer::forLog($update);

    expect($cleaned['message']['contact']['vcard'])->toBe('BE***')
        ->and(json_encode($cleaned))->not->toContain('998901234567')
        ->and($cleaned['message']['contact']['user_id'])->toBe(7);
});

it('masks the payment order token carried as invoice_payload but keeps the payment ids', function (): void {
    $update = [
        'message' => [
            'chat' => ['id' => 42, 'type' => 'private'],
            'successful_payment' => ['invoice_payload' => 'AbCdEfGhIjKlMnOpQrStUvWxYz123456', 'telegram_payment_charge_id' => 'ch_1', 'total_amount' => 150],
        ],
        'pre_checkout_query' => ['id' => 'q1', 'invoice_payload' => 'AbCdEfGhIjKlMnOpQrStUvWxYz123456'],
    ];

    $cleaned = UpdateSanitizer::forLog($update);

    expect($cleaned['message']['successful_payment']['invoice_payload'])->toBe('Ab***')
        ->and($cleaned['message']['successful_payment']['telegram_payment_charge_id'])->toBe('ch_1')
        ->and($cleaned['message']['successful_payment']['total_amount'])->toBe(150)
        ->and($cleaned['pre_checkout_query']['invoice_payload'])->toBe('Ab***');
});
