<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Learners\Support\Regions;
use App\Domain\Polls\Enums\PollStatus;
use App\Domain\Polls\Services\PollAudienceQuery;
use App\Domain\Polls\Services\PollEditor;
use App\Domain\Polls\Services\PollViewCache;
use App\Domain\Telegram\Services\PollMessenger;
use App\Filament\Resources\PollResource\Pages;
use App\Filament\Resources\Support\AdminLabels;
use App\Filament\Resources\Support\AdminNumbers;
use App\Filament\Resources\Support\AdminTable;
use App\Models\Course;
use App\Models\Poll;
use App\Policies\PollPolicy;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Polls in the bot (docs/35 §1.5, O6-O8). Active platform admin only ({@see PollPolicy}); not in `/org`.
 *
 * The form is a draft until the launch: the question (ru, uz), 2-10 options, the picture, one or several answers, the change of an
 * answer, the deadline and the audience (plan, language, course, region - «Другая страна» is the region `other`). Its fields go to
 * {@see PollEditor}, which refuses on the server what may not change any more (after the launch only the title and the deadline) -
 * the disabled fields here are the display of the same rule.
 *
 * The preview is the text a learner reads, built in memory from the form ({@see PollMessenger}); the number of recipients is
 * counted only on the button «Посчитать получателей» ({@see PollAudienceQuery::count()}, one statement) - never per keystroke.
 * The test send, the launch, the stop and the closing are the header actions of the edit page ({@see Pages\EditPoll}), the results
 * are their own page ({@see Pages\ViewPollResults}).
 *
 * The list reads only the narrow columns of `polls` (no question, no snapshot); no statement per row.
 */
