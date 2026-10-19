<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromoCodeResource\Pages;

use App\Domain\Billing\Services\PromoCodeAdminService;
use App\Filament\Resources\PromoCodeResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePromoCode extends CreateRecord
{
    protected static string $resource = PromoCodeResource::class;

    /**
     * Creation (normalized code, creator, audit record) is PromoCodeAdminService's job.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $user */
        $user = auth()->user();

        /** @var array{code: string, plan: string, duration_days: int, max_redemptions?: int|null, expires_at?: mixed, is_active?: bool, note?: string|null} $data */
        return app(PromoCodeAdminService::class)->create($data, $user, request()->ip());
    }
}
