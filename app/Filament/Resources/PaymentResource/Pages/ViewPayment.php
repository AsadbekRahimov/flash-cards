<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentResource\Pages;

use App\Filament\Resources\PaymentResource;
use App\Filament\Resources\PaymentResource\Support\PaymentActions;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewPayment extends ViewRecord
{
    protected static string $resource = PaymentResource::class;

    public function getTitle(): string
    {
        return __('admin.payments.titles.view');
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [PaymentActions::refund()];
    }
}
