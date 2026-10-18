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
    <p>{!! __('twofactor.recovery.intro', [], $locale) !!}</p>

    <ul class="codes">
        @foreach ($codes as $code)
            <li>{{ $code }}</li>
        @endforeach
    </ul>

    <a class="link" href="/admin">{{ __('twofactor.recovery.to_admin', [], $locale) }}</a>
</div>
</body>
</html>
