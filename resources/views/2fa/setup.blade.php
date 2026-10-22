<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('twofactor.setup.title', [], $locale) }} — {{ config('app.name') }}</title>
    @include('2fa._styles')
</head>
<body>
<div class="card">
    <h1>{{ __('twofactor.setup.heading', [], $locale) }}</h1>
    <p>{{ __('twofactor.setup.intro', [], $locale) }}</p>

    <div class="qr">{!! $qrSvg !!}</div>

    <p><small>{{ __('twofactor.setup.manual_key', [], $locale) }}</small></p>
    <div class="mono">{{ $secret }}</div>

    <form method="POST" action="{{ route('2fa.confirm', ['guard' => $guard]) }}">
        @csrf
        <label for="code">{{ __('twofactor.setup.code_label', [], $locale) }}</label>
        <input id="code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus>
        @error('code')<div class="error">{{ $message }}</div>@enderror
        <button type="submit">{{ __('twofactor.confirm_button', [], $locale) }}</button>
    </form>
</div>
</body>
</html>
