<?php

declare(strict_types=1);

namespace App\Filament\Resources\PollResource\Support;

use App\Domain\Polls\Enums\PollDeliveryStatus;
use App\Domain\Polls\Enums\PollStatus;
use App\Domain\Polls\Exceptions\PollLaunchException;
use App\Domain\Polls\Services\PollAudienceQuery;
use App\Domain\Polls\Services\PollEditor;
use App\Domain\Polls\Services\PollLauncher;
use App\Domain\Polls\Services\PollTestSender;
use App\Filament\Resources\PollResource;
use App\Filament\Resources\Support\AdminErrors;
use App\Filament\Resources\Support\AdminLabels;
use App\Models\Poll;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;

/**
 * The life of a poll on its edit page (docs/35 §1.5, O6). Each action is authorized by `PollPolicy::run` (active admin) and asks its
 * service, which decides under the poll's row lock whatever the button showed: a second click, a second administrator or the
 * closing command find the new status and change nothing.
 *
 *  - «Отправить себе» ({@see PollTestSender}) - the saved poll to the administrator's own Telegram; disabled without a linked account;
 *  - «Запустить» ({@see PollLauncher::launch()}) - with a confirmation that names the number of recipients (one count, on the click);
 *  - «Остановить рассылку» ({@see PollLauncher::cancel()}) - what was not sent yet is not sent;
 *  - «Закрыть сейчас» ({@see PollLauncher::close()}) - the answers stop, the totals are fixed;
 *  - «Результаты», «Удалить» (a draft only, {@see PollEditor::delete()}).
 */
final class PollRunActions
{
    /** @return list<Action> */
    public static function all(): array
    {
        return [self::results(), self::testSend(), self::launch(), self::cancel(), self::close(), self::delete()];
    }

    public static function results(): Action
    {
        return Action::make('results')
            ->label(__('admin.polls.actions.results'))
            ->icon('heroicon-o-chart-bar')
            ->color('gray')
            ->visible(fn (Poll $record): bool => PollResource::launched($record))
            ->url(fn (Poll $record): string => PollResource::getUrl('results', ['record' => $record]));
    }

    public static function testSend(): Action
    {
        return Action::make('testSend')
            ->label(__('admin.polls.actions.test'))
            ->icon('heroicon-o-paper-airplane')
            ->color('gray')
            ->authorize('run')
            ->visible(fn (Poll $record): bool => ! PollResource::finished($record))
            ->disabled(fn (): bool => self::admin()?->telegram_user_id === null)
            ->tooltip(fn (): ?string => self::admin()?->telegram_user_id === null ? __('admin.polls.actions.test_no_telegram') : null)
            ->modalHeading(__('admin.polls.actions.test_heading'))
            ->modalDescription(__('admin.polls.actions.test_help'))
            ->modalSubmitActionLabel(__('admin.polls.actions.test_submit'))
            ->schema([
                Forms\Components\Select::make('locale')
                    ->label(__('admin.polls.fields.test_locale'))
                    ->options(AdminLabels::options('polls.locale', ['ru', 'uz']))
                    ->default('ru')
                    ->selectablePlaceholder(false)
                    ->required(),
            ])
            ->action(function (Poll $record, array $data): void {
                $admin = self::admin();
                if ($admin === null) {
                    return;
                }

                try {
                    $delivery = app(PollTestSender::class)->send($record, $admin, (string) ($data['locale'] ?? 'ru'));
                } catch (PollLaunchException $e) {
                    Notification::make()->title(AdminErrors::text('polls', $e->reason))->danger()->send();

                    return;
                }

                $status = $delivery->status;
                Notification::make()
                    ->title(__('admin.polls.notifications.test.'.$status->value, ['code' => (string) $delivery->error_code]))
                    ->status($status === PollDeliveryStatus::Sent ? 'success' : 'danger')
                    ->send();
            });
    }

