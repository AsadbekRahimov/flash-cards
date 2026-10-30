<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Practice\Enums\AudioStatus;
use App\Domain\Practice\Enums\PracticeItemStatus;
use App\Domain\Practice\Enums\PracticeItemType;
use App\Domain\Practice\Services\PracticeItemService;
use App\Filament\Resources\PracticeItemResource\Pages;
use App\Filament\Resources\PracticeItemResource\Support\PracticeItemActions;
use App\Filament\Resources\Support\AdminLabels;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\PracticeItem;
use App\Models\Word;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

/**
 * The tasks of the practice (docs/16 §2.2-§2.4, §12): a collocation, a sentence to build, a phrase to write from dictation.
 * Admin only (PracticeItemPolicy); the cabinet of a center has no such screen.
 *
 * A task is a key plus versions that are never changed: saving the form writes a NEW version (a draft) through
 * PracticeItemService, which also owns the status. The status is never a field of the form: it moves by the buttons
 * "Проверено", "Опубликовать", "В архив" and "Вернуть" ({@see PracticeItemActions}). A published task that has been edited stays
 * published with the old version handed out - the list marks it "есть неопубликованная правка".
 *
 * Everything in a task is untrusted text (it comes from authors and files) and is shown escaped, never as HTML.
 */
class PracticeItemResource extends Resource
{
    protected static ?string $model = PracticeItem::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-puzzle-piece';

