<?php

declare(strict_types=1);

use App\Domain\Twa\Services\JwtService;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;

const JWT_TEST_SECRET = 'secret-at-least-32-chars-abcdef-1234';

/**
 * @param  array<string, mixed>  $overrides  claims to replace; a null value drops the claim
 */
function forgeJwt(array $overrides = [], string $secret = JWT_TEST_SECRET): string
{
    $claims = array_merge([
        'iss' => 'lexiflow',
        'sub' => '42',
        'v' => 2,
        'iat' => time(),
        'exp' => time() + 300,
    ], $overrides);

    return JWT::encode(array_filter($claims, fn ($v): bool => $v !== null), $secret, 'HS256');
}

it('issues and verifies a JWT', function (): void {
    $jwt = new JwtService(secret: JWT_TEST_SECRET, ttl: 300);

    $issued = $jwt->issue(learnerId: 42);

    expect($issued['token'])->toBeString()
        ->and($issued['expires_in'])->toBe(300);

    $payload = $jwt->verify($issued['token']);
    expect($payload['learner_id'])->toBe(42)
        ->and($payload['exp'])->toBeGreaterThan(time());
});

it('writes the v2 payload {iss, sub, v, iat, exp} without a group claim', function (): void {
    $jwt = new JwtService(secret: JWT_TEST_SECRET, ttl: 300);

    $token = $jwt->issue(42)['token'];

    $claims = (array) JWT::decode($token, new Key(JWT_TEST_SECRET, 'HS256'));

    expect(array_keys($claims))->toEqualCanonicalizing(['iss', 'sub', 'v', 'iat', 'exp'])
        ->and($claims['iss'])->toBe('lexiflow')
        ->and($claims['sub'])->toBe('42')
        ->and($claims['v'])->toBe(2);
});

it('rejects a JWT signed with a different secret', function (): void {
    $a = new JwtService(secret: str_repeat('a', 32), ttl: 300);
    $b = new JwtService(secret: str_repeat('b', 32), ttl: 300);

    $token = $a->issue(1)['token'];

    expect(fn () => $b->verify($token))->toThrow(SignatureInvalidException::class);
});

it('rejects an expired JWT', function (): void {
    $jwt = new JwtService(secret: str_repeat('s', 32), ttl: -10);

    $token = $jwt->issue(1)['token'];

    expect(fn () => $jwt->verify($token))->toThrow(ExpiredException::class);
});

it('rejects a tampered payload', function (): void {
    $jwt = new JwtService(secret: JWT_TEST_SECRET, ttl: 300);
    [$header, $payload, $signature] = explode('.', $jwt->issue(1)['token']);

    $forged = rtrim(strtr(base64_encode((string) json_encode([
        'iss' => 'lexiflow', 'sub' => '2', 'v' => 2, 'iat' => time(), 'exp' => time() + 300,
    ])), '+/', '-_'), '=');

    expect(fn () => $jwt->verify("{$header}.{$forged}.{$signature}"))->toThrow(SignatureInvalidException::class)
        ->and($payload)->not->toBe($forged);
});

it('rejects malformed tokens', function (): void {
    $jwt = new JwtService(secret: str_repeat('s', 32), ttl: 300);

    expect(fn () => $jwt->verify('not-a-jwt'))->toThrow(UnexpectedValueException::class);
});

it('rejects a token without the version claim (pre-learner format)', function (): void {
    $jwt = new JwtService(secret: JWT_TEST_SECRET, ttl: 300);

    // Old shape: sub is a learners.id, gid present, no `v`.
    $legacy = forgeJwt(['v' => null, 'gid' => 7]);

    expect(fn () => $jwt->verify($legacy))->toThrow(UnexpectedValueException::class, 'unsupported_jwt_version');
});

it('rejects other versions', function (int|string $version): void {
    $jwt = new JwtService(secret: JWT_TEST_SECRET, ttl: 300);

    expect(fn () => $jwt->verify(forgeJwt(['v' => $version])))
        ->toThrow(UnexpectedValueException::class, 'unsupported_jwt_version');
})->with([1, 3, '2']);

it('rejects a foreign issuer', function (): void {
    $jwt = new JwtService(secret: JWT_TEST_SECRET, ttl: 300);

    expect(fn () => $jwt->verify(forgeJwt(['iss' => 'someone-else'])))
        ->toThrow(UnexpectedValueException::class, 'invalid_jwt_issuer');
});

it('rejects a token without a subject', function (): void {
    $jwt = new JwtService(secret: JWT_TEST_SECRET, ttl: 300);

    expect(fn () => $jwt->verify(forgeJwt(['sub' => null])))
        ->toThrow(UnexpectedValueException::class, 'malformed_jwt_payload');
});
