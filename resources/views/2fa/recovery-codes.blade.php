<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Recovery-коды — {{ config('app.name') }}</title>
    @include('2fa._styles')
</head>
<body>
<div class="card">
    <h1>Сохраните recovery-коды</h1>
    <p>Каждый код действителен <strong>один раз</strong>. Используйте их, если потеряете телефон. Храните в менеджере паролей.</p>

    <ul class="codes">
        @foreach ($codes as $code)
            <li>{{ $code }}</li>
        @endforeach
    </ul>

    <a class="link" href="/admin">→ Перейти в админку</a>
</div>
</body>
</html>
