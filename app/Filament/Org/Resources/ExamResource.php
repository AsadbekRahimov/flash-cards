<?php

declare(strict_types=1);

namespace App\Filament\Org\Resources;

use App\Domain\Learning\Services\ExamReportService;
use App\Domain\Organizations\Services\OrgTenantScope;
use App\Filament\Org\Resources\ExamResource\Pages;
use App\Filament\Resources\Support\AdminTable;
use App\Models\ExamSession;
use Carbon\CarbonImmutable;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists;
use Filament\Resources\Pages\PageRegistration;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Exams of the groups of the center, read-only: the owner sees every group, a teacher their own ({@see OrgTenantScope::exams()}). The
 * list shows what was asked and how the class did (participants, share of right answers); the page of an exam shows the results by
 * learner, who did not take part and the words the class got wrong most ({@see ExamReportService}), and exports the results to CSV.
 * Columns read only what the page query brought (docs/20 §1.3): the counts and sums are aggregates of that query.
 */
class ExamResource extends OrgResource
{
    protected static ?string $model = ExamSession::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?int $navigationSort = 57;

    protected static ?string $slug = 'exams';

    /** An exam has no `organization` of its own: the center is reached through the group, which {@see OrgTenantScope::exams()} checks. */
    protected static bool $isScopedToTenant = false;

    /**
     * The columns of the page query (docs/20 §1.4): everything but the `config` of a session - the answer key, a document of tens of
     * kilobytes nobody here reads.
     *
     * @var list<string>
     */
    private const COLUMNS = [
        'exam_sessions.id',
        'exam_sessions.telegram_group_id',
        'exam_sessions.lesson_id',
        'exam_sessions.mode',
        'exam_sessions.status',
        'exam_sessions.started_at',
        'exam_sessions.ends_at',
        'exam_sessions.closed_at',
    ];

    public static function getNavigationLabel(): string
    {
        return __('org.nav.exams');
    }

