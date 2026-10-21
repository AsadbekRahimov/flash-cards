<x-mail::message>
# {{ __('org.mail.invoice_request.heading', ['organization' => \App\Mail\OrgInvoiceRequestMail::escapeMarkdown($organizationName)]) }}

{{ __('org.mail.invoice_request.intro', ['user' => \App\Mail\OrgInvoiceRequestMail::escapeMarkdown($requesterName.' <'.$requesterEmail.'>'), 'organization' => \App\Mail\OrgInvoiceRequestMail::escapeMarkdown($organizationName), 'slug' => \App\Mail\OrgInvoiceRequestMail::escapeMarkdown($organizationSlug)]) }}

- {{ __('org.mail.invoice_request.tier') }}: **{{ __('org.billing.tiers.'.$tier.'.name') }}** ({{ $seats === null ? __('org.billing.seats_unlimited') : __('org.billing.seats_count', ['count' => $seats]) }})
- {{ __('org.mail.invoice_request.period') }}: {{ __('billing.period.'.$period) }}
- {{ __('org.mail.invoice_request.amount') }}: {{ $amountLabel ?? __('org.mail.invoice_request.amount_none') }}
@if ($comment !== null)
- {{ __('org.mail.invoice_request.comment') }}: {{ \App\Mail\OrgInvoiceRequestMail::escapeMarkdown($comment) }}
@endif

{{ __('org.mail.invoice_request.next') }}

{{ config('app.name') }}
</x-mail::message>
