<?php

declare(strict_types=1);

namespace App\Filament\Org\Pages;

use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Filament\Support\Components\Attributes\Exposed;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Timebox;
use LogicException;
use SensitiveParameter;

/**
 * `/org/password-reset/request`: the same answer whether or not the e-mail belongs to an account
 * (docs/07 §8), so the form cannot be used to enumerate staff.
 *
 * Response: the parent page clears the form only on success and answers `INVALID_USER` / the broker's
 * `THROTTLED` with an error; here every outcome gives the same notification and an empty form.
 *
 * Time: the broker boxes its own work to `auth.timebox_duration` (200 ms), which an unknown e-mail
 * fits in but a known one does not — bcrypt of the token at BCRYPT_ROUNDS=12 alone takes ~250 ms, then
 * the token insert and the queue push of the e-mail. The call is therefore wrapped in a second, longer
 * {@see Timebox} (`auth.password_reset_timebox_duration`, 1 s by default) that covers the longest
 * synchronous path with a margin; the e-mail itself is a queued notification (Filament's
 * `ResetPassword` is `ShouldQueue`), so SMTP latency never reaches the response. An overrun is logged
 * (without the e-mail) so the protection does not degrade silently. The per-visitor rate limit of the
 * parent page stays.
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

        $status = $this->timeboxed(fn (): string => $this->sendResetLink($data));

        if (in_array($status, [Password::RESET_LINK_SENT, Password::INVALID_USER, Password::RESET_THROTTLED], true)) {
            $this->getSentNotification(Password::RESET_LINK_SENT)?->send();
            $this->form->fill();

            return;
        }

        $this->getFailureNotification($status)?->send();
        $this->form->fill();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function sendResetLink(array $data): string
    {
        return Password::broker(Filament::getAuthPasswordBroker())->sendResetLink(
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
    }

    /**
     * Run the broker call inside a timebox of `auth.password_reset_timebox_duration` microseconds, so
     * the response takes the same time for a known, an unknown and a throttled e-mail.
     *
     * @param  callable(): string  $callback
     */
    protected function timeboxed(callable $callback): string
    {
        $duration = (int) config('auth.password_reset_timebox_duration', 1_000_000);
        $worked = 0;

        try {
            return app(Timebox::class)->call(function () use ($callback, &$worked): string {
                $started = hrtime(true);

                try {
                    return $callback();
                } finally {
                    // Only the work itself: the sleep the box adds afterwards must not count.
                    $worked = intdiv(hrtime(true) - $started, 1_000);
                }
            }, $duration);
        } finally {
            if ($worked > $duration * 0.75) {
                // Near or past the budget the response time starts to leak the outcome; raise the budget before it does.
                Log::warning('org.password_reset.timebox_near_limit', [
                    'worked_us' => $worked,
                    'budget_us' => $duration,
                ]);
            }
        }
    }
}
