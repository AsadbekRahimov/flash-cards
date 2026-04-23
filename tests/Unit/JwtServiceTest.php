<?php

declare(strict_types=1);

use App\Domain\Twa\Services\JwtService;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;

it('issues and verifies a JWT', function (): void {
    $jwt = new JwtService(secret: 'secret-at-least-32-chars-abcdef-1234', ttl: 300);

    $issued = $jwt->issue(studentId: 42, groupId: 7);

    expect($issued['token'])->toBeString()
        ->and($issued['expires_in'])->toBe(300);

    $payload = $jwt->verify($issued['token']);
    expect($payload['student_id'])->toBe(42)
        ->and($payload['group_id'])->toBe(7)
        ->and($payload['exp'])->toBeGreaterThan(time());
});

it('rejects a JWT signed with a different secret', function (): void {
    $a = new JwtService(secret: str_repeat('a', 32), ttl: 300);
    $b = new JwtService(secret: str_repeat('b', 32), ttl: 300);

    $token = $a->issue(1, 1)['token'];

    expect(fn () => $b->verify($token))->toThrow(SignatureInvalidException::class);
});

it('rejects an expired JWT', function (): void {
    $jwt = new JwtService(secret: str_repeat('s', 32), ttl: -10);

    $token = $jwt->issue(1, 1)['token'];

    expect(fn () => $jwt->verify($token))->toThrow(ExpiredException::class);
});

it('rejects malformed tokens', function (): void {
    $jwt = new JwtService(secret: str_repeat('s', 32), ttl: 300);

    expect(fn () => $jwt->verify('not-a-jwt'))->toThrow(UnexpectedValueException::class);
});
