<?php

declare(strict_types=1);

namespace App\Filament\Resources\PracticeItemResource\Support;

use App\Domain\Practice\Enums\PracticeItemStatus;
use App\Domain\Practice\Enums\PracticeItemType;
use App\Domain\Practice\Enums\VersionState;
use App\Domain\Practice\Exceptions\PracticeContentException;
use App\Domain\Practice\Exceptions\PracticeItemStateException;
use App\Domain\Practice\Services\PracticeItemService;
use App\Filament\Resources\Support\AdminErrors;
use App\Models\PracticeItem;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * The buttons of a task (a row of the list and the header of the edit page): "Проверено", "Опубликовать", "В архив", "Вернуть"
 * and "Сгенерировать аудио заново". Every move is PracticeItemService's: it owns the rules, the strict checks of publishing and
 * the audit trail; a refusal is worded in Russian by AdminErrors. The buttons only show where the move can be made.
 */
final class PracticeItemActions
{
    /** @return list<Action> */
    public static function all(): array
    {
        return [self::review(), self::publish(), self::archive(), self::restore(), self::regenerateAudio()];
    }

    /** draft -> reviewed: the text of the current version has been checked. */
    public static function review(): Action
    {
        return Action::make('review')
            ->label(__('admin.practice_items.actions.review'))
            ->icon('heroicon-o-check-badge')
            ->color('info')
            ->visible(fn (PracticeItem $record): bool => ! self::archived($record) && $record->currentVersion?->state === VersionState::Draft)
            ->authorize('review')
            ->action(fn (PracticeItem $record) => self::run(
                'reviewed',
                fn (User $user): PracticeItem => app(PracticeItemService::class)->markReviewed($record, $user, request()->ip()),
            ));
    }

    /** reviewed -> published. */
    public static function publish(): Action
    {
        return Action::make('publish')
            ->label(__('admin.practice_items.actions.publish'))
            ->icon('heroicon-o-rocket-launch')
            ->color('success')
            ->visible(fn (PracticeItem $record): bool => ! self::archived($record) && $record->currentVersion?->state === VersionState::Reviewed)
            ->authorize('publish')
            ->requiresConfirmation()
            ->modalHeading(__('admin.practice_items.actions.publish_heading'))
            ->modalDescription(__('admin.practice_items.actions.publish_help'))
            ->action(fn (PracticeItem $record) => self::run(
                'published',
                fn (User $user): PracticeItem => app(PracticeItemService::class)->publish($record, $user, request()->ip()),
            ));
    }

    /** Any stage -> the archive: nobody is given the task any more. */
    public static function archive(): Action
    {
        return Action::make('archive')
            ->label(__('admin.practice_items.actions.archive'))
            ->icon('heroicon-o-archive-box')
            ->color('danger')
            ->visible(fn (PracticeItem $record): bool => ! self::archived($record))
            ->authorize('archive')
            ->requiresConfirmation()
            ->modalHeading(__('admin.practice_items.actions.archive_heading'))
            ->modalDescription(__('admin.practice_items.actions.archive_help'))
            ->action(fn (PracticeItem $record) => self::run(
                'archived',
                fn (User $user): PracticeItem => app(PracticeItemService::class)->archive($record, $user, request()->ip()),
            ));
    }

    /** The archive -> where the task was. */
    public static function restore(): Action
    {
        return Action::make('restore')
            ->label(__('admin.practice_items.actions.restore'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->visible(fn (PracticeItem $record): bool => self::archived($record))
            ->authorize('restore')
            ->requiresConfirmation()
            ->modalHeading(__('admin.practice_items.actions.restore_heading'))
            ->modalDescription(__('admin.practice_items.actions.restore_help'))
            ->action(fn (PracticeItem $record) => self::run(
                'restored',
                fn (User $user): PracticeItem => app(PracticeItemService::class)->restore($record, $user, request()->ip()),
            ));
    }

    /** Voices the current version of a dictation again, even if the audio is ready. */
    public static function regenerateAudio(): Action
    {
        return Action::make('regenerateAudio')
            ->label(__('admin.practice_items.actions.regenerate_audio'))
            ->icon('heroicon-o-speaker-wave')
            ->color('gray')
            ->visible(fn (PracticeItem $record): bool => ! self::archived($record) && $record->type === PracticeItemType::Dictation && $record->current_version_id !== null)
            ->authorize('update')
            ->action(fn (PracticeItem $record) => self::run(
                'audio_queued',
                function () use ($record): void {
                    app(PracticeItemService::class)->regenerateAudio($record);
                },
            ));
    }

    /**
     * A refusal of PracticeItemService as the admin reads it: the Russian reason, kept on the screen until closed. The pages
     * of the form use it for a text that was not accepted.
     */
    public static function refused(PracticeContentException|PracticeItemStateException $e): void
    {
        Notification::make()
            ->title(__('admin.practice_items.notifications.failed'))
            ->body(AdminErrors::for('practice_items', $e))
            ->danger()
            ->persistent()
            ->send();
    }

    private static function archived(PracticeItem $record): bool
    {
        return $record->status === PracticeItemStatus::Archived;
    }

    /**
     * Runs a move for the signed-in admin and tells how it went; a refusal of the service is a notification, never an error page.
     *
     * @param  Closure(User): mixed  $move
     */
    private static function run(string $done, Closure $move): void
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return;
        }

        try {
            $move($user);
        } catch (PracticeContentException|PracticeItemStateException $e) {
            self::refused($e);

            return;
        }

        Notification::make()->title(__('admin.practice_items.notifications.'.$done))->success()->send();
    }
}
