<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Engagement\Enums\ScoreKind;
use App\Domain\Engagement\Enums\ScoreSource;
use App\Domain\Learners\Support\StoredDate;
use App\Filament\Concerns\RequiresActiveAdmin;
use App\Filament\Resources\LearnerResource;
use App\Filament\Resources\LearnerResource\RelationManagers\ScoreDaysRelationManager;
use App\Filament\Resources\LearnerResource\Support\ScoreCorrectionActions;
use App\Filament\Resources\Support\AdminDates;
use App\Filament\Resources\Support\AdminLabels;
use App\Filament\Resources\Support\AdminTable;
use App\Models\Learner;
use App\Models\LearnerScoreDay;
use App\Models\ScoreCredit;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Locked;

/**
 * The journal of one rating day of a learner (docs/32 §2.5, §6.4, S15): the credits of `score_credits` — the lexeme, the kind, the
 * source, the points each count got, who voided it and why — with «Аннулировать» / «Восстановить» per credit, for the selected ones
 * and for the whole day, through {@see ScoreCorrectionActions} (the service recounts the day). Opened from the "Очки по дням" tab
 * ({@see ScoreDaysRelationManager}); not in the menu. Admin only, on every request ({@see RequiresActiveAdmin}).
 *
 * A page = the credits of (learner, day) by the unique `(learner_id, rating_day, lexeme_id)` in the order of `id` (at most a few dozen
 * a day), the lexemes and the voiding administrators loaded for the page in one statement each, the day row by its unique: the number
 * of statements does not grow with the rows (docs/20 §1.3).
 */
class LearnerScoreDayCredits extends Page implements HasTable
{
    use InteractsWithTable;
    use RequiresActiveAdmin;

    protected static ?string $slug = 'learner-score-day';

    protected string $view = 'filament.pages.learner-score-day';

    #[Locked]
    public int $learnerId;

    #[Locked]
    public string $day;

    /** Read once per request; not Livewire properties (never serialised). */
    private ?Learner $learner = null;

    private ?LearnerScoreDay $scoreDay = null;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    // Not in the menu, but the panel asks every page for its captions.
    public static function getNavigationLabel(): string
    {
        return __('admin.learners.score_days.page.nav');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.administration');
    }

    public static function getRoutePath(Panel $panel): string
    {
        return '/learners/{learner}/score-days/{day}';
    }

    public static function canAccess(): bool
    {
        return self::isActiveAdmin();
    }

    public function mount(int|string $learner, string $day): void
    {
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $day) : null;
        abort_unless($date !== null && $date->format('Y-m-d') === $day && is_numeric($learner), 404);

        $this->learnerId = (int) $learner;
        $this->day = $day;