    public static function getModelLabel(): string
    {
        return __('org.exams.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('org.exams.plural');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /** @return Builder<ExamSession> */
    public static function getEloquentQuery(): Builder
    {
        return static::scope()->exams(static::user(), static::tenant())
            ->select(self::COLUMNS)
            ->with(['group:id,title', 'lesson:id,number,stage_id', 'lesson.stage:id,number'])
            ->withCount('results as participants_count')
            ->withSum('results as correct_sum', 'correct_count')
            ->withSum('results as total_sum', 'total_count');
    }

    public static function table(Table $table): Table
    {
        $timezone = static::tenant()->timezone;

        // Page sizes 10/25/50/100 and nothing else (docs/20 §1.6).
        return AdminTable::bounded($table)
            ->columns([
                Tables\Columns\TextColumn::make('group.title')->label(__('org.exams.columns.group'))->limit(32),
                Tables\Columns\TextColumn::make('lesson.number')
                    ->label(__('org.exams.columns.lesson'))
                    ->formatStateUsing(fn (ExamSession $record): string => static::lessonLabel($record)),
                Tables\Columns\TextColumn::make('started_at')
                    ->label(__('org.exams.columns.date'))
                    ->dateTime('d.m.Y H:i', $timezone)
                    ->sortable(),
                Tables\Columns\TextColumn::make('duration')
                    ->label(__('org.exams.columns.duration'))
                    ->state(fn (ExamSession $record): string => static::durationLabel($record)),
                Tables\Columns\TextColumn::make('participants_count')->label(__('org.exams.columns.participants')),
                Tables\Columns\TextColumn::make('average')
                    ->label(__('org.exams.columns.average'))
                    ->state(fn (ExamSession $record): string => static::averageLabel($record)),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('org.exams.columns.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __('org.exams.status.'.$state))
                    ->color(fn (string $state): string => $state === 'open' ? 'warning' : 'success'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('telegram_group_id')
                    ->label(__('org.exams.columns.group'))
                    ->options(fn (): array => static::scope()->groups(static::user(), static::tenant())->orderBy('title')->pluck('title', 'id')->all()),
                Tables\Filters\Filter::make('period')
                    ->label(__('org.exams.filters.period'))
                    ->schema([
                        DatePicker::make('from')->label(__('org.exams.filters.from'))->native(false),
                        DatePicker::make('until')->label(__('org.exams.filters.until'))->native(false),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(filled($data['from'] ?? null), fn (Builder $q): Builder => $q->where('exam_sessions.started_at', '>=', CarbonImmutable::parse((string) $data['from'], $timezone)->startOfDay()))
                        ->when(filled($data['until'] ?? null), fn (Builder $q): Builder => $q->where('exam_sessions.started_at', '<=', CarbonImmutable::parse((string) $data['until'], $timezone)->endOfDay()))),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->emptyStateHeading(__('org.exams.empty'))
            ->defaultSort('started_at', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        $timezone = static::tenant()->timezone;
        $report = app(ExamReportService::class);

        return $schema->components([
            Infolists\Components\TextEntry::make('group.title')->label(__('org.exams.columns.group')),
            Infolists\Components\TextEntry::make('lesson.number')
                ->label(__('org.exams.columns.lesson'))
                ->formatStateUsing(fn (ExamSession $record): string => static::lessonLabel($record)),
            Infolists\Components\TextEntry::make('started_at')->label(__('org.exams.columns.date'))->dateTime('d.m.Y H:i', $timezone),
            Infolists\Components\TextEntry::make('duration')
                ->label(__('org.exams.columns.duration'))
                ->state(fn (ExamSession $record): string => static::durationLabel($record)),
            Infolists\Components\TextEntry::make('status')
                ->label(__('org.exams.columns.status'))
                ->badge()
                ->formatStateUsing(fn (string $state): string => __('org.exams.status.'.$state)),
            Infolists\Components\TextEntry::make('participants_count')->label(__('org.exams.columns.participants')),
            Infolists\Components\TextEntry::make('average')
                ->label(__('org.exams.columns.average'))
                ->state(fn (ExamSession $record): string => static::averageLabel($record)),
            Infolists\Components\RepeatableEntry::make('results')
                ->label(__('org.exams.results.heading'))
                ->placeholder(__('org.exams.results.empty'))
                ->columnSpanFull()
                ->state(fn (ExamSession $record): array => array_map(fn (array $row): array => $row + ['ratio' => $row['correct'].' / '.$row['total']], $report->results($record)))
                ->schema([
                    Infolists\Components\TextEntry::make('rank')->label(__('org.exams.results.rank')),
                    Infolists\Components\TextEntry::make('name')->label(__('org.exams.results.learner')),
                    Infolists\Components\TextEntry::make('ratio')->label(__('org.exams.results.correct')),
                    Infolists\Components\TextEntry::make('percent')
                        ->label(__('org.exams.results.percent'))
                        ->formatStateUsing(fn (mixed $state): string => $state.'%'),
                ])
                ->columns(4),
            Infolists\Components\TextEntry::make('absent')
                ->label(__('org.exams.absent.heading'))
                ->placeholder(__('org.exams.absent.empty'))
                ->columnSpanFull()
                ->state(fn (ExamSession $record): array => $report->absent($record))
                ->listWithLineBreaks()
                ->bulleted(),
            Infolists\Components\RepeatableEntry::make('words')
                ->label(__('org.exams.words.heading'))
                ->placeholder(__('org.exams.words.empty'))
                ->columnSpanFull()
                ->state(fn (ExamSession $record): array => array_map(fn (array $row): array => $row + ['ratio' => $row['correct'].' / '.$row['total']], $report->words($record)))
                ->schema([
                    Infolists\Components\TextEntry::make('word')->label(__('org.exams.words.word')),
                    Infolists\Components\TextEntry::make('translation')->label(__('org.exams.words.translation')),
                    Infolists\Components\TextEntry::make('ratio')->label(__('org.exams.words.correct')),
                    Infolists\Components\TextEntry::make('percent')
                        ->label(__('org.exams.words.percent'))
                        ->formatStateUsing(fn (mixed $state): string => $state.'%'),
                ])
                ->columns(4),
        ]);
    }

    /** `Module 2 · Lesson 3` from the stage and lesson numbers the query loaded (no query). */
    public static function lessonLabel(ExamSession $exam): string
    {
        $stage = $exam->lesson?->stage?->number;
        $lesson = $exam->lesson?->number;

        return $stage !== null && $lesson !== null ? __('org.exams.lesson_label', ['stage' => $stage, 'lesson' => $lesson]) : '—';
    }

    /** Minutes from the start to the close (an open exam: to its planned end). */
    public static function durationLabel(ExamSession $exam): string
    {
        $end = $exam->closed_at ?? $exam->ends_at;

        if ($exam->started_at === null || $end === null) {
            return '—';
        }

        $minutes = max(1, (int) round($exam->started_at->diffInSeconds($end, true) / 60));

        return __('org.exams.minutes', ['value' => $minutes]);
    }

    /** Share of right answers of all the answers of the participants (from the sums of the page query); `—` when nobody answered. */
    public static function averageLabel(ExamSession $exam): string
    {
        $total = (int) ($exam->getAttribute('total_sum') ?? 0);

        return $total > 0 ? ExamReportService::percent((int) ($exam->getAttribute('correct_sum') ?? 0), $total).'%' : '—';
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListExams::route('/'),
            'view' => Pages\ViewExam::route('/{record}'),
        ];
    }
}
