<?php

declare(strict_types=1);

namespace App\Filament\Org\Resources\ExamResource\Pages;

use App\Domain\Learning\Services\ExamReportService;
use App\Domain\Organizations\Services\ExamResultsCsv;
use App\Filament\Org\Resources\ExamResource;
use App\Models\ExamSession;
use App\Models\Organization;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ViewRecord;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ViewExam extends ViewRecord
{
    protected static string $resource = ExamResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('csv')
                ->label(__('org.exams.actions.csv'))
                ->icon('heroicon-o-arrow-down-tray')
                ->authorize(fn (): bool => ExamResource::can('view', $this->exam()))
                ->action(fn (): StreamedResponse => $this->download()),
        ];
    }

    private function download(): StreamedResponse
    {
        $exam = $this->exam();
        $tenant = Filament::getTenant();
        abort_unless($tenant instanceof Organization, 404);

        $results = app(ExamReportService::class)->results($exam);
        $csv = app(ExamResultsCsv::class);

        return response()->streamDownload(function () use ($csv, $results): void {
            $out = fopen('php://output', 'wb');
            if ($out !== false) {
                $csv->write($out, $results);
                fclose($out);
            }
        }, $csv->filename($exam, $tenant), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function exam(): ExamSession
    {
        /** @var ExamSession $exam */
        $exam = $this->getRecord();

        return $exam;
    }
}
