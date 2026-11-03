<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Administration\Services\WidgetCache;
use App\Domain\Game\Services\GameActivityStats;
use App\Filament\Concerns\RequiresActiveAdmin;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * "Караван — 7 дней" (docs/18 §12.10, §12.12): runs started, learners who played, and their share among the active learners.
 * Admin only: platform-wide numbers. The entry `admin:widget:game_activity:v1:all` ({@see WidgetCache}): scalars, fresh for 300 s.
 */
class GameActivityWidget extends StatsOverviewWidget
{
    use RequiresActiveAdmin;

    public const WIDGET = 'game_activity';

    public const FRESH_SECONDS = 300;

    public static function canView(): bool
    {
        return self::isActiveAdmin();
    }

    protected static ?int $sort = 8;

    // No polling (docs/20 §1.11): the figures are cached anyway.
    protected ?string $pollingInterval = null;

    protected function getHeading(): string
    {
        return __('admin.widgets.game_activity.heading');
    }

    /** @return array<int, Stat> */
    protected function getStats(): array
    {
        /** @var array{runs: int, players: int, active: int, share: int} $figures */
        $figures = app(WidgetCache::class)->remember(self::WIDGET, 'all', self::FRESH_SECONDS, fn (): array => app(GameActivityStats::class)->lastWeek());

        return [
            Stat::make(__('admin.widgets.game_activity.runs'), (string) $figures['runs'])
                ->description(__('admin.widgets.game_activity.runs_help'))
                ->icon('heroicon-o-flag'),
            Stat::make(__('admin.widgets.game_activity.players'), (string) $figures['players'])
                ->description(__('admin.widgets.game_activity.players_help'))
                ->icon('heroicon-o-users'),
            Stat::make(__('admin.widgets.game_activity.share'), $figures['share'].' %')
                ->description(__('admin.widgets.game_activity.share_help', ['active' => $figures['active']]))
                ->color($figures['share'] > 0 ? 'success' : 'gray')
                ->icon('heroicon-o-chart-pie'),
        ];
    }
}
