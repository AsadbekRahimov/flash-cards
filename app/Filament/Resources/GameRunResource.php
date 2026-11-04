<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Game\Enums\RunFinishReason;
use App\Domain\Game\Enums\RunKind;
use App\Domain\Game\Enums\RunStatus;
use App\Filament\Resources\GameRunResource\Pages;
use App\Filament\Resources\GameRunResource\RelationManagers;
use App\Filament\Resources\Support\AdminLabels;
use App\Filament\Resources\Support\AdminTable;
use App\Models\Course;
use App\Models\GameRun;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The runs of the game "Караван" (docs/18 §12.10): read-only, admin only (GameRunPolicy). The list never selects `snapshot` and `state`
 * (up to 40 KB a row); the page of a run shows the composition by KIND of element, its attempts (the journal of accepted requests, without
 * the stored answers) and the rewards - never the snapshot itself: it holds the expected answers (docs/18 §12.3.2).
 */
class GameRunResource extends Resource
{
    protected static ?string $model = GameRun::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-flag';

    protected static ?int $navigationSort = 20;

    /**
     * The columns of the list: the table and its filters read these, the snapshot and the state are left out. A column added to the table needs its attribute here.
     *
     * @var list<string>
     */
    private const LIST_COLUMNS = [
        'game_runs.id',
        'game_runs.learner_id',
        'game_runs.course_id',
        'game_runs.kind',
        'game_runs.status',
        'game_runs.finish_reason',
        'game_runs.total',
        'game_runs.answered',
        'game_runs.correct',
        'game_runs.score',
        'game_runs.stars',
        'game_runs.coins_awarded',
        'game_runs.league_points',
        'game_runs.srs_pulled',
        'game_runs.started_at',
        'game_runs.finished_at',
    ];

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.game');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.game.runs.nav');
    }

    public static function getModelLabel(): string
    {
        return __('admin.game.runs.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.game.runs.plural');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /** @return Builder<GameRun> */
    public static function getEloquentQuery(): Builder
    {
        return GameRun::query()->with(['learner:id,first_name,last_name,username', 'course:id,slug,title']);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('learner')
                ->label(__('admin.game.runs.fields.learner'))
                ->state(fn (GameRun $record): string => self::learnerName($record)),
            TextEntry::make('kind')
                ->label(__('admin.game.runs.fields.kind'))
                ->formatStateUsing(fn (RunKind $state): string => AdminLabels::value('game.runs.kind', $state->value)),
            TextEntry::make('course')->label(__('admin.game.runs.fields.course'))->state(fn (GameRun $record): string => self::courseName($record)),
            TextEntry::make('status')
                ->label(__('admin.game.runs.fields.status'))
                ->formatStateUsing(fn (RunStatus $state): string => AdminLabels::value('game.runs.status', $state->value)),
            TextEntry::make('finish_reason')
                ->label(__('admin.game.runs.fields.finish_reason'))
                ->formatStateUsing(fn (?RunFinishReason $state): string => AdminLabels::value('game.runs.finish_reason', $state?->value))
                ->placeholder('—'),
            TextEntry::make('started_at')->label(__('admin.game.runs.fields.started_at'))->dateTime(),
            TextEntry::make('finished_at')->label(__('admin.game.runs.fields.finished_at'))->dateTime()->placeholder('—'),
            TextEntry::make('composition')
                ->label(__('admin.game.runs.fields.composition'))
                ->state(fn (GameRun $record): string => self::composition($record))
                ->columnSpanFull(),
            TextEntry::make('answers')
                ->label(__('admin.game.runs.fields.answers'))
                ->state(fn (GameRun $record): string => __('admin.game.runs.answers_line', [
                    'answered' => $record->answered,
                    'total' => $record->total,
                    'correct' => $record->correct,
                    'typos' => $record->typos,
                    'wrong' => $record->wrong,
                    'hints' => $record->hints,
                ])),
            TextEntry::make('flasks')
                ->label(__('admin.game.runs.fields.flasks'))
                ->state(fn (GameRun $record): string => $record->flasks.' / '.$record->flasks_max),
            TextEntry::make('combo_best')->label(__('admin.game.runs.fields.combo_best')),
            TextEntry::make('score')->label(__('admin.game.runs.fields.score')),
            TextEntry::make('stars')->label(__('admin.game.runs.fields.stars'))->placeholder('—'),
            TextEntry::make('coins_awarded')->label(__('admin.game.runs.fields.coins_awarded')),
            TextEntry::make('league_points')->label(__('admin.game.runs.fields.league_points')),
            TextEntry::make('srs_pulled')->label(__('admin.game.runs.fields.srs_pulled')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return AdminTable::big($table)
            ->modifyQueryUsing(fn (Builder $query, bool $isResolvingRecord): Builder => $isResolvingRecord ? $query : $query->select(self::LIST_COLUMNS))
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('learner_id')
                    ->label(__('admin.game.runs.fields.learner'))
                    ->formatStateUsing(fn (GameRun $record): string => self::learnerName($record)),
                Tables\Columns\TextColumn::make('kind')
                    ->label(__('admin.game.runs.fields.kind'))
                    ->badge()
                    ->formatStateUsing(fn (RunKind $state): string => AdminLabels::value('game.runs.kind', $state->value)),
                Tables\Columns\TextColumn::make('course_id')
                    ->label(__('admin.game.runs.fields.course'))
                    ->formatStateUsing(fn (GameRun $record): string => self::courseName($record)),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('admin.game.runs.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (RunStatus $state): string => AdminLabels::value('game.runs.status', $state->value))
                    ->color(fn (RunStatus $state): string => $state === RunStatus::Active ? 'warning' : 'gray'),
                Tables\Columns\TextColumn::make('finish_reason')
                    ->label(__('admin.game.runs.fields.finish_reason'))
                    ->formatStateUsing(fn (?RunFinishReason $state): string => AdminLabels::value('game.runs.finish_reason', $state?->value))
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('score')->label(__('admin.game.runs.fields.score')),
                Tables\Columns\TextColumn::make('stars')->label(__('admin.game.runs.fields.stars'))->placeholder('—'),
                Tables\Columns\TextColumn::make('coins_awarded')->label(__('admin.game.runs.fields.coins_awarded')),
                Tables\Columns\TextColumn::make('league_points')->label(__('admin.game.runs.fields.league_points'))->toggleable(),
                Tables\Columns\TextColumn::make('srs_pulled')->label(__('admin.game.runs.fields.srs_pulled'))->toggleable(),
                Tables\Columns\TextColumn::make('started_at')->label(__('admin.game.runs.fields.started_at'))->dateTime(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('kind')
                    ->label(__('admin.game.runs.fields.kind'))
                    ->options(AdminLabels::options('game.runs.kind', array_map(fn (RunKind $k): string => $k->value, RunKind::cases()))),
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('admin.game.runs.fields.status'))
                    ->options(AdminLabels::options('game.runs.status', array_map(fn (RunStatus $s): string => $s->value, RunStatus::cases()))),
                Tables\Filters\SelectFilter::make('course_id')
                    ->label(__('admin.game.runs.fields.course'))
                    ->searchable()
                    ->options(fn (): array => self::courseOptions(''))
                    ->getSearchResultsUsing(fn (string $search): array => self::courseOptions(trim($search))),
                // The learner by id (the "Караван" tab of a learner links here): the index `(learner_id, status, started_at)` answers it.
                Tables\Filters\Filter::make('learner')
                    ->label(__('admin.game.runs.fields.learner'))
                    ->schema([Forms\Components\TextInput::make('learner_id')->label(__('admin.game.runs.filters.learner_id'))->numeric()->minValue(1)])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['learner_id'] ?? null)
                        ? $query->where('game_runs.learner_id', (int) $data['learner_id'])
                        : $query)
                    ->indicateUsing(fn (array $data): array => filled($data['learner_id'] ?? null)
                        ? [Indicator::make(__('admin.game.runs.filters.learner_indicator', ['id' => $data['learner_id']]))]
                        : []),
                Tables\Filters\Filter::make('started')
                    ->label(__('admin.game.runs.fields.started_at'))
                    ->schema([
                        Forms\Components\DatePicker::make('from')->label(__('admin.game.runs.filters.from')),
                        Forms\Components\DatePicker::make('until')->label(__('admin.game.runs.filters.until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(filled($data['from'] ?? null), fn (Builder $q): Builder => $q->where('game_runs.started_at', '>=', CarbonImmutable::parse((string) $data['from'])->startOfDay()))
                        ->when(filled($data['until'] ?? null), fn (Builder $q): Builder => $q->where('game_runs.started_at', '<', CarbonImmutable::parse((string) $data['until'])->addDay()->startOfDay())))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if (filled($data['from'] ?? null)) {
                            $indicators[] = Indicator::make(__('admin.game.runs.filters.from').': '.$data['from']);
                        }
                        if (filled($data['until'] ?? null)) {
                            $indicators[] = Indicator::make(__('admin.game.runs.filters.until').': '.$data['until']);
                        }

                        return $indicators;
                    }),
            ])
            ->emptyStateHeading(__('admin.game.runs.empty'))
            ->actions([Actions\ViewAction::make()])
            ->defaultSort('id', 'desc');
    }

    /** @return array<int, class-string> */
    public static function getRelations(): array
    {
        return [RelationManagers\AttemptsRelationManager::class];
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGameRuns::route('/'),
            'view' => Pages\ViewGameRun::route('/{record}'),
        ];
    }

    /** The composition by KIND of element: «Перевод RU→EN × 3, …». The elements themselves (their expected answers) are never shown. */
    private static function composition(GameRun $run): string
    {
        $counts = [];
        $elements = $run->snapshot['elements'] ?? [];
        foreach (is_array($elements) ? $elements : [] as $element) {
            $kind = is_array($element) && is_string($element['kind'] ?? null) ? $element['kind'] : null;
            if ($kind !== null) {
                $counts[$kind] = ($counts[$kind] ?? 0) + 1;
            }
        }

        if ($counts === []) {
            return '—';
        }

        $parts = [];
        foreach ($counts as $kind => $count) {
            $parts[] = AdminLabels::value('game.runs.task_kind', $kind).' × '.$count;
        }

        return implode(', ', $parts);
    }

    private static function learnerName(GameRun $run): string
    {
        $learner = $run->learner;
        $name = $learner instanceof Learner ? trim(($learner->first_name ?? '').' '.($learner->last_name ?? '')) : '';
        $username = $learner instanceof Learner && $learner->username !== null ? ' @'.$learner->username : '';

        return '#'.$run->learner_id.' '.($name !== '' ? $name : '—').$username;
    }

    private static function courseName(GameRun $run): string
    {
        $course = $run->course;

        return $course instanceof Course ? $course->slug : '#'.$run->course_id;
    }

    /**
     * Platform and center courses by their code and Russian title; the first fifty, or the fifty that match what is typed.
     *
     * @return array<int, string> course id => caption
     */
    private static function courseOptions(string $search): array
    {
        $options = [];
        $courses = Course::query()
            ->whereIn('owner_type', ['platform', 'organization'])
            ->when($search !== '', fn (Builder $q): Builder => AdminTable::whereContains($q, 'courses.slug', $search))
            ->orderBy('slug')
            ->limit(50)
            ->get(['id', 'slug', 'title']);
        foreach ($courses as $course) {
            $title = $course->title['ru'] ?? '';
            $options[$course->id] = $title !== '' ? "{$course->slug} — {$title}" : $course->slug;
        }

        return $options;
    }
}
