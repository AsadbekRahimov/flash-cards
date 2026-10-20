<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "You were invited to the cabinet of <center>" (docs/13 §6.3). For a new account the link is a
 * single-use password-reset link of the `org` broker (valid `expiresHours`); for an existing one —
 * the cabinet URL. Sent in the organization's language (`->locale()` by the caller). Sent
 * synchronously after the commit: the owner is waiting for the confirmation.
 */
final class OrgStaffInvitationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $organizationName,
        public readonly string $inviterName,
        public readonly string $url,
        public readonly bool $withResetLink,
        public readonly int $expiresHours,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('org.mail.invitation.subject', ['organization' => $this->organizationName]),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.org.staff-invitation');
    }
}
