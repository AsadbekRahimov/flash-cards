<?php

declare(strict_types=1);

namespace App\Filament\Org\Pages;

use Filament\Pages\Page;

/**
 * Placeholder screen of the cabinet until its task lands (docs/13 §11: Billing 2B.6).
 * Shows a short notice and, optionally, a few facts (`rows()`).
 */
abstract class StubPage extends Page
{
    protected string $view = 'filament.org.pages.stub';

    abstract public function message(): string;

    /**
     * Label => value facts shown above the notice.
     *
     * @return array<string, string>
     */
    public function rows(): array
    {
        return [];
    }
}
