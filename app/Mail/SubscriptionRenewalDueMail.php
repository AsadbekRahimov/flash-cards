<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "The subscription of your center ends in N days" for the owners of an organization (docs/13 §4.4).
 * Rendered in `$language` (ru / uz); all strings are `billing.renewal.mail.*`.
 */
final class SubscriptionRenewalDueMail extends Mailable
{
    public function __construct(
        public readonly string $organization,
        public readonly int $daysLeft,
        public readonly string $date,
        public readonly string $panelUrl,
        public readonly ?string $contact,
        public readonly string $language,
    ) {
        $this->locale($language);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: trans_choice('billing.renewal.mail.subject', $this->daysLeft, ['organization' => $this->organization, 'date' => $this->date], $this->language),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.renewal-due');
    }
}
