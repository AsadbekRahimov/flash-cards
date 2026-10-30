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
 *
 * "Проверено" and "Опубликовать" act on the version the page showed (the id rides on the button, {@see ShownVersionAction}); when
 * the task has a newer one by the time of the click, the service refuses with `version_changed`.
 */
final class PracticeItemActions
{
    /**
     * @param  (Closure(): ?int)|null  $shownVersion  the version the page shows, where that is not the current one of the record
     * @return list<Action>
     */
    public static function all(?Closure $shownVersion = null): array
    {
        return [self::review($shownVersion), self::publish($shownVersion), self::archive(), self::restore(), self::regenerateAudio()];
    }

    /**
     * draft -> reviewed: the text of the current version has been checked.
     *
     * @param  (Closure(): ?int)|null  $shownVersion
     */
    public static function review(?Closure $shownVersion = null): Action
    {
        return ShownVersionAction::make('review')
            ->shownVersion($shownVersion)
            ->label(__('admin.practice_items.actions.review'))
            ->icon('heroicon-o-check-badge')
            ->color('info')
            ->visible(fn (PracticeItem $record): bool => ! self::archived($record) && $record->currentVersion?->state === VersionState::Draft)
            ->authorize('review')
            ->action(fn (PracticeItem $record, array $arguments) => self::run(
                'reviewed',
                fn (User $user): PracticeItem => app(PracticeItemService::class)->markReviewed($record, $user, request()->ip(), self::expectedVersion($arguments)),
            ));
    }

    /**
     * reviewed -> published.
     *
     * @param  (Closure(): ?int)|null  $shownVersion
     */
    public static function publish(?Closure $shownVersion = null): Action
    {
        return ShownVersionAction::make('publish')
            ->shownVersion($shownVersion)
            ->label(__('admin.practice_items.actions.publish'))
            ->icon('heroicon-o-rocket-launch')
            ->color('success')
            ->visible(fn (PracticeItem $record): bool => ! self::archived($record) && $record->currentVersion?->state === VersionState::Reviewed)
            ->authorize('publish')
            ->requiresConfirmation()
            ->modalHeading(__('admin.practice_items.actions.publish_heading'))
            ->modalDescription(__('admin.practice_items.actions.publish_help'))
            ->action(fn (PracticeItem $record, array $arguments) => self::run(
                'published',
                fn (User $user): PracticeItem => app(PracticeItemService::class)->publish($record, $user, request()->ip(), self::expectedVersion($arguments)),
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
     * of the form use it for a text that was not accepted (the buttons of the list reach it through `run()`).
     *
     * The body of a Filament notification is HTML, and the sentence holds the text of a word as the vocabulary has it (`wordIds()`
     * names a target word that went inactive by its text: a word with `<a href>` or `<img>` in it is accepted by the import and
     * by the form, `required|string|max:100`), so the sentence is escaped here.
     */
    public static function refused(PracticeContentException|PracticeItemStateException $e): void
    {
        Notification::make()
            ->title(__('admin.practice_items.notifications.failed'))
            ->body(e(AdminErrors::for('practice_items', $e)))
            ->danger()
            ->persistent()
            ->send();
    }

    /**
     * The version the button was drawn for. The arguments come from the browser: anything but a whole number is no version (the
     * move is then not tied to one).
     *
     * @param  array<array-key, mixed>  $arguments
     */
    private static function expectedVersion(array $arguments): ?int
    {
        $version = $arguments[ShownVersionAction::ARGUMENT] ?? null;

        return is_int($version) || (is_string($version) && ctype_digit($version)) ? (int) $version : null;
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
