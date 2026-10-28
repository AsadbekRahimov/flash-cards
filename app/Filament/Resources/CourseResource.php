<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Learners\DTO\StartPayload;
use App\Domain\Localization\Services\LocaleResolver;
use App\Filament\Resources\CourseResource\Pages;
use App\Filament\Resources\CourseResource\RelationManagers\StagesRelationManager;
use App\Filament\Resources\CourseResource\Support\CourseTransitionActions;
use App\Filament\Resources\Support\AdminLabels;
use App\Models\Course;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Course catalog (docs/12 §1.7). Admin only (CoursePolicy). The status is never edited in the
 * form: it moves through the transition actions, i.e. through CoursePublisher.
 */
class CourseResource extends Resource
{
    protected static ?string $model = Course::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?int $navigationSort = 5;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.content');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.courses.nav');
    }

    public static function getModelLabel(): string
    {
        return __('admin.courses.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.courses.plural');
    }

    public static function form(Schema $schema): Schema
    {
        $fields = [
            Forms\Components\TextInput::make('slug')
                ->label(__('admin.courses.fields.slug'))
                ->required()
                ->maxLength(StartPayload::COURSE_SLUG_MAX)
                ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                ->helperText(__('admin.courses.fields.slug_help', ['max' => StartPayload::COURSE_SLUG_MAX]))
                ->unique(ignoreRecord: true)
                ->disabledOn('edit'),
            Forms\Components\TextInput::make('status')
                ->label(__('admin.courses.fields.status'))
                ->formatStateUsing(fn (?string $state): string => AdminLabels::value('courses.status', $state))
                ->disabled()
                ->dehydrated(false)
                ->visibleOn('edit')
                ->helperText(__('admin.courses.fields.status_help')),
        ];

        // One title / description input per interface language; every language is required for the title.
        foreach (app(LocaleResolver::class)->supported() as $locale) {
            $fields[] = Forms\Components\TextInput::make("title.{$locale}")
                ->label(__('admin.courses.fields.title_locale', ['locale' => strtoupper($locale)]))
                ->required()
                ->maxLength(150);
        }
        foreach (app(LocaleResolver::class)->supported() as $locale) {
            $fields[] = Forms\Components\Textarea::make("description.{$locale}")
                ->label(__('admin.courses.fields.description_locale', ['locale' => strtoupper($locale)]))
                ->rows(2)
                ->maxLength(1000);
        }

        return $schema->components([
            ...$fields,
            Forms\Components\Select::make('level')
                ->label(__('admin.courses.fields.level'))
                ->options(array_combine(['A1', 'A2', 'B1', 'B2', 'C1', 'C2'], ['A1', 'A2', 'B1', 'B2', 'C1', 'C2']))
                ->placeholder('—'),
            Forms\Components\Select::make('exam_tag')
                ->label(__('admin.courses.fields.exam_tag'))
                ->options(AdminLabels::options('courses.exam_tag', ['general', 'school', 'ielts', 'sat']))
                ->placeholder('—'),
            Forms\Components\Select::make('visibility')
                ->label(__('admin.courses.fields.visibility'))
                ->options(AdminLabels::options('courses.visibility', ['public', 'unlisted', 'private']))
                ->default('public')
                ->required(),
            Forms\Components\TextInput::make('position')->label(__('admin.courses.fields.position'))->numeric()->default(0)->required(),
            Forms\Components\Toggle::make('is_featured')->label(__('admin.courses.fields.is_featured')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('slug')->label(__('admin.courses.fields.slug'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('title.ru')->label(__('admin.courses.fields.title_locale', ['locale' => 'RU']))->limit(40),
                Tables\Columns\TextColumn::make('title.uz')->label(__('admin.courses.fields.title_locale', ['locale' => 'UZ']))->limit(40)->toggleable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('admin.courses.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('courses.status', $state))
                    ->color(fn (string $state): string => match ($state) {
                        'published' => 'success',
                        'review' => 'warning',
                        'archived' => 'gray',
                        default => 'info',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('visibility')
                    ->label(__('admin.courses.fields.visibility'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('courses.visibility', $state))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('level')->label(__('admin.courses.fields.level'))->placeholder('—'),
                Tables\Columns\TextColumn::make('exam_tag')
                    ->label(__('admin.courses.fields.exam_tag'))
                    ->formatStateUsing(fn (?string $state): string => AdminLabels::value('courses.exam_tag', $state))
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('words_count')->label(__('admin.courses.columns.words_count'))->sortable(),
                Tables\Columns\TextColumn::make('stages_count')->counts('stages')->label(__('admin.courses.columns.stages_count')),
                Tables\Columns\TextColumn::make('published_at')->label(__('admin.courses.columns.published_at'))->dateTime()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('admin.courses.fields.status'))
                    ->options(AdminLabels::options('courses.status', ['draft', 'review', 'published', 'archived'])),
            ])
            ->emptyStateHeading(__('admin.courses.empty'))
            ->actions([
                ...CourseTransitionActions::all(),
                Actions\EditAction::make(),
            ])
            ->defaultSort('position');
    }

    /** @return array<int, class-string> */
    public static function getRelations(): array
    {
        return [StagesRelationManager::class];
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCourses::route('/'),
            'create' => Pages\CreateCourse::route('/create'),
            'edit' => Pages\EditCourse::route('/{record}/edit'),
        ];
    }
}
