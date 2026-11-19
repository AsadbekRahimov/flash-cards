# Курсы «Английский C1» и «Английский C2» — обзор для владельца

Два новых курса-черновика, продолжение `english-b2` (см. [CONTENT_B1_B2.md](CONTENT_B1_B2.md), [CONTENT_A1_A2.md](CONTENT_A1_A2.md)). Вместе с тремя прежними курсами словарная база платформы — **5 000 слов** в 500 уроках.

| Курс | Slug | Название ru / uz | Уровень | Позиция | Слов |
|------|------|------------------|---------|---------|------|
| Продвинутый | `english-c1` | «Английский C1: продвинутый» / «Ingliz tili C1: ilg'or daraja» | `C1` | 40 | 1 000 |
| В совершенстве | `english-c2` | «Английский C2: в совершенстве» / «Ingliz tili C2: mukammal daraja» | `C2` | 50 | 1 000 |

У обоих курсов `exam_tag` `general`, видимость `public`, после импорта статус **черновик** (`draft`): сами они не публикуются. `level_label` в `course.json` нет (как у B1 и B2): в «Готовности к уровню» подпись берётся из `level`. Описание обещает слова «для учёбы, работы и экзаменов» и ничего не говорит о результате экзамена.

**Уровни ученика.** В онбординге бота и в настройках (`/settings`, настройки в Mini App) теперь шесть уровней: `A1`, `A2`, `B1`, `B2`, `C1`, `C2` (и «Не знаю» → `A1`); их список — `OnboardingService::LEVELS`, на него опираются бот, настройки и валидация `PATCH /settings`. Подписи кнопок: ru «C1 — продвинутый», «C2 — свободно»; uz «C1 — ilg'or», «C2 — erkin» (строки узбекского — в [I18N_UZ_REVIEW.md](I18N_UZ_REVIEW.md), их тоже смотрит носитель). Рекомендация курса — прежнее правило `CourseCatalogService::recommend()`: курс с точно таким же уровнем, иначе первый по позиции. Поэтому после публикации новый ученик с целью «общий английский» и уровнем C1 или C2 получит свой курс. **Курсы A1–A2, B1 и B2 публикуются не позже C1 и C2** (иначе новичку будет предложен первый опубликованный курс по позиции). Уже выбранный курс ученика онбординг не меняет.

Формат тот же, что у предыдущих курсов: 20 модулей × 5 уроков × 10 слов; у каждого слова часть речи, перевод ru и uz, пример на 5–10 слов и его перевод ru и uz, **транскрипция** (IPA в слешах, знаки из набора проекта). Узбекский — латиница с ASCII-апострофом. Американское написание (`organize`, `behavior`).

**Ни одно слово не повторяется** ни внутри курса, ни между пятью курсами (`USED_WORDS.txt` в каждом пакете, 1 000 строк; проверяет `tests/Feature/Content/LevelCoursePacksTest.php`: 5 000 разных слов). Слова подобраны по темам и уровню самостоятельно, без копирования опубликованных списков.

## Как подготовлены тексты

Тексты — **черновик, подготовленный Claude**, носитель их не читал. Перед публикацией курс читает владелец или носитель узбекского и английского.

1. План слов каждого курса (темы, уроки, по 10 слов) составлен и проверен на повторы со всеми существующими курсами (`check_syllabus.py`). Многозначным словам сразу записано нужное значение.
2. **20 авторов** по два модуля на курс написали карточки и проверили их механически (`check_pack.py`): формат, часть речи, кириллица в ru и латиница в uz, длина примера, пример содержит слово или его регулярную форму.
3. **8 редакторов** по пять модулей (по четыре на курс) прочитали каждую карточку: значение по теме урока, естественность русского и узбекского, совпадение перевода с примером; одинаковые переводы разных слов разведены (`dup_translations.py`). Правки внесены в **73 карточки C1 и 81 карточку C2**.
4. **Транскрипции** добавлены отдельным шагом; тест проверяет, что у каждой из 5 000 карточек она есть, в слешах и только из знаков проекта.
5. В лексикон диктанта (`resources/lexicon/en-common.txt`) добавлены слова курсов, которых он не знал (около 1 900), а в `en-us-gb.txt` — британские написания (`-ise` для `-ize` и формы, `counselling`, `demeanour`, `manoeuvre`, `tumour`, `somber`/`sombre`, `distil`) — это нужно, чтобы диктант считал одно слово другим словом, а не опечаткой.

## Модули и уроки: C1

