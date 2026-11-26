<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

use App\Domain\Engagement\Services\Scoring\ScoreBoard;
use JsonException;

/**
 * The keyset position in a table of the word points v2 ({@see ScoreBoard}, docs/32 §5.3): the order `points desc, long_words desc,
 * active_days desc, learner_id asc` of the last row shown. Travels to the client as `?after=` - base64url of `{"p":…,"l":…,"d":…,
 * "id":…}`. It is untrusted input: {@see decode()} takes only four non-negative integers in range and answers null to anything else
 * (the caller then shows the first page or refuses with 422 - never a query built from the string).
 */
final readonly class BoardCursor
{
    private const MAX_INT = 2_147_483_647;

    public function __construct(
        public int $points,
        public int $longWords,
        public int $activeDays,
        public int $learnerId,
    ) {}

    public function encode(): string
    {
        $json = json_encode(['p' => $this->points, 'l' => $this->longWords, 'd' => $this->activeDays, 'id' => $this->learnerId], JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    public static function decode(?string $value): ?self
    {
        if ($value === null || $value === '' || strlen($value) > 200 || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            return null;
        }

        $json = base64_decode(strtr($value, '-_', '+/'), true);
        if ($json === false) {
            return null;
        }

        try {
            $data = json_decode($json, true, 2, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($data) || array_keys($data) !== ['p', 'l', 'd', 'id']) {
            return null;
        }
        foreach ($data as $part) {
            if (! is_int($part) || $part < 0 || $part > self::MAX_INT) {
                return null;
            }
        }

        return new self($data['p'], $data['l'], $data['d'], $data['id']);
    }
}
