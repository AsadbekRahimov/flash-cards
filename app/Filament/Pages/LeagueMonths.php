<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Administration\Services\PendingLeagueMonthsBadge;
use App\Domain\Engagement\Services\MonthlyRatingReviewService;
use App\Domain\Engagement\Services\Scoring\ScoreRulesOverview;
use App\Models\LeagueMonth;
use App\Models\User;
use App\Policies\LeagueMonthPolicy;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * «Рейтинг месяца» (docs/13 §7.6): the months of the rating with their status and rules version; the month that waits for the
 * review is marked on the menu item. Only an active admin ({@see LeagueMonthPolicy}). The page only shows
 * what {@see MonthlyRatingReviewService::months()} returns — the decisions are on the month's page ({@see LeagueMonthReview}).
 * Above the months — the word-points rules, read only ({@see ScoreRulesOverview}, docs/32 §8.5).
 */
class LeagueMonths extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-trophy';

    protected static ?int $navigationSort = 15;

    protected static ?string $slug = 'league-months';

    protected string $view = 'filament.pages.league-months';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.administration');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.league_month.nav');
    }

    /** Months waiting for the review: one integer from a 60-second cache, never a query per page view (docs/20 §1). */
    public static function getNavigationBadge(): ?string
    {
        if (! self::canAccess()) {
            return null;
        }

        $count = PendingLeagueMonthsBadge::count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('admin.league_month.nav_badge_tooltip');
    }

    public function getTitle(): string
    {
        return __('admin.league_month.title');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && Gate::forUser($user)->allows('viewAny', LeagueMonth::class);
    }

    /**
     * The months, newest first, with who confirmed them (one more query for all rows, not one per row).
     *
     * @return Collection<int, LeagueMonth>
     */
    public function months(): Collection
    {
        return app(MonthlyRatingReviewService::class)->months()->load('confirmedBy:id,name');
    }

    /**
     * The word-points rules (docs/32 §6.1): the configuration and the versions of the current week and month (two reads).
     *
     * @return array<string, mixed>
     */
    public function scoringRules(): array
    {
        return app(ScoreRulesOverview::class)->overview();
    }
}