| Модуль | Урок | Слов |
|--------|------|------|
| 1. Характер и личность | 1 Достоинства · 2 Недостатки · 3 Манера держаться · 4 Нравственность · 5 Талант и мастерство | 50 |
| 2. Чувства и отношения | 1 Сильные чувства · 2 Душевное состояние · 3 Близкие люди · 4 Ссоры и обиды · 5 Сочувствие и поддержка | 50 |
| 3. Работа и карьера | 1 Должности и рост · 2 Обязанности и нагрузка · 3 Оплата труда · 4 Трудовые споры · 5 Деловые качества | 50 |
| 4. Бизнес и управление | 1 Стратегия компании · 2 Рынок и конкуренция · 3 Управление и контроль · 4 Переговоры и сделки · 5 Производство и поставки | 50 |
| 5. Экономика и финансы | 1 Экономическая политика · 2 Инвестиции · 3 Долги и банкротство · 4 Налоги и бюджет · 5 Богатство и бедность | 50 |
| 6. Наука и исследования | 1 План исследования · 2 Данные и статистика · 3 Выводы и доказательства · 4 Физика и химия · 5 Клетка и гены | 50 |
| 7. Технологии | 1 Разработка программ · 2 Кибербезопасность · 3 Умные системы · 4 Техника и механизмы · 5 Технологии и общество | 50 |
| 8. Здоровье и медицина | 1 Болезни и симптомы · 2 Лечение · 3 Здоровье населения · 4 Тело человека · 5 Образ жизни | 50 |
| 9. Психология и мышление | 1 Как мы думаем · 2 Побуждения и привычки · 3 Психические расстройства · 4 Восприятие и память · 5 Самооценка и взросление | 50 |
| 10. Природа и экология | 1 Климатический кризис · 2 Загрязнение и отходы · 3 Природные зоны · 4 Жизнь животных · 5 Ресурсы и энергия | 50 |
| 11. Политика и власть | 1 Государственный строй · 2 Выборы и партии · 3 Международные отношения · 4 Подавление и протест · 5 Законы и реформы | 50 |
| 12. Право и правосудие | 1 Преступления · 2 Судебный процесс · 3 Наказание и помилование · 4 Гражданское право · 5 Законность и справедливость | 50 |
| 13. Общество | 1 Социальные слои · 2 Миграция и идентичность · 3 Семья и родство · 4 Социальные проблемы · 5 Благотворительность | 50 |
| 14. СМИ и информация | 1 Новости и репортёры · 2 Правда и вымысел · 3 Реклама и продвижение · 4 Цифровые медиа · 5 Свобода слова | 50 |
| 15. Искусство и культура | 1 Живопись и графика · 2 Музыка и концерты · 3 Литература · 4 Кино и театр · 5 Оценка произведения | 50 |
| 16. Образование и научный стиль | 1 Высшее образование · 2 Научная публикация · 3 Методы преподавания · 4 Глаголы научной речи · 5 Слова-связки | 50 |
| 17. Аргументация и дискуссия | 1 Утверждать и возражать · 2 Логика · 3 Степень уверенности · 4 Искусство убеждать · 5 Спор и согласие | 50 |
| 18. История и конфликты | 1 Война · 2 Империи и колонии · 3 Изучение прошлого · 4 Восстания и перевороты · 5 Угрозы безопасности | 50 |
| 19. Город и пространство | 1 Развитие города · 2 Архитектура и стройка · 3 Аренда и недвижимость · 4 Транспорт · 5 Рельеф и ландшафт | 50 |
| 20. Абстрактные понятия | 1 Рост и спад · 2 Причина и следствие · 3 Препятствия и возможности · 4 Количество и масштаб · 5 Сходство и различие | 50 |
| **Итого C1** | 100 уроков | **1 000** |

## Модули и уроки: C2

| Модуль | Урок | Слов |
|--------|------|------|
| 1. Оттенки характера | 1 Благородные качества · 2 Высокомерие и лицемерие · 3 Темперамент · 4 Ум и ограниченность · 5 Воля и упорство | 50 |
| 2. Эмоции и состояния души | 1 Радость и восторг · 2 Печаль и тоска · 3 Гнев и раздражение · 4 Страх и тревога · 5 Равнодушие и скука | 50 |
| 3. Речь и оттенки смысла | 1 Манера речи · 2 Глаголы речи · 3 Похвала и порицание · 4 Уклончивость и притворство · 5 Тон и подтекст | 50 |
| 4. Официальный стиль | 1 Глаголы делового языка · 2 Документы и заседания · 3 Слова официальных бумаг · 4 Вежливость и церемониал · 5 Разрешено и запрещено | 50 |
| 5. Философия и этика | 1 Добро и зло · 2 Бытие и реальность · 3 Знание и истина · 4 Вера и религия · 5 Идеи и мировоззрения | 50 |
| 6. Разум и познание | 1 Сознание · 2 Суждение и вывод · 3 Заблуждения · 4 Память и воображение · 5 Ясное и загадочное | 50 |
| 7. Художественное описание | 1 Свет и цвет · 2 Звук и тишина · 3 Пейзаж · 4 Движение · 5 Облик человека | 50 |
| 8. Литература и язык | 1 Малые жанры · 2 Стилистические приёмы · 3 Оценка стиля · 4 Сюжет и герои · 5 Слово и язык | 50 |
| 9. Власть и государство | 1 Правители и власть · 2 Коррупция и интриги · 3 Дипломатия · 4 Протест и усмирение · 5 Устройство государства | 50 |
| 10. Тонкости права | 1 Юридические понятия · 2 Вина и доказательства · 3 Наследство и имущество · 4 Мошенничество и злоупотребления · 5 Международное право | 50 |
| 11. Экономика и капитал | 1 Финансовые рынки · 2 Экономические учения · 3 Роскошь и нищета · 4 Цена и ценность · 5 Корпорации | 50 |
| 12. Точные и естественные науки | 1 Научное мышление · 2 Физика и космос · 3 Вещества и реакции · 4 Земля и эволюция · 5 Математика | 50 |
| 13. Медицина и организм | 1 Недуги · 2 Средства лечения · 3 Анатомия · 4 Ощущения · 5 От рождения до старости | 50 |
| 14. Мир природы | 1 Погода и стихия · 2 Растения · 3 Повадки животных · 4 Бедствия и разрушения · 5 Вода и скалы | 50 |
| 15. Общество и нравы | 1 Сословия и положение · 2 Обычаи и устои · 3 Нетерпимость и отчуждение · 4 Слава и репутация · 5 Манера общения | 50 |
| 16. Конфликт и согласие | 1 Вражда и раздоры · 2 Давление и уступки · 3 Отпор и уклонение · 4 Примирение и прощение · 5 Победа и поражение | 50 |
| 17. Время и перемены | 1 Мгновение и вечность · 2 Начало и конец · 3 Старое и новое · 4 Изменчивость и неизбежность · 5 Упадок и возрождение | 50 |
| 18. Мера и оценка | 1 Избыток и нехватка · 2 Главное и второстепенное · 3 Тщательность и небрежность · 4 Очевидное и сомнительное · 5 Обычное и исключительное | 50 |
| 19. Искусство и эстетика | 1 Красота и вкус · 2 Музыкальные термины · 3 Скульптура и зодчество · 4 Мастерство творца · 5 Зритель и впечатление | 50 |
| 20. Труд и достижения | 1 Усердие и лень · 2 Трудности и испытания · 3 Вершины успеха · 4 Промахи и слабости · 5 Замысел и расчёт | 50 |
| **Итого C2** | 100 уроков | **1 000** |

