# План курса фраз: 1 000 фраз одним выпуском

Документ координатора (задача F6 docs/35, 25.11.2026). Решение владельца (docs/31 §5г): **вся база — 1 000 фраз одним выпуском**.
Здесь — структура курса, правила файлов и таблица «урок → тема → число фраз → ориентиры». Как писать фразы — `PHRASE_BRIEF.md`,
как вычитывать — `PHRASE_PROOF_BRIEF.md`, механическая проверка — `check_phrases.py`. Формат записи и правила импорта — README, раздел
«Курс фраз».

**Таблицы модулей, уроков и долей видов читает `check_phrases.py`** (названия модулей и уроков в файлах сверяются с ними буква в
букву, уровень фразы — с полосой модуля, число фраз — с колонкой «Фраз»). Формат строк не менять: `| M01 | … |`, `| m01-l1 | … |`,
`` | `formula` | … | `` . Проверка самого плана: `python3 docs/content-authoring/check_phrases.py --check-plan`.

## 1. Курс

| Что | Значение |
|---|---|
| Курс | **один**: `english-phrases`, «Разговорные фразы» / «So'zlashuv iboralari» |
| Каталог пакета | `database/data/courses/english-phrases/` (`course.json` + 100 файлов `mNN-lN.json`) |
| `course.json` | `"slug": "english-phrases"`, `"exam_tag": "phrases"`, `"level": "B1"`, `"level_label": "A2–C1"`, `"visibility": "public"`, `"is_featured": false`, `"position": 60` (после курсов уровней 10–50), `title` и `description` ru/uz |
| Объём | 20 модулей × 5 уроков × **10 фраз** = **1 000** |
| Файлы | `mNN-lN.json`: номер модуля двумя цифрами, урока — одной (`m07-l3.json`); `course` = `english-phrases`; `stage` и `lesson` — номер и оба названия из таблицы §4 |
| Тест пакета | `tests/Feature/Content/PhrasePacksTest.php` — пропускается, пока каталога пакета нет |

Почему **10 фраз в уроке**: квота Free — 10 новых карточек в день (docs/31 §5г), фраза расходует ту же квоту (решение Ф3 docs/35). Урок
из 10 фраз — ровно один день Free; урок из 12–15 (как у курсов слов) Free не закончил бы за день. Модуль из 5 уроков — неделя
занятий; 20 модулей — формат курсов уровней и проверочных скриптов.

Почему один курс, а не три (`-a2b1`, `-b2`, `-c1` в первом проекте docs/35 §3.4): база выходит одним выпуском; уровни идут внутри курса
от простого к сложному блоками модулей (§2). Если понадобится разделить курс по уровням, блоки §2 режутся по границам модулей без правки
содержания (поменяются только `course` и номера модулей в файлах).

## 2. Уровни и порядок

| Модули | Уровень (полоса модуля) | Фраз | Что внутри |
|---|---|---|---|
| 1–6 | A2–B1 | 300 | Формулы общения и бытовые выражения: приветствие, просьба, магазин, дорога, дом. Больше всего `formula` и `fixed`, простые `phrasal_verb` |
| 7–13 | B1–B2 | 350 | Эмоции, мнение, работа, учёба, люди, здоровье, решения. Формулы и устойчивые выражения + первые частые идиомы и пословицы |
| 14–20 | B2–C1 | 350 | Идиомы по образам (еда, животные, тело), успех, деловое общение (`formal`), разговорные и сленговые выражения (`slang` с меткой), пословицы |

- `phrase.cefr` каждой фразы — в полосе своего модуля **или на одну ступень ниже** (простая фраза в позднем модуле допустима: `time flies`
  B1 в модуле 20; сложная в раннем — нет). Проверяет `check_phrases.py` (код `LEVEL`).
- Внутри модуля — от частого к редкому: в первых уроках модуля больше `frequency` 1, в последних — 2 и 3.
- `register` `rude` в курс не берём. `slang` — только в модуле 19 и единично там, где без него нет живой речи (не больше 30 на курс).
- `variant`: по умолчанию `both`; `uk` / `us` — только когда носитель другой страны так не говорит (`Can I have the bill?` — `uk`, `the check` — `us`).
  Пара UK/US — две записи в одном уроке, если обе частые.

## 3. Доли видов на весь курс

Проверяет `check_phrases.py --full` (код `KIND_BALANCE`): доля вида в процентах от всех фраз курса.

