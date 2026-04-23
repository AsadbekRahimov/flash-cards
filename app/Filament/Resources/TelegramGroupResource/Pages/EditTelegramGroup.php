<?php

declare(strict_types=1);

namespace App\Filament\Resources\TelegramGroupResource\Pages;

use App\Filament\Resources\TelegramGroupResource;
use Filament\Resources\Pages\EditRecord;

class EditTelegramGroup extends EditRecord
{
    protected static string $resource = TelegramGroupResource::class;
}
