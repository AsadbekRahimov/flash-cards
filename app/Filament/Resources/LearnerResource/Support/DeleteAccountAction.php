<?php

declare(strict_types=1);

namespace App\Filament\Resources\LearnerResource\Support;

use App\Domain\Administration\Services\PanelDataScope;
use App\Domain\Learners\Services\LearnerDeletionService;
use App\Filament\Resources\LearnerResource;
use App\Models\Learner;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * «Удалить аккаунт» - the button on the learner's page and in the list, for support (owner's decision of 03.11.2026).
 * Admin only (`LearnerPolicy::update`, like the block); the soft delete and its audit record (`learner.deleted`, `meta.by = admin`)
 * are LearnerDeletionService's. The learner can still come back with `/start` until the purge (`learners.purge.after_days`).
 */
final class DeleteAccountAction
{
    public static function make(bool $redirectToList = false): Action
    {
        return Action::make('deleteAccount')
            ->label(__('admin.learners.actions.delete_account.label'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->visible(fn (): bool => app(PanelDataScope::class)->isAdmin())
            ->authorize('update')
            ->requiresConfirmation()
            ->modalHeading(__('admin.learners.actions.delete_account.heading'))
            ->modalDescription(__('admin.learners.actions.delete_account.help', ['days' => max(1, (int) config('learners.purge.after_days', 30))]))
            ->action(function (Learner $record): void {
                $actor = auth()->user();
                if (! $actor instanceof User) {
                    return;
                }

                $deleted = app(LearnerDeletionService::class)->delete($record, $actor, request()->ip());

                Notification::make()
                    ->title(__($deleted ? 'admin.learners.actions.delete_account.deleted' : 'admin.learners.actions.delete_account.unchanged'))
                    ->success()
                    ->send();
            })
            // The page of a deleted learner no longer opens (the panel never lists soft-deleted rows).
            ->successRedirectUrl($redirectToList ? fn (): string => LearnerResource::getUrl('index') : null);
    }
}