| Вид | Что это | От, % | До, % |
|---|---|---|---|
| `formula` | разговорные формулы: *No worries*, *How's it going?*, *Fair enough* | 20 | 40 |
| `fixed` | устойчивые выражения: *by the way*, *take it easy*, *on time* | 15 | 35 |
| `idiom` | идиомы, переносный смысл: *a piece of cake*, *break the ice* | 18 | 35 |
| `phrasal_verb` | фразовые глаголы: *figure out*, *give up* | 8 | 20 |
| `proverb` | пословицы и поговорки: *better late than never* | 4 | 10 |

Ориентир по блокам: модули 1–6 — около 60 % `formula`; 7–13 — поровну формул, устойчивых выражений и идиом; 14–20 — около половины идиом,
модуль 20 — пословицы.

## 4. Модули и уроки

Ориентиры — 3–5 фраз урока, по которым видна тема и уровень. Автор берёт их в урок (если ориентир ему кажется неудачным — заменяет и пишет
об этом в отчёте) и дописывает урок до 10 фраз по `PHRASE_BRIEF.md`. Ориентиры не повторяются между уроками (`--check-plan`); фраза урока
не должна повторять ориентир или фразу другого урока (`DUPLICATE_PHRASE`).

### Модули

| Модуль | Название (ru) | Название (uz) | Уровень |
|---|---|---|---|
| M01 | Знакомство и вежливость | Tanishuv va xushmuomalalik | A2–B1 |
| M02 | Разговор и понимание | Suhbat va tushunish | A2–B1 |
| M03 | Просьбы и предложения | Iltimos va takliflar | A2–B1 |
| M04 | Покупки и деньги | Xarid va pul | A2–B1 |
| M05 | Город и дорога | Shahar va yo'l | A2–B1 |
| M06 | Дом и время | Uy va vaqt | A2–B1 |
| M07 | Эмоции | His-tuyg'ular | B1–B2 |
| M08 | Согласие и несогласие | Rozilik va norozilik | B1–B2 |
| M09 | Работа | Ish | B1–B2 |
| M10 | Учёба | O'qish | B1–B2 |
| M11 | Люди и отношения | Odamlar va munosabatlar | B1–B2 |
| M12 | Здоровье и отдых | Salomatlik va dam olish | B1–B2 |
| M13 | Решения и проблемы | Qarorlar va muammolar | B1–B2 |
| M14 | Идиомы: еда | Iboralar: ovqat | B2–C1 |
| M15 | Идиомы: животные | Iboralar: hayvonlar | B2–C1 |
| M16 | Идиомы: тело | Iboralar: tana a'zolari | B2–C1 |
| M17 | Успех и неудача | Muvaffaqiyat va omadsizlik | B2–C1 |
| M18 | Деловое общение | Ishbilarmonlik muloqoti | B2–C1 |
| M19 | Разговорный английский | So'zlashuv ingliz tili | B2–C1 |
| M20 | Мудрость и пословицы | Hikmatlar va maqollar | B2–C1 |

### Уроки

