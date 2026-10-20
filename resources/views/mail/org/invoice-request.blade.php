<x-mail::message>
# {{ __('org.mail.invoice_request.heading', ['organization' => $organizationName]) }}

{{ __('org.mail.invoice_request.intro', ['user' => $requesterName.' <'.$requesterEmail.'>', 'organization' => $organizationName, 'slug' => $organizationSlug]) }}

- {{ __('org.mail.invoice_request.tier') }}: **{{ __('org.billing.tiers.'.$tier.'.name') }}** ({{ $seats === null ? __('org.billing.seats_unlimited') : __('org.billing.seats_count', ['count' => $seats]) }})
- {{ __('org.mail.invoice_request.period') }}: {{ __('billing.period.'.$period) }}
- {{ __('org.mail.invoice_request.amount') }}: {{ $amountLabel ?? __('org.mail.invoice_request.amount_none') }}
@if ($comment !== null)
- {{ __('org.mail.invoice_request.comment') }}: {{ $comment }}
@endif

{{ __('org.mail.invoice_request.next') }}

{{ config('app.name') }}
</x-mail::message>
