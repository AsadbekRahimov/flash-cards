<?php

declare(strict_types=1);

namespace App\Filament\Org\Pages;

/** Assignments (docs/13 §6.4) — the screen arrives with task 2B.4; every staff role will use it. */
class Assignments extends StubPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?int $navigationSort = 50;

    public static function getNavigationLabel(): string
    {
        return __('org.nav.assignments');
    }

    public function getTitle(): string
    {
        return __('org.nav.assignments');
    }

    public function message(): string
    {
        return __('org.stubs.assignments');
    }
}