## Что проверить при чтении

Общие правила — как у B1/B2: перевод — значение слова в теме урока, совпадающее с примером; узбекский пример естественно передаёт смысл; обращение на «Siz» / «вы». Отметку «Проверено» ставят в админке; при замене текста перевода импорт её снимает.

- **Многозначные слова заведены в одном значении** (тема урока), другие значения на карточку не попали.
- **Близкие синонимы** (особенно модули 16–20 обоих курсов) разведены первым словом перевода и примером; полного различия в ru и uz иногда нет.
- **Заимствования в узбекском** оставлены, где это обычное слово (`kulminatsiya`, `altruizm`, `virtuoz`); где у слова нет устоявшегося узбекского термина, дана короткая фраза — ее и стоит прочитать носителю.
- Темы политики, права, истории и войны (C1: 11, 12, 18; C2: 9, 10) написаны без реальных стран, партий и людей; примеры о насилии нейтральны.
- Примеры используют само слово или его регулярную форму: формы с удвоением согласной и неправильные формы в примерах не встречаются (`GapFillBuilder`).

## Задания практики

По 900 авторских заданий на курс — в каждом из 100 уроков ровно по три задания каждого типа (сочетание, собери предложение, диктант-фраза): `database/data/practice/english-c1-p01.json` … `-p10.json` и `english-c2-p01.json` … `-p10.json`, по 90 заданий (два модуля) в файле. Формат и правила — как у B1 и B2 ([CONTENT_B1_B2.md](CONTENT_B1_B2.md#задания-практики), `docs/16` §4.2, §4.5, §5.2); всё это проверяет `tests/Feature/Content/PracticePackTest.php` (строки `english-c1` и `english-c2` в `ppk_courses()`).

- **Целевые слова** задания — только слова его урока; девять заданий урока покрывают все десять его слов. Практика урока открывается, когда слова урока введены ученику, а фразы озвучены.
- **Уровень** задания — уровень курса (`C1` / `C2`). Ключи: `c1-m03-l2-c1` — курс, модуль, урок, тип (`c` сочетание, `s` предложение, `d` диктант) и номер.
- **Сочетание.** Целевое слово видно в предложении, пропуск — его партнёр (глагол, предлог, прилагательное, наречие). Верный вариант один; два неверных — типичные ошибки или кальки, которых в стандартном британском и американском английском не говорят.
- **Собери предложение.** В `accepted` перечислены все порядки слов с тем же смыслом: у 65 заданий C1 и 94 заданий C2 из 300 их больше одного (вынесенное в начало обстоятельство, вводная часть в середине, книжная инверсия).
- **Диктант-фраза.** Без имён, чисел и запятых; варианты написания добавлены по правилу `docs/16` §5.2. Британские написания слов этих диктантов (`despatch`, `fuelled`, `rancour`, `clamour`, `dishevelled`, `byelaw`, `lacklustre`, `gruelling`, `mediaeval`) и американское `demagog` засчитываются через `resources/lexicon/en-us-gb.txt` (17 пар с формами); дефисные `buy-out`, `pre-eminent`, `non-combatants` — в `accepted` своих заданий.
- Лексикон диктанта (`resources/lexicon/en-common.txt`) знает все слова диктантов и почти все слова остальных заданий (добавлено 211 обычных слов); не знает только намеренно редких неверных вариантов `unglued`, `hotness` и притяжательных `students'`, `patients'` — на проверку ответа это не влияет.

**Как подготовлены.** 20 авторов (файл на автора) написали задания по `content-authoring/TASK_BRIEF.md` и проверили их тем же валидатором и теми же проверяющими ответ классами, что работают в приложении (`check_practice.php`); затем каждый файл отдельно прочитал редактор с задачей «доказать, что задание неверно». Редакторы изменили 79 заданий C1 и 92 задания C2. Носитель задания не читал: импортируются они **черновиками**, а места, которые редакторы просят показать носителю, собраны в [CONTENT_C1_C2_PRACTICE_REVIEW.md](CONTENT_C1_C2_PRACTICE_REVIEW.md).

**«Форма слова» и «Найди ошибку».** 600 заданий двух новых типов (`docs/16` §5.6, §5.7) на курс — в каждом из 100 уроков по три задания «Форма слова» (`word_form`) и три «Найди ошибку» (`error_hunt`): `database/data/practice/english-c1-x01.json` … `-x10.json` и `english-c2-x01.json` … `-x10.json`, по 60 заданий (два модуля) в файле, ключи `<префикс>-mNN-lN-w1..3` / `-e1..3`. Их проверяет `tests/Feature/Content/PracticeExtraPackTest.php`; правила и порядок работы — [content-authoring/](content-authoring/README.md) (`EXTRA_TASK_BRIEF.md`). Импортируются черновиками; места, которые редакторы просят показать носителю, — в [CONTENT_EXTRA_PRACTICE_REVIEW.md](CONTENT_EXTRA_PRACTICE_REVIEW.md).

## На вычитку носителю

Ниже — сомнения авторов и редакторов, ничего не отсеяно: это вопросы «слово — что смущает» по курсам и модулям (из отчётов исполнителей, английский в оригинале). Редакторы перечисляют то, что осталось сомнительным **после** их правок. Многие записи — не ошибки, а места, где в узбекском нет однословного термина и дана фраза; такие карточки стоит прочитать в первую очередь. Общее замечание авторов: `redundancy` (C1) — британское употребление в курсе с американским написанием; в C1 модуль 18, урок 5 (`massacre`, `genocide`, `atrocity`, `hostage`) — тема насилия, примеры нейтральны; в C2 модуль 14, урок 5 (`juvenile`) — юридический пример в уроке о возрастах.

### C1

**Сомнения авторов**

Модули 1-2:

- awe - uz "ehtiromli hayrat" is descriptive, no single Uzbek word.
- contempt - uz "nafrat, mensimaslik"; "nafrat" also means hatred.
- overwhelmed - uz "ezilib qolgan" approximate; ru "перегруженный".
- dismayed - uz "qattiq xafa bo'lgan" loses the shock nuance.
- empathize - uz "dardini his qilmoq", close to sympathize.
- prodigy, altruism, euphoria - uz loanwords (vunderkind, altruizm, eyforiya).
- complacent - ru example "самодовольной" slightly off the "self-satisfied and careless" sense.
- retaliate - example shows "javob qaytarmoq", not "o'ch olmoq".
- righteous - uz "solih" has a religious tint.
- whimsical, shrewd - two-word glosses, not exact synonyms.

Модули 3-4:

- affiliate - "аффилированная компания / affillangan kompaniya": legal term, loanword in Uzbek.
- annuity - "рента, аннуитет / renta, annuitet": no native Uzbek term.
- bottleneck - uz "tor joy" is a calque.
- acumen - uz "ishbilarmonlik qobiliyati" is approximate.
- burnout - uz "kasbiy toliqish" is descriptive.
- whistleblower - "разоблачитель / fosh etuvchi": no established term.
- synergy - example only weakly shows the meaning.
- redundancy - British usage in an American-spelling course.
- foothold - "плацдарм / tayanch nuqta".
- professionalism - uz "professionallik".
- punctuality - uz "vaqtga rioya qilish".
- seniority - "трудовой стаж / ish staji" drops the rank sense.
- incumbent - translation clumsy but follows notes.

Модули 5-6:

- deregulation - uz is the descriptive "davlat nazoratini yumshatish", not a term.
- volatility - uz "o'zgaruvchanlik" is general, not a finance term.
- hedge - uz "xatarni sug'urtalamoq" (avoided "xejirlamoq").
- speculation - uz "birja chayqovchiligi" sounds pejorative.
- foreclosure, rebate, outlier - long descriptive translations in both languages.
- bailout - "спасение от банкротства" narrows the meaning.
- subsistence - "пропитание, выживание" / "kun ko'rish" is approximate.
- qualitative, cellular - uz "sifatiy", "hujayraviy" are bookish.
- caveat - uz "ogohlantiruvchi izoh" is not an exact match for "оговорка".
- conclusive / tentative - uz "uzil-kesil", "qat'iy bo'lmagan" need a native check.
- tissue - ru example has "ткань" without "организма".

Модули 7-8:

- immersive - uz "muhitga sho'ng'ituvchi" is my coinage; "immersiv" may be preferred.
- disruptive - ru "прорывной, подрывной" / uz "tub burilish yasovchi" are descriptive, no fixed term.
- intrusive - uz "shilqim" may sound colloquial.
- augmented - uz "kengaytirilgan" (reallik); "to'ldirilgan" also exists.
- prognosis - uz "prognoz" is a loan.
- dosage - uz "doza, dori miqdori".
- deploy - uz "joylashtirmoq".
- scalable - uz "kengaytiriladigan".
- propulsion - narrowed to "тяга / tortish kuchi".
- moderation - uz "me'yor" (literally "norm").
- malignant - uz "xavfli" (the set term with o'sma, but generic).
- thermal, computational, surgical, cardiac - uz "...ga oid", while the examples use the bare noun attributively.

Модули 9-10:

- craving - uz "kuchli tusash" bookish.
- obsession - uz "ruju qo'yish" (idiom, verbal noun).
- delusion - uz "vasvasa" (psychiatric term, also "temptation").
- inferiority - uz "o'zini kam sanash", descriptive.
- narcissism - uz "o'ziga bino qo'yish" = conceit.
- mindfulness - uz "onglilik", no settled term.
- decarbonize - uz "ugleroddan xoli qilmoq", coined.
- runoff - uz "yuza suv oqimi".
- canopy - uz "o'rmon shox-shabbasi".
- invasive - uz "kelgindi" instead of "invaziv".
- grassland - "o'tloqzor" / "травянистая равнина".
- reminiscent, oblivious - example translations use verbs ("напоминал", "не замечала"), not the card's participle.
- envisage, heatwave - British-leaning spellings fixed by the syllabus.

Модули 11-12:

- policymaker - no one-word equivalent; "лицо, определяющее политику" / "siyosatni belgilovchi shaxs" is clumsy.
- plea - "заявление подсудимого" / "sudlanuvchining bayonoti" is a descriptive rendering.
- wrongful - uz "nohaq" is looser than ru "неправомерный, несправедливый".
- arbitrary - uz "o'zboshimchalik bilan qilingan" is a phrase, not an adjective.
- confinement - "заточение" / "tutqunlik", chosen to separate it from incarceration ("лишение свободы") and detention.
- redress - "возмещение" / "tovon" is close to restitution (m12-l3).
- enact - "принимать закон" includes the object.
- tyranny - uz "istibdod" is literary.
- abstention, partisan, envoy - uz renderings are descriptive.
- homicide, assault - words are inherently violent; examples kept neutral.

Модули 13-14:

- publicist - uz "matbuot agenti" is a calque of ru "пресс-агент".
- caregiver - narrowed to "сиделка" / "parvarish qiluvchi".
- expatriate - uz descriptive "xorijda yashovchi kishi".
- sibling - uz "tug'ishgan".
- charitable - uz "xayriya" (noun used attributively).
- benefactor - uz "homiy", same root as sponsorship "homiylik" (m14-l3).
- grassroots - uz "quyidan boshlangan".
- investigative - "расследовательский" / "surishtiruvga oid".
- paywall - "платный доступ" / "pullik kirish", descriptive.
- redact - "вымарывать" / "qoralab o'chirmoq".
- exposure - "известность" / "tanilish", media sense only via the example.
- allegation - "недоказанное обвинение" may be too strong.
- mobility, exclusion - "ijtimoiy mobillik", "ijtimoiy yakkalanish".
- elite, caste, avatar, teaser, meme, clickbait, branding, obituary - uz loanwords.

Модули 15-16:

- montage - "нарезка кадров / kadrlar tizmasi", no established Uzbek term.
- imagery - uz "obrazlilik" is a Russian-derived term.
- climax, contour, tempo, virtuoso, intermission - uz loanwords (kulminatsiya, kontur, temp, virtuoz, antrakt) kept as the established terms.
- visionary - adjective, uz descriptive "kelajakni oldindan ko'ruvchi".
- evocative, poignant, iconic, remedial - descriptive phrases in uz (and ru for remedial: "восполняющий пробелы").
- didactic - taught as "поучительный / ibratli", not "дидактический".
- concerto - "инструментальный концерт / cholg'u konserti" to separate from recital.
- postulate - uz paraphrase.
- whereby - conjunction, uz "u orqali".
- albeit - uz "garchi" (sentence needs "bo'lsa-da").
- backstage - taught as adverb.

Модули 17-18:

- settler - uz "ko'chkindi" is a dictionary word but rare.
- imperial - uz "imperiyaga oid", but the example says "Imperiya armiyasi".
- emancipation - narrowed to "освобождение от рабства" to separate it from liberation.
- premise / rationale - uz "dastlabki asos" vs "mantiqiy asos", close.
- impasse / deadlock - "безвыходное положение" vs "тупик в переговорах"; uz "muzokaralardagi tanglik" is descriptive.
- discourse - uz "bahs-munozara".
- relic - uz "osori atiqa" is bookish.
- upheaval - uz "larza".
- surrender - taught as a verb.
- m18-l5 (massacre, genocide, atrocity, hostage) - topic conflicts with the "nothing violent" rule; examples kept neutral.

Модули 19-20:

- zoning / interchange / masonry / overpass - Uzbek terms (zonalarga ajratish, ko'p sathli chorraha, tosh terimi, yo'l o'tkazgich) need a native check.
- municipality, condominium, realtor, facade, atrium, lagoon - Uzbek is a loanword.
- structural - "конструктивный / konstruksiyaga oid" is stiff.
- carpool - taught as a verb; ru translation is 5 words.
- elicit vs induce - both "вызывать" in Russian (elicit = "вызывать отклик").
- ramification vs repercussion - "возможное" vs "негативное последствие" is a thin split.
- multitude - uz "son-sanoqsiz miqdor" is descriptive.
- counterpart - "коллега с другой стороны"; example translations say only "коллега / hamkasb".
- sheer - example uses "sheer luck", not scale.
- incur - example uses the base form (checker rejects "incurred").

**Сомнения редакторов** (после правок)

Модули 1-5:

- awe - is "ehtiromli hayrat" natural?
- conscientious - "vijdonli" or "mas'uliyatli"?
- temptation - "vasvasa, nafs xohishi" too religious?
- overwhelmed - "ezilib qolgan"?
- successor - "voris" for an office?
- burnout - "kasbiy toliqish" or "kasbiy so'nish"?
- bottleneck - "tor joy"?
- affiliate - "affillangan kompaniya"?
- whistleblower - "fosh etuvchi" / «разоблачитель»?
- companionship - "hamsuhbatlik, hamrohlik"?
- affordability - "hamyonboplik" register?

Модули 6-10:

- scalable — "masshtablanuvchan" or "kengaytiriladigan"?
- augmented — "to'ldirilgan" or "kengaytirilgan reallik"?
- immersive — is "muhitga sho'ng'ituvchi" natural?
- intrusive — "shilqim" for ads?
- caveat — "ogohlantiruvchi izoh" for «оговорка»?
- mindfulness — "onglilik"?
- delusion — "vasvasa g'oyasi"?
- obsession — "ruju qo'yish"?
- inferiority — "o'zini kam sanash"?
- heatwave — "issiqlik to'lqini" (calque)?
- qualitative — "sifatiy"?
- malignant — "yomon sifatli" or "xavfli o'sma"?

Модули 11-15:

- sibling - "tug'ishgan" as a noun?
- paywall - "pullik kirish" natural?
- caregiver - "parvarish qiluvchi" / ru "сиделка" too narrow?
- acquit - jury as "maslahatchilar hay'ati"?
- benefactor - "homiy" (clashes in sense with sponsorship "homiylik")?
- visionary - "kelajakni oldindan ko'ruvchi" for designs?
- eyewitness - "shohid" vs "guvoh"?
- coercion - "majburlash ostida"?

Модули 16-20:

- remedial - "bo'shliqlarni to'ldiruvchi"?
- immersion - "til muhitiga sho'ng'ish" a calque?
- deadlock - "muzokaralardagi tanglik" vs impasse "boshi berk ko'cha"?
- discourse - "bahs-munozara" or "diskurs"?
- understatement - "kamaytirib aytish"?
- settler - "ko'chkindi" pejorative?
- prehistoric - "tarixdan oldingi" or "ibtidoiy"?
- masonry - "tosh terimi"?
- multitude - "son-sanoqsiz miqdor"?
- sheer - "sof omad"?
- synonymous - "ma'nodosh" figuratively?
- jury - "hakamlar hay'ati"?

### C2

**Сомнения авторов**

Модули 1-2:

- acrimony - uz "zaharxandalik" is rare; example shows the meaning weakly.
- languid - uz "erinchoq, bo'shashgan" is approximate; ru sentence slightly stiff.
- dogmatic - uz loanword "dogmatik" ("aqidaparast" avoided for its extremist connotation).
- pedantic - uz "maydakash, rasmiyatchi" is closer to "petty".
- indignation - uz "g'azabli norozilik" is descriptive.
- discerning - taught as taste ("взыскательный / nozik didli"), not judgement.
- precocious, exhilaration, qualm - descriptive uz phrases; qualm example is plural.
- detached - uz "befarq" is close to apathy's "loqaydlik".
- wistful - ru "тоскующий" loses the dreamy shade.

Модули 3-4:

- thereof, wherein, insofar - function words; uz "uning, shuning" / "unda" / "shu darajada" are approximations.
- pursuant - adjective per notes, translated as a preposition phrase.
- discretionary - no natural ru/uz adjective; descriptive phrase.
- feign - uz "o'zini solmoq" is an incomplete idiom.
- glib, facetious - descriptive translations.
- affidavit - uz 4-word description.
- memorandum - "xizmat xati" vs loan "memorandum".
- dossier - uz "hujjatlar to'plami".
- proceedings - narrowed to court.
- decorum/propriety - uz distinction thin.
- euphemism - "between jobs" translated literally.
- accolade - "почётная награда" slightly narrow.

Модули 5-6:

- hindsight - "понимание задним числом" / "voqeadan keyingi anglash": descriptive, example translations clunky.
- lucid - phrase translations ("в ясном сознании" / "es-hushi joyida"), not adjectives.
- entity - uz "mavjudlik, obyekt" approximate.
- utilitarian - taught as adjective; uz "utilitar" is a loan.
- existential, orthodox - uz loans "ekzistensial", "ortodoksal".
- premonition - uz "oldindan sezish" stiff in the example.
- misguided - ru "основанный на заблуждении, неразумный" long.
- heuristic - taught as noun; context only partly reveals meaning.
- subliminal, esoteric, introspective, tangible - multiword descriptive uz translations.
- decipher - uz "o'qib tushunmoq" weaker than "расшифровывать".

Модули 7-8:

- stature - taught as "рост" only, to keep it apart from physique ("телосложение").
- sonorous - uz "gulduros" may read as "booming".
- pallid - shown on a winter sun, not a face.
- meander - taught as a path winding, not a person strolling.
- soliloquy, parlance, epiphany, flashback - descriptive uz phrases, no set term.
- pathos - uz "hazin ta'sirchanlik" is approximate.
- parable - uz "ibratli rivoyat" ("masal" would mean fable).
- ode/elegy - uz "qasida"/"marsiya" are Eastern genre equivalents.
- alliteration, pun, colloquial, archaic, idiom - examples quote English words left untranslated.
- No transcription field (brief omits it; b2 files have one).

Модули 9-10:

- kickback - uz "ulush tarzidagi pora" is descriptive; no settled Uzbek term.
- cronyism - ru "протекция приятелям" is coined to stay distinct from nepotism/patronage.
- brinkmanship - uz "tahlika yoqasidagi siyosat" is descriptive.
- overture - uz "yarashuv sari qadam" is clumsy.
- filibuster - taught as a noun; uz is a paraphrase.
- bylaw - chose "local ordinance", not AmE "organization's rules".
- escrow - rendered as "счёт эскроу / eskrou hisobvarag'i".
- endowment - "целевой капитал / maqsadli jamg'arma".
- internment, noncombatant - uz paraphrases instead of the loanwords.
- beneficiary - "выгодоприобретатель / naf oluvchi" is legalese.
- stateless - ru "без гражданства" is not an adjective form.

Модули 11-12:

- pittance - uz "tiyin-tebin" is colloquial.
- lackluster - "посредственный / o'rtamiyona" is slightly stronger than the word.
- fiduciary - uz "ishonchga asoslangan" is descriptive ("fidutsiar" exists).
- falsify - taught as "опровергать" (Popper), not "подделывать".
- speculative, nomenclature, schema, inert, amortize, interpolate - uz loanwords.
- interventionism - rendered as "политика государственного вмешательства".
- receivership - "внешнее управление / tashqi boshqaruv" is an approximate legal equivalent.
- squalor, divest, defray - phrase translations.
- parasitic - uz "tekinxo'r" rather than "parazit".
- quantum - taught as a noun.
- crystalline - uz "kristall".

Модули 13-14:

- trachea - uz "kekirdak" (some speakers use it for the larynx; "traxeya" is the alternative).
- squall - uz is descriptive, "to'satdan ko'tarilgan shamol"; gale is "dovul".
- inclement - uz "noqulay" is generic outside "noqulay ob-havo".
- haze - uz "yengil tuman" is close to fog.
- lair - uz "yirtqich uyasi", weakly distinct from burrow "in".
- chasm - uz "tubsiz choh" is literary.
- cataclysm, geriatric, palliative, degenerative - uz loanwords.
- panacea - example only weakly shows the meaning.
- numb, queasy, drowsy, parched - sentence translations use a verb or phrase, not the card's adjective form.
- juvenile - legal example in a life-stages lesson.

Модули 15-16:

- upstart - uz "birdan ko'tarilgan odam" is descriptive, no set Uzbek word.
- pariah / outcast - uz "hamma yuz o'girgan odam" vs "quvg'indi"; the distinction is thin.
- pander - uz "ko'ngliga qarab ish tutmoq" is milder than "потакать".
- preempt - uz "ilgarilab harakat qilmoq" is a paraphrase.
- browbeat - ru "давить, принуждать нажимом" vs uz "do'q-po'pisa qilmoq" (more threat-like).
- debacle, fiasco - uz are verbal nouns ("butunlay barbod bo'lish", "chippakka chiqish").
- rout - uz "yirik mag'lubiyat" is a sports sense.
- antipathy - uz "yoqtirmaslik hissi" is clumsy.
- shun, snub, repel - base form in the example (checker rejects doubled consonants).

Модули 17-18:

- atrophy - uz "atrofiyaga uchramoq" is a loan; literal muscle example.
- anachronism - uz "anaxronizm" is a loan.
- decay - taught as a verb, not a noun.
- glut - uz "ortiqcha taklif" is narrower than the English.
- seminal - uz "asos soluvchi" may sound calqued.
- fastidious - uz "injiq, nozikta'b" leans towards "capricious".
- ostensible - uz "zohiriy" is bookish.
- inexorable - uz "shafqatsiz, muqarrar" is approximate.
- vicissitude - plural in example; uz "past-balandlik".
- precursor - uz "o'tmishdosh" for a device.
- quintessential - example uses plov/Uzbek wedding.
- unequivocal - «» quotes in ru/uz example.

Модули 19-20:

- cadence - used "каденция, заключительный оборот" (harmonic close), not the notes' "ритмический оборот".
- mastermind - syllabus gives no POS; taught as noun ("автор замысла" / "reja muallifi").
- effigy - "haykaliy tasvir" is descriptive, not an established Uzbek term.
- gaudy ("bachkana"), austere ("sipo"), riveting ("nihoyatda qiziqarli"), visceral ("ich-ichidan keluvchi") - Uzbek approximations.
- filigree, spire, patina, kitsch, surreal - Uzbek loanwords (filigran, shpil, patina, kitch, syurreal).
- gaffe - uz "nojo'ya ish" is weaker than a social blunder.
- paragon - "образец, эталон" drops "совершенства".

**Сомнения редакторов** (после правок)

Модули 1-5:

- acrimony - is "achchiqlik, zaharxandalik" natural?
- entity - "mavjud narsa, obyekt" as the philosophical term?
- languid - "erinchoq" (lazy) or something softer?
- affidavit - "ko'rsatuv" or "ko'rsatma"?
- feign - "o'zini solmoq" without a complement?
- tacit - "sukut bilan bildirilgan"?
- ordinance - "hokimlik qarori"?
- bombastic - "tumtaroq"?
- stilted - "высокопарный" (per notes) or "натянутый"?
- subtext - spelling "tagma'no"?

Модули 6-10:

- kickback - is "ulush tarzidagi pora" natural, or is "otkat" the norm?
- endowment - "maqsadli jamg'arma" or "endaument jamg'armasi"?
- appellant - "apellyatsiya beruvchi" or "apellyant"?
- opaque - is "noshaffof" acceptable?
- armistice - "yarash sulhi"?
- reciprocity - "o'zarolik"?
- sonorous - "gulduros" for a voice?
- pallid - "rangpar" for the sun?
- cronyism - Russian "протекция приятелям"?
- gauge - Russian "определять, прикидывать" is close to appraise "оценивать".

Модули 11-15:

- gestation - homiladorlik or bo'g'ozlik for animals?
- gale - is "dovul" too strong?
- primordial - ibtidoiy or birlamchi/azaliy?
- upstart - "birdan ko'tarilgan odam" natural?
- scapegoat - does "balogardon" fit?
- distill - is "distillamoq" used?
- queasy - "испытывающий тошноту" is clumsy.
- throb - "to'mtoq og'riq" a calque?
- efficacy - ta'sirchanlik or samaradorlik?
- haze - yengil tuman or g'ubor?
- receivership - "внешнее управление" approximate.

Модули 16-20:

- preempt - is "ilgarilab harakat qilmoq" natural?
- stymie - "заводить в тупик" / "boshi berk ko'chaga kiritmoq" with "plans"?
- seminal - "asos soluvchi" for a paper?
- gaudy - "bachkana"?
- patina - "zang qatlami" (suggests rust)?
- zealous - "jonkuyar"?
- adversity - "kulfat, musibat" too strong?
- riveting - "o'ziga rom etuvchi"?
- grueling - "tinka qurituvchi"?
- gaffe - "nojo'ya ish"?
- kitsch - spelling "kitch"?

## Импорт на проде

Файлы: `database/data/courses/english-c1/` и `database/data/courses/english-c2/` (`course.json`, `mNN-lN.json`, `USED_WORDS.txt`).

1. Импорт. `--create` создаёт черновик, если курса ещё нет; повторный запуск пропускает совпадающие слова и обновляет изменённые.

   ```
   php artisan content:import-course database/data/courses/english-c1 --create
   php artisan content:import-course database/data/courses/english-c2 --create
   ```

   В конце — таблица по файлам и итог (добавлено / обновлено / пропущено). Код возврата не нулевой, если какой-то файл отклонён.
2. Админка → «Курсы» → `english-c1`, `english-c2`: проверить название, описание, число слов (1 000 у каждого), пройти по урокам и словам.
3. Озвучка (публикация её не запускает):

   ```
   php artisan audio:pregenerate english-c1
   php artisan audio:pregenerate english-c2
   ```

   Перед публикацией, когда очередь `low` отработала, — проверить покрытие озвучкой (код возврата 1, если файлов меньше 95%; список слов с отсутствующим или пустым файлом):

   ```
   php artisan audio:coverage english-c1 --verify --min=95
   php artisan audio:coverage english-c2 --verify --min=95
   ```

4. Практика — после курса (импорт находит уроки и целевые слова в курсе), все файлы подряд, черновиками:

   ```
   for f in database/data/practice/english-c1-p*.json database/data/practice/english-c2-p*.json; do php artisan practice:import "$f" || break; done
   ```

   900 заданий на курс в десяти файлах. Каждый файл импортируется целиком или никак; повторный запуск безопасен — неизменённые задания пропускаются. Озвучка фраз диктанта ставится в очередь после импорта. Публикация заданий — после проверки текстов: тот же цикл с `php artisan practice:import "$f" --publish` или по заданию в админке («Задания практики»: «Проверено» → «Опубликовать»).

   Задания «Форма слова» и «Найди ошибку» — тем же способом, своим циклом (x-файлы; озвучки у них нет):

   ```
   for f in database/data/practice/english-c1-x*.json database/data/practice/english-c2-x*.json; do php artisan practice:import "$f" || break; done
   ```

   600 заданий на курс в десяти файлах (по 60). Повторный запуск пропускает все 60 заданий каждого файла (`Skipped`); публикация — тот же цикл с `--publish` после чтения текстов ([CONTENT_EXTRA_PRACTICE_REVIEW.md](CONTENT_EXTRA_PRACTICE_REVIEW.md)).
5. Публикация курса — после чтения текстов: «На проверке», затем «Опубликован». Покрытие переводами ru и uz у обоих курсов 100 % (порог 95 %). Предыдущие курсы (`english-a1-a2`, `english-b1`, `english-b2`) публикуются раньше.

Правки текстов удобнее вносить в файлы пакета и импортировать заново. Если текст правили вручную в админке, перед повторным импортом сопоставьте правки с пакетом.
