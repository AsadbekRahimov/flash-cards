<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

use App\Domain\Engagement\Enums\ScoreCategory;

/**
 * The `?after=` of a page of a table of the word points v2 as it travels to the client (docs/04 A.19, docs/32 §5.3): the keyset
 * position ({@see BoardCursor}) bound to the table it was read from - `m{month}{f|p}.{cursor}`. A cursor of another month or of the
 * other category is refused like a broken one (422 `invalid_cursor`): continuing a list with a position of another list would show
 * a page that matches nothing the client has on its screen. The scope is not a secret (the client knows its month and category); it
 * only keeps two lists apart.
 */
final readonly class BoardPageCursor
{
    public static function encode(int $monthId, ScoreCategory $category, BoardCursor $cursor): string
    {
        return self::tag($monthId, $category).'.'.$cursor->encode();
    }

    /** The position, when `$value` is a well-formed cursor of exactly this table; null otherwise. */
    public static function decode(?string $value, int $monthId, ScoreCategory $category): ?BoardCursor
    {
        if ($value === null || strlen($value) > 250) {
            return null;
        }

        $prefix = self::tag($monthId, $category).'.';

        return str_starts_with($value, $prefix) ? BoardCursor::decode(substr($value, strlen($prefix))) : null;
    }

    private static function tag(int $monthId, ScoreCategory $category): string
    {
        return 'm'.$monthId.($category === ScoreCategory::Pro ? 'p' : 'f');
    }
}
