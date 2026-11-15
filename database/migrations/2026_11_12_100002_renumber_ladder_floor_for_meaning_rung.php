<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * docs/06 §12.1 — the ladder got a rung between the self-graded flash and the four English options: the choice of the
 * meaning (`meaning`). The rungs are numbered flash 0 → meaning 1 → choice 2 → typed 3 (LadderPolicy), and
 * `word_repetitions.meta.ladder_floor` (the ceiling a lapse left, docs/06 §12.1) stores a rung number: the stored
 * 1 (the options) and 2 (typed) move up by one so every card keeps the rung it was failing at. Nothing else of the
 * row changes; rows without a floor are not touched. `down()` moves them back, and a floor at the new rung 1 — which
 * has no counterpart in the old numbering — is dropped (the card is served by its maturity, flash at worst).
 */
return new class extends Migration
{
    private const KEY = 'ladder_floor';

    public function up(): void
    {
        $this->remap([1 => 2, 2 => 3]);
    }

    public function down(): void
    {
        $this->remap([3 => 2, 2 => 1, 1 => null]);
    }

    /**
     * @param  array<int, int|null>  $map  old floor => new floor (null = remove the key); other values are left as they are
     */
    private function remap(array $map): void
    {
        DB::table('word_repetitions')
            ->select(['id', 'meta'])
            ->whereJsonContainsKey('meta->'.self::KEY)
            ->orderBy('id')
            ->chunkById(1000, function ($rows) use ($map): void {
                foreach ($rows as $row) {
                    $meta = is_string($row->meta) ? json_decode($row->meta, true) : $row->meta;
                    if (! is_array($meta) || ! is_numeric($meta[self::KEY] ?? null)) {
                        continue;
                    }
                    $floor = (int) $meta[self::KEY];
                    if (! array_key_exists($floor, $map)) {
                        continue;
                    }
                    if ($map[$floor] === null) {
                        unset($meta[self::KEY]);
                    } else {
                        $meta[self::KEY] = $map[$floor];
                    }

                    DB::table('word_repetitions')->where('id', $row->id)->update(['meta' => json_encode($meta, JSON_THROW_ON_ERROR)]);
                }
            });
    }
};
