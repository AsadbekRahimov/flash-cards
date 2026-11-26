<?php

declare(strict_types=1);

namespace App\Filament\Resources\CupResource\Pages;

use App\Filament\Resources\CupResource;
use App\Models\Cup;
use App\Models\TelegramGroup;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewCup extends ViewRecord
{
    protected static string $resource = CupResource::class;

    public function getTitle(): string
    {
        return __('admin.cups.titles.view', ['id' => $this->cup()->id]);
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        $group = fn (): TelegramGroup => $this->cup()->telegramGroup;

        return [
            CupResource::cancelAction()
                ->record(fn (): Cup => $this->cup())
                ->after(fn () => $this->refreshCup()),
            CupResource::blockAction(fn (): TelegramGroup => $group())
                ->after(fn () => $this->refreshCup()),
        ];
    }

    private function cup(): Cup
    {
        /** @var Cup $cup */
        $cup = $this->getRecord();

        return $cup;
    }

    private function refreshCup(): void
    {
        $this->record = CupResource::getEloquentQuery()->findOrFail($this->cup()->id);
    }
}
