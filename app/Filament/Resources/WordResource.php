<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Administration\Services\PanelDataScope;
use App\Filament\Resources\WordResource\Pages;
use App\Filament\Resources\WordResource\RelationManagers\WordTranslationsRelationManager;
use App\Models\Word;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Word screen: the host of the per-language translations (WordTranslationsRelationManager).
 * Words are CREATED on the lesson screen or by the import; they are never deleted (docs/03 §8) —
 * a word is hidden with `is_active` instead.
 */
class WordResource extends Resource
{
    protected static ?string $model = Word::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-language';

    protected static ?int $navigationSort = 25;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.content');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.words.nav');
    }

    public static function getModelLabel(): string
    {
        return __('admin.words.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.words.plural');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Teachers do not see the content of learners' private decks.
     *
     * @return Builder<Word>
     */
    public static function getEloquentQuery(): Builder
    {
        return app(PanelDataScope::class)->words();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('word')->label(__('admin.words.fields.word'))->required()->maxLength(100),
            Forms\Components\TextInput::make('translation')
                ->label(__('admin.words.fields.translation'))
                ->required()
                ->maxLength(500)
                ->helperText(__('admin.words.fields.translation_help')),
            Forms\Components\Textarea::make('example')->label(__('admin.words.fields.example'))->rows(2)->columnSpanFull(),
            Forms\Components\TextInput::make('transcription')->label(__('admin.words.fields.transcription'))->maxLength(100),
            Forms\Components\Toggle::make('is_active')->label(__('admin.words.fields.is_active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('word')->label(__('admin.words.fields.word'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('translation')->label(__('admin.words.columns.translation'))->searchable()->limit(30),
                Tables\Columns\TextColumn::make('lesson.stage.course.slug')->label(__('admin.words.columns.course')),
                Tables\Columns\TextColumn::make('lesson.stage.number')->label(__('admin.words.columns.stage')),
                Tables\Columns\TextColumn::make('lesson.number')->label(__('admin.words.columns.lesson')),
                Tables\Columns\TextColumn::make('translations_count')->counts('translations')->label(__('admin.words.columns.languages')),
                Tables\Columns\IconColumn::make('is_active')->boolean()->label(__('admin.words.fields.is_active')),
            ])
            ->emptyStateHeading(__('admin.words.empty'))
            ->actions([
                Actions\EditAction::make()->label(__('admin.words.actions.translations')),
            ])
            ->defaultSort('id');
    }

    /** @return array<int, class-string> */
    public static function getRelations(): array
    {
        return [WordTranslationsRelationManager::class];
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWords::route('/'),
            'edit' => Pages\EditWord::route('/{record}/edit'),
        ];
    }
}
