<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Learners\DTO\StartPayload;
use App\Domain\Localization\Services\LocaleResolver;
use App\Filament\Resources\CourseResource\Pages;
use App\Filament\Resources\CourseResource\RelationManagers\StagesRelationManager;
use App\Filament\Resources\CourseResource\Support\CourseTransitionActions;
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

    protected static string|\UnitEnum|null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        $fields = [
            Forms\Components\TextInput::make('slug')
                ->required()
                ->maxLength(StartPayload::COURSE_SLUG_MAX)
                ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                ->helperText('Latin lowercase, digits and dashes, up to '.StartPayload::COURSE_SLUG_MAX.' characters (a course link in the bot, c_<slug>, must fit Telegram\'s 64). Used in links and in the import file ("course"); cannot be changed later.')
                ->unique(ignoreRecord: true)
                ->disabledOn('edit'),
            Forms\Components\TextInput::make('status')
                ->disabled()
                ->dehydrated(false)
                ->visibleOn('edit')
                ->helperText('Changed with the buttons above (draft → review → published → archived).'),
        ];

        // One title / description input per interface language; every language is required for the title.
        foreach (app(LocaleResolver::class)->supported() as $locale) {
            $fields[] = Forms\Components\TextInput::make("title.{$locale}")
                ->label('Title ('.strtoupper($locale).')')
                ->required()
                ->maxLength(150);
        }
        foreach (app(LocaleResolver::class)->supported() as $locale) {
            $fields[] = Forms\Components\Textarea::make("description.{$locale}")
                ->label('Description ('.strtoupper($locale).')')
                ->rows(2)
                ->maxLength(1000);
        }

        return $schema->components([
            ...$fields,
            Forms\Components\Select::make('level')
                ->options(array_combine(['A1', 'A2', 'B1', 'B2', 'C1', 'C2'], ['A1', 'A2', 'B1', 'B2', 'C1', 'C2']))
                ->placeholder('—'),
            Forms\Components\Select::make('exam_tag')
                ->label('Goal')
                ->options(['general' => 'General', 'school' => 'School', 'ielts' => 'IELTS', 'sat' => 'SAT'])
                ->placeholder('—'),
            Forms\Components\Select::make('visibility')
                ->options(['public' => 'Public (catalog)', 'unlisted' => 'Unlisted (by link / group)', 'private' => 'Private'])
                ->default('public')
                ->required(),
            Forms\Components\TextInput::make('position')->numeric()->default(0)->required(),
            Forms\Components\Toggle::make('is_featured')->label('Featured'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('slug')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('title.ru')->label('Title (RU)')->limit(40),
                Tables\Columns\TextColumn::make('title.uz')->label('Title (UZ)')->limit(40)->toggleable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'published' => 'success',
                        'review' => 'warning',
                        'archived' => 'gray',
                        default => 'info',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('visibility')->badge()->toggleable(),
                Tables\Columns\TextColumn::make('level')->placeholder('—'),
                Tables\Columns\TextColumn::make('exam_tag')->label('Goal')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('words_count')->label('Words')->sortable(),
                Tables\Columns\TextColumn::make('stages_count')->counts('stages')->label('Modules'),
                Tables\Columns\TextColumn::make('published_at')->dateTime()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'draft' => 'Draft',
                    'review' => 'Review',
                    'published' => 'Published',
                    'archived' => 'Archived',
                ]),
            ])
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
