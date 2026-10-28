<?php

declare(strict_types=1);

namespace App\Filament\Resources\WordResource\RelationManagers;

use App\Domain\Content\Services\WordTranslationWriter;
use App\Domain\Localization\Services\LocaleResolver;
use App\Filament\Resources\Support\AdminLabels;
use App\Models\Word;
use App\Models\WordTranslation;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;

/**
 * Per-language translations of a word (docs/12 §1.7). Saving goes through
 * {@see WordTranslationWriter}: the ru row and `words.translation` stay in step, and a changed
 * text becomes a `manual` row that waits for a native-speaker check ("Mark reviewed").
 */
class WordTranslationsRelationManager extends RelationManager
{
    protected static string $relationship = 'translations';

    protected static ?string $recordTitleAttribute = 'translation';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.words.translations.title');
    }

    public function form(Schema $schema): Schema
    {
        $locales = app(LocaleResolver::class)->supported();

        return $schema->components([
            Forms\Components\Select::make('locale')
                ->label(__('admin.words.translations.fields.locale'))
                ->options(array_combine($locales, array_map('strtoupper', $locales)))
                ->required()
                ->disabledOn('edit')
                // One translation per language and word.
                ->unique(
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('word_id', $this->getOwnerRecord()->getKey()),
                ),
            Forms\Components\TextInput::make('translation')->label(__('admin.words.translations.fields.translation'))->required()->maxLength(500),
            Forms\Components\Textarea::make('example_translation')->label(__('admin.words.translations.fields.example_translation'))->rows(2)->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modelLabel(mb_strtolower(__('admin.words.translations.singular')))
            ->pluralModelLabel(__('admin.words.translations.plural'))
            ->emptyStateHeading(__('admin.words.translations.empty'))
            ->columns([
                Tables\Columns\TextColumn::make('locale')->label(__('admin.words.translations.fields.locale'))->badge()->sortable(),
                Tables\Columns\TextColumn::make('translation')->label(__('admin.words.translations.fields.translation'))->searchable()->limit(50),
                Tables\Columns\TextColumn::make('source')
                    ->label(__('admin.words.translations.columns.source'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => AdminLabels::value('words.translations.source', $state))
                    ->toggleable(),
                Tables\Columns\IconColumn::make('reviewed_at')
                    ->label(__('admin.words.translations.columns.reviewed'))
                    ->boolean()
                    ->getStateUsing(fn (WordTranslation $record): bool => $record->reviewed_at !== null),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->label(__('admin.words.translations.create'))
                    ->using(fn (array $data, RelationManager $livewire): Model => $this->write($livewire->getOwnerRecord(), $data)),
            ])
            ->actions([
                Actions\Action::make('markReviewed')
                    ->label(__('admin.words.translations.mark_reviewed'))
                    ->icon('heroicon-o-check-badge')
                    ->visible(fn (WordTranslation $record): bool => $record->reviewed_at === null)
                    ->authorize('update')
                    ->action(fn (WordTranslation $record) => app(WordTranslationWriter::class)->markReviewed($record)),
                Actions\EditAction::make()
                    ->using(fn (array $data, WordTranslation $record): Model => $this->write($this->getOwnerRecord(), $data + ['locale' => $record->locale])),
                Actions\DeleteAction::make(),
            ])
            ->defaultSort('locale');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function write(Model $owner, array $data): WordTranslation
    {
        /** @var Word $word */
        $word = $owner;

        return app(WordTranslationWriter::class)->save(
            $word,
            (string) $data['locale'],
            (string) $data['translation'],
            isset($data['example_translation']) && $data['example_translation'] !== '' ? (string) $data['example_translation'] : null,
        );
    }
}
