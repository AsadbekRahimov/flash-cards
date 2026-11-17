<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Analytics\Services\RetentionReportService;
use App\Filament\Concerns\RequiresActiveAdmin;
use Filament\Actions\Action;
use Filament\Pages\Page;

/**
 * «Удержание» (docs/ADMIN_GUIDE.md): weekly registration cohorts with D1-D30, the first training session and D1 / D7 by
 * language and trial, so a change of the training can be judged by whether people come back. A thin page over
 * {@see RetentionReportService} (cached, a constant number of queries); platform data, so an active admin only
 * (the same rule as the dashboard widgets, {@see RequiresActiveAdmin}).
 */
class RetentionReport extends Page
{
    use RequiresActiveAdmin;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?int $navigationSort = 16;

    protected static ?string $slug = 'retention';

    protected string $view = 'filament.pages.retention-report';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.administration');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.retention.nav');
    }

    public function getTitle(): string
    {
        return __('admin.retention.title');
    }

    public static function canAccess(): bool
    {
        return self::isActiveAdmin();
    }

    /** @return array<string, mixed> */
    public function report(): array
    {
        return app(RetentionReportService::class)->report();
    }

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label(__('admin.retention.refresh'))
                ->icon('heroicon-o-arrow-path')
                ->action(function (): void {
                    app(RetentionReportService::class)->forget();
                }),
        ];
    }
}
