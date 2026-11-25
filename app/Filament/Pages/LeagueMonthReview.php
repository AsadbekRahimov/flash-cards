<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Engagement\DTO\MonthCandidateReview;
use App\Domain\Engagement\DTO\MonthlyPrize;
use App\Domain\Engagement\DTO\MonthReview;
use App\Domain\Engagement\Enums\ScoreCategory;
use App\Domain\Engagement\Exceptions\LeagueMonthException;
use App\Domain\Engagement\Services\MonthlyRatingReviewService;
use App\Domain\Engagement\Services\MonthlyRatingService;
use App\Filament\Resources\Support\AdminErrors;
use App\Filament\Resources\Support\AdminLabels;
use App\Filament\Resources\Support\LeagueMonthDisplay;
use App\Models\LeagueMonth;
use App\Models\User;
use App\Policies\LeagueMonthPolicy;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Components\Fieldset;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;
use Livewire\Attributes\Url;

/**
 * «Рейтинг: <месяц>» (docs/13 §7.6): the review of one month — its prizes and the candidates with the anti-abuse signals —
 * and the administrator's decisions: edit the prizes, disqualify / reinstate a candidate, confirm the results, mark a
 * prize as delivered. Every decision is a method of {@see MonthlyRatingReviewService} (its own lock, state check and audit
 * row); this page only asks, with `Gate::authorize` before each one ({@see LeagueMonthPolicy}), and words a refusal
 * ({@see LeagueMonthException}) as a notification. The candidates come from one `review()` per request with their learners
 * loaded, and the signals are read from the stored `signals` — a row costs no query.
 *
 * A month on the word points v2 (docs/32 §7, S15) has two tables: the candidates of the category picked by the tabs (`?category=`),
 * their competition places (a tie shares a place and its prize), the prizes of each category (3 + 3, owner's decision В5: the Pro
 * ones read with {@see MonthlyRatingService::prizes()}, `MonthReview::$prizes` is the Free list), the points by rating day, and the
 * confirmation that refuses a tie beyond the reserve ({@see LeagueMonthException::TIE_BEYOND_RESERVE}) until the administrator
 * confirms again with an explicit «accept» ({@see confirmTieOverflowAction()}). A v1 month keeps the one table as before.
 */
class LeagueMonthReview extends Page
{
    protected static ?string $slug = 'league-months/review';

    protected string $view = 'filament.pages.league-month-review';

    public LeagueMonth $month;

    /** The table of a v2 month on the screen (`free` / `pro`); ignored by a v1 month. */
    #[Url]
    public string $category = 'free';

    /** What the page shows, read once per request (and again after a decision). Not a Livewire property: never serialised. */
    private ?MonthReview $review = null;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    // Not in the menu, but the panel asks every page for its captions.
    public static function getNavigationLabel(): string
    {
        return __('admin.league_month.title');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.administration');
    }

    public static function getRoutePath(Panel $panel): string
    {
        return '/league-months/{month}';
    }

    public static function canAccess(): bool
    {
        return LeagueMonths::canAccess();
    }

    public function mount(LeagueMonth $month): void
    {
        Gate::authorize('view', $month);

        $this->month = $month;
    }

    public function getTitle(): string
    {
        return __('admin.league_month.title_month', ['month' => LeagueMonthDisplay::month($this->month)]);
    }

    /** @return array<string, string> */
    public function getBreadcrumbs(): array
    {
        return [
            LeagueMonths::getUrl() => __('admin.league_month.title'),
            '' => LeagueMonthDisplay::month($this->month),
        ];
    }

    public function review(): MonthReview
    {
        return $this->review ??= app(MonthlyRatingReviewService::class)->review($this->month);
    }

    public function isV2(): bool
    {
        return MonthlyRatingService::isV2($this->month);
    }

    /** The category on the screen of a v2 month (an unknown value from the address is Free); null for a v1 month. */
    public function category(): ?ScoreCategory
    {
        return $this->isV2() ? (ScoreCategory::tryFrom($this->category) ?? ScoreCategory::Free) : null;
    }

