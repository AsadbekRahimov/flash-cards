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

        // Audio, the lesson cache and (for `is_active`) the course counter follow the edit; read before the sync below.
        app(LessonContentRefresher::class)->wordEdited($word);

        app(WordTranslationWriter::class)->syncDefaultFromWord($word);
    }
}
