<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromoCodeResource\Pages;

use App\Domain\Billing\Services\PromoCodeAdminService;
use App\Filament\Resources\PromoCodeResource;
use App\Models\PromoCode;
use App\Models\User;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditPromoCode extends EditRecord
{
    protected static string $resource = PromoCodeResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }

    /**
     * The change and its audit record are PromoCodeAdminService's job; the code, plan and counter are not editable.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var PromoCode $record */
        /** @var User $user */
        $user = auth()->user();

        return app(PromoCodeAdminService::class)->update($record, $data, $user, request()->ip());
    }
}