    /**
     * The candidates on the screen: all of a v1 month, those of the picked category of a v2 month (by rank).
     *
     * @return list<MonthCandidateReview>
     */
    public function rows(): array
    {
        $category = $this->category();

        return $category === null
            ? $this->review()->candidates
            : array_values(array_filter($this->review()->candidates, static fn (MonthCandidateReview $row): bool => $row->candidate->category === $category->value));
    }

    /**
     * The candidates of each category of a v2 month (the counters of the tabs).
     *
     * @return array<string, int>
     */
    public function categoryCounts(): array
    {
        $counts = ['free' => 0, 'pro' => 0];
        foreach ($this->review()->candidates as $row) {
            if (isset($counts[(string) $row->candidate->category])) {
                $counts[(string) $row->candidate->category]++;
            }
        }

        return $counts;
    }

    /**
     * The prizes of a table: the one list of a v1 month, the list of the category of a v2 month (read from the month row, no query).
     *
     * @return list<MonthlyPrize>
     */
    public function prizesOf(?ScoreCategory $category): array
    {
        return $category === null ? $this->review()->prizes : app(MonthlyRatingService::class)->prizes($this->month, $category);
    }

    /** The prize places of a table: `monthly.places` of a v1 month, `monthly.categories.{cat}.places` of a v2 one. */
    public function placesOf(?ScoreCategory $category): int
    {
        return app(MonthlyRatingService::class)->places($category);
    }

    public function tieReserveOf(ScoreCategory $category): int
    {
        return app(MonthlyRatingService::class)->tieReserve($category);
    }

    /**
     * The categories of a pending v2 month whose shared prize places give more winners than `places + tie_reserve` if it were
     * confirmed now — what {@see MonthlyRatingReviewService::confirm()} refuses without `acceptTieOverflow` (from the review, no query).
     *
     * @return array<string, array{winners: int, places: int, allowed: int}>
     */
    public function tieOverflow(): array
    {
        if (! $this->isV2() || $this->month->status !== 'pending_review') {
            return [];
        }

        $winners = ['free' => 0, 'pro' => 0];
        foreach ($this->review()->candidates as $row) {
            if ($row->provisionalPlace !== null && isset($winners[(string) $row->candidate->category])) {
                $winners[(string) $row->candidate->category]++;
            }
        }

        $overflow = [];
        foreach (ScoreCategory::cases() as $category) {
            $allowed = $this->placesOf($category) + $this->tieReserveOf($category);
            if ($winners[$category->value] > $allowed) {
                $overflow[$category->value] = ['winners' => $winners[$category->value], 'places' => $this->placesOf($category), 'allowed' => $allowed];
            }
        }

        return $overflow;
    }

