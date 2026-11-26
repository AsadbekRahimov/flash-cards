<?php

declare(strict_types=1);

use App\Domain\Content\Support\LexemeKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A learner's deck is outside the catalog of lexemes (owner's decision of 27.11.2026, docs/32 §2.1, §12.12): the words of decks that
 * matched a lexeme of the catalog (S1: `LexemeCatalog::matchFor`, `lexemes:backfill --decks`) lose the link, so their answers give no
 * points of the rating and spend no attempt of the day of the lexeme. Data only, no schema: batches of 1 000 by `words.id` over the
 * deck words that still have a link (each batch its own short statement - `$withinTransaction = false`, no lock held across batches); a
 * run that broke off starts again and finds the rest. The credits and the `learner_lexemes` written before stay as they are (the points
 * default to `off`, there is no production yet - docs/32 §12.12).
 *
 * `down()` links the deck words again to the EXISTING lexeme of their spelling (what the deck did before the decision; none is created).
 */
return new class extends Migration
{
    /** @var bool */
    public $withinTransaction = false;

    private const BATCH = 1000;

    public function up(): void
    {
        $after = 0;
        do {
            $ids = $this->deckWords($after, linked: true)->pluck('words.id')->map(static fn (mixed $id): int => (int) $id)->all();
            if ($ids === []) {
                break;
            }
            $after = $ids[count($ids) - 1];
            DB::table('words')->whereIn('id', $ids)->whereNotNull('lexeme_id')->update(['lexeme_id' => null]);
        } while (count($ids) === self::BATCH);
    }

    public function down(): void
    {
        $after = 0;
        do {
            $rows = $this->deckWords($after, linked: false)->get(['words.id', 'words.word', 'courses.target_language']);
            if ($rows->isEmpty()) {
                break;
            }
            $after = (int) $rows->last()->id;

            /** @var array<string, array<int, string>> $byLanguage */
            $byLanguage = [];
            foreach ($rows as $row) {
                $key = LexemeKey::of((string) $row->word);
                if ($key !== null) {
                    $language = is_string($row->target_language) && $row->target_language !== '' ? $row->target_language : 'en';
                    $byLanguage[$language][(int) $row->id] = $key;
                }
            }
            foreach ($byLanguage as $language => $keys) {
                $lexemes = DB::table('lexemes')->where('language', $language)->whereIn('key', array_values(array_unique($keys)))->pluck('id', 'key');
                foreach ($keys as $wordId => $key) {
                    if (isset($lexemes[$key])) {
                        DB::table('words')->where('id', $wordId)->whereNull('lexeme_id')->update(['lexeme_id' => (int) $lexemes[$key]]);
                    }
                }
            }
        } while ($rows->count() === self::BATCH);
    }

    private function deckWords(int $after, bool $linked): \Illuminate\Database\Query\Builder
    {
        return DB::table('words')
            ->join('lessons', 'lessons.id', '=', 'words.lesson_id')
            ->join('stages', 'stages.id', '=', 'lessons.stage_id')
            ->join('courses', 'courses.id', '=', 'stages.course_id')
            ->where('courses.owner_type', 'learner')
            ->where('words.id', '>', $after)
            ->when($linked, fn ($q) => $q->whereNotNull('words.lexeme_id'), fn ($q) => $q->whereNull('words.lexeme_id'))
            ->orderBy('words.id')
            ->limit(self::BATCH);
    }
};
