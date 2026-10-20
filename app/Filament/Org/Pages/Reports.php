<?php

declare(strict_types=1);

namespace App\Filament\Org\Pages;

/** Activity report (docs/13 §6.4) — the screen arrives with task 2B.5; every staff role will use it. */
class Reports extends StubPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?int $navigationSort = 60;

    public static function getNavigationLabel(): string
    {
        return __('org.nav.reports');
    }

    public function getTitle(): string
    {
        return __('org.nav.reports');
    }

    public function message(): string
    {
        return __('org.stubs.reports');
    }
}
