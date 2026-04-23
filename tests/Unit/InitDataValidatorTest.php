<?php

declare(strict_types=1);

use App\Domain\Twa\Exceptions\InvalidInitDataException;
use App\Domain\Twa\Services\InitDataValidator;

const BOT_TOKEN = 'test-bot-token-1234567890';

function buildInitData(array $overrides = [], ?int $authDateOverride = null): string
{
    $fields = array_merge([
        'query_id'  => 'AAHdF6IQAAAAAN0XohDhrOrc',
        'user'      => json_encode([
            'id'            => 42,
            'first_name'    => 'Alex',
            'last_name'     => 'Ivanov',
            'username'      => 'alex',
            'language_code' => 'ru',
        ]),
        'auth_date' => (string) ($authDateOverride ?? time()),
    ], $overrides);

    return InitDataValidator::sign(BOT_TOKEN, $fields);
}

it('accepts a properly signed initData and returns user payload', function (): void {
    $initData = buildInitData();

    $result = (new InitDataValidator(BOT_TOKEN))->validate($initData);

    expect($result['user']['id'])->toBe(42)
        ->and($result['user']['first_name'])->toBe('Alex')
        ->and($result['auth_date'])->toBeInt();
});

it('rejects initData with invalid hash', function (): void {
    $initData = buildInitData();
    $tampered = preg_replace('/hash=[a-f0-9]+/', 'hash='.str_repeat('0', 64), $initData);

    try {
        (new InitDataValidator(BOT_TOKEN))->validate($tampered);
        $this->fail('Expected exception was not thrown');
    } catch (InvalidInitDataException $e) {
        expect($e->reason)->toBe(InvalidInitDataException::REASON_INVALID_HASH);
    }
});

it('rejects initData signed with a different bot token', function (): void {
    $initData = buildInitData();

    try {
        (new InitDataValidator('another-bot-token'))->validate($initData);
        $this->fail('Expected exception was not thrown');
    } catch (InvalidInitDataException $e) {
        expect($e->reason)->toBe(InvalidInitDataException::REASON_INVALID_HASH);
    }
});

it('rejects initData without hash field', function (): void {
    try {
        (new InitDataValidator(BOT_TOKEN))->validate('user=%7B%22id%22%3A42%7D&auth_date=' . time());
        $this->fail('Expected exception was not thrown');
    } catch (InvalidInitDataException $e) {
        expect($e->reason)->toBe(InvalidInitDataException::REASON_MISSING_FIELDS);
    }
});

it('rejects initData older than max_age', function (): void {
    $initData = buildInitData([], authDateOverride: time() - 100_000);

    try {
        (new InitDataValidator(BOT_TOKEN))->validate($initData, maxAgeSeconds: 86400);
        $this->fail('Expected exception was not thrown');
    } catch (InvalidInitDataException $e) {
        expect($e->reason)->toBe(InvalidInitDataException::REASON_EXPIRED);
    }
});

it('rejects initData without a user field', function (): void {
    $initData = InitDataValidator::sign(BOT_TOKEN, [
        'query_id'  => 'abc',
        'auth_date' => (string) time(),
    ]);

    try {
        (new InitDataValidator(BOT_TOKEN))->validate($initData);
        $this->fail('Expected exception was not thrown');
    } catch (InvalidInitDataException $e) {
        expect($e->reason)->toBe(InvalidInitDataException::REASON_NO_USER);
    }
});

it('preserves special characters inside the user JSON during signing and validation', function (): void {
    $initData = buildInitData([
        'user' => json_encode([
            'id'         => 777,
            'first_name' => 'Алёна',
            'username'   => 'user.with.dots',
        ]),
    ]);

    $result = (new InitDataValidator(BOT_TOKEN))->validate($initData);

    expect($result['user']['id'])->toBe(777)
        ->and($result['user']['first_name'])->toBe('Алёна')
        ->and($result['user']['username'])->toBe('user.with.dots');
});
