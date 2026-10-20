<x-mail::message>
# {{ __('org.mail.invitation.heading', ['organization' => $organizationName]) }}

{{ __('org.mail.invitation.intro', ['inviter' => $inviterName, 'organization' => $organizationName]) }}

@if ($withResetLink)
{{ __('org.mail.invitation.set_password', ['hours' => $expiresHours]) }}

<x-mail::button :url="$url">
{{ __('org.mail.invitation.button_set_password') }}
</x-mail::button>
@else
{{ __('org.mail.invitation.existing_account') }}

<x-mail::button :url="$url">
{{ __('org.mail.invitation.button_open') }}
</x-mail::button>
@endif

{{ __('org.mail.invitation.ignore') }}

{{ config('app.name') }}
</x-mail::message>
