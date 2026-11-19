<?php

declare(strict_types=1);

namespace App\Filament\Org\Resources\AssignmentResource\Pages;

use App\Domain\Organizations\Services\AssignmentService;
use App\Domain\Organizations\Services\OrgHardWordsReport;
use App\Filament\Org\Resources\AssignmentResource;
use App\Models\Assignment;
use App\Models\Organization;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewAssignment extends ViewRecord
{
    protected static string $resource = AssignmentResource::class;

    /** @var list<array{word_id: int, word: string, translation: string, learners: int, confused_with: string|null}>|null */
    private ?array $toRepeat = null;

    /**
     * The report opens with a fresh snapshot: recounted when older than `stale_after_minutes`. After a recount the record is
     * read again, so the counts of the summary are those of the new snapshot (one statement, only when it recounted).
     */
    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var Assignment $assignment */
        $assignment = $this->getRecord();

        if (app(AssignmentService::class)->refreshIfStale($assignment)) {
            $this->record = $this->resolveRecord($record);
        }
    }

    /**
     * "Material to repeat": up to 3 hard words of the assigned lesson that at least 2 learners of the group keep failing
     * ({@see OrgHardWordsReport::toRepeat()}, scoped to what the user sees). One statement per request, empty = no block.
     *
     * @return list<array{word_id: int, word: string, translation: string, learners: int, confused_with: string|null}>
     */
    public function materialToRepeat(): array
    {
        if ($this->toRepeat !== null) {
            return $this->toRepeat;
        }

        $assignment = $this->assignment();
        $user = Filament::auth()->user();
        $tenant = Filament::getTenant();

        if (! $user instanceof User || ! $tenant instanceof Organization) {
            return $this->toRepeat = [];
        }

        return $this->toRepeat = app(OrgHardWordsReport::class)->toRepeat($user, $tenant, $assignment->group, $assignment->lesson_id);
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
