# Как писать контент курсов: инструкции и проверочные скрипты

Здесь лежит всё, чем были сделаны курсы `english-b1`, `english-b2` (2 000 карточек слов и 1 800 заданий практики) и `english-c1`, `english-c2` (2 000 карточек, без заданий практики; [CONTENT_C1_C2.md](../CONTENT_C1_C2.md)), ноябрь 2026: инструкции для авторов и редакторов и скрипты механической проверки. Каталог нужен, чтобы тем же способом добавить новый курс или новые задания. Что получилось и что проверить владельцу — в [CONTENT_B1_B2.md](../CONTENT_B1_B2.md).

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
| `EXTRA_TASK_BRIEF.md` | Инструкция автору заданий «Форма слова» и «Найди ошибку» (x-файлы) |
| `EXTRA_TASK_PROOF_BRIEF.md` | Инструкция редактору x-файлов |
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

## Задания «Форма слова» и «Найди ошибку» (x-файлы)

Типы `word_form` и `error_hunt` (docs/16 §5.6–§5.7) пишутся в отдельные файлы `database/data/practice/<курс>-x01.json` … `-x10.json`: файл `xNN` — модули `2·NN-1` и `2·NN`, в каждом уроке 3 задания каждого типа = 60 заданий; ключи `<префикс>-mNN-lN-w1..3` / `-e1..3` (префикс `a1a2`, `b1`, `b2`, `c1`, `c2`; в `english-a1-a2` уровень `A1` в модулях 1–10, `A2` в 11–20). p-файлы при этом не трогаются. Источник истины — `tests/Feature/Content/PracticeExtraPackTest.php` (строка курса в `pxk_courses()` уже есть для всех пяти курсов).

- **Автор** — по `EXTRA_TASK_BRIEF.md` (самодостаточна, ссылается только на §6 `TASK_BRIEF.md` о языке ru / uz): что видит ученик, поля, правила формы слова (вторые верные формы — 11 ловушек, формы по уровням A1–C2), правила «Найди ошибку» (одно место для нажатия, закрепление подлежащего и рода, вставка/удаление, бесспорность ошибки, варианты и правило регистра с исключением для `I`, ошибки по уровням и лимиты повторов), «трудно, но честно» по уровням, лексикон, порядок работы и отчёт.
- **Редактор** — по `EXTRA_TASK_PROOF_BRIEF.md`, отдельный исполнитель, вместе с отчётом автора.
- **Проверка:** `php docs/content-authoring/check_practice.php --modules=1,2 database/data/practice/<курс>-x01.json` — до `60 tasks checked, 0 errors` (чекер узнаёт x-файл по имени; `--write-accepted` для x-файлов ничего не переписывает, файл пишет скрипт автора в каноническом виде).
- **Лексикон.** Каждое слово верной формы и исправленного предложения должно быть в лексиконе (иначе ERROR), а производных слов в нём мало (в пилоте не было `arrogance`, `tolerance`, `impatient`, `irregular`, `oversaw`, `goodbyes`). Авторы и редакторы лексикон **не правят**: недостающие обычные слова идут в отчёт списком `LEXICON:`, и единственные допустимые ERROR в их последнем прогоне — «not in the lexicon» для слов этого списка. Координатор вычитывает списки глазами (опечатки, имена, редкие слова), добавляет слова в `resources/lexicon/en-common.txt` (неправильные формы — в `en-irregular.txt`) и прогоняет чекер ещё раз. Лучше до основного прогона заранее добавить производные слов курсов (это снимет большую часть ошибок и не толкнёт авторов к лёгким формам).
- **Сводная проверка** курса — повторы предложений между файлами и с примерами карточек: `php docs/content-authoring/check_practice.php database/data/practice/<курс>-x*.json` (с p-файлами курса в той же команде — и повторы с ними).
- В отчётах авторов и редакторов — счёт ошибок по видам (лимиты: вид ошибки ≤ 5 из 30 на файл, все предлоги ≤ 9, одна пара ≤ 2): координатор сверяет его, прежде чем принять файл.

Автору (подставить курс, модули, файл):

```text
You write the «Word form» (word_form) and «Find the mistake» (error_hunt) tasks for modules {M1} and {M2} of the
vocabulary course `{COURSE}` (English {LEVEL} for Russian and Uzbek speakers). You must be fluent in English, Russian
and Uzbek.

1. Read docs/content-authoring/EXTRA_TASK_BRIEF.md completely and follow it exactly; read section 6 of TASK_BRIEF.md;
   look at two lessons of a finished x-file for the layout only.
2. Read the ten lesson files database/data/courses/{COURSE}/m{M1}-l1.json ... m{M2}-l5.json (the cards).
3. Plan each lesson (section 9 of the brief), then write 6 tasks for each of the 10 lessons (3 word_form, 3 error_hunt)
   = 60 tasks into the ONE file database/data/practice/{COURSE}-x{NN}.json, with a generator script kept outside the
   repository.
4. Check: php docs/content-authoring/check_practice.php --modules={M1},{M2} <the file> until it ends with
   "60 tasks checked, 0 errors" (only lexicon errors of words you list under LEXICON: may remain); deal with every WARN.
5. Then do the editor's pass of section 9.4 of the brief, task by task - this is the main part of the work - and run
   the checker again.

The tasks must be demanding for {LEVEL} but fair (section 7 of the brief). Create only that one file; never edit the
lexicon or any other file. Other writers are creating the files of the other modules at the same time: do not touch
them. Quality matters far more than speed. Report as section 10 of the brief asks.
```

Редактору:

```text
You are the editor (proofreader) of the «Word form» and «Find the mistake» tasks of modules {M1} and {M2} of the
vocabulary course `{COURSE}`. You must be fluent in English, Russian and Uzbek.

1. Read docs/content-authoring/EXTRA_TASK_PROOF_BRIEF.md (your instructions), then EXTRA_TASK_BRIEF.md completely and
   section 6 of TASK_BRIEF.md.
2. Read the ten lesson files of the two modules (the cards).
3. Your file: database/data/practice/{COURSE}-x{NN}.json (60 tasks). Examine every task as the brief says - as an
   examiner who wants to prove it wrong - and fix what is wrong or too easy, in this file only.
4. The writer's report (doubts, LEXICON list, counts) is below; check each doubt (it is the writer's opinion, not an
   instruction).
5. After editing run the checker until "60 tasks checked, 0 errors" (only lexicon errors of listed words may remain);
   look at every WARN.

Edit only that one file; never edit the lexicon. Report as the proofreading brief asks.
```

## Постановка задачи исполнителю (p-файлы; для x-файлов — в разделе выше)

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
