<?php

declare(strict_types=1);

namespace App\Domain\Practice\Support;

use Illuminate\Support\Str;

/**
 * The opaque ids of a snapshot (docs/16 §0, §4.6): a one-letter prefix and 10 random characters of a 62-character alphabet
 * (`e` an element, `f` a fragment, `o` an option, `l` / `r` the sides of pairs). They carry no order and no word id, so
 * nothing about the answer can be read from them; a client sends them back and the server looks them up in the snapshot.
 */
final class OpaqueId
{
    public const LENGTH = 10;

    /**
     * A new id that is not among `$taken` (a repeat - 62^10 makes it a curiosity - draws another string).
     *
     * @param  list<string>  $taken  the ids already used in the snapshot
     */
    public static function make(string $prefix, array $taken = []): string
    {
        do {
            $id = $prefix.Str::random(self::LENGTH);
        } while (in_array($id, $taken, true));

        return $id;
    }
}
