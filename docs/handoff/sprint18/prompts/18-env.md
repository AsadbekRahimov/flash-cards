## Окружение (ты в изолированном git worktree) — выполнить ПЕРВЫМ ДЕЛОМ
0. Worktree создаётся от `master`, работа идёт поверх ветки координатора. В корне своего worktree сразу: `git merge --ff-only claude/tender-allen-712v3m`. Проверь, что есть `docs/16_PRACTICE_DESIGN.md`.
1. vendor и .env не в git. НЕ делай symlink vendor (Composer тогда грузит `App\`/`Tests\` из главного чекаута). Hardlink-копия: `cp -al /home/user/flash-cards/vendor vendor && cp /home/user/flash-cards/.env .env` (composer в копии не запускай).
2. TWA (если задача трогает `resources/twa`): `cd resources/twa && cp -al /home/user/flash-cards/resources/twa/node_modules node_modules` (быстро; `npm ci` не нужен). Проверки TWA: `npx vue-tsc --noEmit && npx vitest run && npx vite build --outDir $S/twa-build-{TASK} --emptyOutDir` (НЕ в `public/twa`).
3. Проверки PHP: `./vendor/bin/pint`, `./vendor/bin/phpstan analyse --memory-limit=2G` (level 8, 0 ошибок), `./vendor/bin/pest --parallel --processes=6` (весь набор). Docker нет — запускай напрямую.
4. PostgreSQL + Redis (для гонок, partial-индексов, PG-only тестов) — `docs/handoff/sprint18/prompts/env-pg.md` (свой кластер и порты из своей задачи).
5. Не создавай и не меняй файлов в главном чекауте `/home/user/flash-cards` — там работают другие.
6. Коммить логическими шагами в ветку своего worktree (не пушь). `git add` только конкретные пути, никогда `-A`/`.`. Контейнер может перезапуститься — коммить часто. В конце сообщения коммита:
   <строки атрибуции из инструкций текущей сессии координатора>

## Прочитай
`CLAUDE.md`; `docs/16_PRACTICE_DESIGN.md` (проект: имена таблиц, колонок, маршрутов, кодов ошибок — копируй буквально; если видишь в нём ошибку или противоречие — НЕ импровизируй молча: сделай самое безопасное и опиши в отчёте); `docs/15_PRACTICE_GAMES_PLAN.md` (правила владельца). Для TWA также `docs/14_UX_AUDIT_AND_REDESIGN.md` (§3–§5, §8) и `docs/05_TWA.md`.

## Общие правила
- PHP: `declare(strict_types=1)`, бизнес-логика в `app/Domain/*/Services`, вывод API — Resource-классы, миграции с рабочим `down()`, Pest (не PHPUnit-синтаксис), без `DB::raw()` без bindings, без новых зависимостей.
- Тесты не удалять и не ослаблять ради зелёного цвета; падающий чужой тест — разобраться и описать в отчёте.
- TWA: только токены и классы дизайн-системы (никаких `red-600`, `gray-*`, `text-white` и т. п.); состояния — `LoadingState`/`ErrorState`/`EmptyState`; «Назад» — `useBackButton`; иконки — `AppIcon`; одна `btn-primary` на экран; нажатия ≥ 44 px; цвет всегда дополняется значком и текстом; тексты только через i18n, ru и uz вместе (uz — латиница, апостроф ASCII `'`, обращение «Siz», термины docs/14 §8); без `v-html`; кириллица только в `src/i18n/*`; без новых npm-пакетов.
- Новые uz-строки (TWA, `lang/uz/*`) — в `docs/I18N_UZ_REVIEW.md` новым разделом В КОНЦЕ файла `## Практика и баттл 2026-10 · {TASK} — на проверку носителю` (таблица `| Ключ | uz | ru |`). `UzReviewDocTest` должен быть зелёным.
- Общие файлы (`routes/api.php`, `resources/twa/src/api/types.ts`, `resources/twa/src/i18n/{ru,uz}.ts`, `config/practice.php`, `lang/*/practice.php`, `docs/04_API_AND_BOT.md`): добавляй свои блоки там, где велит проект (раздел «Нарезка»), не переставляй чужие строки — параллельно работают другие исполнители, координатор сливает ветки.