| Файл | Урок (ru) | Урок (uz) | Фраз | Ориентиры |
|---|---|---|---|---|
| m01-l1 | Приветствия | Salomlashish | 10 | How's it going? · Nice to meet you · Long time no see · What's up? |
| m01-l2 | Прощание | Xayrlashish | 10 | See you later · Take care · Have a nice day · Keep in touch |
| m01-l3 | Благодарность | Minnatdorchilik | 10 | Thanks a lot · You're welcome · Don't mention it · I owe you one |
| m01-l4 | Извинения | Uzr so'rash | 10 | I'm so sorry · My bad · No worries · Never mind |
| m01-l5 | Рассказать о себе | O'zi haqida gapirish | 10 | Where are you from? · What do you do? · Let me introduce myself · Pleased to meet you |
| m02-l1 | Переспросить | Qayta so'rash | 10 | Sorry, what was that? · Could you say that again? · I beg your pardon · Come again? |
| m02-l2 | Понял или нет | Tushundimi, yo'qmi | 10 | I see · Got it · I'm lost · What do you mean? |
| m02-l3 | Живая реакция | Jonli munosabat | 10 | Is that so? · You don't say · Tell me more · Good for you |
| m02-l4 | Подумать перед ответом | Javobdan oldin o'ylash | 10 | Let me think · Good question · I'm not sure · It depends |
| m02-l5 | Связать мысли | Fikrlarni bog'lash | 10 | By the way · To be honest · In other words · First of all |
| m03-l1 | Вежливая просьба | Xushmuomala iltimos | 10 | Could you help me? · Can I ask you something? · Excuse me · Would you mind? |
| m03-l2 | Предложить помощь | Yordam taklif qilish | 10 | Can I help you? · Here you go · Let me get that · Do you need a hand? |
| m03-l3 | Пригласить | Taklif qilish | 10 | Are you free tonight? · Let's grab a coffee · Do you want to come? · Join us |
| m03-l4 | Принять или отказаться | Rozi bo'lish yoki rad etish | 10 | Sounds good · I'd love to · Maybe next time · I'm afraid I can't |
| m03-l5 | Разрешение | Ruxsat | 10 | Go ahead · Feel free · Be my guest · Is that OK? |
| m04-l1 | В магазине | Do'konda | 10 | I'm just looking · How much is it? · Can I try it on? · Anything else? |
| m04-l2 | Оплата | To'lov | 10 | Keep the change · Cash or card? · It's on me · Let's split the bill |
| m04-l3 | Цены | Narxlar | 10 | a good deal · a rip-off · on sale · It's worth it |
| m04-l4 | Деньги в жизни | Hayotda pul | 10 | save up · pay back · run out of money · Money doesn't grow on trees |
| m04-l5 | Кафе и ресторан | Kafe va restoran | 10 | For here or to go? · Can I have the bill? · I'll have the same · Enjoy your meal |
| m05-l1 | Как пройти | Yo'l so'rash | 10 | Is it far from here? · Go straight ahead · You can't miss it · Turn left |
| m05-l2 | Транспорт | Transport | 10 | get on · get off · Is this seat taken? · miss the bus |
| m05-l3 | Такси и пробки | Taksi va tirbandlik | 10 | rush hour · stuck in traffic · pull over · drop off |
| m05-l4 | Поездки | Sayohatlar | 10 | Have a safe trip · on the way · hit the road · travel light |
| m05-l5 | Отель и аэропорт | Mehmonxona va aeroport | 10 | check in · check out · Enjoy your stay · make a reservation |
| m06-l1 | Утро и вечер | Ertalab va kechqurun | 10 | wake up · get up · sleep in · call it a day |
| m06-l2 | Дела по дому | Uy ishlari | 10 | do the dishes · tidy up · take out the trash · do the laundry |
| m06-l3 | Время | Vaqt | 10 | in a minute · Just a second · from time to time · in no time |
| m06-l4 | Планы | Rejalar | 10 | What are you up to? · I'm on my way · make plans · play it by ear |
| m06-l5 | Самочувствие | O'zini his qilish | 10 | I'm fine, thanks · Get well soon · under the weather · feel better |
| m07-l1 | Радость | Quvonch | 10 | over the moon · on cloud nine · You made my day · I'm so happy for you |
| m07-l2 | Удивление | Hayrat | 10 | No way! · You're kidding! · I can't believe it · What a surprise |
| m07-l3 | Грусть и разочарование | Qayg'u va umidsizlik | 10 | What a shame · That's too bad · feel down · down in the dumps |
| m07-l4 | Раздражение | Asabiylashish | 10 | I'm fed up · get on my nerves · drive me crazy · lose my temper |
| m07-l5 | Страх и волнение | Qo'rquv va hayajon | 10 | butterflies in my stomach · freak out · keep calm · a nervous wreck |
| m08-l1 | Согласие | Rozilik | 10 | You're right · Fair enough · I couldn't agree more · Same here |
| m08-l2 | Несогласие | Norozilik | 10 | I don't think so · I'm not so sure · Not really · That's not the point |
| m08-l3 | Мнение | Fikr | 10 | In my opinion · If you ask me · As far as I know · I'd say |
| m08-l4 | Спор и компромисс | Bahs va murosa | 10 | meet halfway · agree to disagree · have a point · Let's not argue |
| m08-l5 | Убедить | Ishontirish | 10 | Trust me · Believe it or not · Take my word for it · I promise |
| m09-l1 | Рабочий день | Ish kuni | 10 | nine to five · work overtime · take a break · get down to business |
| m09-l2 | Задачи и сроки | Vazifalar va muddatlar | 10 | on time · behind schedule · meet a deadline · as soon as possible |
| m09-l3 | Звонки и встречи | Qo'ng'iroqlar va uchrashuvlar | 10 | get back to you · keep me posted · set up a meeting · hold on |
| m09-l4 | Коллеги | Hamkasblar | 10 | a team player · pull your weight · in charge · a pat on the back |
| m09-l5 | Поиск работы | Ish izlash | 10 | apply for a job · fill in a form · Good luck! · the right person for the job |
| m10-l1 | На уроке | Darsda | 10 | raise your hand · pay attention · hand in · Any questions? |
| m10-l2 | Экзамены | Imtihonlar | 10 | keep my fingers crossed · learn by heart · pass with flying colors · cram for an exam |
| m10-l3 | Как учиться | Qanday o'qish kerak | 10 | Practice makes perfect · brush up on · look up · go over |
| m10-l4 | Ошибки и прогресс | Xatolar va rivojlanish | 10 | make a mistake · learn from your mistakes · get the hang of it · It takes time |
| m10-l5 | Ум и знания | Aql va bilim | 10 | use your head · It rings a bell · a know-it-all · a quick learner |
| m11-l1 | Хорошие черты | Yaxshi fazilatlar | 10 | a heart of gold · down to earth · easy-going · a good sport |
| m11-l2 | Плохие черты | Yomon fazilatlar | 10 | a big mouth · a pain in the neck · a couch potato · full of yourself |
| m11-l3 | Внешность | Tashqi ko'rinish | 10 | look like · dress up · in good shape · the spitting image of |
| m11-l4 | Отношения | Munosabatlar | 10 | get along with · hit it off · fall out · see eye to eye |
| m11-l5 | Семья и друзья | Oila va do'stlar | 10 | Like father, like son · run in the family · A friend in need is a friend indeed · Blood is thicker than water |
| m12-l1 | Здоровье | Salomatlik | 10 | catch a cold · on the mend · as fit as a fiddle · An apple a day keeps the doctor away |
| m12-l2 | Отдых | Dam olish | 10 | put your feet up · chill out · take it easy · recharge your batteries |
| m12-l3 | Спорт | Sport | 10 | work out · warm up · keep fit · give it a try |
| m12-l4 | Победы и поражения | G'alaba va mag'lubiyat | 10 | Win some, lose some · a close call · neck and neck · a sore loser |
| m12-l5 | Поддержка | Qo'llab-quvvatlash | 10 | Go for it · Keep it up · Hang in there · Don't give up |
| m13-l1 | Принять решение | Qaror qabul qilish | 10 | make up your mind · sleep on it · on second thought · think it over |
| m13-l2 | Проблемы | Muammolar | 10 | What's wrong? · in trouble · in hot water · the last straw |
| m13-l3 | Решить проблему | Muammoni hal qilish | 10 | figure out · sort out · work it out · deal with |
| m13-l4 | Риск | Tavakkal | 10 | take a chance · play it safe · Better safe than sorry · Nothing ventured, nothing gained |
| m13-l5 | Легко и трудно | Oson va qiyin | 10 | a piece of cake · Easier said than done · No sweat · an uphill battle |
| m14-l1 | Суть и секреты | Mohiyat va sirlar | 10 | spill the beans · in a nutshell · a hot potato · the apple of my eye |
| m14-l2 | Люди в образах еды | Ovqat timsolidagi odamlar | 10 | a smart cookie · a big cheese · full of beans · as cool as a cucumber |
| m14-l3 | Вкусы и мнения | Didlar va fikrlar | 10 | not my cup of tea · take it with a grain of salt · sour grapes · eat your words |
| m14-l4 | Работа и деньги | Ish va pul | 10 | bread and butter · have a lot on your plate · sell like hot cakes · the icing on the cake |
| m14-l5 | Пословицы о еде | Ovqat haqidagi maqollar | 10 | Too many cooks spoil the broth · Don't put all your eggs in one basket · You can't have your cake and eat it · Don't cry over spilt milk |
| m15-l1 | Кошки и собаки | Mushuklar va itlar | 10 | let the cat out of the bag · It's raining cats and dogs · Curiosity killed the cat · as sick as a dog |
| m15-l2 | Птицы | Qushlar | 10 | kill two birds with one stone · The early bird catches the worm · A little bird told me · as free as a bird |
| m15-l3 | Лошади | Otlar | 10 | hold your horses · straight from the horse's mouth · a dark horse · Don't look a gift horse in the mouth |
| m15-l4 | Рыбы и другие | Baliqlar va boshqalar | 10 | a fish out of water · a big fish in a small pond · smell a rat · the elephant in the room |
| m15-l5 | Характер в образах животных | Hayvonlar timsolidagi xarakter | 10 | the black sheep · as busy as a bee · a wild goose chase · monkey business |
| m16-l1 | Голова | Bosh | 10 | off the top of my head · head over heels · keep your head above water · lose your head |
| m16-l2 | Руки и ноги | Qo'llar va oyoqlar | 10 | pull my leg · get cold feet · all thumbs · cost an arm and a leg |
| m16-l3 | Глаза, уши, рот | Ko'z, quloq, og'iz | 10 | keep an eye on · all ears · turn a blind eye · My lips are sealed |
| m16-l4 | Сердце | Yurak | 10 | have a heart · a change of heart · with all my heart · my heart sank |
| m16-l5 | Тело: разное | Tana: turli iboralar | 10 | Break a leg! · keep your chin up · stick your neck out · by the skin of your teeth |
| m17-l1 | Успех | Muvaffaqiyat | 10 | make it · pull it off · go places · The sky's the limit |
| m17-l2 | Неудача | Omadsizlik | 10 | fall through · back to square one · a dead end · miss the boat |
| m17-l3 | Начинать и расти | Boshlash va o'sish | 10 | learn the ropes · get your foot in the door · climb the ladder · a steep learning curve |
| m17-l4 | Не сдаваться | Taslim bo'lmaslik | 10 | Every cloud has a silver lining · Where there's a will, there's a way · Rome wasn't built in a day · throw in the towel |
| m17-l5 | Усилия | Harakat | 10 | go the extra mile · bite off more than you can chew · on the right track · cut corners |
| m18-l1 | Деловые письма | Rasmiy xatlar | 10 | I look forward to hearing from you · Please find attached · Kind regards · To whom it may concern |
| m18-l2 | Переговоры | Muzokaralar | 10 | a win-win situation · the bottom line · touch base · think outside the box |
| m18-l3 | Планирование | Rejalashtirish | 10 | on the same page · a ballpark figure · back to the drawing board · in the long run |
| m18-l4 | Решения и ответственность | Qarorlar va mas'uliyat | 10 | call the shots · at short notice · take on board · The buck stops here |
| m18-l5 | Проекты | Loyihalar | 10 | keep me in the loop · get the ball rolling · a step in the right direction · up and running |
| m19-l1 | Среди друзей | Do'stlar orasida | 10 | hang out · No big deal · for real · It's not rocket science |
| m19-l2 | Хватит! | Bas qil! | 10 | Cut it out! · Give me a break · Get real · Knock it off |
| m19-l3 | Короткие ответы | Qisqa javoblar | 10 | Beats me · You bet · I'm beat · Suit yourself |
| m19-l4 | Деньги в разговоре | So'zlashuvda pul | 10 | flat broke · shell out · pay through the nose · cheap as chips |
| m19-l5 | Время и случай | Vaqt va tasodif | 10 | out of the blue · once in a blue moon · in the blink of an eye · every now and then |
| m20-l1 | Поступки и люди | Xatti-harakat va odamlar | 10 | Actions speak louder than words · Don't judge a book by its cover · Honesty is the best policy · The grass is always greener on the other side |
| m20-l2 | О времени | Vaqt haqida | 10 | Time is money · Time flies · Better late than never · There's no time like the present |
| m20-l3 | Вместе | Birgalikda | 10 | Two heads are better than one · Many hands make light work · Birds of a feather flock together · It takes two to tango |
| m20-l4 | Осторожность | Ehtiyotkorlik | 10 | Look before you leap · Don't count your chickens before they hatch · Haste makes waste · Slow and steady wins the race |
| m20-l5 | Жизненный опыт | Hayot tajribasi | 10 | All's well that ends well · No pain, no gain · Live and learn · Every dog has its day |