    /** Right after the first item of «Контент» (the courses, 5): the group «Практика» sits next to the content. */
    protected static ?int $navigationSort = 6;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.practice');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.practice_items.nav');
    }

    public static function getModelLabel(): string
    {
        return __('admin.practice_items.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.practice_items.plural');
    }

    /**
     * @return Builder<PracticeItem>
     */
    public static function getEloquentQuery(): Builder
    {
        return PracticeItem::query()->with(['course', 'lesson.stage', 'currentVersion', 'publishedVersion']);
    }

    public static function form(Schema $schema): Schema
    {
        $archived = fn (?PracticeItem $record): bool => $record?->status === PracticeItemStatus::Archived;
        $is = fn (string $type): \Closure => fn (Get $get): bool => $get('type') === $type;

        return $schema->components([
            Section::make(__('admin.practice_items.sections.place'))->description(__('admin.practice_items.sections.place_help'))->columns(2)->disabled($archived)->schema([
                Forms\Components\Select::make('course_id')
                    ->label(__('admin.practice_items.fields.course'))
                    ->options(fn (): array => self::courseOptions())
                    ->default(fn (): ?int => Course::query()->where('slug', (string) config('content.default_course_slug'))->value('id'))
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Set $set): void {
                        $set('lesson_id', null);
                        $set('word_ids', []);
                    })
                    // The course of a task, like its type and key, is chosen once.
                    ->disabledOn('edit'),
                Forms\Components\Select::make('type')
                    ->label(__('admin.practice_items.fields.type'))
                    ->options(AdminLabels::options('practice_items.type', PracticeItemType::values()))
                    ->default(PracticeItemType::Sentence->value)
                    ->required()
                    ->live()
                    ->native(false)
                    ->disabledOn('edit'),
                Forms\Components\TextInput::make('key')
                    ->label(__('admin.practice_items.fields.key'))
                    ->helperText(__('admin.practice_items.fields.key_help'))
                    ->required()
                    ->maxLength(64)
                    ->regex((string) config('practice.content.key_pattern'))
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule->where('course_id', $get('course_id')),
                    )
                    ->disabledOn('edit'),
                Forms\Components\Select::make('level')
                    ->label(__('admin.practice_items.fields.level'))
                    ->options(array_combine(PracticeItemService::LEVELS, PracticeItemService::LEVELS))
                    ->placeholder('—')
                    ->native(false),
                Forms\Components\Select::make('lesson_id')
                    ->label(__('admin.practice_items.fields.lesson'))
                    ->options(fn (Get $get): array => self::lessonOptions((int) $get('course_id')))
                    ->placeholder(__('admin.practice_items.whole_course'))
                    ->helperText(__('admin.practice_items.fields.lesson_help'))
                    ->searchable(),
                Forms\Components\Select::make('word_ids')
                    ->label(__('admin.practice_items.fields.words'))
                    ->helperText(__('admin.practice_items.fields.words_help'))
                    ->multiple()
                    ->searchable()
                    ->options(fn (Get $get): array => self::wordOptions((int) $get('course_id'), null))
                    ->getSearchResultsUsing(fn (string $search, Get $get): array => self::wordOptions((int) $get('course_id'), $search))
                    ->getOptionLabelsUsing(fn (array $values): array => self::wordLabels($values))
                    ->columnSpanFull(),
            ]),

            Section::make(__('admin.practice_items.sections.sentence'))->visible($is('sentence'))->disabled($archived)->schema([
                Forms\Components\TextInput::make('sentence.prompt.ru')
                    ->label(__('admin.practice_items.fields.prompt_ru'))
                    ->helperText(__('admin.practice_items.fields.prompt_help'))
                    ->maxLength((int) config('practice.content.prompt_max')),
                Forms\Components\TextInput::make('sentence.prompt.uz')
                    ->label(__('admin.practice_items.fields.prompt_uz'))
                    ->maxLength((int) config('practice.content.prompt_max')),
                Forms\Components\Textarea::make('sentence.fragments')
                    ->label(__('admin.practice_items.fields.fragments'))
                    ->helperText(__('admin.practice_items.fields.fragments_help', ['min' => config('practice.content.sentence_fragments.min'), 'max' => config('practice.content.sentence_fragments.max')]))
                    ->rows(5)
                    ->required(),
                Forms\Components\Textarea::make('sentence.accepted')
                    ->label(__('admin.practice_items.fields.accepted_orders'))
                    ->helperText(__('admin.practice_items.fields.accepted_orders_help', ['max' => config('practice.content.sentence_accepted_max')]))
                    ->placeholder(__('admin.practice_items.fields.accepted_orders_placeholder'))
                    ->rows(4),
                Forms\Components\TextInput::make('sentence.display')
                    ->label(__('admin.practice_items.fields.display'))
                    ->helperText(__('admin.practice_items.fields.display_help'))
                    ->maxLength((int) config('practice.content.prompt_max')),
                Forms\Components\Textarea::make('sentence.explanation.ru')
                    ->label(__('admin.practice_items.fields.explanation_ru'))
                    ->rows(2)
                    ->maxLength((int) config('practice.content.explanation_max')),
                Forms\Components\Textarea::make('sentence.explanation.uz')
                    ->label(__('admin.practice_items.fields.explanation_uz'))
                    ->rows(2)
                    ->maxLength((int) config('practice.content.explanation_max')),
            ]),

            Section::make(__('admin.practice_items.sections.collocation'))->visible($is('collocation'))->disabled($archived)->schema([
                Forms\Components\Textarea::make('collocation.text')
                    ->label(__('admin.practice_items.fields.gap_text'))
                    ->helperText(__('admin.practice_items.fields.gap_text_help'))
                    ->rows(2)
                    ->required()
                    ->maxLength((int) config('practice.content.collocation_text_max')),
                Forms\Components\Repeater::make('collocation.options')
                    ->label(__('admin.practice_items.fields.options'))
                    ->helperText(__('admin.practice_items.fields.options_help'))
                    ->schema([
                        Forms\Components\TextInput::make('text')
                            ->label(__('admin.practice_items.fields.option_text'))
                            ->maxLength((int) config('practice.content.fragment_max_length')),
                        Forms\Components\Toggle::make('correct')
                            ->label(__('admin.practice_items.fields.option_correct'))
                            ->inline(false),
                    ])
                    ->columns(2)
                    ->defaultItems((int) config('practice.content.collocation_options'))
                    ->minItems((int) config('practice.content.collocation_options'))
                    ->maxItems((int) config('practice.content.collocation_options'))
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false),
                Forms\Components\TextInput::make('collocation.translation.ru')
                    ->label(__('admin.practice_items.fields.translation_ru'))
                    ->maxLength((int) config('practice.content.prompt_max')),
                Forms\Components\TextInput::make('collocation.translation.uz')
                    ->label(__('admin.practice_items.fields.translation_uz'))
                    ->maxLength((int) config('practice.content.prompt_max')),
                Forms\Components\Textarea::make('collocation.explanation.ru')
                    ->label(__('admin.practice_items.fields.explanation_ru'))
                    ->rows(2)
                    ->maxLength((int) config('practice.content.explanation_max')),
                Forms\Components\Textarea::make('collocation.explanation.uz')
                    ->label(__('admin.practice_items.fields.explanation_uz'))
                    ->rows(2)
                    ->maxLength((int) config('practice.content.explanation_max')),
            ]),

            Section::make(__('admin.practice_items.sections.dictation'))->visible($is('dictation'))->disabled($archived)->schema([
                Forms\Components\TextInput::make('dictation.text')
                    ->label(__('admin.practice_items.fields.phrase'))
                    ->helperText(__('admin.practice_items.fields.phrase_help'))
                    ->required()
                    ->maxLength((int) config('practice.content.dictation_text_max')),
                Forms\Components\Textarea::make('dictation.accepted')
                    ->label(__('admin.practice_items.fields.accepted_variants'))
                    ->helperText(__('admin.practice_items.fields.accepted_variants_help', ['max' => config('practice.content.dictation_accepted_max')]))
                    ->rows(3),
                Forms\Components\TextInput::make('dictation.translation.ru')
                    ->label(__('admin.practice_items.fields.translation_ru'))
                    ->maxLength((int) config('practice.content.prompt_max')),
                Forms\Components\TextInput::make('dictation.translation.uz')
                    ->label(__('admin.practice_items.fields.translation_uz'))
                    ->maxLength((int) config('practice.content.prompt_max')),
                Forms\Components\Textarea::make('dictation.explanation.ru')
                    ->label(__('admin.practice_items.fields.explanation_ru_optional'))
                    ->rows(2)
                    ->maxLength((int) config('practice.content.explanation_max')),
                Forms\Components\Textarea::make('dictation.explanation.uz')
                    ->label(__('admin.practice_items.fields.explanation_uz_optional'))
                    ->rows(2)
                    ->maxLength((int) config('practice.content.explanation_max')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('course.slug')
                    ->label(__('admin.practice_items.fields.course'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('lesson_label')
                    ->label(__('admin.practice_items.fields.lesson'))
                    ->state(fn (PracticeItem $record): string => self::lessonLabel($record)),
                Tables\Columns\TextColumn::make('type')
                    ->label(__('admin.practice_items.fields.type'))
                    ->badge()
                    ->state(fn (PracticeItem $record): string => $record->type->value)
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('practice_items.type', $state))
                    ->color('info')
                    ->sortable(),
                Tables\Columns\TextColumn::make('key')
                    ->label(__('admin.practice_items.fields.key'))
                    ->searchable()
                    ->copyable()
                    ->fontFamily('mono')
                    ->sortable(),
                Tables\Columns\TextColumn::make('preview')
                    ->label(__('admin.practice_items.columns.preview'))
                    ->state(fn (PracticeItem $record): string => self::preview($record))
                    ->limit(60)
                    ->wrap(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('admin.practice_items.fields.status'))
                    ->badge()
                    ->state(fn (PracticeItem $record): string => $record->status->value)
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('practice_items.status', $state))
                    ->color(fn (string $state): string => match ($state) {
                        PracticeItemStatus::Published->value => 'success',
                        PracticeItemStatus::Reviewed->value => 'info',
                        PracticeItemStatus::Archived->value => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\IconColumn::make('has_unpublished_edit')
                    ->label(__('admin.practice_items.columns.unpublished_edit'))
                    ->state(fn (PracticeItem $record): bool => self::hasUnpublishedEdit($record))
                    ->boolean()
                    ->trueIcon('heroicon-o-pencil-square')
                    ->falseIcon('')
                    ->trueColor('warning')
                    ->tooltip(fn (PracticeItem $record): string => self::hasUnpublishedEdit($record) ? __('admin.practice_items.columns.unpublished_edit_help') : ''),
                Tables\Columns\TextColumn::make('audio')
                    ->label(__('admin.practice_items.columns.audio'))
                    ->badge()
                    ->state(fn (PracticeItem $record): ?string => self::audioStatus($record))
                    ->description(fn (PracticeItem $record): ?string => self::draftAudioNote($record))
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('practice_items.audio', $state))
                    ->color(fn (?string $state): string => match ($state) {
                        AudioStatus::Ready->value => 'success',
                        AudioStatus::Pending->value => 'warning',
                        AudioStatus::Failed->value => 'danger',
                        default => 'gray',
                    })
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('version')
                    ->label(__('admin.practice_items.columns.version'))
                    ->state(fn (PracticeItem $record): ?int => $record->currentVersion?->number)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('admin.practice_items.columns.updated_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('course_id')
                    ->label(__('admin.practice_items.fields.course'))
                    ->options(fn (): array => self::courseOptions()),
                Tables\Filters\SelectFilter::make('type')
                    ->label(__('admin.practice_items.fields.type'))
                    ->options(AdminLabels::options('practice_items.type', PracticeItemType::values())),
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('admin.practice_items.fields.status'))
                    ->options(AdminLabels::options('practice_items.status', array_map(static fn (PracticeItemStatus $status): string => $status->value, PracticeItemStatus::cases()))),
                // The audio of a dictation: the version the learners get (the published one, else the current one) or the draft that waits
                // behind it - the column shows the first and notes the second. The value comes from the client: only the known statuses count.
                Tables\Filters\SelectFilter::make('audio')
                    ->label(__('admin.practice_items.columns.audio'))
                    ->options(AdminLabels::options('practice_items.audio', [AudioStatus::Pending->value, AudioStatus::Ready->value, AudioStatus::Failed->value]))
                    ->query(function (Builder $query, array $data): Builder {
                        $status = $data['value'] ?? null;

                        return in_array($status, [AudioStatus::Pending->value, AudioStatus::Ready->value, AudioStatus::Failed->value], true)
                            ? $query->where(fn (Builder $any): Builder => $any
                                ->whereHas('publishedVersion', fn (Builder $version): Builder => $version->where('audio_status', $status))
                                ->orWhereHas('currentVersion', fn (Builder $version): Builder => $version->where('audio_status', $status)))
                            : $query;
                    }),
                Tables\Filters\TernaryFilter::make('unpublished_edit')
                    ->label(__('admin.practice_items.columns.unpublished_edit'))
                    ->placeholder(__('admin.common.all'))
                    ->trueLabel(__('admin.common.yes'))
                    ->falseLabel(__('admin.common.no'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('published_version_id')->whereColumn('current_version_id', '<>', 'published_version_id'),
                        false: fn (Builder $query): Builder => $query->where(fn (Builder $q): Builder => $q->whereNull('published_version_id')->orWhereColumn('current_version_id', 'published_version_id')),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->emptyStateHeading(__('admin.practice_items.empty'))
            ->actions([
                Actions\EditAction::make(),
                ...PracticeItemActions::all(),
            ])
            ->defaultSort('id', 'desc');
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPracticeItems::route('/'),
            'create' => Pages\CreatePracticeItem::route('/create'),
            'edit' => Pages\EditPracticeItem::route('/{record}/edit'),
        ];
    }

    /** «Модуль 2 · Урок 3», or a dash for a task of the whole course. */
    public static function lessonLabel(PracticeItem $record): string
    {
        $stage = $record->lesson?->stage?->number;
        $lesson = $record->lesson?->number;

        return $stage !== null && $lesson !== null
            ? __('admin.practice_items.lesson_label', ['stage' => $stage, 'lesson' => $lesson])
            : '—';
    }

    /** A published task whose newer version is not published yet: the learners still get the old one. */
    public static function hasUnpublishedEdit(PracticeItem $record): bool
    {
        return $record->published_version_id !== null && $record->current_version_id !== $record->published_version_id;
    }

    /**
     * The audio of the version the learners get - the published one, and the current one while the task has not been published; null
     * for the other types. A published version whose audio is not ready is not handed out, so this is the status that matters.
     */
    private static function audioStatus(PracticeItem $record): ?string
    {
        if ($record->type !== PracticeItemType::Dictation) {
            return null;
        }

        return ($record->publishedVersion ?? $record->currentVersion)?->audio_status->value;
    }

    /** «Правка: ошибка» - a small note when the draft waiting behind the published version has another audio status than it. */
    private static function draftAudioNote(PracticeItem $record): ?string
    {
        if ($record->type !== PracticeItemType::Dictation || $record->published_version_id === null || $record->current_version_id === $record->published_version_id) {
            return null;
        }

        $draft = $record->currentVersion?->audio_status;
        if ($draft === null || $draft === $record->publishedVersion?->audio_status) {
            return null;
        }

        return __('admin.practice_items.columns.audio_draft', ['status' => mb_strtolower(AdminLabels::value('practice_items.audio', $draft->value))]);
    }

    /** The English text of a task for the list: what the learner works on (a sentence: the sentence, not its prompt). */
    private static function preview(PracticeItem $record): string
    {
        $content = $record->currentVersion->content ?? [];

        $text = match ($record->type) {
            PracticeItemType::Sentence => $content['display'] ?? (is_array($content['fragments'] ?? null) ? implode(' ', array_filter($content['fragments'], 'is_string')) : ''),
            default => $content['text'] ?? '',
        };

        return is_string($text) ? $text : '';
    }

    /**
     * Platform and center courses (a deck of a learner has no tasks), by their code and Russian title.
     *
     * @return array<int, string> course id => caption
     */
    private static function courseOptions(): array
    {
        $options = [];
        foreach (Course::query()->whereIn('owner_type', ['platform', 'organization'])->orderBy('slug')->get(['id', 'slug', 'title']) as $course) {
            $title = $course->title['ru'] ?? '';
            $options[$course->id] = $title !== '' ? "{$course->slug} — {$title}" : $course->slug;
        }

        return $options;
    }

    /**
     * The lessons of the course in the study order, as «Модуль 2 · Урок 3».
     *
     * @return array<int, string> lesson id => caption
     */
    private static function lessonOptions(int $courseId): array
    {
        $options = [];
        foreach (Lesson::query()
            ->join('stages', 'stages.id', '=', 'lessons.stage_id')
            ->where('stages.course_id', $courseId)
            ->orderBy('stages.number')
            ->orderBy('lessons.number')
            ->get(['lessons.id', 'lessons.number', 'stages.number as stage_number']) as $lesson) {
            $options[$lesson->id] = __('admin.practice_items.lesson_label', ['stage' => $lesson->getAttribute('stage_number'), 'lesson' => $lesson->number]);
        }

        return $options;
    }

    /**
     * Active words of the course (the target words of a task are chosen among them): the first fifty, or the fifty that match
     * what was typed in the word or in its translation.
     *
     * @return array<int, string> word id => «word — translation»
     */
    private static function wordOptions(int $courseId, ?string $search): array
    {
        $term = trim((string) $search);

        $words = Word::query()
            ->join('lessons', 'lessons.id', '=', 'words.lesson_id')
            ->join('stages', 'stages.id', '=', 'lessons.stage_id')
            ->where('stages.course_id', $courseId)
            ->where('words.is_active', true)
            ->when($term !== '', fn (Builder $query): Builder => $query->where(fn (Builder $q): Builder => $q
                ->whereLike('words.word', "%{$term}%")
                ->orWhereLike('words.translation', "%{$term}%")))
            ->orderBy('stages.number')
            ->orderBy('lessons.number')
            ->orderBy('words.id')
            ->limit(50)
            ->get(['words.id', 'words.word', 'words.translation']);

        $options = [];
        foreach ($words as $word) {
            $options[$word->id] = self::wordLabel($word);
        }

        return $options;
    }

    /**
     * @param  array<array-key, mixed>  $ids
     * @return array<int, string>
     */
    private static function wordLabels(array $ids): array
    {
        $options = [];
        foreach (Word::query()->whereIn('id', array_filter($ids, 'is_numeric'))->get(['id', 'word', 'translation']) as $word) {
            $options[$word->id] = self::wordLabel($word);
        }

        return $options;
    }

    private static function wordLabel(Word $word): string
    {
        return $word->word.' — '.$word->translation;
    }
}
