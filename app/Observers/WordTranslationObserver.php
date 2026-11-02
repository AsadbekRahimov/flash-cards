<?php

declare(strict_types=1);

namespace App\Observers;

use App\Domain\Content\Services\WordTranslationResolver;
use App\Models\WordTranslation;
use Illuminate\Support\Facades\DB;

/**
 * The cached translation rows of a word (docs/19 K4) are dropped after the commit of whatever saves or deletes one of its
 * `word_translations`: the manual writer of the admin panel (`WordTranslationWriter`, also when an edit of the word's own ru
 * text is mirrored), the importer, the decks of the learners. Registered in AppServiceProvider; a query-builder write bypasses
 * model events and must call `WordTranslationResolver::forget()` itself.
 */
final class WordTranslationObserver
{
    public function __construct(private readonly WordTranslationResolver $translations) {}

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
        $wordIds = [(int) $translation->word_id];

        // A row moved to another word (never done today): both words change.
        if ($translation->wasChanged('word_id')) {
            $wordIds[] = (int) $translation->getOriginal('word_id');
        }

        DB::afterCommit(fn () => $this->translations->forget($wordIds));
    }
}
