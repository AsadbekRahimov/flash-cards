<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Content\Services\VocabularyImporter;
use App\Filament\Resources\Support\AdminErrors;
use App\Models\User;
use Filament\Actions\Action;
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
 * @property Schema $form
 */
class ImportLesson extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?int $navigationSort = 30;

    protected string $view = 'filament.pages.import-lesson';

    /** @var array<string, mixed> */
    public array $data = [];

    /** @var array<string, mixed>|null */
    public ?array $lastReport = null;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.content');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.pages.import_lesson.nav');
    }

    public function getTitle(): string
    {
        return __('admin.pages.import_lesson.title');
    }

    /** Phase 1: content is platform-owned (docs/12 §8) — only an active admin imports. */
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

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                FileUpload::make('file')
                    ->label(__('admin.pages.import_lesson.file'))
                    ->acceptedFileTypes(['application/json', 'text/plain'])
                    ->maxSize(VocabularyImporter::MAX_BYTES / 1024)
                    ->required()
                    ->storeFiles(false)
                    ->helperText(__('admin.pages.import_lesson.file_help')),
            ])
            ->statePath('data');
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadSample')
                ->label(__('admin.pages.import_lesson.download_sample'))
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => $this->downloadSample()),
        ];
    }

    public function submit(VocabularyImporter $importer): void
    {
        $state = $this->form->getState();

        /** @var UploadedFile|null $file */
        $file = $state['file'] ?? null;

        if ($file === null) {
            Notification::make()->title(__('admin.pages.import_lesson.no_file'))->danger()->send();

            return;
        }

        $raw = (string) file_get_contents($file->getRealPath());

        $user = auth()->user();
        if (! $user instanceof User) {
            abort(403);
        }

        $report = $importer->import($raw, $user, ['ip' => request()->ip()]);

        // `errors` of the report are English (logs, audit record); the page lists the same refusals in Russian.
        $errors = array_map(AdminErrors::importIssue(...), $report->issues);

        $this->lastReport = [...$report->toArray(), 'errors' => $errors];

        if ($report->ok()) {
            Notification::make()
                ->title(__('admin.pages.import_lesson.imported', [
                    'added' => $report->added,
                    'updated' => $report->updated,
                    'skipped' => $report->skipped,
                ]))
                ->success()
                ->send();

            $this->form->fill();
        } else {
            Notification::make()
                ->title(__('admin.pages.import_lesson.failed'))
                ->body(implode("\n", array_slice($errors, 0, 5)))
                ->danger()
                ->persistent()
                ->send();
        }
    }

    public function downloadSample(): StreamedResponse|BinaryFileResponse
    {
        return Storage::disk('local')->download('samples/sample-lesson.json');
    }
}
