<?php

declare(strict_types=1);

namespace App\Domain\Twa\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use UnexpectedValueException;

/**
 * Short-lived JWT for TWA clients.
 *
 * Payload (format v2, docs/12 §4.1):
 *   { iss, sub: learner_id, v: 2, iat, exp }
 *
 * `v` is mandatory: tokens issued before learner-first (where `sub` was a
 * `students.id` and a `gid` claim was present) must never be read as a learner id.
 */
final class JwtService
{
    public const VERSION = 2;

    public function __construct(
        private readonly string $secret,
        private readonly int $ttl,
        private readonly string $alg = 'HS256',
        private readonly string $issuer = 'lexiflow',
    ) {}

    /**
     * @return array{token:string, expires_in:int, expires_at:int}
     */
    public function issue(int $learnerId): array
    {
        $now = time();
        $expires = $now + $this->ttl;

        $payload = [
            'iss' => $this->issuer,
            'sub' => (string) $learnerId,
            'v' => self::VERSION,
            'iat' => $now,
            'exp' => $expires,
        ];

        return [
            'token' => JWT::encode($payload, $this->secret, $this->alg),
            'expires_in' => $this->ttl,
            'expires_at' => $expires,
        ];
    }

    /**
     * @return array{learner_id:int, iat:int, exp:int}
     *
     * @throws UnexpectedValueException
     */
    public function verify(string $token): array
    {
        $decoded = JWT::decode($token, new Key($this->secret, $this->alg));
        $payload = (array) $decoded;

        if (! isset($payload['iss'], $payload['sub'], $payload['exp'])) {
            throw new UnexpectedValueException('malformed_jwt_payload');
        }

        if (($payload['v'] ?? null) !== self::VERSION) {
            throw new UnexpectedValueException('unsupported_jwt_version');
        }

        if (! is_string($payload['iss']) || ! hash_equals($this->issuer, $payload['iss'])) {
            throw new UnexpectedValueException('invalid_jwt_issuer');
        }

        return [
            'learner_id' => (int) $payload['sub'],
            'iat' => (int) ($payload['iat'] ?? 0),
            'exp' => (int) $payload['exp'],
        ];
    }
}
