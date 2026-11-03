<?php

declare(strict_types=1);

namespace App\Filament\Resources\GameItemResource\Pages;

use App\Filament\Resources\GameItemResource;
use App\Models\GameItem;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditGameItem extends EditRecord
{
    protected static string $resource = GameItemResource::class;

    public function getTitle(): string
    {
        return __('admin.game.items.titles.edit');
    }

    /**
     * Saved through Eloquent (the observer moves the version of the cached catalogue on). An item that was given to everybody and no
     * longer is - a price above zero or another availability - is a change the learners notice in their wardrobe: the admin is told.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var GameItem $record */
        $wasDefault = GameItemResource::givenToEveryone($record);

        $record->update(GameItemResource::normalize($data, $record->kind));

        if ($wasDefault && ! GameItemResource::givenToEveryone($record)) {
            Notification::make()
                ->title(__('admin.game.items.notifications.default_lost'))
                ->body(__('admin.game.items.notifications.default_lost_body'))
                ->warning()
                ->persistent()
                ->send();
        }

        return $record;
    }
}
