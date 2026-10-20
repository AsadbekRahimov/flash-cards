<?php

declare(strict_types=1);

namespace App\Filament\Org\Resources\MemberResource\Pages;

use App\Filament\Org\Resources\MemberResource;
use Filament\Resources\Pages\ListRecords;

class ListMembers extends ListRecords
{
    protected static string $resource = MemberResource::class;
}
