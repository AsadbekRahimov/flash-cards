<?php

declare(strict_types=1);

namespace App\Filament\Resources\GameItemResource\Pages;

use App\Domain\Game\Enums\ItemKind;
use App\Domain\Game\Enums\ItemStatus;
use App\Filament\Resources\GameItemResource;
use Filament\Resources\Pages\CreateRecord;

class CreateGameItem extends CreateRecord
{
    protected static string $resource = GameItemResource::class;

    public function getTitle(): string
    {
        return __('admin.game.items.titles.create');
    }

    /**
     * A new item is a draft; "Опубликовать" puts it on the shelf. The field of the other kind is dropped (a cosmetic has a slot, a booster an effect).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = ItemStatus::Draft->value;

        return GameItemResource::normalize($data, ItemKind::from((string) $data['kind']));
    }
}
