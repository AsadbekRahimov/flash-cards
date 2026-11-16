<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Engagement\DTO\MonthCandidateReview;
use App\Domain\Engagement\DTO\MonthReview;
use App\Domain\Engagement\Exceptions\LeagueMonthException;
use App\Domain\Engagement\Services\MonthlyRatingReviewService;
use App\Domain\Engagement\Services\MonthlyRatingService;
use App\Filament\Resources\Support\AdminErrors;
use App\Filament\Resources\Support\LeagueMonthDisplay;
use App\Models\LeagueMonth;
use App\Models\User;
use App\Policies\LeagueMonthPolicy;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Components\Fieldset;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;

/**
 * «Рейтинг: <месяц>» (docs/13 §7.6): the review of one month — its prizes and the candidates with the anti-abuse signals —
 * and the administrator's decisions: edit the prizes, disqualify / reinstate a candidate, confirm the results, mark a
 * prize as delivered. Every decision is a method of {@see MonthlyRatingReviewService} (its own lock, state check and audit
 * row); this page only asks, with `Gate::authorize` before each one ({@see LeagueMonthPolicy}), and words a refusal
 * ({@see LeagueMonthException}) as a notification. The candidates come from one `review()` per request with their learners
 * loaded, and the signals are read from the stored `signals` — a row costs no query.
 */
class LeagueMonthReview extends Page
{
    protected static ?string $slug = 'league-months/review';

    protected string $view = 'filament.pages.league-month-review';

    public LeagueMonth $month;

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

    // --- actions -----------------------------------------------------------------------------

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            $this->confirmAction(),
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
            ->modalContent(fn () => view('filament.pages.league-month-confirm', ['winners' => $this->finalPlaces()]))
            ->modalSubmitActionLabel(__('admin.league_month.actions.confirm'))
            ->action(fn () => $this->decide(
                fn (User $by) => app(MonthlyRatingReviewService::class)->confirm($this->month, $by, request()->ip()),
                'confirmed',
            ));
    }

    public function editPrizesAction(): Action
    {
        $places = app(MonthlyRatingService::class)->places();

        return Action::make('editPrizes')
            ->label(__('admin.league_month.actions.edit_prizes'))
            ->icon('heroicon-o-gift')
            ->visible(fn (): bool => $this->month->status !== 'confirmed')
            ->schema(array_map(
                static fn (int $place): Fieldset => Fieldset::make(__('admin.league_month.prizes.place', ['place' => $place]))
                    ->schema([
                        TextInput::make("ru_{$place}")->label(__('admin.league_month.actions.edit_prizes_ru', ['place' => $place]))->maxLength(MonthlyRatingReviewService::MAX_PRIZE_LENGTH),
                        TextInput::make("uz_{$place}")->label(__('admin.league_month.actions.edit_prizes_uz', ['place' => $place]))->maxLength(MonthlyRatingReviewService::MAX_PRIZE_LENGTH),
                    ])
                    ->columns(2),
                range(1, max(1, $places)),
            ))
            ->fillForm(function (): array {
                $data = [];
                foreach ($this->review()->prizes as $prize) {
                    $data["ru_{$prize->place}"] = $prize->ru;
                    $data["uz_{$prize->place}"] = $prize->uz;
                }

                return $data;
            })
            ->action(function (array $data) use ($places): void {
                $items = [];
                foreach (range(1, max(1, $places)) as $place) {
                    $items[] = ['place' => $place, 'ru' => (string) ($data["ru_{$place}"] ?? ''), 'uz' => (string) ($data["uz_{$place}"] ?? '')];
                }

                $this->decide(fn (User $by) => app(MonthlyRatingReviewService::class)->setPrizes($this->month, $items, $by, request()->ip()), 'prizes_saved');
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
                'places' => app(MonthlyRatingService::class)->places(),
                'max_prize' => MonthlyRatingReviewService::MAX_PRIZE_LENGTH,
                'max_reason' => MonthlyRatingReviewService::MAX_REASON_LENGTH,
            ]))->send();
        }

        $this->month->refresh();
        $this->review = null;
    }

    /**
     * Who gets which place if the month is confirmed now, with the prize of the place (for the confirmation modal).
     *
     * @return list<array{place: int, name: string, points: int, prize: string}>
     */
    private function finalPlaces(): array
    {
        $review = $this->review();
        $prizes = [];
        foreach ($review->prizes as $prize) {
            $prizes[$prize->place] = $prize->ru !== '' && $prize->uz !== '' && $prize->ru !== $prize->uz ? "{$prize->ru} / {$prize->uz}" : $prize->title('ru');
        }

        $places = [];
        foreach ($review->candidates as $row) {
            if ($row->provisionalPlace === null) {
                continue;
            }
            $first = $row->learner?->first_name;
            $places[$row->provisionalPlace] = [
                'place' => $row->provisionalPlace,
                'name' => is_string($first) && $first !== '' ? $first : Lang::string('admin.league_month.candidates.deleted'),
                'points' => $row->candidate->points,
                'prize' => $prizes[$row->provisionalPlace] ?? '',
            ];
        }
        ksort($places);

        return array_values($places);
    }
}