class PollResource extends Resource
{
    protected static ?string $model = Poll::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?int $navigationSort = 40;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.administration');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.polls.nav');
    }

    public static function getModelLabel(): string
    {
        return __('admin.polls.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.polls.plural');
    }

    /** The question, the options, the picture, the answer rules and the audience no longer change. */
    public static function launched(?Poll $record): bool
    {
        return $record !== null && $record->status !== PollStatus::Draft;
    }

    public static function finished(?Poll $record): bool
    {
        return $record !== null && in_array($record->status, [PollStatus::Closed, PollStatus::Cancelled], true);
    }

    public static function form(Schema $schema): Schema
    {
        $locked = fn (?Poll $record): bool => self::launched($record);

        return $schema->components([
            Section::make(__('admin.polls.sections.question'))
                ->description(fn (?Poll $record): ?string => self::launched($record) ? (string) __('admin.polls.sections.locked') : null)
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('title')
                        ->label(__('admin.polls.fields.title'))
                        ->helperText(__('admin.polls.fields.title_help'))
                        ->required()
                        ->maxLength(PollEditor::TITLE_MAX)
                        ->columnSpanFull(),
                    Forms\Components\Textarea::make('question.ru')
                        ->label(__('admin.polls.fields.question_ru'))
                        ->required()
                        ->maxLength(PollEditor::QUESTION_MAX)
                        ->rows(4)
                        ->disabled($locked),
                    Forms\Components\Textarea::make('question.uz')
                        ->label(__('admin.polls.fields.question_uz'))
                        ->required()
                        ->maxLength(PollEditor::QUESTION_MAX)
                        ->rows(4)
                        ->disabled($locked),
                    Forms\Components\FileUpload::make('image_path')
                        ->label(__('admin.polls.fields.image'))
                        ->helperText(__('admin.polls.fields.image_help', ['mb' => AdminNumbers::decimal(max(1, (int) config('polls.image_max_kb', 5120)) / 1024, 1, true)]))
                        ->disk(PollEditor::IMAGE_DISK)
                        ->directory(PollEditor::IMAGE_DIRECTORY)
                        ->visibility('private')
                        ->acceptedFileTypes(array_keys(PollEditor::IMAGE_TYPES))
                        ->maxSize(max(1, (int) config('polls.image_max_kb', 5120)))
                        // The stored name is never the uploader's: 40 random characters and the extension of the checked type.
                        ->getUploadedFileNameForStorageUsing(fn (TemporaryUploadedFile $file): string => Str::random(40).'.'.(PollEditor::IMAGE_TYPES[(string) $file->getMimeType()] ?? 'jpg'))
                        ->disabled($locked)
                        ->columnSpanFull(),
                ]),
            Section::make(__('admin.polls.sections.options'))
                ->schema([
                    Forms\Components\Repeater::make('options')
                        ->label(__('admin.polls.fields.options'))
                        ->helperText(__('admin.polls.fields.options_help', ['max' => PollEditor::LABEL_MAX]))
                        ->schema([
                            Forms\Components\TextInput::make('label.ru')->label(__('admin.polls.fields.label_ru'))->required()->maxLength(PollEditor::LABEL_MAX),
                            Forms\Components\TextInput::make('label.uz')->label(__('admin.polls.fields.label_uz'))->required()->maxLength(PollEditor::LABEL_MAX),
                        ])
                        ->columns(2)
                        ->minItems(PollEditor::MIN_OPTIONS)
                        ->maxItems(PollEditor::MAX_OPTIONS)
                        ->defaultItems(PollEditor::MIN_OPTIONS)
                        ->addActionLabel(__('admin.polls.fields.add_option'))
                        ->reorderable()
                        ->disabled($locked),
                    Forms\Components\Toggle::make('allows_multiple')
                        ->label(__('admin.polls.fields.allows_multiple'))
                        ->live()
                        ->disabled($locked),
                    Forms\Components\TextInput::make('max_choices')
                        ->label(__('admin.polls.fields.max_choices'))
                        ->helperText(__('admin.polls.fields.max_choices_help'))
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->maxValue(PollEditor::MAX_OPTIONS)
                        ->visible(fn (Get $get): bool => (bool) $get('allows_multiple'))
                        ->disabled($locked),
                    Forms\Components\Toggle::make('allow_change')
                        ->label(__('admin.polls.fields.allow_change'))
                        ->default(true)
                        ->disabled($locked),
                    Forms\Components\DateTimePicker::make('closes_at')
                        ->label(__('admin.polls.fields.closes_at'))
                        ->helperText(__('admin.polls.fields.closes_at_help', ['tz' => config('learners.default_timezone')]))
                        ->timezone(config('learners.default_timezone'))
                        ->seconds(false)
                        ->disabled(fn (?Poll $record): bool => self::finished($record)),
                ]),
            Section::make(__('admin.polls.sections.audience'))
                ->key('audienceSection')
                ->description(__('admin.polls.sections.audience_help'))
                ->columns(2)
                ->headerActions([
                    Action::make('countRecipients')
                        ->label(__('admin.polls.actions.count'))
                        ->icon('heroicon-o-users')
                        ->color('gray')
                        ->action(function (Get $get): void {
                            $audience = $get('audience');
                            $count = app(PollAudienceQuery::class)->count(is_array($audience) ? $audience : null);

                            Notification::make()
                                ->title(__('admin.polls.notifications.recipients', ['count' => $count]))
                                ->info()
                                ->send();
                        }),
                ])
                ->schema([
                    Forms\Components\Select::make('audience.plan')
                        ->label(__('admin.polls.fields.plan'))
                        ->options(AdminLabels::options('polls.plan', ['all', 'free', 'pro']))
                        ->default('all')
                        ->selectablePlaceholder(false)
                        ->required()
                        ->disabled($locked),
                    Forms\Components\CheckboxList::make('audience.locales')
                        ->label(__('admin.polls.fields.locales'))
                        ->helperText(__('admin.polls.fields.empty_means_all'))
                        ->options(AdminLabels::options('polls.locale', ['ru', 'uz']))
                        ->columns(2)
                        ->disabled($locked),
                    Forms\Components\Select::make('audience.course_ids')
                        ->label(__('admin.polls.fields.courses'))
                        ->helperText(__('admin.polls.fields.empty_means_all'))
                        ->multiple()
                        ->options(fn (): array => self::courseOptions())
                        ->disabled($locked),
                    Forms\Components\Select::make('audience.region_codes')
                        ->label(__('admin.polls.fields.regions'))
                        ->helperText(__('admin.polls.fields.regions_help'))
                        ->multiple()
                        ->options(fn (): array => self::regionOptions())
                        ->disabled($locked),
                    Forms\Components\Toggle::make('audience.onboarded_only')
                        ->label(__('admin.polls.fields.onboarded_only'))
                        ->default(true)
                        ->disabled($locked),
                ]),
            Section::make(__('admin.polls.sections.preview'))
                ->description(__('admin.polls.sections.preview_help'))
                ->columns(2)
                ->collapsible()
                ->schema([
                    Text::make(fn (Get $get): string => self::preview($get, 'ru')),
                    Text::make(fn (Get $get): string => self::preview($get, 'uz')),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return AdminTable::bounded($table)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->select([
                'id', 'title', 'status', 'allows_multiple', 'recipient_count', 'launched_at', 'closes_at', 'closed_at', 'created_at',
            ]))
            ->columns([
                Tables\Columns\TextColumn::make('id')->label(__('admin.polls.columns.id'))->sortable(),
                Tables\Columns\TextColumn::make('title')->label(__('admin.polls.fields.title'))->searchable()->limit(60),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('admin.polls.columns.status'))
                    ->badge()
                    ->formatStateUsing(fn (PollStatus $state): string => AdminLabels::value('polls.status', $state->value))
                    ->color(fn (PollStatus $state): string => match ($state) {
                        PollStatus::Draft => 'gray',
                        PollStatus::Sending => 'warning',
                        PollStatus::Sent => 'success',
                        PollStatus::Closed => 'info',
                        PollStatus::Cancelled => 'danger',
                    }),
                Tables\Columns\IconColumn::make('allows_multiple')->label(__('admin.polls.columns.multiple'))->boolean(),
                Tables\Columns\TextColumn::make('recipient_count')->label(__('admin.polls.columns.recipients'))->placeholder('—')->numeric(),
                Tables\Columns\TextColumn::make('launched_at')->label(__('admin.polls.columns.launched_at'))->dateTime()->placeholder('—')->sortable(),
                Tables\Columns\TextColumn::make('closes_at')->label(__('admin.polls.fields.closes_at'))->dateTime()->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')->label(__('admin.polls.columns.created_at'))->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('admin.polls.columns.status'))
                    ->options(AdminLabels::options('polls.status', array_map(static fn (PollStatus $s): string => $s->value, PollStatus::cases()))),
            ])
            ->emptyStateHeading(__('admin.polls.empty'))
            ->recordActions([
                Actions\EditAction::make(),
                Action::make('results')
                    ->label(__('admin.polls.actions.results'))
                    ->icon('heroicon-o-chart-bar')
                    ->color('gray')
                    ->visible(fn (Poll $record): bool => self::launched($record))
                    ->url(fn (Poll $record): string => self::getUrl('results', ['record' => $record])),
            ])
            ->defaultSort('id', 'desc');
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPolls::route('/'),
            'create' => Pages\CreatePoll::route('/create'),
            'edit' => Pages\EditPoll::route('/{record}/edit'),
            'results' => Pages\ViewPollResults::route('/{record}/results'),
        ];
    }

    /** @return array<int, string> course id => title, for the audience */
    public static function courseOptions(): array
    {
        $options = [];
        foreach (Course::query()->orderBy('id')->get(['id', 'title']) as $course) {
            $title = $course->title['ru'] ?? $course->title['en'] ?? reset($course->title);
            $options[(int) $course->id] = is_string($title) && $title !== '' ? $title : '#'.$course->id;
        }

        return $options;
    }

    /** @return array<string, string> region code => name: the 14 units of Uzbekistan, then «Другая страна» (`other`) */
    public static function regionOptions(): array
    {
        $options = [];
        foreach ([...Regions::ordered('ru'), Regions::OTHER] as $code) {
            $options[$code] = Regions::name($code, 'ru');
        }

        return $options;
    }

    /** What the learner reads in `$locale`, from the form as it is now (nothing is read or written). */
    private static function preview(Get $get, string $locale): string
    {
        $poll = new Poll;
        $poll->forceFill(['question' => (array) ($get('question') ?? []), 'allows_multiple' => (bool) $get('allows_multiple')]);

        $view = ['id' => 0, 'question' => ['ru' => '', 'uz' => ''], 'allows_multiple' => false, 'max_choices' => null, 'allow_change' => true, 'options' => []];
        $position = 0;
        foreach ((array) ($get('options') ?? []) as $row) {
            $label = is_array($row) && is_array($row['label'] ?? null) ? $row['label'] : [];
            $view['options'][] = ['id' => $position + 1, 'position' => $position, 'label' => ['ru' => (string) ($label['ru'] ?? ''), 'uz' => (string) ($label['uz'] ?? '')]];
            $position++;
        }

        [, $options] = PollViewCache::models($view);
        $messenger = app(PollMessenger::class);
        $text = $messenger->text($poll, $options, $locale);
        $buttons = [];
        $n = 1;
        foreach ($messenger->keyboard($poll, $options, str_repeat('x', 12), $locale, 1)['inline_keyboard'] as $row) {
            $buttons[] = '['.($row[0]['text'] ?? $n).']';
            $n++;
        }

        return mb_strtoupper($locale).': '.($text === '' ? '—' : $text).($buttons === [] ? '' : "\n".implode(' ', $buttons))
            .' ('.__('admin.polls.fields.preview_length', ['n' => mb_strlen($text)]).')';
    }
}
