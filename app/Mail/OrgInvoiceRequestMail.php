<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "A center asks for an invoice" — sent to the platform owner (`billing.invoice.request_email`),
 * in the application language, with the owner's address as Reply-To (docs/13 §6.5). The platform
 * answers with an invoice / activates the tariff by hand (`grantManual(tier)`).
 */
final class OrgInvoiceRequestMail extends Mailable
{
    public function __construct(
        public readonly string $organizationName,
        public readonly string $organizationSlug,
        public readonly string $requesterName,
        public readonly string $requesterEmail,
        public readonly string $tier,
        public readonly ?int $seats,
        public readonly string $period,
        public readonly ?string $amountLabel,
        public readonly ?string $comment,
    ) {
        $this->locale((string) config('app.locale', 'ru'));
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: $this->requesterEmail === '' ? [] : [$this->requesterEmail],
            subject: __('org.mail.invoice_request.subject', ['organization' => $this->organizationName]),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.org.invoice-request');
    }
}
