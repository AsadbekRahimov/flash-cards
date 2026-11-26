<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

use App\Domain\Engagement\Enums\ScoreCategory;

/**
 * The `?after=` of a page of a table of the word points v2 as it travels to the client (docs/04 A.19, A.19a, docs/32 §5.3): the keyset
 * position ({@see BoardCursor}) bound to the table it was read from:
 *
 *   a category of a month           `m{month}{f|p}.{cursor}`
 *   ... of a region of it           `m{month}{f|p}~{region}.{cursor}`
 *   a category of "all time"        `a{f|p}.{cursor}`
 *   ... of a region                 `a{f|p}~{region}.{cursor}`
 *
 * A cursor of another table (another month, category, region or period) is refused like a broken one (422 `invalid_cursor`):
 * continuing a list with a position of another list would show a page that matches nothing the client has on its screen. The table is
 * not a secret (the client knows what it asked for); the tag only keeps the lists apart.
 */
final readonly class BoardPageCursor
{
    public static function encode(int $monthId, ScoreCategory $category, BoardCursor $cursor, ?string $region = null): string
    {
        return self::monthTag($monthId, $category, $region).'.'.$cursor->encode();
    }

    /** The position, when `$value` is a well-formed cursor of exactly this table of the month; null otherwise. */
    public static function decode(?string $value, int $monthId, ScoreCategory $category, ?string $region = null): ?BoardCursor
    {
        return self::decodeTagged($value, self::monthTag($monthId, $category, $region));
    }

    public static function encodeAllTime(ScoreCategory $category, BoardCursor $cursor, ?string $region = null): string
    {
        return self::allTimeTag($category, $region).'.'.$cursor->encode();
    }

    /** The position, when `$value` is a well-formed cursor of exactly this table of "all time"; null otherwise. */
    public static function decodeAllTime(?string $value, ScoreCategory $category, ?string $region = null): ?BoardCursor
    {
        return self::decodeTagged($value, self::allTimeTag($category, $region));
    }

    private static function decodeTagged(?string $value, string $tag): ?BoardCursor
    {
        if ($value === null || strlen($value) > 250) {
            return null;
        }

        $prefix = $tag.'.';

        return str_starts_with($value, $prefix) ? BoardCursor::decode(substr($value, strlen($prefix))) : null;
    }

    private static function monthTag(int $monthId, ScoreCategory $category, ?string $region): string
    {
        return 'm'.$monthId.self::categoryTag($category).self::regionTag($region);
    }

    private static function allTimeTag(ScoreCategory $category, ?string $region): string
    {
        return 'a'.self::categoryTag($category).self::regionTag($region);
    }

    private static function categoryTag(ScoreCategory $category): string
    {
        return $category === ScoreCategory::Pro ? 'p' : 'f';
    }

    private static function regionTag(?string $region): string
    {
        return $region === null ? '' : '~'.$region;
    }
}
