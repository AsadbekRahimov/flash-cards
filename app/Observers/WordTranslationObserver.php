<?php

declare(strict_types=1);

namespace App\Observers;

use App\Domain\Content\Services\WordHookResolver;
use App\Domain\Content\Services\WordTranslationResolver;
use App\Models\WordTranslation;
use Illuminate\Support\Facades\DB;

/**
 * The cached translation rows of a word (docs/19 K4) are dropped after the commit of whatever saves or deletes one of its
 * `word_translations`: the manual writer of the admin panel (`WordTranslationWriter`, also when an edit of the word's own ru
 * text is mirrored), the importer, the decks of the learners. Registered in AppServiceProvider; a query-builder write bypasses
 * model events and must call `WordTranslationResolver::forget()` itself.
 *
 * An import writes thousands of rows in one transaction, so the words are GATHERED and the store is asked once: every row
 * adds its word to the batch and registers a callback for the commit, the first callback that runs drops the whole batch with
 * one call (each word once) and the others find it empty. A callback per row, not one for the batch: the callbacks of a
 * rolled-back transaction are discarded, and a batch "armed" by a rolled-back row would leave the rows of the next transaction
 * without a callback. A word that a rollback left in the batch is dropped by the next commit as well - a reload, never a stale
 * text. The batch lives in this object, which Eloquent makes anew for every event unless the container keeps one: it is a
 * scoped binding of AppServiceProvider (one instance per request or job).
 */
final class WordTranslationObserver
{
    /** @var array<int, true> the words whose rows changed since the last drop, by id */
    private array $batch = [];

    public function __construct(
        private readonly WordTranslationResolver $translations,
        private readonly WordHookResolver $hooks,
    ) {}

    public function saved(WordTranslation $translation): void
    {
        $this->forgetAfterCommit($translation);
    }

    public function deleted(WordTranslation $translation): void
    {
        $this->forgetAfterCommit($translation);
    }

    private function forgetAfterCommit(WordTranslation $translation): void
    {
        $this->batch[(int) $translation->word_id] = true;

        // A row moved to another word (never done today): both words change.
        if ($translation->wasChanged('word_id')) {
            $this->batch[(int) $translation->getOriginal('word_id')] = true;
        }

        // After the commit, and never a reason to fail it: the rows are written, a cache that cannot be reached is reported and
        // the entries expire by themselves.
        DB::afterCommit(fn () => rescue($this->dropBatch(...)));
    }

    private function dropBatch(): void
    {
        $wordIds = array_keys($this->batch);
        $this->batch = [];

        if ($wordIds !== []) {
            $this->translations->forget($wordIds);
            // The hooks ride in the same rows (docs/06 §12.3) and have their own entry.
            $this->hooks->forget($wordIds);
        }
    }
}
