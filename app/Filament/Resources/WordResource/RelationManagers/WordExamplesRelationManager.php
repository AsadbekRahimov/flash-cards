<?php

declare(strict_types=1);

namespace App\Filament\Resources\WordResource\RelationManagers;

use App\Domain\Content\Services\WordContentEditor;
use App\Domain\Localization\Services\LocaleResolver;
use App\Filament\Resources\Support\AdminTable;
use App\Models\Word;
use App\Models\WordExample;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Exposed;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Additional examples of a word (`word_examples`, docs/06 §12.4): the anchor (`words.example`) is edited on the word's own
 * form; these rows rotate after it. Every write — create, edit, delete, drag-and-drop order — goes through
 * {@see WordContentEditor}, which drops the cached examples of the word after the commit.
 */
class WordExamplesRelationManager extends RelationManager
{
    protected static string $relationship = 'examples';

    protected static ?string $recordTitleAttribute = 'text';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.words.examples.title');
    }

    public function form(Schema $schema): Schema
    {
        $fields = [
            Forms\Components\Textarea::make('text')
                ->label(__('admin.words.examples.fields.text'))
                ->required()
                ->rows(2)
                ->maxLength(2000)
                ->columnSpanFull(),
        ];
        foreach (app(LocaleResolver::class)->supported() as $locale) {
            $fields[] = Forms\Components\Textarea::make("translations.{$locale}")
                ->label(__('admin.words.examples.fields.translation', ['locale' => strtoupper($locale)]))
                ->rows(2)
                ->maxLength(2000)
                ->columnSpanFull();
        }

        return $schema->components($fields);
    }

    public function table(Table $table): Table
    {
        return AdminTable::bounded($table)
            ->modelLabel(mb_strtolower(__('admin.words.examples.singular')))
            ->pluralModelLabel(__('admin.words.examples.plural'))
            ->emptyStateHeading(__('admin.words.examples.empty'))
            ->emptyStateDescription(__('admin.words.examples.empty_help'))
            ->columns([
                Tables\Columns\TextColumn::make('position')->label(__('admin.words.examples.columns.position')),
                Tables\Columns\TextColumn::make('text')->label(__('admin.words.examples.fields.text'))->limit(80),
                Tables\Columns\TextColumn::make('translations.ru')->label(__('admin.words.examples.columns.ru'))->limit(60),
                Tables\Columns\TextColumn::make('translations.uz')->label(__('admin.words.examples.columns.uz'))->limit(60),
            ])
            ->reorderable('position')
            ->headerActions([
                Actions\CreateAction::make()
                    ->label(__('admin.words.examples.create'))
                    ->modalHeading(__('admin.words.examples.create'))
                    ->visible(fn (): bool => $this->word()->examples()->count() < WordContentEditor::MAX_EXAMPLES)
                    ->using(fn (array $data): Model => app(WordContentEditor::class)->addExample(
                        $this->word(),
                        (string) $data['text'],
                        $this->translationsOf($data),
                    )),
            ])
            ->actions([
                Actions\EditAction::make()
                    ->using(fn (array $data, WordExample $record): Model => app(WordContentEditor::class)->updateExample(
                        $record,
                        (string) $data['text'],
                        $this->translationsOf($data),
                    )),
                Actions\DeleteAction::make()
                    ->using(fn (WordExample $record) => app(WordContentEditor::class)->deleteExample($record)),
            ])
            ->defaultSort('position');
    }

    /**
     * The order goes through the editor: the table's own reorder is one UPDATE over a unique `(word_id, position)`.
     *
     * @param  array<int|string>  $order
     */
    #[Exposed]
    public function reorderTable(array $order, int|string|null $draggedRecordKey = null): void
    {
        if (! $this->getTable()->isReorderable()) {
            return;
        }

        app(WordContentEditor::class)->reorderExamples($this->word(), $order);

        $this->flushCachedTableRecords();
    }

    private function word(): Word
    {
        /** @var Word $word */
        $word = $this->getOwnerRecord();

        return $word;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string|null>
     */
    private function translationsOf(array $data): array
    {
        $raw = is_array($data['translations'] ?? null) ? $data['translations'] : [];

        return array_map(static fn (mixed $v): ?string => is_string($v) ? $v : null, $raw);
    }
}
