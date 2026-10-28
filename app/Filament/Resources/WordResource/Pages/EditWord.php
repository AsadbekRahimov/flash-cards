<?php

declare(strict_types=1);

namespace App\Filament\Resources\WordResource\Pages;

use App\Domain\Content\Services\LessonContentRefresher;
use App\Domain\Content\Services\WordTranslationWriter;
use App\Filament\Resources\WordResource;
use App\Models\Word;
use Filament\Resources\Pages\EditRecord;

class EditWord extends EditRecord
{
    protected static string $resource = WordResource::class;

    public function getTitle(): string
    {
        return __('admin.words.titles.edit');
    }

    /** The ru text lives in `words.translation` and in the ru translation row: keep them equal. */
    protected function afterSave(): void
    {
        /** @var Word $word */
        $word = $this->getRecord();

        $textChanged = $word->wasChanged('word');
        $activeChanged = $word->wasChanged('is_active');

        app(WordTranslationWriter::class)->syncDefaultFromWord($word);

        // The pre-generated audio belongs to the old text: drop it and voice the new one.
        if ($textChanged) {
            app(LessonContentRefresher::class)->wordTextChanged($word);
        }

        // An inactive word leaves cards and exams, so the lesson cache and the course counter must follow.
        if ($activeChanged) {
            app(LessonContentRefresher::class)->wordChanged($word);
        }
    }
}
