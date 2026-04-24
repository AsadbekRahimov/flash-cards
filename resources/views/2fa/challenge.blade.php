<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Двухфакторная проверка — {{ config('app.name') }}</title>
    @include('2fa._styles')
</head>
<body>
<div class="card">
    <h1>Введите код</h1>
    <p>Откройте приложение-аутентификатор и введите 6-значный код. Либо используйте recovery-код.</p>

    <form method="POST" action="{{ route('2fa.verify') }}">
        @csrf
        <label for="code">Код</label>
        <input id="code" type="text" name="code" inputmode="text" autocomplete="one-time-code" required autofocus>
        @error('code')<div class="error">{{ $message }}</div>@enderror
        <button type="submit">Подтвердить</button>
    </form>
</div>
</body>
</html>
