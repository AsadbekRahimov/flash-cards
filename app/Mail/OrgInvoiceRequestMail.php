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

    /**
     * User-typed text inside a Markdown mail: line breaks become spaces and every ASCII punctuation
     * mark is backslash-escaped (`&`, `<`, `>`, quotes are left to Blade's HTML escaping), so a
     * comment like `[x](http://evil)` is shown as text, never rendered as a link or a heading.
     */
    public static function escapeMarkdown(string $text): string
    {
        $text = (string) preg_replace('/\s*\R\s*/u', ' ', $text);

        return (string) preg_replace('/([!#-%(-\/:;=?-@\[-`{-~])/', '\\\\$1', $text);
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
