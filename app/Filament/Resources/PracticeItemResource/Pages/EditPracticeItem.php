<?php

declare(strict_types=1);

namespace App\Filament\Resources\PracticeItemResource\Pages;

use App\Domain\Practice\Exceptions\PracticeContentException;
use App\Domain\Practice\Exceptions\PracticeItemStateException;
use App\Domain\Practice\Services\PracticeItemService;
use App\Filament\Resources\PracticeItemResource;
use App\Filament\Resources\PracticeItemResource\Support\PracticeItemActions;
use App\Filament\Resources\PracticeItemResource\Support\PracticeItemFormMapper;
use App\Models\PracticeItem;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;

class EditPracticeItem extends EditRecord
{
    protected static string $resource = PracticeItemResource::class;

    /**
     * The version the form shows. The record is read again from the database with every request, the form is not: "Проверено" and
     * "Опубликовать" act on THIS version (PracticeItemService refuses when the task has a newer one), whatever the page was redrawn
     * with since it was opened.
     */
    #[Locked]
    public ?int $shownVersionId = null;

    public function getTitle(): string
    {
        return __('admin.practice_items.titles.edit');
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return PracticeItemActions::all(fn (): ?int => $this->shownVersionId);
    }

    /**
     * The form shows the place, the target words and the text of the CURRENT version (a draft that is waiting, if there is one), not
     * the columns and the words of the task, which are those of the published version.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var PracticeItem $record */
        $record = $this->getRecord();
        $this->shownVersionId = $record->current_version_id;

        return PracticeItemFormMapper::fill($record);
    }

    /**
     * Saving writes a NEW version (nothing is changed in an old one) through PracticeItemService. The course, the type and the
     * key are the task's own whatever the form says; the status is not touched, only the buttons move it.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var PracticeItem $record */
        /** @var User $user */
        $user = auth()->user();

        try {
            $saved = app(PracticeItemService::class)->save(
                $record,
                $record->course()->firstOrFail(),
                PracticeItemFormMapper::toData($data, $record->type),
                $user,
            );
        } catch (PracticeContentException|PracticeItemStateException $e) {
            PracticeItemActions::refused($e);

            $this->halt();

            throw $e;
        }

        $saved->item->unsetRelations();
        // What was just saved is what the form shows now.
        $this->shownVersionId = $saved->version->id;

        return $saved->item;
    }
}
