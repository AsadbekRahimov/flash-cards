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

class EditPracticeItem extends EditRecord
{
    protected static string $resource = PracticeItemResource::class;

    public function getTitle(): string
    {
        return __('admin.practice_items.titles.edit');
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return PracticeItemActions::all();
    }

    /**
     * The form shows the text of the CURRENT version (a draft that is waiting, if there is one), not the columns of the task.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var PracticeItem $record */
        $record = $this->getRecord();

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

        return $saved->item;
    }
}
