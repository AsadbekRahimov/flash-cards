<?php

declare(strict_types=1);

namespace App\Domain\Learning\Support;

use App\Models\Word;
use App\Models\WordRepetition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A `word_repetitions` row and its active word in ONE statement (docs/06 §4.2, §5.1).
 *
 * The card flow used to read the repetition and then its word (`with('word')`): two round trips per pick and per
 * grade. Here `words` is joined - with the scope's own conditions, so the join IS the scope check - and its columns
 * ride along as `word__<column>`, the way CourseCatalogService carries a lesson's stage and course. {@see hydrate()}
 * splits them off again, so the repetition is the model a plain query would give and the word is a plain Word.
 *
 * Locks: {@see lockRepetition()} takes the row lock of `word_repetitions` ONLY. A bare `FOR UPDATE` on a join would
 * lock the `words` row too - one row shared by every learner of the lesson, a global serialisation point - so on
 * PostgreSQL the lock names its table (`FOR UPDATE OF "word_repetitions"`); sqlite locks the whole database anyway.
 */
final class RepetitionWithWord
{
    private const PREFIX = 'word__';

    /** Every column of `words` (docs/03); a column added to the table is added here, or the Word in hand lacks it. */
    private const WORD_COLUMNS = [
        'id', 'lesson_id', 'word', 'translation', 'example', 'part_of_speech', 'transcription', 'audio_path',
        'is_active', 'meta', 'created_at', 'updated_at',
    ];

    /**
     * The learner's repetitions of the ACTIVE words of the scope, each row carrying its word.
     *
     * @return Builder<WordRepetition>
     */
    public static function query(int $learnerId, TrainingScope $scope): Builder
    {
        return self::withWord(WordRepetition::query()->where('word_repetitions.learner_id', $learnerId), $scope);
    }

    /**
     * Adds the join and the word columns to a `word_repetitions` query; the scope's conditions go into the join.
     *
     * @param  Builder<WordRepetition>  $query
     * @return Builder<WordRepetition>
     */
    public static function withWord(Builder $query, ?TrainingScope $scope = null): Builder
    {
        return $query
            ->select('word_repetitions.*')
            ->addSelect(array_map(static fn (string $column): string => "words.{$column} as ".self::PREFIX.$column, self::WORD_COLUMNS))
            ->join('words', function ($join) use ($scope): void {
                $join->on('words.id', '=', 'word_repetitions.word_id');
                if ($scope !== null) {
                    $join->where('words.is_active', true);
                    if ($scope->isLesson()) {
                        $join->where('words.lesson_id', $scope->lessonId);
                    }
                }
            });
    }

    /**
     * `FOR UPDATE` of the repetition row alone (see the class docblock).
     *
     * @param  Builder<WordRepetition>  $query
     * @return Builder<WordRepetition>
     */
    public static function lockRepetition(Builder $query): Builder
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? $query->lock('for update of "word_repetitions"')
            : $query->lockForUpdate();
    }

    /**
     * Takes the `word__*` columns out of the repetition and returns them as the Word; null when the row carried
     * none (a query built without {@see withWord()}).
     */
    public static function hydrate(WordRepetition $repetition): ?Word
    {
        $attributes = $repetition->getAttributes();
        $word = [];
        foreach (self::WORD_COLUMNS as $column) {
            $key = self::PREFIX.$column;
            if (array_key_exists($key, $attributes)) {
                $word[$column] = $attributes[$key];
                unset($attributes[$key]);
            }
        }

        if ($word === []) {
            return null;
        }

        $repetition->setRawAttributes($attributes, true);

        /** @var Word $model */
        $model = (new Word)->newFromBuilder($word, $repetition->getConnectionName());
        $repetition->setRelation('word', $model);

        return $model;
    }
}
