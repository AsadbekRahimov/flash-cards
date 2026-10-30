## PostgreSQL и Redis для проверок (как в CI)
Многие тесты конкурентности (`*ConcurrencyTest`, PG-only) на SQLite пропускаются. Для изменений с блокировками/транзакциями/индексами прогоняй их на PostgreSQL 16:
- Запуск своего кластера (выбери СВОЙ свободный порт, напр. 554xx, и уникальное имя):
  `eval "$(docs/handoff/sprint18/tools/pg.sh start <имя> <порт>)"` — экспортирует `DB_CONNECTION=pgsql DB_HOST DB_PORT DB_DATABASE=lexiflow DB_USERNAME=lexiflow`.
- Redis (свой порт): `redis-server --port <порт> --daemonize yes --save '' --appendonly no` и `export REDIS_HOST=127.0.0.1 REDIS_PORT=<порт> CACHE_STORE=redis QUEUE_CONNECTION=sync SESSION_DRIVER=array`.
- Затем в своём worktree: `php artisan migrate --force` и `./vendor/bin/pest <файлы или весь набор>` (переменные окружения перекрывают phpunit.xml).
- В конце остановить: `docs/handoff/sprint18/tools/pg.sh stop <имя>` и `redis-cli -p <порт> shutdown nosave`.
- Для EXPLAIN на объёме данных можно свободно наполнять свою БД (она временная).
