<?php

declare(strict_types=1);

namespace App\Filament\Org\Resources;

use App\Domain\Organizations\Services\AssignmentService;
use App\Filament\Org\Resources\AssignmentResource\Pages;
use App\Filament\Org\Resources\AssignmentResource\RelationManagers\ResultsRelationManager;
use App\Models\Assignment;
use App\Models\Lesson;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Assignments of the center (docs/13 §1.4, §6.3): a lesson of the group's course with a deadline.
 * Owner — all groups of the organization; teacher — only their own groups (`OrgTenantScope`).
 * Creation and closing go through {@see AssignmentService}; nothing is edited or deleted. The view
 * page shows the per-learner progress snapshot (aggregate only — docs/12 §4.4).
 */
class AssignmentResource extends OrgResource
{
    protected static ?string $model = Assignment::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?int $navigationSort = 50;

    public static function getNavigationLabel(): string
    {
        return __('org.nav.assignments');
    }

    public static function getModelLabel(): string
    {
        return __('org.assignments.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('org.assignments.plural');
    }

    /** @return Builder<Assignment> */
    public static function getEloquentQuery(): Builder
    {
        return static::scope()->assignments(static::user(), static::tenant())
            ->with(['group', 'lesson.stage'])
            ->withProgressCounts();
    }

    /**
     * Groups the user may set an assignment for: their groups that have a course.
     *
     * @return array<int, string>
     */
    public static function groupOptions(): array
    {
        /** @var array<int, string> $options */
        $options = static::scope()->groups(static::user(), static::tenant())
            ->whereNotNull('telegram_groups.course_id')
            ->orderBy('telegram_groups.title')
            ->pluck('telegram_groups.title', 'telegram_groups.id')
            ->all();

        return $options;
    }

    /**
     * Lessons of the course of a group the user may use.
     *
     * @return array<int, string>
     */
    public static function lessonOptions(mixed $groupId): array
    {
        if (! is_numeric($groupId)) {
            return [];
        }

        $courseId = static::scope()->groups(static::user(), static::tenant())
            ->whereKey((int) $groupId)
            ->value('telegram_groups.course_id');

        if ($courseId === null) {
            return [];
        }

        $options = [];

        $rows = Lesson::query()
            ->toBase()
            ->join('stages', 'stages.id', '=', 'lessons.stage_id')
            ->where('stages.course_id', $courseId)
            ->orderBy('stages.number')
            ->orderBy('lessons.number')
            ->get(['lessons.id', 'lessons.number', 'lessons.title', 'stages.number as stage_number']);

        foreach ($rows as $row) {
            $options[(int) $row->id] = __('org.assignments.lesson_option', [
                'stage' => $row->stage_number,
                'lesson' => $row->number,
                'title' => $row->title ?? '',
            ]);
        }

        return $options;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\Select::make('telegram_group_id')
                ->label(__('org.assignments.fields.group'))
                ->helperText(__('org.assignments.fields.group_help'))
                ->options(fn (): array => static::groupOptions())
                ->required()
                ->live()
                ->afterStateUpdated(fn (Set $set) => $set('lesson_id', null)),
            Forms\Components\Select::make('lesson_id')
                ->label(__('org.assignments.fields.lesson'))
                ->helperText(__('org.assignments.fields.lesson_help'))
                ->options(fn (Get $get): array => static::lessonOptions($get('telegram_group_id')))
                ->required(),
            Forms\Components\TextInput::make('title')
                ->label(__('org.assignments.fields.title'))
                ->helperText(__('org.assignments.fields.title_help'))
                ->maxLength(120),
            Forms\Components\DateTimePicker::make('due_at')
                ->label(__('org.assignments.fields.due_at'))
                ->required()
                ->seconds(false)
                ->timezone(fn (): string => static::tenant()->timezone)
                ->minDate(now()),
            Forms\Components\TextInput::make('completion_threshold')
                ->label(__('org.assignments.fields.threshold'))
                ->helperText(__('org.assignments.fields.threshold_help'))
                ->numeric()
                ->integer()
                ->minValue(1)
                ->maxValue(100)
                ->default(100)
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        $timezone = static::tenant()->timezone;

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('group.title')->label(__('org.assignments.columns.group'))->limit(32),
                Tables\Columns\TextColumn::make('title')
                    ->label(__('org.assignments.columns.title'))
                    ->state(fn (Assignment $record): string => $record->displayTitle())
                    ->limit(40),
                Tables\Columns\TextColumn::make('lesson')
                    ->label(__('org.assignments.columns.lesson'))
                    ->state(fn (Assignment $record): string => ($record->lesson->stage->number ?? 0).'.'.$record->lesson->number),
                Tables\Columns\TextColumn::make('due_at')
                    ->label(__('org.assignments.columns.due_at'))
                    ->dateTime('d.m.Y H:i', $timezone)
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('org.assignments.columns.status'))
                    ->badge()
                    ->state(fn (Assignment $record): string => static::statusOf($record))
                    ->formatStateUsing(fn (string $state): string => __('org.assignments.status.'.$state))
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'overdue' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('progress')
                    ->label(__('org.assignments.columns.progress'))
                    ->state(fn (Assignment $record): string => __('org.assignments.progress_of', [
                        'done' => (int) $record->done_count,
                        'total' => (int) $record->total_count,
                    ])),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('org.assignments.columns.status'))
                    ->options([
                        Assignment::STATUS_ACTIVE => __('org.assignments.status.active'),
                        Assignment::STATUS_CLOSED => __('org.assignments.status.closed'),
                    ]),
                Tables\Filters\SelectFilter::make('telegram_group_id')
                    ->label(__('org.assignments.columns.group'))
                    ->options(fn (): array => static::scope()->groups(static::user(), static::tenant())->orderBy('title')->pluck('title', 'id')->all()),
            ])
            ->recordActions([
                Actions\ViewAction::make(),
                Actions\Action::make('close')
                    ->label(__('org.assignments.actions.close'))
                    ->icon('heroicon-o-lock-closed')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(__('org.assignments.actions.close_confirm'))
                    ->visible(fn (Assignment $record): bool => $record->isActive())
                    ->authorize(fn (Assignment $record): bool => static::canEdit($record))
                    ->action(function (Assignment $record): void {
                        app(AssignmentService::class)->close($record);

                        Notification::make()->title(__('org.assignments.notifications.closed'))->success()->send();
                    }),
            ])
            ->defaultSort('due_at', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        $timezone = static::tenant()->timezone;

        return $schema->components([
            Infolists\Components\TextEntry::make('title')
                ->label(__('org.assignments.columns.title'))
                ->state(fn (Assignment $record): string => $record->displayTitle()),
            Infolists\Components\TextEntry::make('group.title')->label(__('org.assignments.columns.group')),
            Infolists\Components\TextEntry::make('lesson')
                ->label(__('org.assignments.columns.lesson'))
                ->state(fn (Assignment $record): string => ($record->lesson->stage->number ?? 0).'.'.$record->lesson->number),
            Infolists\Components\TextEntry::make('due_at')
                ->label(__('org.assignments.columns.due_at'))
                ->dateTime('d.m.Y H:i', $timezone),
            Infolists\Components\TextEntry::make('status')
                ->label(__('org.assignments.columns.status'))
                ->badge()
                ->state(fn (Assignment $record): string => static::statusOf($record))
                ->formatStateUsing(fn (string $state): string => __('org.assignments.status.'.$state)),
            Infolists\Components\TextEntry::make('completion_threshold')
                ->label(__('org.assignments.columns.threshold'))
                ->suffix('%'),
            Infolists\Components\TextEntry::make('progress')
                ->label(__('org.assignments.columns.progress'))
                ->state(fn (Assignment $record): string => __('org.assignments.progress_of', [
                    'done' => (int) $record->done_count,
                    'total' => (int) $record->total_count,
                ])),
        ]);
    }

    /** `active` / `overdue` (active, due date passed) / `closed`. */
    public static function statusOf(Assignment $assignment): string
    {
        if (! $assignment->isActive()) {
            return 'closed';
        }

        return $assignment->isOverdue() ? 'overdue' : 'active';
    }

    /** @return array<int, class-string> */
    public static function getRelations(): array
    {
        return [ResultsRelationManager::class];
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAssignments::route('/'),
            'create' => Pages\CreateAssignment::route('/create'),
            'view' => Pages\ViewAssignment::route('/{record}'),
        ];
    }
}
