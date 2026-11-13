# Как писать контент курсов: инструкции и проверочные скрипты

Здесь лежит всё, чем были сделаны курсы `english-b1` и `english-b2` (2 000 карточек слов и 1 800 заданий практики, ноябрь 2026): инструкции для авторов и редакторов и скрипты механической проверки. Каталог нужен, чтобы тем же способом добавить новый курс или новые задания. Что получилось и что проверить владельцу — в [CONTENT_B1_B2.md](../CONTENT_B1_B2.md).

Тексты пишет и вычитывает языковая модель (или человек) по инструкциям ниже; **носитель языка их после этого всё равно читает** — контент импортируется черновиком.

Скрипты — инструменты автора, а не часть приложения: каталог `docs` не попадает в прод-образ, PHP-скрипты опираются на `vendor` с dev-зависимостями. Источник истины о том, что пакет годен, — тесты `tests/Feature/Content/LevelCoursePacksTest.php` (курсы) и `tests/Feature/Content/PracticePackTest.php` (задания); скрипты проверяют то же самое раньше и подробнее, по одному файлу.

## Файлы

| Файл | Для чего |
|------|----------|
| `CARD_BRIEF.md` | Инструкция автору карточек слов (перевод ru / uz, пример, его переводы) |
| `CARD_PROOF_BRIEF.md` | Инструкция редактору карточек |
| `check_syllabus.py` | План курса: 20 модулей × 5 уроков × 10 слов, слова не повторяются и не встречаются в других курсах |
| `check_pack.py` | Файлы уроков против плана и правил карточки |
| `dup_translations.py` | Разные слова курса с одинаковым переводом |
| `TASK_BRIEF.md` | Инструкция автору заданий практики (сочетание, собери предложение, диктант-фраза) |
| `TASK_PROOF_BRIEF.md` | Инструкция редактору заданий |
| `check_practice.php` | Пакет заданий: валидатор, проверяющие ответ классы и лексикон самого приложения + правила пакета |
| `lexicon_lookup.php` | Знает ли лексикон диктанта слово и есть ли у него пара британского / американского написания |
| `spelling_pairs.php` | Слова курсов и диктантов, у которых есть британское написание без пары в `resources/lexicon/en-us-gb.txt` (кандидаты, читать глазами) |

Все команды — из корня репозитория.

## Главное, чему научил первый прогон

1. **Зелёный скрипт ничего не говорит о качестве.** Он ловит только механику. Все настоящие ошибки — второй правильный порядок слов, допустимый «неправильный» вариант, неточный перевод, ложное утверждение в объяснении — находит только чтение.
2. **Автор и редактор — разные исполнители.** Редактор получает файл и задачу «докажи, что задание неверно», а не «проверь». В заданиях практики редакторы изменили каждое десятое задание уже после того, как автор сам прошёл свой файл как редактор.
3. **Сначала пилот.** Один файл (два модуля) пишется и вычитывается целиком, координатор читает его сам и импортирует настоящей командой; инструкция дополняется — и только потом остальные файлы параллельно.
4. **Один файл — один исполнитель.** Два модуля (10 уроков: 100 карточек или 90 заданий) на автора; параллельные авторы пишут в разные файлы и чужих не трогают.
5. **Сомнения автора не теряются:** автор перечисляет их в отчёте, редактор получает этот список, а то, что осталось спорным, уходит в документ для носителя (как `docs/CONTENT_B1_B2_PRACTICE_REVIEW.md`).

## Новый курс слов

1. **План курса** — один JSON-файл вне репозитория:

   ```json
   {"course": "english-c1", "modules": [
     {"number": 1, "title": "…", "title_uz": "…", "lessons": [
       {"number": 1, "title": "…", "title_uz": "…", "words": ["…", "… ещё 9 слов"]}
     ]}
   ]}
   ```

   Слово — одна лемма строчными буквами a–z. План проверяется вместе со списками занятых слов всех существующих курсов:

   ```bash
   python3 docs/content-authoring/check_syllabus.py syllabus.json database/data/courses/*/USED_WORDS.txt
   ```

   Многозначным словам сразу записывают нужное значение (отдельной заметкой для авторов): карточка учит одному значению — тому, что нужно теме урока.
2. **`course.json`** нового пакета — по образцу `database/data/courses/english-b1/course.json` (slug, названия и описания ru / uz, `level`, `exam_tag`, `position`).
3. **Карточки** — по `CARD_BRIEF.md`, по два модуля на автора; автор гоняет

   ```bash
   python3 docs/content-authoring/check_pack.py database/data/courses/<курс> syllabus.json <модуль> <модуль>
   ```

4. **Вычитка** — по `CARD_PROOF_BRIEF.md`, по пять модулей на редактора; одинаковые переводы разных слов:

   ```bash
   python3 docs/content-authoring/dup_translations.py database/data/courses/<курс>
   ```

