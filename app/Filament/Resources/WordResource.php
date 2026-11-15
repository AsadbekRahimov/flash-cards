<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Administration\Services\PanelDataScope;
use App\Domain\Content\Services\WordContentEditor;
use App\Filament\Resources\Support\AdminTable;
use App\Filament\Resources\WordResource\Pages;
use App\Filament\Resources\WordResource\RelationManagers\WordExamplesRelationManager;
use App\Filament\Resources\WordResource\RelationManagers\WordTranslationsRelationManager;
use App\Models\Word;
use Closure;
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

    /**
     * The columns the list selects: what the columns and the policy of the row read (docs/20 §1.4) - not the example, the
     * audio path or the meta of a word.
     *
     * @var list<string>
     */
    private const LIST_COLUMNS = ['words.id', 'words.lesson_id', 'words.word', 'words.translation', 'words.is_active'];

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
            Forms\Components\TagsInput::make('confusable_with')
                ->label(__('admin.words.fields.confusable_with'))
                ->helperText(__('admin.words.fields.confusable_with_help'))
                ->placeholder(__('admin.words.fields.confusable_with_placeholder'))
                ->splitKeys([',', 'Enter'])
                ->nestedRecursiveRules(['string', 'max:100'])
                ->rule('max:'.WordContentEditor::MAX_CONFUSABLE)
                // Not a column: written by EditWord::afterSave through the content editor into `words.meta`.
                ->dehydrated(false)
                ->afterStateHydrated(function (Forms\Components\TagsInput $component, ?Word $record): void {
                    $component->state($record?->confusableWith() ?? []);
                })
                ->rule(fn (?Word $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                    if ($record === null || ! is_array($value)) {
                        return;
                    }
                    $unknown = app(WordContentEditor::class)->unknownConfusable($record, $value);
                    if ($unknown !== []) {
                        $fail(__('admin.words.fields.confusable_with_unknown', ['words' => implode(', ', $unknown)]));
                    }
                })
                ->columnSpanFull(),
            Forms\Components\Toggle::make('is_active')->label(__('admin.words.fields.is_active'))->default(true),
        ]);
    }

    /**
     * The list: the query of the page narrowed to LIST_COLUMNS, and the course path of a word as the columns of the lesson, the
     * stage and the course read it (their numbers and the code of the course, plus the owner of the course for the policy of
     * the row).
     *
     * @param  Builder<Word>  $query  {@see getEloquentQuery()}
     * @return Builder<Word>
     */
    private static function forList(Builder $query): Builder
    {
        return $query
            ->select(self::LIST_COLUMNS)
            ->with(['lesson:id,number,stage_id', 'lesson.stage:id,number,course_id', 'lesson.stage.course:id,slug,owner_type']);
    }

    public static function table(Table $table): Table
    {
        return AdminTable::big($table)
            // A row action loads its own record (`$isResolvingRecord`): the whole row.
            ->modifyQueryUsing(fn (Builder $query, bool $isResolvingRecord): Builder => $isResolvingRecord ? $query : self::forList($query))
            ->columns([
                Tables\Columns\TextColumn::make('word')->label(__('admin.words.fields.word'))->searchable()->sortable(),
                // The longer text of the two is searched from its own box in the header, not by the search of the table.
                Tables\Columns\TextColumn::make('translation')->label(__('admin.words.columns.translation'))->searchable(isIndividual: true, isGlobal: false)->limit(30),
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
        return [WordTranslationsRelationManager::class, WordExamplesRelationManager::class];
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
