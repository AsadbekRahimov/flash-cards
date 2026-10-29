<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\FilamentDefaultsProvider;
use App\Providers\Filament\OrgPanelProvider;

return [
    AppServiceProvider::class,
    FilamentDefaultsProvider::class,
    AdminPanelProvider::class,
    OrgPanelProvider::class,
];
