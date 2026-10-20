<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('twofactor.recovery.title', [], $locale) }} — {{ config('app.name') }}</title>
    @include('2fa._styles')
</head>
<body>
<div class="card">
    <h1>{{ __('twofactor.recovery.heading', [], $locale) }}</h1>
    @php($intro = explode(':emphasis', __('twofactor.recovery.intro', [], $locale), 2))
    <p>{{ $intro[0] }}<strong>{{ __('twofactor.recovery.intro_emphasis', [], $locale) }}</strong>{{ $intro[1] ?? '' }}</p>

    <ul class="codes">
        @foreach ($codes as $code)
            <li>{{ $code }}</li>
        @endforeach
    </ul>

    @php($continueUrl = $continueUrl ?? '/admin')
    <a class="link" href="{{ $continueUrl }}">{{ str_contains($continueUrl, '/org') ? __('twofactor.recovery.to_org', [], $locale) : __('twofactor.recovery.to_admin', [], $locale) }}</a>
</div>
</body>
</html>
