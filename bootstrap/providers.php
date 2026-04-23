<?php

$providers = [
    App\Providers\AppServiceProvider::class,
];

// The Filament panel provider is excluded in the `testing` env so that
// Filament resource discovery does not gate backend tests on the
// ongoing Filament v5.6 API migration (form()/schema() signatures).
// Set FILAMENT_PROVIDER_IN_TESTS=1 to force-register it.
if (env('APP_ENV') !== 'testing' || env('FILAMENT_PROVIDER_IN_TESTS', false)) {
    $providers[] = App\Providers\Filament\AdminPanelProvider::class;
}

return $providers;
