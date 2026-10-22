<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('twofactor.challenge.title', [], $locale) }} — {{ config('app.name') }}</title>
    @include('2fa._styles')
</head>
<body>
<div class="card">
    <h1>{{ __('twofactor.challenge.heading', [], $locale) }}</h1>
    <p>{{ __('twofactor.challenge.intro', [], $locale) }}</p>

    <form method="POST" action="{{ route('2fa.verify', ['guard' => $guard]) }}">
        @csrf
        <label for="code">{{ __('twofactor.challenge.code_label', [], $locale) }}</label>
        <input id="code" type="text" name="code" inputmode="text" autocomplete="one-time-code" required autofocus>
        @error('code')<div class="error">{{ $message }}</div>@enderror
        <button type="submit">{{ __('twofactor.confirm_button', [], $locale) }}</button>
    </form>
</div>
</body>
</html>
