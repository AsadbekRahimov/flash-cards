<x-mail::message>
# {{ __('billing.renewal.mail.greeting', [], $language) }}

{{ trans_choice('billing.renewal.mail.body', $daysLeft, ['organization' => $organization, 'date' => $date], $language) }}

{{ __('billing.renewal.mail.keep', [], $language) }}

<x-mail::button :url="$panelUrl">
{{ __('billing.renewal.mail.action', [], $language) }}
</x-mail::button>
@if ($contact !== null)

{{ __('billing.renewal.mail.contact', ['contact' => $contact], $language) }}
@endif
</x-mail::message>
