<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Content\Services\VocabularyImporter;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportLesson extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static string|\UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Import Lesson';

    protected static ?int $navigationSort = 30;

    protected static string $view = 'filament.pages.import-lesson';

    /** @var array<string, mixed> */
    public array $data = [];

    /** @var array<string, mixed>|null */
    public ?array $lastReport = null;

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                FileUpload::make('file')
                    ->label('Lesson JSON')
                    ->acceptedFileTypes(['application/json', 'text/plain'])
                    ->maxSize(VocabularyImporter::MAX_BYTES / 1024)
                    ->required()
                    ->storeFiles(false)
                    ->helperText('Max 2 MB. Schema: see "Download sample".'),
            ])
            ->statePath('data');
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadSample')
                ->label('Download sample')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => $this->downloadSample()),
        ];
    }

    public function submit(VocabularyImporter $importer): void
    {
        $state = $this->form->getState();

        /** @var \Illuminate\Http\UploadedFile|null $file */
        $file = $state['file'] ?? null;

        if ($file === null) {
            Notification::make()->title('No file provided.')->danger()->send();

            return;
        }

        $raw = (string) file_get_contents($file->getRealPath());

        $report = $importer->import($raw, [
            'user_id' => auth()->id(),
            'ip' => request()->ip(),
        ]);

        $this->lastReport = $report->toArray();

        if ($report->ok()) {
            Notification::make()
                ->title("Imported: +{$report->added} new, {$report->updated} updated, {$report->skipped} skipped.")
                ->success()
                ->send();

            $this->form->fill();
        } else {
            Notification::make()
                ->title('Import failed — nothing was written.')
                ->body(implode("\n", array_slice($report->errors, 0, 5)))
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