    public static function launch(): Action
    {
        return Action::make('launch')
            ->label(__('admin.polls.actions.launch'))
            ->icon('heroicon-o-rocket-launch')
            ->color('success')
            ->authorize('run')
            ->visible(fn (Poll $record): bool => $record->status === PollStatus::Draft)
            ->requiresConfirmation()
            ->modalHeading(__('admin.polls.actions.launch_heading'))
            // Counted when the dialog opens: one statement on a click, not on every render of the page.
            ->modalDescription(fn (Poll $record): string => __('admin.polls.actions.launch_help', [
                'count' => app(PollAudienceQuery::class)->count($record->audience),
            ]))
            ->modalSubmitActionLabel(__('admin.polls.actions.launch_submit'))
            ->action(function (Poll $record, Action $action): void {
                $admin = self::admin();
                if ($admin === null) {
                    return;
                }

                try {
                    $launched = app(PollLauncher::class)->launch($record, $admin);
                } catch (PollLaunchException $e) {
                    Notification::make()->title(AdminErrors::text('polls', $e->reason))->danger()->send();

                    return;
                }

                Notification::make()
                    ->title(__($launched ? 'admin.polls.notifications.launched' : 'admin.polls.notifications.unchanged', ['count' => (int) $record->fresh()?->recipient_count]))
                    ->status($launched ? 'success' : 'warning')
                    ->send();

                $action->redirect(PollResource::getUrl('edit', ['record' => $record]));
            });
    }

    public static function cancel(): Action
    {
        return Action::make('cancel')
            ->label(__('admin.polls.actions.cancel'))
            ->icon('heroicon-o-stop-circle')
            ->color('danger')
            ->authorize('run')
            ->visible(fn (Poll $record): bool => $record->status === PollStatus::Sending)
            ->requiresConfirmation()
            ->modalHeading(__('admin.polls.actions.cancel_heading'))
            ->modalDescription(__('admin.polls.actions.cancel_help'))
            ->action(function (Poll $record, Action $action): void {
                $admin = self::admin();
                if ($admin === null) {
                    return;
                }

                $done = app(PollLauncher::class)->cancel($record, $admin);
                Notification::make()
                    ->title(__($done ? 'admin.polls.notifications.cancelled' : 'admin.polls.notifications.unchanged'))
                    ->status($done ? 'success' : 'warning')
                    ->send();

                $action->redirect(PollResource::getUrl('edit', ['record' => $record]));
            });
    }

    public static function close(): Action
    {
        return Action::make('close')
            ->label(__('admin.polls.actions.close'))
            ->icon('heroicon-o-lock-closed')
            ->color('warning')
            ->authorize('run')
            ->visible(fn (Poll $record): bool => in_array($record->status, [PollStatus::Sending, PollStatus::Sent], true))
            ->requiresConfirmation()
            ->modalHeading(__('admin.polls.actions.close_heading'))
            ->modalDescription(__('admin.polls.actions.close_help'))
            ->action(function (Poll $record, Action $action): void {
                $admin = self::admin();
                if ($admin === null) {
                    return;
                }

                $done = app(PollLauncher::class)->close($record, $admin);
                Notification::make()
                    ->title(__($done ? 'admin.polls.notifications.closed' : 'admin.polls.notifications.unchanged'))
                    ->status($done ? 'success' : 'warning')
                    ->send();

                $action->redirect(PollResource::getUrl('results', ['record' => $record]));
            });
    }

    public static function delete(): Action
    {
        return Action::make('delete')
            ->label(__('admin.polls.actions.delete'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->authorize('delete')
            ->visible(fn (Poll $record): bool => $record->status === PollStatus::Draft)
            ->requiresConfirmation()
            ->modalHeading(__('admin.polls.actions.delete_heading'))
            ->action(function (Poll $record, Action $action): void {
                $done = app(PollEditor::class)->delete($record);
                Notification::make()
                    ->title(__($done ? 'admin.polls.notifications.deleted' : 'admin.polls.notifications.unchanged'))
                    ->status($done ? 'success' : 'warning')
                    ->send();

                if ($done) {
                    $action->redirect(PollResource::getUrl('index'));
                }
            });
    }

    private static function admin(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
