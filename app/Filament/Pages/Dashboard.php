<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Analytics\Services\RetentionReportService;
use App\Models\User;
use App\Filament\Widgets\ActivityChartWidget;
use App\Filament\Widgets\AttentionWidget;
use App\Filament\Widgets\BillingFunnelWidget;
use App\Filament\Widgets\CentersFirstAssignmentWidget;
use App\Filament\Widgets\DashboardKpiWidget;
use App\Filament\Widgets\ExamsLast30DaysWidget;
use App\Filament\Widgets\ExerciseMixTableWidget;
use App\Filament\Widgets\FirstDayWidget;
use App\Filament\Widgets\GameActivityWidget;
use App\Filament\Widgets\HardestWordsTableWidget;
use App\Filament\Widgets\LeagueGroupsTableWidget;
use App\Filament\Widgets\OnboardingFunnelWidget;
use App\Filament\Widgets\OrgActivationTableWidget;
use App\Filament\Widgets\OrganizationSeatsTableWidget;
use App\Filament\Widgets\PlatformSubscriptionsWidget;
use App\Filament\Widgets\ProPathWidget;
use App\Filament\Widgets\RetentionTrendWidget;
use App\Filament\Widgets\RevenueLast30DaysWidget;
use App\Filament\Widgets\SchedulerComparisonWidget;
use App\Filament\Widgets\TopLearnersTableWidget;
use App\Filament\Widgets\TotalLearnersWidget;
use App\Filament\Widgets\WebhookErrorsWidget;
use App\Filament\Widgets\WeeklyActivityWidget;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Widgets\Widget;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;

/**
 * Home of `/admin` (the design of 2026-11): the title, the date and the switch «Учёба / Деньги»; the tab is in the query string
 * (`?tab=money`), so a link opens the same view. Only the widgets of the open tab are on the page — a widget of the other tab is not
 * mounted, so its figures are not read; every widget is lazy (docs/20 §1.11), the page itself reads no figure.
 *
 * «Деньги» is platform money, an active admin only: for anybody else the page is «Учёба» whatever the query says, and a teacher
 * sees the widgets of their own groups there (each widget keeps its own `canView`).
 */
class Dashboard extends BaseDashboard
{
    public const TAB_STUDY = 'study';

    public const TAB_MONEY = 'money';

    /** «Учёба»: the blocks of the design first, then the older learning widgets in the new style. */
    public const STUDY_WIDGETS = [
        DashboardKpiWidget::class,
        AttentionWidget::class,
        FirstDayWidget::class,
        RetentionTrendWidget::class,
        CentersFirstAssignmentWidget::class,
        ProPathWidget::class,
        WeeklyActivityWidget::class,
        TotalLearnersWidget::class,
        ExamsLast30DaysWidget::class,
        ActivityChartWidget::class,
        TopLearnersTableWidget::class,
        HardestWordsTableWidget::class,
        SchedulerComparisonWidget::class,
        OnboardingFunnelWidget::class,
        GameActivityWidget::class,
        ExerciseMixTableWidget::class,
        LeagueGroupsTableWidget::class,
        OrgActivationTableWidget::class,
    ];

    /** «Деньги»: subscriptions, revenue, webhook errors, the Pro funnel, the seats of the centers. */
    public const MONEY_WIDGETS = [
        PlatformSubscriptionsWidget::class,
        RevenueLast30DaysWidget::class,
        WebhookErrorsWidget::class,
        BillingFunnelWidget::class,
        OrganizationSeatsTableWidget::class,
    ];

    #[Url]
    public string $tab = self::TAB_STUDY;

    public static function getNavigationLabel(): string
    {
        return __('admin.dashboard.title');
    }

    public function getTitle(): string
    {
        return __('admin.dashboard.title');
    }

    /** The tab shown: «Деньги» only when asked for and the viewer is an active admin. */
    public function activeTab(): string
    {
        return $this->tab === self::TAB_MONEY && self::isActiveAdmin() ? self::TAB_MONEY : self::TAB_STUDY;
    }

    /** @return array<class-string<Widget>> */
    public function getWidgets(): array
    {
        return $this->activeTab() === self::TAB_MONEY ? self::MONEY_WIDGETS : self::STUDY_WIDGETS;
    }

    /** @return array<string, int> */
    public function getColumns(): array
    {
        return ['default' => 1, 'lg' => 2, 'xl' => 3];
    }

    /**
     * The same rule as the platform widgets ({@see \App\Filament\Concerns\RequiresActiveAdmin}); the page itself does not use
     * that trait, it would refuse a teacher the whole dashboard.
     */
    private static function isActiveAdmin(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->is_active === true && $user->hasRole('admin');
    }

    public function getHeader(): View
    {
        $tabs = [];
        if (self::isActiveAdmin()) {
            foreach ([self::TAB_STUDY, self::TAB_MONEY] as $tab) {
                $tabs[$tab] = [
                    'label' => __('admin.dashboard.tabs.'.$tab),
                    'url' => static::getUrl($tab === self::TAB_STUDY ? [] : ['tab' => $tab]),
                ];
            }
        }

        return view('filament.pages.dashboard-header', [
            'heading' => $this->getTitle(),
            'subheading' => __('admin.dashboard.subheading', ['date' => Carbon::now(RetentionReportService::REPORT_TIMEZONE)->translatedFormat('l, j F Y')]),
            'tabs' => $tabs,
            'active' => $this->activeTab(),
        ]);
    }
}
