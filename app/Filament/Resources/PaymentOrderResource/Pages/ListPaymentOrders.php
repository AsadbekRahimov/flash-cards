<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentOrderResource\Pages;

use App\Filament\Resources\PaymentOrderResource;
use Filament\Resources\Pages\ListRecords;

class ListPaymentOrders extends ListRecords
{
    protected static string $resource = PaymentOrderResource::class;
}
