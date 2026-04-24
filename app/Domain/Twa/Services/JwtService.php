<?php

declare(strict_types=1);

namespace App\Domain\Twa\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use UnexpectedValueException;

/**
 * Short-lived JWT for TWA clients.
 *
 * Payload:
 *   { iss, sub: student_id, gid: telegram_group_id, iat, exp }
 */
final class JwtService
{
    public function __construct(
        private readonly string $secret,
        private readonly int $ttl,
        private readonly string $alg = 'HS256',
        private readonly string $issuer = 'lexiflow',
    ) {}

    /**
     * @return array{token:string, expires_in:int, expires_at:int}
     */
    public function issue(int $studentId, int $groupId): array
    {
        $now = time();
        $expires = $now + $this->ttl;

        $payload = [
            'iss' => $this->issuer,
            'sub' => (string) $studentId,
            'gid' => $groupId,
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
     * @return array{student_id:int, group_id:int, iat:int, exp:int}
     *
     * @throws UnexpectedValueException
     */
    public function verify(string $token): array
    {
        $decoded = JWT::decode($token, new Key($this->secret, $this->alg));
        $payload = (array) $decoded;

        if (! isset($payload['sub'], $payload['gid'], $payload['exp'])) {
            throw new UnexpectedValueException('malformed_jwt_payload');
        }

        return [
            'student_id' => (int) $payload['sub'],
            'group_id' => (int) $payload['gid'],
            'iat' => (int) ($payload['iat'] ?? 0),
            'exp' => (int) $payload['exp'],
        ];
    }
}
