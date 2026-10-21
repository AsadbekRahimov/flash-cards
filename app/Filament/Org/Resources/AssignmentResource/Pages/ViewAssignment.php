<?php

declare(strict_types=1);

namespace App\Filament\Org\Resources\AssignmentResource\Pages;

use App\Domain\Organizations\Services\AssignmentService;
use App\Filament\Org\Resources\AssignmentResource;
use App\Models\Assignment;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewAssignment extends ViewRecord
{
    protected static string $resource = AssignmentResource::class;

    /** The report opens with a fresh snapshot: recounted when older than `stale_after_minutes`. */
    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var Assignment $assignment */
        $assignment = $this->getRecord();

        app(AssignmentService::class)->refreshIfStale($assignment);
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('close')
                ->label(__('org.assignments.actions.close'))
                ->icon('heroicon-o-lock-closed')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription(__('org.assignments.actions.close_confirm'))
                ->visible(fn (): bool => $this->assignment()->isActive())
                ->authorize(fn (): bool => AssignmentResource::canEdit($this->assignment()))
                ->action(function (): void {
                    app(AssignmentService::class)->close($this->assignment());

                    Notification::make()->title(__('org.assignments.notifications.closed'))->success()->send();
                    $this->refreshFormData(['status']);
                }),
        ];
    }

    private function assignment(): Assignment
    {
        /** @var Assignment $assignment */
        $assignment = $this->getRecord();

        return $assignment;
    }
}