        $this->learner();
        abort_if($this->scoreDay() === null, 404);
    }

    public function learner(): Learner
    {
        if ($this->learner === null) {
            /** @var Learner|null $learner */
            $learner = Learner::query()->withTrashed()->find($this->learnerId);
            abort_if($learner === null, 404);
            $this->learner = $learner;
        }

        return $this->learner;
    }

    /** The day row with both counts as stored (null once the day is gone with the learner). */
    public function scoreDay(): ?LearnerScoreDay
    {
        return $this->scoreDay ??= LearnerScoreDay::query()
            ->where('learner_id', $this->learnerId)
            ->where('rating_day', StoredDate::of($this->day))
            ->first();
    }

    public function getTitle(): string
    {
        return __('admin.learners.score_days.page.title', [
            'day' => AdminDates::date(CarbonImmutable::parse($this->day)),
            'name' => $this->learnerName(),
        ]);
    }

    private function learnerName(): string
    {
        $name = (string) $this->learner()->first_name;

        return $name !== '' ? $name : '#'.$this->learnerId;
    }

    /** @return array<string, string> */
    public function getBreadcrumbs(): array
    {
        return [
            LearnerResource::getUrl() => (string) __('admin.learners.plural'),
            LearnerResource::getUrl('view', ['record' => $this->learnerId]) => $this->learnerName(),
            '' => (string) __('admin.learners.score_days.page.nav'),
        ];
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('voidDay')
                ->label(__('admin.learners.score_days.actions.void_day'))
                ->icon('heroicon-o-no-symbol')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading(fn (): string => __('admin.learners.score_days.actions.void_day_heading', ['day' => AdminDates::date(CarbonImmutable::parse($this->day))]))
                ->modalDescription(__('admin.learners.score_days.actions.void_day_description'))
                ->schema([ScoreCorrectionActions::reasonField()])
                ->action(fn (array $data) => $this->correct(null, (string) ($data['reason'] ?? ''))),
            Action::make('restoreDay')
                ->label(__('admin.learners.score_days.actions.restore_day'))
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading(fn (): string => __('admin.learners.score_days.actions.restore_day_heading', ['day' => AdminDates::date(CarbonImmutable::parse($this->day))]))
                ->modalDescription(__('admin.learners.score_days.actions.restore_description'))
                ->action(fn () => $this->correct(null, null)),
        ];
    }

    public function table(Table $table): Table
    {
        return AdminTable::bounded($table)
            ->query(fn (): Builder => ScoreCredit::query()
                ->select(['id', 'learner_id', 'rating_day', 'lexeme_id', 'word_id', 'source', 'source_id', 'kind', 'week_points', 'month_points', 'voided_at', 'voided_by_user_id', 'void_reason', 'created_at'])
                ->with(['lexeme:id,key', 'voidedBy:id,name'])
                ->where('learner_id', $this->learnerId)
                ->where('rating_day', StoredDate::of($this->day)))
            ->heading(__('admin.learners.score_days.page.credits'))
            ->emptyStateHeading(__('admin.learners.score_days.page.empty'))
            ->columns([
                Tables\Columns\TextColumn::make('id')->label(__('admin.learners.score_days.credits.id'))->sortable(),
                Tables\Columns\TextColumn::make('lexeme.key')
                    ->label(__('admin.learners.score_days.credits.lexeme'))
                    ->placeholder('—')
                    ->description(fn (ScoreCredit $record): string => __('admin.learners.score_days.credits.word', ['id' => $record->word_id])),
                Tables\Columns\TextColumn::make('kind')
                    ->label(__('admin.learners.score_days.credits.kind'))
                    ->badge()
                    ->formatStateUsing(fn (ScoreKind $state): string => AdminLabels::value('learners.score_days.kind', $state->value))
                    ->color(fn (ScoreKind $state): string => $state === ScoreKind::Long ? 'success' : ($state === ScoreKind::New ? 'info' : 'gray')),
                Tables\Columns\TextColumn::make('source')
                    ->label(__('admin.learners.score_days.credits.source'))
                    ->formatStateUsing(fn (ScoreSource $state): string => AdminLabels::value('learners.score_days.source', $state->value))
                    ->description(fn (ScoreCredit $record): string => '#'.$record->source_id),
                Tables\Columns\TextColumn::make('week_points')
                    ->label(__('admin.learners.score_days.credits.week_points'))
                    ->color(fn (ScoreCredit $record): ?string => $record->voided_at !== null ? 'gray' : null),
                Tables\Columns\TextColumn::make('month_points')
                    ->label(__('admin.learners.score_days.credits.month_points'))
                    ->color(fn (ScoreCredit $record): ?string => $record->voided_at !== null ? 'gray' : null),
                Tables\Columns\TextColumn::make('created_at')->label(__('admin.learners.score_days.credits.created_at'))->dateTime(),
                Tables\Columns\TextColumn::make('voided_at')
                    ->label(__('admin.learners.score_days.credits.voided_at'))
                    ->dateTime()
                    ->placeholder('—')
                    ->description(fn (ScoreCredit $record): ?string => $record->voided_at === null ? null : ($record->voidedBy->name ?? (string) __('admin.learners.score_days.credits.voided_by_unknown'))),
                Tables\Columns\TextColumn::make('void_reason')
                    ->label(__('admin.learners.score_days.credits.void_reason'))
                    ->placeholder('—')
                    ->limit(80)
                    ->wrap(),
            ])
            ->recordActions([
                Action::make('void')
                    ->label(__('admin.learners.score_days.actions.void'))
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (ScoreCredit $record): bool => $record->voided_at === null)
                    ->requiresConfirmation()
                    ->modalHeading(fn (ScoreCredit $record): string => __('admin.learners.score_days.actions.void_heading', ['id' => $record->id]))
                    ->modalDescription(__('admin.learners.score_days.actions.void_description'))
                    ->schema([ScoreCorrectionActions::reasonField()])
                    ->action(fn (ScoreCredit $record, array $data) => $this->correct([(int) $record->id], (string) ($data['reason'] ?? ''))),
                Action::make('restore')
                    ->label(__('admin.learners.score_days.actions.restore'))
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->visible(fn (ScoreCredit $record): bool => $record->voided_at !== null)
                    ->requiresConfirmation()
                    ->modalHeading(fn (ScoreCredit $record): string => __('admin.learners.score_days.actions.restore_heading', ['id' => $record->id]))
                    ->modalDescription(__('admin.learners.score_days.actions.restore_description'))
                    ->action(fn (ScoreCredit $record) => $this->correct([(int) $record->id], null)),
            ])
            ->toolbarActions([
                BulkAction::make('voidSelected')
                    ->label(__('admin.learners.score_days.actions.void_selected'))
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('admin.learners.score_days.actions.void_selected_heading'))
                    ->modalDescription(__('admin.learners.score_days.actions.void_description'))
                    ->schema([ScoreCorrectionActions::reasonField()])
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records, array $data): void {
                        $ids = [];
                        foreach ($records as $record) {
                            $ids[] = (int) $record->getKey();
                        }
                        $this->correct($ids, (string) ($data['reason'] ?? ''));
                    }),
            ])
            ->defaultSort('id');
    }

    /**
     * A void (`$reason` given) or a restore of the chosen credits (null = the whole day), then the day and the table read again.
     *
     * @param  list<int>|null  $creditIds
     */
    private function correct(?array $creditIds, ?string $reason): void
    {
        ScoreCorrectionActions::run($this->learner(), $this->day, $creditIds, $reason);

        $this->scoreDay = null;
        $this->flushCachedTableRecords();
    }
}
