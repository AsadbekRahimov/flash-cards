<?php

declare(strict_types=1);

namespace App\Filament\Resources\PollResource\Pages;

use App\Domain\Polls\Exceptions\PollEditException;
use App\Domain\Polls\Services\PollEditor;
use App\Filament\Resources\PollResource;
use App\Filament\Resources\PollResource\Support\PollRunActions;
use App\Filament\Resources\Support\AdminErrors;
use App\Models\Poll;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

/**
 * The form of a poll and its life: «Отправить себе», «Запустить», «Остановить рассылку», «Закрыть сейчас», «Результаты»,
 * «Удалить» (a draft) - {@see PollRunActions}. Saving goes through PollEditor, which refuses on the server what a launched poll
 * may not change.
 */
class EditPoll extends EditRecord
{
    protected static string $resource = PollResource::class;

    public function getTitle(): string
    {
        /** @var Poll $poll */
        $poll = $this->getRecord();

        return __('admin.polls.titles.edit', ['title' => $poll->title]);
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return PollRunActions::all();
    }

    /**
     * The options of the repeater (`label.{ru,uz}` by position).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Poll $poll */
        $poll = $this->getRecord();
        $data['options'] = [];
        foreach ($poll->options()->get(['id', 'position', 'label']) as $option) {
            $data['options'][] = ['label' => ['ru' => (string) ($option->label['ru'] ?? ''), 'uz' => (string) ($option->label['uz'] ?? '')]];
        }

        return $data;
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Poll $record */
        try {
            return app(PollEditor::class)->update($record, $data);
        } catch (PollEditException $e) {
            Notification::make()->title(AdminErrors::text('polls', $e->reason))->danger()->send();
            throw (new Halt)->rollBackDatabaseTransaction();
        }
    }
}
