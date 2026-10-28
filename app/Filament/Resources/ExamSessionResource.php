<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Administration\Services\PanelDataScope;
use App\Filament\Resources\ExamSessionResource\Pages;
use App\Filament\Resources\ExamSessionResource\RelationManagers\AnswersRelationManager;
use App\Filament\Resources\ExamSessionResource\RelationManagers\ResultsRelationManager;
use App\Filament\Resources\Support\AdminLabels;
use App\Models\ExamSession;
use Filament\Actions;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ExamSessionResource extends Resource
{
    protected static ?string $model = ExamSession::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.learning');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.exams.nav');
    }

    public static function getModelLabel(): string
    {
        return __('admin.exams.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.exams.plural');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('group.title')->label(__('admin.exams.fields.group')),
            TextEntry::make('lesson_label')
                ->label(__('admin.exams.fields.lesson'))
                ->state(fn (ExamSession $record): string => self::lessonLabel($record)),
            TextEntry::make('status')
                ->label(__('admin.exams.fields.status'))
                ->badge()
                ->formatStateUsing(fn (string $state): string => AdminLabels::value('exams.status', $state)),
            TextEntry::make('startedBy.name')->label(__('admin.exams.fields.started_by'))->placeholder('—'),
            TextEntry::make('started_at')->label(__('admin.exams.fields.started_at'))->dateTime(),
            TextEntry::make('ends_at')->label(__('admin.exams.fields.ends_at'))->dateTime()->placeholder('—'),
            TextEntry::make('closed_at')->label(__('admin.exams.fields.closed_at'))->dateTime()->placeholder('—'),
            TextEntry::make('results_count')->label(__('admin.exams.fields.participants')),
            TextEntry::make('answers_count')->label(__('admin.exams.fields.answers')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('group.title')
                    ->label(__('admin.exams.fields.group'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('lesson.number')
                    ->label(__('admin.exams.fields.lesson'))
                    ->formatStateUsing(fn (ExamSession $record): string => self::lessonLabel($record)),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('admin.exams.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('exams.status', $state))
                    ->color(fn (string $state): string => $state === 'open' ? 'warning' : 'success'),
                Tables\Columns\TextColumn::make('results_count')
                    ->label(__('admin.exams.fields.participants'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('answers_count')
                    ->label(__('admin.exams.fields.answers'))
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('started_at')
                    ->label(__('admin.exams.fields.started_at'))
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('ends_at')
                    ->label(__('admin.exams.fields.ends_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('telegram_group_id')
                    ->options(fn (): array => app(PanelDataScope::class)
                        ->groups()
                        ->orderBy('title')
                        ->pluck('title', 'id')
                        ->all())
                    ->label(__('admin.exams.fields.group')),
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('admin.exams.fields.status'))
                    ->options(AdminLabels::options('exams.status', ['open', 'closed'])),
            ])
            ->emptyStateHeading(__('admin.exams.empty'))
            ->actions([
                Actions\ViewAction::make(),
            ])
            ->defaultSort('started_at', 'desc');
    }

    /** @return Builder<ExamSession> */
    public static function getEloquentQuery(): Builder
    {
        return app(PanelDataScope::class)
            ->exams()
            ->with(['group', 'lesson.stage', 'startedBy'])
            ->withCount(['results', 'answers']);
    }

    /** @return array<int, class-string> */
    public static function getRelations(): array
    {
        return [
            ResultsRelationManager::class,
            AnswersRelationManager::class,
        ];
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListExamSessions::route('/'),
            'view' => Pages\ViewExamSession::route('/{record}'),
        ];
    }

    private static function lessonLabel(ExamSession $session): string
    {
        $stage = $session->lesson?->stage?->number;
        $lesson = $session->lesson?->number;

        return $stage !== null && $lesson !== null
            ? __('admin.exams.lesson_label', ['stage' => $stage, 'lesson' => $lesson])
            : '—';
    }
}
