<?php

declare(strict_types=1);

namespace App\Filament\Org\Resources\InviteResource\Pages;

use App\Filament\Org\Resources\InviteResource;
use Filament\Resources\Pages\ListRecords;

class ListInvites extends ListRecords
{
    protected static string $resource = InviteResource::class;
}