Итого: 20 модулей, 100 уроков, 1 000 фраз; ориентиров — 400.

## 5. Порядок работы (F7)

1. **Пилот** — модуль 1 (50 фраз): один автор пишет по `PHRASE_BRIEF.md`, отдельный редактор вычитывает по `PHRASE_PROOF_BRIEF.md`,
   координатор читает целиком и импортирует настоящей командой (`php artisan content:import-course database/data/courses/english-phrases --create`:
   добавлено → повтор без изменений). Бриф дополняется по итогам.
2. **Остальные модули** — по 2 модуля (100 фраз) на автора, параллельно, каждый в свои 10 файлов. Автор заканчивает, когда
   `python3 docs/content-authoring/check_phrases.py database/data/courses/english-phrases` не находит ошибок в его файлах
   (ошибки `DUPLICATE_PHRASE` с чужими файлами решает координатор: фраза остаётся в уроке, где она нужнее теме).
3. **Вычитка** — по 2 модуля на редактора, отдельный исполнитель, с отчётом автора. Узбекский — носитель (вопрос Ф2 docs/35 открыт:
   архитектор рекомендует проверку до публикации); спорные места — `docs/CONTENT_PHRASES_REVIEW.md`.
4. **Сводная проверка** всего курса: `python3 docs/content-authoring/check_phrases.py --full --summary database/data/courses/english-phrases`,
   затем `PhrasePacksTest`, импорт черновиком, проверка в `/admin`, публикация.
