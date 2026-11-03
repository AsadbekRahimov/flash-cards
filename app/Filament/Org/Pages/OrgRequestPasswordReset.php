<?php

declare(strict_types=1);

namespace App\Filament\Org\Pages;

use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Filament\Notifications\Notification;
use Filament\Support\Components\Attributes\Exposed;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use LogicException;
use SensitiveParameter;

/**
 * `/org/password-reset/request`: the same "link sent" answer whether or not the e-mail belongs to an
 * account (docs/07 §8), so the form cannot be used to enumerate staff. The parent page clears the form
 * only on success and answers `INVALID_USER` / the broker's `THROTTLED` with an error; here every outcome
 * gives the same notification and an empty form, and an unknown e-mail pays for a dummy hash so the
 * response time is closer to the real one. The per-visitor rate limit of the parent page stays.
 */
class OrgRequestPasswordReset extends RequestPasswordReset
{
    #[Exposed]
    public function request(): void
    {
        try {
            $this->rateLimit(2);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return;
        }

        $data = $this->form->getState();

        $status = Password::broker(Filament::getAuthPasswordBroker())->sendResetLink(
            $this->getCredentialsFromFormData($data),
            function (CanResetPassword $user, #[SensitiveParameter] string $token): void {
                if (
                    ($user instanceof FilamentUser) &&
                    (! $user->canAccessPanel(Filament::getPanel('org')))
                ) {
                    return;
                }

                if (! method_exists($user, 'notify')) {
                    throw new LogicException('Model ['.$user::class.'] does not have a [notify()] method.');
                }

                $notification = app(ResetPasswordNotification::class, ['token' => $token]);
                $notification->url = Filament::getResetPasswordUrl($token, $user);

                $user->notify($notification);

                event(new PasswordResetLinkSent($user));
            },
        );

        if ($status === Password::INVALID_USER) {
            // A known e-mail costs the broker a token hash and a DB write; spend the same here.
            Hash::make(Str::random(40));
        }

        if (in_array($status, [Password::RESET_LINK_SENT, Password::INVALID_USER, Password::RESET_THROTTLED], true)) {
            $this->getSentNotification(Password::RESET_LINK_SENT)?->send();
            $this->form->fill();

            return;
        }

        $this->getFailureNotification($status)?->send();
        $this->form->fill();
    }
}
