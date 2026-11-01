<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Practice\Services\PracticeImporter;
use App\Filament\Resources\Support\AdminErrors;
use App\Filament\Resources\Support\AdminNumbers;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The import of the tasks of the practice from a JSON file (docs/16 §15.1): the page over PracticeImporter, the same service as
 * `php artisan practice:import`. Only an active admin; the course comes from the file ("course"). The tasks are written as
 * drafts; the box «Опубликовать сразу» publishes them at once and is the person's confirmation that the content has been
 * reviewed - a file cannot do it for itself. A refused file writes nothing, and every reason is listed with the number of the task
 * and its key.
 *
 * @property Schema $form
 */
class ImportPracticeItems extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-tray';

    /** Under «Задания практики» (6). */
    protected static ?int $navigationSort = 7;

    protected string $view = 'filament.pages.import-practice-items';

    /** @var array<string, mixed> */
    public array $data = [];

    /** @var array<string, mixed>|null */
    public ?array $lastReport = null;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.practice');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.pages.import_practice.nav');
    }

    public function getTitle(): string
    {
        return __('admin.pages.import_practice.title');
    }

    /** The content of the practice is the platform's (PracticeItemPolicy): only an active admin imports. */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->is_active
            && $user->hasRole('admin');
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    /** The size limit of the file: the one of the importer, which refuses the files over it. */
    private function maxBytes(): int
    {
        return app(PracticeImporter::class)->maxBytes();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                FileUpload::make('file')
                    ->label(__('admin.pages.import_practice.file'))
                    ->acceptedFileTypes(['application/json', 'text/plain'])
                    ->maxSize((int) ceil($this->maxBytes() / 1024))
                    ->required()
                    ->storeFiles(false)
                    ->helperText(__('admin.pages.import_practice.file_help', [
                        'mb' => AdminNumbers::decimal($this->maxBytes() / 1024 / 1024, 1, trimZeros: true),
                        'max' => (int) config('practice.import.max_items'),
                    ])),
                Checkbox::make('publish')
                    ->label(__('admin.pages.import_practice.publish'))
                    ->helperText(__('admin.pages.import_practice.publish_help')),
            ])
            ->statePath('data');
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadSample')
                ->label(__('admin.pages.import_practice.download_sample'))
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => $this->downloadSample()),
        ];
    }

    public function submit(PracticeImporter $importer): void
    {
        $state = $this->form->getState();

        /** @var UploadedFile|null $file */
        $file = $state['file'] ?? null;

        if ($file === null) {
            Notification::make()->title(__('admin.pages.import_practice.no_file'))->danger()->send();

            return;
        }

        $user = auth()->user();
        if (! $user instanceof User) {
            abort(403);
        }

        // One byte over the limit is enough for the importer to refuse the file: do not read a huge file whole.
        $raw = (string) file_get_contents((string) $file->getRealPath(), false, null, 0, $importer->maxBytes() + 1);

        $report = $importer->import($raw, $user, (bool) ($state['publish'] ?? false), ['ip' => request()->ip()]);

        // `errors` of the report are English (logs, audit record); the page lists the same refusals in Russian.
        $errors = array_map(AdminErrors::practiceImportIssue(...), $report->issues);

        $this->lastReport = [...$report->toArray(), 'errors' => $errors];

        if ($report->ok()) {
            Notification::make()
                ->title(__('admin.pages.import_practice.imported', [
                    'added' => $report->added,
                    'updated' => $report->updated,
                    'skipped' => $report->skipped,
                    'published' => $report->published,
                ]))
                ->success()
                ->send();

            $this->form->fill();
        } else {
            Notification::make()
                ->title(__('admin.pages.import_practice.failed'))
                // A text of the file (a key, a word) is in the lines: each is escaped, the lines are told apart by <br>. Filament prints
                // the body as HTML, and what it lets through (<a href>, <img src>) must never come from a file.
                ->body(implode('<br>', array_map(e(...), array_slice($errors, 0, 5))))
                ->danger()
                ->persistent()
                ->send();
        }
    }

    public function downloadSample(): StreamedResponse|BinaryFileResponse
    {
        return Storage::disk('local')->download('samples/sample-practice.json');
    }
}