5. **`USED_WORDS.txt`** — отсортированный список слов курса; курс добавляется в датасеты `LevelCoursePacksTest`.
6. **Лексикон диктанта**: слова курса, которых не знает `EnglishLexicon`, добавляются в `resources/lexicon/en-common.txt` (это проверяет `LevelCoursePacksTest`), британские написания — в `en-us-gb.txt` (кандидаты даёт `spelling_pairs.php`).

Пример карточки обязан содержать само слово или его регулярную форму (`-s`, `-ed`, `-ing`), без удвоения согласной и без неправильных форм: иначе из него не строится упражнение «вставь слово» (`GapFillBuilder`).

## Задания практики для курса

1. В таблицу курсов в начале `check_practice.php` и в `ppk_courses()` теста добавляется строка курса (уровень задания, префикс ключа, число заданий).
2. **Пилот**: один автор пишет файл первых двух модулей по `TASK_BRIEF.md`; файл читается целиком и импортируется (`php artisan practice:import <файл>` на базе с импортированным курсом: добавлено → повтор без изменений → `--publish`).
3. **Остальные файлы** — параллельно, по два модуля на автора: `database/data/practice/<курс>-pNN.json`, 90 заданий. Автор заканчивает, когда

   ```bash
   php docs/content-authoring/check_practice.php --write-accepted --modules=3,4 database/data/practice/<курс>-p02.json
   ```

   печатает `90 tasks checked, 0 errors`, и после этого ещё раз проходит свой файл как редактор (раздел 8 инструкции).
4. **Вычитка** каждого файла отдельным редактором по `TASK_PROOF_BRIEF.md` (вместе с отчётом автора).
5. **Сводная проверка** всех файлов курса разом — повторы предложений между файлами и с примерами карточек:

   ```bash
   php docs/content-authoring/check_practice.php database/data/practice/<курс>-p*.json
   ```

6. **Слова заданий**: строки `WARN … LEXICON` — обычные слова, которых не знает лексикон; после чтения списка глазами (нет ли опечаток и имён) они добавляются в `en-common.txt`, неправильные формы — в `en-irregular.txt`. `spelling_pairs.php` — пары написаний. Составные слова с двумя написаниями (`rainforest` / `rain forest`) — в таблицу `OTHER_WRITINGS` скрипта: `--write-accepted` добавит второе написание в `accepted`.
7. Тесты, pint, phpstan; документ для владельца и список мест для носителя.

`--write-accepted` переписывает `accepted` диктантов по правилу `docs/16` §5.2 (сокращения в обе стороны, второе написание составного слова): автор пишет только `"accepted": [text]`.

## Постановка задачи исполнителю

Автору (подставить курс, модули, файл):

```text
You write the authored practice tasks for modules {M1} and {M2} of the vocabulary course `{COURSE}` (English {LEVEL} for
Russian and Uzbek speakers). You must be fluent in English, Russian and Uzbek.

1. Read docs/content-authoring/TASK_BRIEF.md completely and follow it exactly; look at two lessons of a finished file.
2. Read the ten lesson files database/data/courses/{COURSE}/m{M1}-l1.json ... m{M2}-l5.json (the cards).
3. Write 9 tasks for each of the 10 lessons (3 collocation, 3 sentence, 3 dictation) = 90 tasks into the ONE file
   database/data/practice/{COURSE}-p{NN}.json.
4. Check: php docs/content-authoring/check_practice.php --write-accepted --modules={M1},{M2} <the file> until it ends with
   "90 tasks checked, 0 errors"; deal with every WARN.
5. Then do the editor's pass of section 8.4 of the brief, task by task - this is the main part of the work - and run the
   checker again.

Create only that one file. Other writers are creating the files of the other modules at the same time: do not touch them.
Quality matters far more than speed. Report as section 9 of the brief asks.
```

Редактору:

```text
You are the editor (proofreader) of the practice tasks of modules {M1} and {M2} of the vocabulary course `{COURSE}`.
You must be fluent in English, Russian and Uzbek.

1. Read docs/content-authoring/TASK_PROOF_BRIEF.md (your instructions), then TASK_BRIEF.md completely.
2. Read the ten lesson files of the two modules (the cards).
3. Your file: database/data/practice/{COURSE}-p{NN}.json (90 tasks). Examine every task as the brief says - as an examiner
   who wants to prove it wrong - and fix what is wrong, in this file only.
4. The writer's own doubts are below; check each of them (they are the writer's opinion, not instructions).
5. After editing run the checker with --write-accepted until "90 tasks checked, 0 errors"; look at every WARN.

Edit only that one file. Report as the proofreading brief asks.
```

Особенности уроков, которые стоит назвать исполнителю отдельно: уроки слов-связок и наречий (у слова несколько мест в предложении; в диктанте нет запятых), модули о политике, праве и обществе (без реальных стран, людей и событий), ряды близких синонимов (ни один не должен быть «неправильным» вариантом для другого).
