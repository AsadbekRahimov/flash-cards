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
 * Admin only: platform-wide numbers. The entry `admin:widget:game_activity:v2:all` ({@see WidgetCache}): scalars, fresh for 300 s.
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
        /** @var array{runs: int, players: int, active: int, share: int, finished_runs: int, runs_per_player_day: float, paywall_learners: int, paywall_paid: int, paywall_percent: int, study_players_reviews: int, study_players_percent: int|null, study_others_reviews: int, study_others_percent: int|null} $figures */
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
            Stat::make(__('admin.widgets.game_activity.runs_per_player_day'), number_format($figures['runs_per_player_day'], 1, '.', ''))
                ->description(__('admin.widgets.game_activity.runs_per_player_day_help', ['runs' => $figures['finished_runs']]))
                ->icon('heroicon-o-arrow-path'),
            Stat::make(__('admin.widgets.game_activity.paywall'), __('admin.widgets.game_activity.paywall_value', [
                'paid' => $figures['paywall_paid'],
                'total' => $figures['paywall_learners'],
                'percent' => $figures['paywall_percent'],
            ]))
                ->description(__('admin.widgets.game_activity.paywall_help'))
                ->icon('heroicon-o-banknotes'),
            Stat::make(__('admin.widgets.game_activity.study'), __('admin.widgets.game_activity.study_value', [
                'players' => self::percentLabel($figures['study_players_percent']),
                'others' => self::percentLabel($figures['study_others_percent']),
            ]))
                ->description(__('admin.widgets.game_activity.study_help', [
                    'players' => $figures['study_players_reviews'],
                    'others' => $figures['study_others_reviews'],
                ]))
                ->icon('heroicon-o-academic-cap'),
        ];
    }

    private static function percentLabel(?int $percent): string
    {
        return $percent === null ? '—' : $percent.' %';
    }
}