    // --- actions -----------------------------------------------------------------------------

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            $this->confirmAction(),
            $this->confirmTieOverflowAction(),
            $this->resendAction(),
            $this->editPrizesAction(),
            $this->clearPrizesAction(),
            $this->defaultPrizesAction(),
        ];
    }

    public function confirmAction(): Action
    {
        return Action::make('confirm')
            ->label(__('admin.league_month.actions.confirm'))
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->visible(fn (): bool => $this->month->status === 'pending_review')
            ->modalHeading(__('admin.league_month.actions.confirm_heading'))
            ->modalContent(fn () => view('filament.pages.league-month-confirm', ['winners' => $this->finalPlaces(), 'overflow' => $this->tieOverflowText()]))
            ->modalSubmitActionLabel(__('admin.league_month.actions.confirm'))
            ->action(fn () => $this->decide(
                fn (User $by) => app(MonthlyRatingReviewService::class)->confirm($this->month, $by, request()->ip()),
                'confirmed',
            ));
    }

    /**
     * The confirmation of a v2 month whose tie on a prize place goes beyond the published reserve (docs/32 §7): offered only then,
     * apart from the ordinary one, with an explicit «accept» the form requires; the audit row says `tie_overflow_accepted`.
     */
    public function confirmTieOverflowAction(): Action
    {
        return Action::make('confirmTieOverflow')
            ->label(__('admin.league_month.actions.confirm_overflow'))
            ->icon('heroicon-o-exclamation-triangle')
            ->color('warning')
            ->visible(fn (): bool => $this->tieOverflow() !== [])
            ->modalHeading(__('admin.league_month.actions.confirm_overflow_heading'))
            ->modalContent(fn () => view('filament.pages.league-month-confirm', ['winners' => $this->finalPlaces(), 'overflow' => $this->tieOverflowText()]))
            ->schema([
                Checkbox::make('accept')
                    ->label(__('admin.league_month.actions.confirm_overflow_accept'))
                    ->accepted()
                    ->required(),
            ])
            ->modalSubmitActionLabel(__('admin.league_month.actions.confirm_overflow'))
            ->action(fn (array $data) => $this->decide(
                fn (User $by) => app(MonthlyRatingReviewService::class)->confirm($this->month, $by, request()->ip(), acceptTieOverflow: (bool) ($data['accept'] ?? false)),
                'confirmed',
            ));
    }

    public function resendAction(): Action
    {
        return Action::make('resend')
            ->label(__('admin.league_month.actions.resend'))
            ->icon('heroicon-o-paper-airplane')
            ->color('gray')
            ->visible(fn (): bool => $this->month->status === 'confirmed')
            ->requiresConfirmation()
            ->modalDescription(__('admin.league_month.actions.resend_description'))
            ->action(fn () => $this->decide(
                fn (User $by) => app(MonthlyRatingReviewService::class)->resendCongratulations($this->month, $by, request()->ip()),
                'resent',
            ));
    }

    public function editPrizesAction(): Action
    {
        // The tables of the month and their prize places: one table of a v1 month (field `ru_1`), Free and Pro of a v2 one (`free_ru_1`).
        $tables = $this->isV2()
            ? array_map(fn (ScoreCategory $category): array => [$category, $category->value.'_', $this->placesOf($category)], ScoreCategory::cases())
            : [[null, '', $this->placesOf(null)]];

        $fieldsets = [];
        foreach ($tables as [$category, $prefix, $places]) {
            foreach (range(1, max(1, $places)) as $place) {
                $caption = $category === null
                    ? __('admin.league_month.prizes.place', ['place' => $place])
                    : __('admin.league_month.prizes.category_place', ['category' => AdminLabels::value('learners.score_days.category', $category->value), 'place' => $place]);
                $fieldsets[] = Fieldset::make($caption)
                    ->schema([
                        TextInput::make("{$prefix}ru_{$place}")->label(__('admin.league_month.actions.edit_prizes_ru', ['place' => $place]))->maxLength(MonthlyRatingReviewService::MAX_PRIZE_LENGTH),
                        TextInput::make("{$prefix}uz_{$place}")->label(__('admin.league_month.actions.edit_prizes_uz', ['place' => $place]))->maxLength(MonthlyRatingReviewService::MAX_PRIZE_LENGTH),
                    ])
                    ->columns(2);
            }
        }

        return Action::make('editPrizes')
            ->label(__('admin.league_month.actions.edit_prizes'))
            ->icon('heroicon-o-gift')
            ->visible(fn (): bool => $this->month->status !== 'confirmed')
            ->schema($fieldsets)
            ->fillForm(function () use ($tables): array {
                $data = [];
                foreach ($tables as [$category, $prefix]) {
                    foreach ($this->prizesOf($category) as $prize) {
                        $data["{$prefix}ru_{$prize->place}"] = $prize->ru;
                        $data["{$prefix}uz_{$prize->place}"] = $prize->uz;
                    }
                }

                return $data;
            })
            ->action(function (array $data) use ($tables): void {
                $lists = [];
                foreach ($tables as [$category, $prefix, $places]) {
                    $items = [];
                    foreach (range(1, max(1, $places)) as $place) {
                        $items[] = ['place' => $place, 'ru' => (string) ($data["{$prefix}ru_{$place}"] ?? ''), 'uz' => (string) ($data["{$prefix}uz_{$place}"] ?? '')];
                    }
                    $lists[$category === null ? '' : $category->value] = $items;
                }
                // A v1 month: the list; a v2 month: `{free: [...], pro: [...]}` (MonthlyRatingReviewService::setPrizes).
                $prizes = array_key_exists('', $lists) ? $lists[''] : $lists;

                $this->decide(fn (User $by) => app(MonthlyRatingReviewService::class)->setPrizes($this->month, $prizes, $by, request()->ip()), 'prizes_saved');
            });
    }

    public function clearPrizesAction(): Action
    {
        return Action::make('clearPrizes')
            ->label(__('admin.league_month.actions.clear_prizes'))
            ->icon('heroicon-o-x-mark')
            ->color('gray')
            ->visible(fn (): bool => $this->month->status !== 'confirmed')
            ->requiresConfirmation()
            ->modalDescription(__('admin.league_month.actions.clear_prizes_description'))
            ->action(fn () => $this->decide(fn (User $by) => app(MonthlyRatingReviewService::class)->setPrizes($this->month, [], $by, request()->ip()), 'prizes_saved'));
    }

    public function defaultPrizesAction(): Action
    {
        return Action::make('defaultPrizes')
            ->label(__('admin.league_month.actions.default_prizes'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->visible(fn (): bool => $this->month->status !== 'confirmed')
            ->requiresConfirmation()
            ->modalDescription(__('admin.league_month.actions.default_prizes_description'))
            ->action(fn () => $this->decide(fn (User $by) => app(MonthlyRatingReviewService::class)->setPrizes($this->month, null, $by, request()->ip()), 'prizes_saved'));
    }

    /** Row action: the arguments carry `candidate` (id of the candidate) and `name` (for the heading only). */
    public function disqualifyAction(): Action
    {
        return Action::make('disqualify')
            ->label(__('admin.league_month.actions.disqualify'))
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->size('sm')
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => __('admin.league_month.actions.disqualify_heading', ['name' => (string) ($arguments['name'] ?? '')]))
            ->modalDescription(__('admin.league_month.actions.disqualify_description'))
            ->schema([
                Textarea::make('reason')
                    ->label(__('admin.league_month.fields.reason'))
                    ->helperText(__('admin.league_month.fields.reason_help', ['max' => MonthlyRatingReviewService::MAX_REASON_LENGTH]))
                    ->required()
                    ->maxLength(MonthlyRatingReviewService::MAX_REASON_LENGTH)
                    ->rows(3),
            ])
            ->action(fn (array $data, array $arguments) => $this->decide(
                fn (User $by) => app(MonthlyRatingReviewService::class)->disqualify($this->month, (int) ($arguments['candidate'] ?? 0), (string) ($data['reason'] ?? ''), $by, request()->ip()),
                'disqualified',
            ));
    }

    public function reinstateAction(): Action
    {
        return Action::make('reinstate')
            ->label(__('admin.league_month.actions.reinstate'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->size('sm')
            ->requiresConfirmation()
            ->action(fn (array $arguments) => $this->decide(
                fn (User $by) => app(MonthlyRatingReviewService::class)->reinstate($this->month, (int) ($arguments['candidate'] ?? 0), $by, request()->ip()),
                'reinstated',
            ));
    }

    public function prizeDeliveredAction(): Action
    {
        return Action::make('prizeDelivered')
            ->label(__('admin.league_month.actions.prize_delivered'))
            ->icon('heroicon-o-gift')
            ->color('success')
            ->size('sm')
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => __('admin.league_month.actions.prize_delivered_heading', ['name' => (string) ($arguments['name'] ?? '')]))
            ->modalDescription(__('admin.league_month.actions.prize_delivered_description'))
            ->action(fn (array $arguments) => $this->decide(
                fn (User $by) => app(MonthlyRatingReviewService::class)->markPrizeDelivered($this->month, (int) ($arguments['candidate'] ?? 0), $by, request()->ip()),
                'delivered',
            ));
    }

    /** Row action: the points of the learner day by day; arguments `learner` (id) and `name`. Read-only. */
    public function daysAction(): Action
    {
        return Action::make('days')
            ->label(__('admin.league_month.actions.days'))
            ->icon('heroicon-o-calendar-days')
            ->color('gray')
            ->size('sm')
            ->modalHeading(fn (array $arguments): string => __('admin.league_month.days.heading', ['name' => (string) ($arguments['name'] ?? '')]))
            ->modalContent(function (array $arguments) {
                Gate::authorize('view', $this->month);
                $learnerId = (int) ($arguments['learner'] ?? 0);
                // Only the learners of this page's candidates: the id comes from the browser.
                abort_unless(collect($this->review()->candidates)->contains(fn (MonthCandidateReview $row): bool => $row->candidate->learner_id === $learnerId), 404);

                // A v2 month counts by rating day (`learner_score_days`): its monthly count, with the way to the journal of the day.
                if ($this->isV2()) {
                    return view('filament.pages.league-month-score-days', [
                        'days' => app(MonthlyRatingService::class)->scoreDays($this->month, $learnerId),
                        'learnerId' => $learnerId,
                    ]);
                }

                return view('filament.pages.league-month-days', [
                    'days' => app(MonthlyRatingReviewService::class)->dailyBreakdown($this->month, $learnerId),
                    'cap' => (int) config('leagues.monthly.daily_cap', 300),
                ]);
            })
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('admin.league_month.days.close'));
    }

    // --- helpers -----------------------------------------------------------------------------

    /**
     * Runs a decision: authorises it, shows the service's refusal as a notification (the page does not fail), and
     * re-reads the month and the review after it.
     *
     * @param  Closure(User): mixed  $decision
     */
    private function decide(Closure $decision, string $done): void
    {
        Gate::authorize('update', $this->month);

        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        try {
            $decision($user);
            Notification::make()->success()->title(__("admin.league_month.notifications.{$done}"))->send();
        } catch (LeagueMonthException $e) {
            Notification::make()->danger()->title(AdminErrors::text('league_month', $e->errorCode, [
                'places' => app(MonthlyRatingService::class)->places($this->category()),
                'max_prize' => MonthlyRatingReviewService::MAX_PRIZE_LENGTH,
                'max_reason' => MonthlyRatingReviewService::MAX_REASON_LENGTH,
                'tie' => $this->tieOverflowText(),
            ]))->send();
        }

        $this->month->refresh();
        $this->review = null;
    }

    /**
     * «Free: 5 победителей при 3 местах (резерв — до 4)» for every category whose tie goes beyond the reserve; '' when none.
     */
    private function tieOverflowText(): string
    {
        $lines = [];
        foreach ($this->tieOverflow() as $category => $tie) {
            $lines[] = __('admin.league_month.confirm.overflow_line', [
                'category' => AdminLabels::value('learners.score_days.category', $category),
                'winners' => $tie['winners'],
                'places' => $tie['places'],
                'allowed' => $tie['allowed'],
            ]);
        }

        return implode('; ', $lines);
    }

    /**
     * Who gets which place if the month is confirmed now, with the prize of the place (for the confirmation modal): by category
     * (a v2 month, Free first) and place; a shared place lists every candidate on it.
     *
     * @return list<array{category: string|null, place: int, name: string, points: int, prize: string, unreachable: 'blocked'|'deleted'|null}>
     */
    private function finalPlaces(): array
    {
        $review = $this->review();
        $prizes = [];
        foreach ($this->isV2() ? ScoreCategory::cases() : [null] as $category) {
            $table = $category === null ? '' : $category->value;
            foreach ($this->prizesOf($category) as $prize) {
                $prizes[$table.':'.$prize->place] = $prize->ru !== '' && $prize->uz !== '' && $prize->ru !== $prize->uz ? "{$prize->ru} / {$prize->uz}" : $prize->title('ru');
            }
        }

        $places = [];
        foreach ($review->candidates as $row) {
            if ($row->provisionalPlace === null) {
                continue;
            }
            $category = $this->isV2() ? $row->candidate->category : null;
            $first = $row->learner?->first_name;
            $unreachable = LeagueMonthDisplay::unreachable($row->learner);
            $places[] = [
                'category' => $category,
                'place' => $row->provisionalPlace,
                'name' => $unreachable !== 'deleted' && is_string($first) && $first !== '' ? $first : Lang::string('admin.league_month.candidates.deleted'),
                'unreachable' => $unreachable,
                'points' => $row->candidate->points,
                'prize' => $prizes[($category ?? '').':'.$row->provisionalPlace] ?? '',
            ];
        }
        // The candidates come by rank (and a v2 rank is per category): a stable sort by category, then place, keeps that order inside a place.
        $order = ['free' => 0, 'pro' => 1];
        usort($places, static fn (array $a, array $b): int => [$order[$a['category'] ?? ''] ?? 0, $a['place']] <=> [$order[$b['category'] ?? ''] ?? 0, $b['place']]);

        return $places;
    }
}
