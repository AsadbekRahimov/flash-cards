<?php

declare(strict_types=1);

namespace App\Filament\Org\Pages;

use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Password;

/**
 * `/org/password-reset/request`: the same "link sent" answer whether or not the e-mail belongs to an
 * account (docs/07 §8), so the form cannot be used to enumerate staff. Filament shows an error for
 * `INVALID_USER` and for the broker's own `THROTTLED` (which exists only for a real account); both are
 * answered as success here. The per-visitor rate limit of the parent page stays.
 */
class OrgRequestPasswordReset extends RequestPasswordReset
{
    protected function getFailureNotification(string $status): ?Notification
    {
        if (in_array($status, [Password::INVALID_USER, Password::RESET_THROTTLED], true)) {
            return $this->getSentNotification(Password::RESET_LINK_SENT);
        }

        return parent::getFailureNotification($status);
    }
}
