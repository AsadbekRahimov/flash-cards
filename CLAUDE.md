# LexiFlow Pro — инструкции для AI-агента

## Коротко о проекте
Telegram-based платформа обучения английскому. Подробности в docs/.

## Правила кода
- PHP 8.2+, strict_types обязателен.
- Laravel 12, следуй Laravel best practices.
- Тесты: Pest (не PHPUnit синтаксис).
- Стиль: Laravel Pint (php-cs-fixer preset).
- Статический анализ: Larastan level 8 должен проходить.
- Бизнес-логика — в Services (`app/Domain/*/Services/`), НЕ в контроллерах и НЕ в моделях.
- Все новые маршруты API → Resource-классы для вывода.
- Миграции: всегда `down()` реализован.

## Что делать ДО кода
1. Прочитать соответствующий документ в docs/.
2. Проверить существующие модели и сервисы — не дублировать.
3. Спросить меня, если задача неоднозначна.

## Запрещено
- Коммитить .env.
- Использовать DB::raw() без bindings.
- Создавать fat-controllers или fat-models.
- Игнорировать failing tests.
- Добавлять зависимости без согласования.

## Команды проекта
- `make test` — все тесты
- `make analyse` — phpstan
- `make lint` — pint
- `make shell` — в контейнер

## Текущий спринт
См. docs/08_ROADMAP.md и PROGRESS.md.
