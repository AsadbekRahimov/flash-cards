<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\FilamentDefaultsProvider;
use App\Providers\Filament\OrgPanelProvider;
use App\Support\Surface;

// Required by the RegisterProviders bootstrapper, after .env is loaded: the `api` surface (the `app-api` container,
// APP_SURFACE=api) registers the application's own provider only - no panels (App\Support\Surface).
if (Surface::isApi()) {
    return [
        AppServiceProvider::class,
    ];
}

return [
    AppServiceProvider::class,
    FilamentDefaultsProvider::class,
    AdminPanelProvider::class,
    OrgPanelProvider::class,
];
