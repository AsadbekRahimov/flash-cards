<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Настройка 2FA — {{ config('app.name') }}</title>
    @include('2fa._styles')
</head>
<body>
<div class="card">
    <h1>Включите двухфакторную аутентификацию</h1>
    <p>Отсканируйте QR-код в Google Authenticator, 1Password или Authy, затем введите 6-значный код для подтверждения.</p>

    <div class="qr">{!! $qrSvg !!}</div>

    <p><small>Или введите ключ вручную:</small></p>
    <div class="mono">{{ $secret }}</div>

    <form method="POST" action="{{ route('2fa.confirm') }}">
        @csrf
        <label for="code">Код из приложения</label>
        <input id="code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus>
        @error('code')<div class="error">{{ $message }}</div>@enderror
        <button type="submit">Подтвердить</button>
    </form>
</div>
</body>
</html>
