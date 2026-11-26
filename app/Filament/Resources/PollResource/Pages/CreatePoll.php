<?php

declare(strict_types=1);

namespace App\Filament\Resources\PollResource\Pages;

use App\Domain\Polls\Exceptions\PollEditException;
use App\Domain\Polls\Services\PollEditor;
use App\Filament\Resources\PollResource;
use App\Filament\Resources\Support\AdminErrors;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreatePoll extends CreateRecord
{
    protected static string $resource = PollResource::class;

    public function getTitle(): string
    {
        return __('admin.polls.titles.create');
    }

    /**
     * A draft is written by PollEditor (the checks of the server, the options, the creator).
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $user */
        $user = auth()->user();

        try {
            return app(PollEditor::class)->create($data, $user);
        } catch (PollEditException $e) {
            Notification::make()->title(AdminErrors::text('polls', $e->reason))->danger()->send();
            throw (new Halt)->rollBackDatabaseTransaction();
        }
    }

    protected function getRedirectUrl(): string
    {
        return PollResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
