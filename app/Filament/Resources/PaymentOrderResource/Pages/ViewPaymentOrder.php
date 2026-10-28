<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentOrderResource\Pages;

use App\Filament\Resources\PaymentOrderResource;
use Filament\Resources\Pages\ViewRecord;

class ViewPaymentOrder extends ViewRecord
{
    protected static string $resource = PaymentOrderResource::class;

    public function getTitle(): string
    {
        return __('admin.orders.titles.view');
    }
}
