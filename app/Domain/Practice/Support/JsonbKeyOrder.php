<?php

declare(strict_types=1);

namespace App\Domain\Practice\Support;

/**
 * Puts the keys of every JSON object in the order PostgreSQL's `jsonb` keeps them: the shorter key first, then bytewise.
 * What is stored in a jsonb column and read back comes in that order whatever order it was written in; a body built from
 * the stored value and the one built in memory would differ in key order only. Normalized in advance, both are the same
 * bytes on PostgreSQL and on SQLite (the stored result of an answer is returned to a repeated request byte for byte).
 * Lists keep their order.
 */
final class JsonbKeyOrder
{
    /**
     * An object (an array with string keys) with its keys, and the keys of everything inside it, in the order of jsonb.
     *
     * @param  array<array-key, mixed>  $object
     * @return array<array-key, mixed>
     */
    public static function object(array $object): array
    {
        $keys = array_keys($object);
        usort($keys, static fn (int|string $a, int|string $b): int => (strlen((string) $a) <=> strlen((string) $b)) ?: strcmp((string) $a, (string) $b));

        $ordered = [];
        foreach ($keys as $key) {
            $ordered[$key] = self::normalize($object[$key]);
        }

        return $ordered;
    }

    /** Any JSON value: lists are walked in their order, objects get {@see object()}, scalars pass. */
    public static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::normalize(...), $value);
        }

        return self::object($value);
    }
}
