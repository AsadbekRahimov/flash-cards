<?php

declare(strict_types=1);

namespace App\Filament\Org\Pages;

use App\Domain\Telegram\Exceptions\TelegramLinkException;
use App\Domain\Telegram\Services\TelegramAccountLinkService;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * Own profile of a staff user (`/org/profile`): name, email, password and the Telegram account. Since the audit of 2026-11-10
 * (H1) the Telegram id is never typed: "Connect Telegram" issues a one-time `t.me/<bot>?start=link_<token>` link and the bot writes
 * `message.from.id` of whoever opens it ({@see TelegramAccountLinkService}); the page shows which account is connected and whether it
 * was verified that way, and lets the person disconnect it. Self-service on purpose: an owner never edits another account's identity —
 * a user row may be staff of several centers.
 */
class OrgProfile extends EditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getNameFormComponent(),
            $this->getEmailFormComponent(),
            Section::make(__('org.profile.telegram.title'))
                ->schema([
                    Text::make(fn (): string => $this->telegramStatus()),
                    Actions::make([
                        $this->connectTelegramAction(),
                        $this->disconnectTelegramAction(),
                    ]),
                ]),
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
            $this->getCurrentPasswordFormComponent(),
        ]);
    }

    /** The state of the connection, re-read from the row (the bot writes it while this page is open). */
    private function telegramStatus(): string
    {
        $user = $this->staff()->fresh() ?? $this->staff();

        if ($user->telegram_user_id === null) {
            return __('org.profile.telegram.none');
        }

        if (! $user->hasVerifiedTelegram()) {
            return __('org.profile.telegram.unverified', ['id' => $user->telegram_user_id]);
        }

        $account = $user->telegram_username !== null ? '@'.$user->telegram_username : ($user->telegram_name ?? '#'.$user->telegram_user_id);

        return __('org.profile.telegram.connected', ['account' => $account]).' '.__('org.profile.telegram.connected_help');
    }

    /**
     * Issues the link when the modal opens (that click IS the request) and keeps it in the mounted action's data, so a re-render
     * of the page does not issue another one and invalidate the shown link. Refusals (rate limit) close the modal with a notice.
     */
    private function connectTelegramAction(): Action
    {
        return Action::make('connectTelegram')
            ->label(fn (): string => $this->staff()->telegram_user_id !== null && ! $this->staff()->hasVerifiedTelegram()
                ? __('org.profile.telegram.confirm')
                : __('org.profile.telegram.connect'))
            ->icon('heroicon-o-paper-airplane')
            ->color('primary')
            ->modalHeading(fn (): string => __('org.profile.telegram.connect'))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('filament::components/modal.actions.close.label'))
            ->mountUsing(function (Schema $schema, Action $action): void {
                try {
                    $link = app(TelegramAccountLinkService::class)->issueLink($this->staff());
                } catch (TelegramLinkException $e) {
                    Notification::make()->title(__('org.profile.telegram.errors.'.$e->errorCode))->danger()->send();
                    $action->cancel();

                    return;
                }

                $schema->fill([
                    'url' => $link->url,
                    'expires' => $link->expiresAt->setTimezone(config('app.timezone'))->format('H:i'),
                ]);
            })
            ->schema([
                Text::make(fn (Get $get): string => __('org.profile.telegram.link_help', ['time' => (string) $get('expires')])),
                Forms\Components\TextInput::make('url')
                    ->label(__('org.profile.telegram.link_url'))
                    ->disabled()
                    ->dehydrated(false),
                Html::make(fn (Get $get): HtmlString => new HtmlString(
                    '<a class="fi-link fi-color-primary" href="'.e((string) $get('url')).'" target="_blank" rel="noopener">'.e(__('org.profile.telegram.link_open')).'</a>',
                )),
            ]);
    }

    private function disconnectTelegramAction(): Action
    {
        return Action::make('disconnectTelegram')
            ->label(__('org.profile.telegram.disconnect'))
            ->icon('heroicon-o-x-mark')
            ->color('danger')
            ->visible(fn (): bool => $this->staff()->telegram_user_id !== null)
            ->requiresConfirmation()
            ->modalDescription(__('org.profile.telegram.disconnect_confirm'))
            ->action(function (): void {
                try {
                    app(TelegramAccountLinkService::class)->unlink($this->staff(), request()->ip());
                } catch (TelegramLinkException $e) {
                    Notification::make()->title(__('org.profile.telegram.errors.'.$e->errorCode))->danger()->send();

                    return;
                }

                Notification::make()->title(__('org.profile.telegram.disconnected'))->success()->send();
            });
    }

    private function staff(): User
    {
        $user = $this->getUser();

        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }
}
