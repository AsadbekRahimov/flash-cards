<?php

declare(strict_types=1);

namespace App\Domain\Learning\DTO;

use JsonException;

/**
 * The keyset position in "My vocabulary" (docs/40 §2.3, docs/04 A.25): the order `lower(words.word), word_repetitions.id` of the last
 * row shown. Travels to the client as `?after=` - base64url of `{"w": "<lower word as the database folded it>", "id": <row id>}`, at
 * most {@see MAX_LENGTH} characters: a word is up to 100 characters (`words.word`), up to 4 bytes each in UTF-8, so the longest honest
 * cursor is ~575 characters (200 would cut a long phrase with non-ASCII letters). Untrusted input: {@see decode()} takes only that shape and answers null to anything else (the
 * service then refuses with 422 - the string never becomes SQL, both parts go as bindings).
 */
final readonly class VocabularyCursor
{
    public const MAX_LENGTH = 600;

    private const MAX_ID = 9_223_372_036_854_775_807;

    public function __construct(
        public string $word,
        public int $id,
    ) {}

    public function encode(): string
    {
        $json = json_encode(['w' => $this->word, 'id' => $this->id], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    public static function decode(?string $value): ?self
    {
        if ($value === null || $value === '' || strlen($value) > self::MAX_LENGTH || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
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

        if (! is_array($data) || array_keys($data) !== ['w', 'id'] || ! is_string($data['w']) || ! is_int($data['id'])
            || $data['id'] < 1 || $data['id'] > self::MAX_ID || mb_check_encoding($data['w'], 'UTF-8') === false) {
            return null;
        }

        return new self($data['w'], $data['id']);
    }
}
