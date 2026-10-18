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

    /** The ru text lives in `words.translation` and in the ru translation row: keep them equal. */
    protected function afterSave(): void
    {
        /** @var Word $word */
        $word = $this->getRecord();

        app(WordTranslationWriter::class)->syncDefaultFromWord($word);

        // An inactive word leaves cards and exams, so the lesson cache and the course counter must follow.
        if ($word->wasChanged('is_active')) {
            app(LessonContentRefresher::class)->wordChanged($word);
        }
    }
}
