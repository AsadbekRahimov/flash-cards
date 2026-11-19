# Задания «Форма слова» и «Найди ошибку» — что показать носителю

Приложение к разделам о заданиях практики в [CONTENT_A1_A2.md](CONTENT_A1_A2.md#задания-практики), [CONTENT_B1_B2.md](CONTENT_B1_B2.md#задания-практики) и [CONTENT_C1_C2.md](CONTENT_C1_C2.md#задания-практики). 3 000 заданий двух новых типов (`docs/16` §5.6, §5.7) — по 600 на курс, в каждом из 100 уроков по три задания «Форма слова» и три «Найди ошибку»: `database/data/practice/<курс>-x01.json` … `-x10.json`, по 60 заданий (два модуля) в файле. Задания написаны по [content-authoring/EXTRA_TASK_BRIEF.md](content-authoring/EXTRA_TASK_BRIEF.md) и вычитаны Claude: каждый файл после автора отдельно прочитал редактор с задачей «доказать, что задание неверно» (`EXTRA_TASK_PROOF_BRIEF.md`). Редакторы изменили 796 заданий из 3 000 (A1–A2 — 132, B1 — 149, B2 — 162, C1 — 171, C2 — 182; в это число входят и правки только русского или узбекского текста; файлы `english-a1-a2-x01` и `english-b2-x01` написаны ещё по первой, короткой инструкции и переписаны редактором почти наполовину). Носитель узбекского и преподаватель английского задания не читали: импортируются они **черновиками**.

Ниже — замечания редакторов по каждому файлу: места, где редактор принял решение, но просит взгляд носителя. Это не список ошибок: всё перечисленное проходит проверки, а явные ошибки редакторы исправили сами. Замечания приведены на английском, как их записали редакторы; ключ задания (`b1-m03-l2-w1` — курс, модуль, урок, тип и номер: `w` — «Форма слова», `e` — «Найди ошибку») находится поиском в файле пакета и в админке («Задания практики»).

Как читать замечания:

- **«is … wrong beyond doubt?»** — про «Найди ошибку»: помеченное слово или неверный вариант исправления должен быть ошибкой в британском И американском английском, в письменной речи. Если носитель считает его допустимым, задание перестраивают (иначе ученик, ответивший верно, получит «ошибку»).
- **«could … be a second right answer / is not listed / should … be accepted»** — про «Форму слова»: ученик вписывает форму сам, засчитываются только формы из `accepted` (до четырёх). Если носитель считает неуказанную форму правильной, её добавляют в `accepted` или перестраивают предложение.
- **«fair at A1 / not in the table / too easy»** — про уровень: вид формы или ошибки выше или ниже уровня курса по таблицам инструкции (§3.4, §4.6). Решает владелец курса.
- **«uz … / ru … natural?»** — узбекский или русский перевод либо объяснение верны по смыслу, но возможна более естественная формулировка.

## Решено при подключении (носителю не нужно)

- **Лексикон.** Каждое слово правильной формы и исправленного предложения должно быть известно лексикону приложения (`resources/lexicon/`); в него добавлены 1 130 обычных слов заданий (`en-common.txt`, по базовой форме, где формы строит сам лексикон) и 21 неправильная форма (`en-irregular.txt`: `strata`, `oases`, `plateaux`, `striven`, `upheld`, `sunken`, `lesser` …). Не добавлены — оставлены предупреждением проверочного скрипта — намеренно редкие неверные варианты исправлений (`dividable`, `ascetical`, `incessancy`, `inexorableness`, `wealthiness`, `frugalness`, `jargonize`, `jargonized`, `hyperbolize`, `theologize`, `sophisticating`, `disinformed`) и притяжательные множественного числа (`countries'`, `sisters'`, `agencies'` … — 12 вариантов): на проверку ответа это не влияет.
- **Британские написания** правильных форм засчитываются через `resources/lexicon/en-us-gb.txt` (38 пар с формами: `categorise`, `epitomise`, `industrialise`, `monopolise`, `plagiarise`, `synthesise`, `amortisation`, `decentralisation`, `generalisation`, `monetisation`, `nationalisation`, `optimisation`, `oxidisation`, `revitalisation`, `anaemic`, `cosiness`, `tranquillity`). `enquiry` (рядом с `inquiry`), `archeologist`, `dextrous` указаны в `accepted` своих заданий: пары для них нет (`enquiry` в британском английском — не просто написание `inquiry`).
- **Дефисные написания** засчитываются вариантом в `accepted`: `non-renewable`, `non-verbal`, `non-combatants`, `semi-aquatic`, `above-mentioned`; при подключении добавлены `non-conformist` (`c2-m15-l2-w2`) и `pre-emptive` (`c2-m16-l3-w3`).
- **Повтор предложения.** Сводная проверка x-файлов вместе с p-файлами нашла одно совпадение: `c2-m13-l3-e1` повторял предложение сочетания `c2-m13-l3-c3` («Her brother donated bone marrow to save her life.»). Предложение заменено (та же ошибка `brain` → `marrow`): «The sick girl was waiting for a bone *brain* transplant from her older sister.», ru «Больная девочка ждала пересадки костного мозга от старшей сестры.», uz «Kasal qizcha opasidan suyak ko'migi ko'chirib o'tkazilishini kutayotgan edi.» — носителю стоит прочитать.

## Для владельца курса

- В уроках служебных слов (союзы, наречия-связки), где меньше трёх слов урока имеют форму нужного уровня, одно задание «Форма слова» тренирует форму другого слова в предложении, построенном вокруг слова урока (инструкция §3.2): `b1-m20-l1-w3` (despite + feeling), `b2-m17-l5-w3` (nevertheless), `c1-m16-l5-w3` (thereby), `c2-m04-l3-w3` (hereby), а также `b1-m12-l4-w2` (arena; в уроке спортивного инвентаря форму уровня B1 имеют только `locker` и `whistle`, а множественное число без изменений инструкция считает слишком лёгким). В уроке `b1-m20-l3` (unless, whether, whatever, addition, besides …) форма уровня есть только у `addition`, поэтому таких заданий два — `b1-m20-l3-w2` (besides + being) и `b1-m20-l3-w3` (whenever + rode); инструкция допускает одно. Если это неприемлемо, задание нужно заменить другим, а не убрать: сессия режима открывается не меньше чем с трёх заданий (`docs/16`, размеры сессий).

## A1–A2, модули 1–2 — `english-a1-a2-x01.json`

- a1a2-m01-l5-e3: a word-choice (calque) mistake is not in the A1 table of 4.6. Also check that "low" cannot be read as "feeling low" (sad); the "but ... tall" contrast should rule that out.
- a1a2-m01-l5-e2: "kinds" as an adjective plural is not literally in the A1 list (put under number). Also check that kinder / kindness are clearly wrong.
- a1a2-m01-l3-e3: the distractors on/to/of are weak; "at" was left out because "at my birthday" (= at my birthday party) is heard.
- a1a2-m02-l3-e2: "at" was left out of the options because "at the kitchen" is not wrong beyond doubt; check that "to" / "into" are clearly wrong.
- a1a2-m01-l1-e3: is "welcome in our village" wrong beyond doubt? (Is "Welcome in!" heard as a host's greeting?)
- a1a2-m02-l5-e2: "Could you open..." is close to the card's "Can you open the door?"; "you + -s" is a less typical mistake.
- a1a2-m02-l5-w3: "we are ___ to another city" - check that the passive "are moved" is not a natural second answer.
- a1a2-m01-l5-w1: is "more sad" an acceptable second answer to sadder? It is not listed.
- a1a2-m01-l4-w1: "women's" - check that "women shoes" is not accepted as standard.
- Uzbek: "kamgap odam" (quiet person, a1a2-m01-l4-e2), "bizda mehmon bo'lib turishibdi" (a1a2-m02-l2-w3), and "kichiklik qiladi" for shoes that are too small (it is in the deleted text, not in the final file).

## A1–A2, модули 3–4 — `english-a1-a2-x02.json`

- a1a2-m04-l1-e1 "arrives *in* Friday evening": American English also accepts the sentence with no preposition ("arrives Friday evening"). Any sentence with a day name has this problem. I kept it because "in" is wrong in every reading.
- a1a2-m03-l2-w2 lemon -> lemonade: is -ade standard enough morphology at A1?
- a1a2-m03-l1: all three word_form tasks are plurals (cheeses, eggs, salads). The lesson words have no other A1 form. Is the countable "kinds" use in "twenty different cheeses" fair?
- a1a2-m03-l5-e1 thirst/thirsty, a1a2-m04-l3-e2 sometime/sometimes and a1a2-m04-l5-e2 data/date are not in the A1 mistakes table. Are they fair at this level?
- a1a2-m04-l4-e3: is "has seven years old" a mistake Russian and Uzbek learners really make?
- a1a2-m03-l3-e2 and a1a2-m04-l1-e3 vs a1a2-m03-l5-e2 and a1a2-m04-l4-e2: these pairs are close repeats (this/these twice; "There *is* two/five ..." twice). They are within the caps; please check the variety is enough.
- a1a2-m03-l5-w1 ("the children ate all the bread yesterday") and a1a2-m03-l3-w1 ("the guests drank all the juice") follow the same pattern.
- a1a2-m04-l1-w3 uz "Ko'pincha dam olish kunlari ..." and a1a2-m03-l1-e3 uz "hammasidan ko'proq yaxshi ko'radi": please check that both sound natural.

## A1–A2, модули 5–6 — `english-a1-a2-x03.json`

- a1a2-m06-l1-e2: "its price is not *expensive*" -> high. Learner's usage notes (Cambridge, Collins, Longman) mark "price is expensive" as wrong, but people say it informally. Check that it is wrong beyond doubt in edited text and fair at A1.
- a1a2-m05-l5-e2: "can *drive* a bike" -> ride. This is word choice, which is not in the A1 table.
- a1a2-m05-l3-e2: "*traffics*" is a countability mistake, which is an A2 kind. Check that it is acceptable at A1.
- a1a2-m05-l2-e3: "on the stadium" -> at. Check that "on" is wrong in both varieties.
- a1a2-m05-l4-e1: "between the hospital *with* the bridge". Could "near / behind the hospital with the bridge" (a hospital that has a bridge) count as a second repair? I judged it implausible.
- a1a2-m06-l3-e3: "this dress *on* blue". Check that "at blue" and "to blue" are impossible.
- a1a2-m06-l3-w1/w2/w3: reddest / whiter / grayer. I did not list "more red", "more white" or "more gray".
- a1a2-m05-l3-w1: "busses" is listed as a variant spelling.
- a1a2-m05-l2-w2 / a1a2-m05-l3-w3 / a1a2-m06-l2-w3: check that the possessive frames are natural ("This hotel's rooms", "My car's only problem", "my new jacket's big pockets").

## A1–A2, модули 7–8 — `english-a1-a2-x04.json`

- a1a2-m07-l3-w3: is the base "wait" -> "waiters" an acceptable link at A1? It relies on the sense "wait at tables".
- a1a2-m07-l1-w3: only "boss's" is accepted. Check whether the grader strips the apostrophe, which would turn "boss'" into the base "boss".
- a1a2-m07-l3-e1: the options are engineers / an engineer / the engineer. The right one is the only one-word option, so its shape may give it away.
- a1a2-m08-l1-e2 ("plays *he* guitar" -> his): the mistake is a possessive slip with "guitar" only as decoration. "the" would also repair the sentence but is not an option.
- a1a2-m08-l1-e3: the distractors "They" and "Them" are weak. Also check that "That song on the radio is my favorite" is not too close to the card "This is my favorite song".
- a1a2-m08-l5-e2: the distractors "much" and "many" are safe but obviously wrong. "the", "one" and "some" were rejected because they give correct sentences.
- a1a2-m08-l5-e1: an adjective given a plural -s ("populars") is not literally in the A1 table. "more popular" and "most popular" also differ in shape from the right option.
- a1a2-m08-l5-e3: the matching mistake ("comparatives in one word") is A2 in the table.
- a1a2-m07-l4-e1 ("There *are* a lot of homework"): uncountable homework is an A2 point used in an A1 there is/are task. It is demanding but fair.
- a1a2-m08-l3-w2 (new): check that the uz "mehmonlarimiz kechki ovqatdan juda zavqlanishdi" and the ru "ужин очень понравился нашим гостям" are natural.

## A1–A2, модули 9–10 — `english-a1-a2-x05.json`

- a1a2-m09-l1-w1 hand→handed: the verb «hand» is closer to B1 vocabulary than A1. Is it acceptable at A1?
- a1a2-m09-l3-e3 «When my son *is* a fever»: is the be/have mix-up a real learner error? The more common error is «has fever» with no article.
- a1a2-m10-l4-e1 «we drive *on* the sea»: could a learner fix it by changing «drive» to «sail» instead? Also «drive in the sea» (the option «in») is wrong only because the meaning is absurd.
- a1a2-m10-l2-e1 «January is the *colder* month»: «a colder month of the year» is a remote second repair (change «the» to «a»).
- a1a2-m10-l4-w1 «this old tree's leaves»: is it natural enough next to «the leaves of this old tree»? Also check that «qari daraxt» is good Uzbek.
- a1a2-m09-l3-w1 healthier: «more healthy» is not listed, as the brief says, although it does occur in edited text.
- a1a2-m09-l1-e3 and a1a2-m10-l4-e2 «eight … *leg*» / «two clean *lake*»: could a learner fix them by changing the numeral to «a» instead? That changes the meaning, so I judged it no real repair.

## A1–A2, модули 11–12 — `english-a1-a2-x06.json`

- a1a2-m12-l1-e2: is "The text *in* the screen" an error beyond doubt?
- a1a2-m11-l3-e2: is "a view *on* the sea" wrong in both varieties?
- a1a2-m11-l5-e2: deleting "to" gives "walked the wrong direction", which is colloquial in American English. No option offers a deletion, so "in" stays the only answer.
- a1a2-m11-l1-e1: the option "a luggage" has two words while the other two have one. It is the typical learner error, so I kept it.
- a1a2-m12-l5-e3: is "socials networks" a realistic learner error? The slang noun "socials" exists, but the sentence is still wrong.
- a1a2-m12-l4-w1: Merriam-Webster lists "programer" as a rare variant spelling. The task does not accept it.
- a1a2-m11-l2-e2 (new): check the ru "Вчера каждый рейс на острова опоздал." and the uz "orollarga uchadigan har bir reys".
- a1a2-m11-l4-w3: in the ru translation "гид ... она" mixes genders. Check whether this is acceptable.

## A1–A2, модули 13–14 — `english-a1-a2-x07.json`

- a1a2-m14-l3-e2 "the quality of these shoes *are* low": could a learner defend "the qualities ... are low"? I think not.
- a1a2-m13-l4-e1 "Don't forget *closing* the windows": is forget + -ing wrong beyond doubt here, given "Don't forget to"? The options also have different lengths ("to close" vs close / closed).
- a1a2-m14-l2-e2 "The presentation was so *bored*": the mistake is the -ed / -ing adjective that the subject "presentation" controls. Is this target close enough to the lesson word?
- a1a2-m14-l2-e1: is the wrong correction "give" impossible in "I need to give an appointment with my dentist"? (A doctor can "give someone an appointment".)
- a1a2-m14-l4-e2: the wrong options "overtime's" and "an overtime" are weak and look odd. Is "did a lot of overtime" fine in US use (US speakers usually say "worked overtime")?
- a1a2-m14-l1-e1 "applied *on* three jobs": "applied to" is not an option, and "for" should be the only answer. Please confirm.
- a1a2-m14-l3-w3: I accept advert and ad. Is any other short form likely?
- Uzbek wording to check:
  - a1a2-m13-l2-w1 «u umuman samimiy emas»
  - a1a2-m13-l2-e3 «Biz uchun pul to'layotganingiz — katta saxiylik.»
  - a1a2-m13-l4-w1 «Uyg'ondim, bugun dushanba deb o'yladim va ishga jo'nadim.»
  - a1a2-m14-l5-w3 «Rahbarim»

## A1–A2, модули 15–16 — `english-a1-a2-x08.json`

- m15-l1 w1-w3: all three are -ist nouns. The school-subject words have no other form at A2.
- a1a2-m15-l5-w1 (pronunciation) and a1a2-m16-l4-w2 (electricity): the brief's table puts these forms above A2. I allowed them because both words of each pair are A2 cards.
- a1a2-m16-l1-e2 (cieling): a spelling mistake. The options give the answer away easily, but the task is fair.
- a1a2-m16-l2-e2: is the dialect "Them curtains" safe as a wrong option?
- a1a2-m16-l4-e2: please confirm that "rent ... of" is archaic and wrong today.
- a1a2-m15-l2-e2: please confirm that "take my son at/on kindergarten" is impossible.
- a1a2-m16-l5-w1, w2 and a1a2-m16-l2-w2, a1a2-m16-l4-w3: the noun cards hammer, nail, vacuum and lock are used as verbs. The sense is the same; please check this is acceptable.
- a1a2-m15-l1-e3: are "historic" and "historical" clearly impossible after "study"?

## A1–A2, модули 17–18 — `english-a1-a2-x09.json`

- a1a2-m17-l5-e3: is "can't afford buying" wrong beyond doubt in edited English? It turns up occasionally in informal writing.
- a1a2-m17-l5-e1: confirm that "Short hair fits you" is a clear mistake and "suits" the only natural word.
- a1a2-m17-l1-e3: a learner might tap "credit" and mean "in installments". That word is above A2, so I accepted the risk.
- a1a2-m18-l2-e1: "Take the doctor's receipt to the pharmacy" is ruled out only by logic.
- a1a2-m17-l2-e1: could "hadn't delivered ... yesterday" count as a second repair? I judged it too marginal.
- a1a2-m18-l1-e2: are the bare nouns in "Fever and cough are two typical symptoms" acceptable? The wrong options symptom's and sign are weak.
- a1a2-m18-l3-e2: the wrong option "of calories" has two words while the others have one.
- a1a2-m17-l2-w2: the verb "queuing" is mostly British; "waiting in line" is more usual in American English. It is correct in both.
- a1a2-m17-l3-e2: "made us a discount" should be non-standard (give/offer a discount). Please confirm.
- Lesson 17-4: its three word_form tasks are all plurals (scarves irregular; necklaces and wallets plain). The writer found no fairer A2 form for these nouns, and I agree.

## A1–A2, модули 19–20 — `english-a1-a2-x10.json`

- a1a2-m19-l2-e1 "deers": Merriam-Webster lists "also deers" as a plural; the learner's dictionaries give only "deer". Is it acceptable as the marked mistake at A2?
- a1a2-m19-l3-e3 "This soil near the rivers *are* very rich.": is "This soil near the rivers" natural enough? "This" is there so that "soil -> soils" cannot repair the sentence.
- a1a2-m19-l4-e1: does "hadn't damaged" still count as a second repair in "Last night's storm didn't damaged..."? I judged it unnatural without a past reference point.
- a1a2-m19-l4-w2: does "not from new trees" rule out "recyclable paper" clearly enough?
- a1a2-m19-l3-w3 plant -> planted: the card is the noun (растение) and the answer is the verb (сажать). Is that close enough to the card's sense?
- a1a2-m19-l1-e1 dessert, a1a2-m19-l5-e3 lightening, a1a2-m20-l2-e1 costume: are these spelling confusions fair mistakes for A2?
- a1a2-m20-l4-w1: is "ad", a clipping, acceptable among the answers? It is listed because it is correct in the gap.
- a1a2-m20-l5-e2: uz «duo qiladi» for "pray for" (the card has «ibodat qilmoq»); a1a2-m19-l4-e1: uz «panjara» for fence.
- a1a2-m20-l2-w3: ru «писательницы» rather than the card's «автор» (Russian needs the feminine because of "her").

## B1, модули 1–2 — `english-b1-x01.json`

- b1-m02-l2-w3: is the uz «juda yopiq odam» natural for secretive, or a calque of «закрытый человек»?
- b1-m01-l4-w3: is «His early balding» natural enough to stay accepted?
- b1-m02-l5-w3: should critique be accepted («even a little critique of her work»)? I added it to be safe.
- b1-m02-l3-w2: should informational be accepted? I added it; it is a weaker choice than informative.
- b1-m01-l1-w2: should the nickname be capitalized («the Little Professor»)? It is in lower case now.
- b1-m01-l1-w3: is «without the director's signing» a possible second answer? I judged it unnatural.
- b1-m01-l3-w1: curiousness (MW, Collins) is not accepted. Neither is jealousness in b1-m02-l2-w2. Both are rare and not in the learner's dictionaries.
- b1-m01-l5-e2: is «the heroic are ...» as a wrong correction impossible beyond doubt?
- b1-m02-l3-e3: is «full of humors» wrong in every reading? «humors» also means moods.
- b1-m02-l1-w1: «got divorced» is close to a bare -ed. It is kept as a get-passive chunk; consider a harder task for divorce.

## B1, модули 3–4 — `english-b1-x02.json`

- b1-m04-l2-e1: "fill milk into my tea". "fill X into Y" occurs in technical English (filling powder into capsules). Check that it is an error beyond doubt in this everyday sentence.
- b1-m04-l3-e2: check that "recipe of apple pie" is marked as an error, not as a rarer variant.
- b1-m04-l4-e1: check that "ice cream in twenty different tastes" is wrong beyond doubt.
- b1-m04-l3-e1: "turn off the plate". Some British hobs have hot "plates". Check that a native reader still sees it as wrong.
- b1-m04-l5-e3: a learner might tap "refused" and want "avoided" ("avoided swallowing" is correct). I judged that "avoid" is not a near-synonym of "refuse", so I left it.
- b1-m04-l5-w3: "serve" is used as a noun in Australian English, so a learner who leaves the base unchanged could be right there.
- b1-m03-l4-w2: "tunnel" used as a verb is unusual at B1. Check that the task is fair.
- b1-m04-l1-w3 (ingredients) and b1-m04-l3-w3 (trays) are close to bare -s plurals. These noun lessons offer few derivatives, so I kept them as the writer's choice.
- b1-m03-l2-e3 (new task): check that "the corridor where connects" reads as a clear B1 relative-pronoun mistake and that the options which/whose/what are fair.

## B1, модули 5–6 — `english-b1-x03.json`

- b1-m05-l1-w3: brainy/brainiest is informal and a stretch for B1. Check that "the brains of all my classmates" cannot pass as an answer.
- b1-m05-l5-w1: anxious -> anxiety is a stem change (a C1 form in the brief's table). Check that it is fair at B1, and that "anxiousness" (listed in Merriam-Webster) is unnatural in "Her greatest ___".
- b1-m05-l4-e3: "be *cover* by your insurance". Check that the passive of cover counts as the pattern of "insurance" and not as decoration.
- b1-m05-l4-w1: medicine -> medical is not a regular affix. Check that it is fair as a base and answer.
- b1-m06-l3-w3: check that "an indirect one" (a flight) is natural, and that putting "direct" in the sentence does not make the task too easy.
- b1-m06-l1-w3 and b1-m06-l5-w2: decide whether to keep the variants minibusses and adventuresome in accepted.
- b1-m06-l4-e1: "a quiet *camping*" can also be repaired by inserting "site" ("camping site", British). The tap stays on the same word, so step 1 is fair, but check this.
- b1-m05-l1-e1: "broke *me* wrist" is dialect only. Check that it is a safe wrong correction.
- b1-m05-l2: e1 and e3 both mark the word "ill" (ill -> sore, ill -> illness). Both are fair, but the repeat is noted.
- b1-m06-l2-e2: launch -> start repeats a collocation the learner has met in another practice type.

## B1, модули 7–8 — `english-b1-x04.json`

- b1-m08-l1-w2 financially: the table puts double derivation at B2. I kept it because each step is B1 or below and the owner wants harder tasks.
- b1-m07-l3-e1 decided *attending*: a learner could change "decided" to "considered", which would make "attending" correct.
- b1-m08-l3-w3: «baker's» is accepted. Does the grader also take a typed «bakers» (punctuation is ignored)? Should «baker's» be accepted at all in an American-spelling course?
- b1-m08-l3-w2 plastic packaging: «packing» (from pack) is not listed.
- b1-m07-l4-e3: check that «has exported fruit ... since last spring» reads naturally. «has been exporting» is not offered.
- b1-m07-l1-e2 psychologist *which*: is a relative pronoun a mistake of the target word, or is the lesson word only decoration?
- b1-m08-l5-e2 *on* warranty: «in warranty» (British) is right too but is not offered. Check that «on warranty» is wrong beyond doubt.
- b1-m07-l2-e3: «applied *on* this position» is close in structure to the card «applied for the position of sales manager».
- b1-m08-l5-w3: «a great variety of dishes» is close in structure to the card «a wide variety of fruit».
- b1-m07-l4-w3: «industry waste» is sometimes used as a noun-noun phrase; check that the unchanged base is unnatural here.

## B1, модули 9–10 — `english-b1-x05.json`

- b1-m09-l1-e1: is "compulsory to all pupils" wrong beyond doubt? It occurs in non-native and Indian English.
- b1-m09-l1-e2: optimal -> optional. "optimal" is wrong only by meaning; the colon clause decides it. Check that this is fair.
- b1-m09-l1-e3: "privately educated" sounds British and may be at the upper edge of B1. Adjective vs adverb is a B2 point in the table.
- b1-m09-l3-e1: "on the third attempt" (British also "at"). Check that "for" and "to" cannot work.
- b1-m10-l1-e3 and b1-m09-l5-e2: the distractors (bottle/battle, summarize/summit) are weak. I found no stronger real words that are surely wrong here.
- b1-m09-l5-e1: "methodic" as a calque of «методика». The distractors methodical/methodically are the wrong part of speech, so they are easy to rule out.
- b1-m10-l4-e1: is "fell to a phone scam" surely wrong? Compare "fell victim to".
- b1-m10-l4-e3: are "spams" and "deletes spamming" wrong beyond doubt?
- b1-m10-l2-e1: "downloaded ... from the class website" is a possible second repair at "to". I consider it ruled out by "for everyone to see".
- b1-m09-l2-e3: since -> for with "two semesters". The mistake is the tense pattern, and "semester" is close to decoration.

## B1, модули 11–12 — `english-b1-x06.json`

- b1-m11-l2-e2 "dies *on* the very last scene": check that "on" is wrong beyond doubt. "at" is left out of the options on purpose.
- b1-m11-l4-w1: fictional and fictitious are accepted. Check whether "fictive" or the base "fiction" (a learner who types it is told "same word") could be defended in "The detective ... is ___".
- b1-m12-l5-w3: sloping and sloped are both accepted. Check that nothing else fits "such ___ ground".
- b1-m11-l3-e2: the wrong options passed and carried are weak. Check that "carried a rehearsal" is impossible. (held and did would be right but are not offered.)
- b1-m12-l2-e3 "The score is three to one, with only five minutes left.": check that it is natural. Uzbek "Hisob uch-bir".
- b1-m12-l2-w2 "The ___ team ran around the stadium with the cup.": check that "victory team" is not natural.
- b1-m11-l2-w2 subtitled, b1-m11-l2-w3 scripted, b1-m11-l4-w3 crafted: all three are noun-to-verb -ed forms. Is that demanding enough at B1? (The words of m11-l2 allow little else.)
- b1-m11-l3-w3 music -> musician: -ian is in the B2 row of the table, but the answer is the lesson word itself.
- Uzbek: "cholg'u asari" (b1-m11-l3-w2), "nogironlik aravachasidagi odamlar" (b1-m12-l4-w2), "kanoeda suzib boramiz" (b1-m12-l5-e3).
- b1-m12-l4-w2: the §3.2 exception (target arena) needs the coordinator's acceptance.

## B1, модули 13–14 — `english-b1-x07.json`

- b1-m13-l2-e2 (turtle, it's -> its): this is really an its/it's slip with "turtle" only as its antecedent, which is close to the "lesson word as decoration" case the brief forbids. I kept it because the lesson's animal nouns give no other fair second error kind: apostrophe tasks such as "a turtles shell" are spoiled by the correct noun-adjunct "turtle shell". The coordinator should decide.
- b1-m14-l4-w1 (deep -> depth): the brief's 3.4 table puts this stem change at C1. I kept it because the answer is the B1 lesson card itself. "deepness" (Merriam-Webster, Collins) is not listed.
- b1-m14-l4-w3 (distance -> distant): the table puts -ant at B2. I kept it because "distant" is a B1 word.
- b1-m13-l4-e2: Collins has "soft" = mild for climate. Check that "a very soft winter" counts as wrong in edited English.
- b1-m13-l4-e1: "hall" is a weak distractor; one with a better shape could not be found. ("hailstorm" and "hailstone" are right or near-right.)
- b1-m14-l5-e1 ("invented a planet") and b1-m14-l5-e2 ("cellars"): these distractors are wrong only in meaning.
- b1-m13-l2-w3: check the new Uzbek "Pingvin bolasining qalin kulrang patlari ... isitib turadi", and that "baby penguin ... feathers" is acceptable for a chick's down.
- b1-m13-l2-e1: confirm that "net" for a spider's web is wrong beyond doubt.
- b1-m13-l5-w1: "the preserving of" is a possible but unnatural gerund; it is not listed.
- b1-m13-l4-w3: "more chilly" is not listed, following brief 3.3.8.

## B1, модули 15–16 — `english-b1-x08.json`

- b1-m16-l1-e1 "*For* our great joy": is "With our great joy, the rain stopped..." wrong beyond doubt? Is "to our joy" fair at B1?
- b1-m16-l4-e1 "prefer sweet fruit *than* vegetables": adding "rather" also fixes the sentence. "than" is still the word to tap, but please confirm this is fair.
- b1-m15-l3-e1 "stole a tourist of all his money": is rob somebody of something within B1? Is "burgled a tourist of" clearly wrong?
- b1-m15-l1-e2 "The *ministry* came in person": the mistake is one of meaning only. The options ministerial and administer are weak.
- b1-m15-l3-e2 "reading *detectives*": the options detective films and detective agencies are easy to rule out.
- b1-m15-l5-w2 disobeys: "obeys" is grammatical, and only the meaning of the other clause rules it out.
- b1-m16-l5-e2: please confirm that "deserves for winning" and "deserves winning the competition" are both wrong.
- b1-m15-l2-w3: the base "guilt" may be a B2 word, which is above the answer "guilty".
- Uzbek: "noshukur" (b1-m16-l1-w1; perhaps "qadr bilmas"), "uyni o'marmoq" (b1-m15-l3-e1 explanation), "g'ashimni juda keltirdi" (b1-m16-l2-w3).
- b1-m16-l3-e1 "reacted *on* my joke": changing "reacted" to "commented" would keep "on" and make the sentence correct. I judged "comment" not a near-synonym of "react" and left it.

## B1, модули 17–18 — `english-b1-x09.json`

- b1-m17-l2-e1 "we *lately* found the key": is "lately" with the past simple wrong beyond doubt, given the archaic "lately = recently"? Deleting the word also gives a correct sentence. No better sentence for "eventually" was available.
- b1-m18-l4-e2: is "the border of the roof" wrong beyond doubt? Are "limit" and "frontier" impossible here?
- b1-m17-l4-e2: "the possibility to study abroad" relies on the learner's-dictionary note against "possibility to do".
- b1-m17-l1-e2: the tense mistake is pinned by "for more than a decade now". This is borderline for "the mistake concerns the lesson word".
- b1-m17-l5-w2 "incomplete": base is "complete" (the card has the verb). Should "uncompleted" be accepted? I did not list it, because the learner's dictionaries do not have it.
- b1-m17-l1-w3 "century's": the grader strips apostrophes, so "centurys" also passes. "centuries'" is not listed.
- b1-m17-l1-w1 "weekdays" is a bare -s plural, which is weak at B1. This noun lesson has no better form.
- b1-m17-l4-e3 "finally *got* her aim": check that no near-synonym swap works, for example "got her wish".
- b1-m18-l4-e1: check that "between the forty guests" is wrong beyond doubt.
- b1-m18-l1-e1 (new): check that "the water is too small" is unambiguous and natural as the calque of «мелко».

## B1, модули 19–20 — `english-b1-x10.json`

- b1-m20-l4-e1 (hard -> hardly): the brief's table puts hard/hardly at C1, which is above B1. I kept it because «hardly» is a word of this lesson and the usual B1 mistake (hardly + never) can also be fixed by changing «hardly» to «almost». Is it fair for B1?
- b1-m20-l2-e2 (The reason *because* she was late): deleting «because» also gives a correct sentence (the reason she was late). The brief says to prefer another sentence in that case; every «reason because» sentence has the same problem.
- b1-m19-l3-e2: is intransitive «ruin» (the fish will ruin) wrong beyond doubt? Merriam-Webster has an old intransitive sense.
- b1-m20-l5-e1: is «not saying the truth» wrong beyond doubt in edited writing?
- b1-m20-l2-e1 (new): is «impact to» clearly wrong? It appears in business writing.
- b1-m20-l1-e3 (new): «instead *to* calling» mixes «to» with the -ing form. Is this mistake natural enough for learners?
- b1-m20-l1-e2: the card «rather» means «скорее», but the task uses «would rather» (предпочесть). Check that this is close enough to the card's meaning.
- b1-m19-l1-w1: is «entry» natural for «the main ___ of the museum»? It is listed together with «entrance».
- b1-m20-l3-w2: the card «besides» is an adverb («кроме того»), but the task uses it as a preposition («помимо»).
- b1-m20-l3-w3: should «had ridden» also be accepted? I judged it unnatural there.

## B2, модули 1–2 — `english-b2-x01.json`

- b2-m01-l1-e3, b2-m01-l2-e1, b2-m01-l3-e3: deleting the adverb (reckless, sincere) also leaves a correct sentence. Is that acceptable for adjective-vs-adverb tasks?
- b2-m01-l4-e1: is "provocate" a believable mistake at B2, and is the Uzbek "gij-gijlashiga" right?
- b2-m01-l5-w2: is the Uzbek "xarizmatik" natural, or should it be "jozibali"?
- b2-m01-l5-e2: could a learner see "a cheerful look at life" (changing "outlook" to "look") as a correct fix?
- b2-m02-l2-e1: "guidances" is used in regulatory writing. Is it wrong beyond doubt here?
- b2-m02-l1-e1: could a learner fix the sentence by changing "probation" to "training" ("in training")?
- b2-m01-l3-e1: is the Russian "Очень внимательно с вашей стороны, что вы позвонили..." natural?
- b2-m01-l5-w1: is pride→proud acceptable as "standard morphology"?
- b2-m01-l1-w3: "и я победил" uses the masculine as the default for "I".

## B2, модули 3–4 — `english-b2-x02.json`

- b2-m03-l3-e2: is "left her some stock in a large oil company" natural to a BrE reader as well? (BrE prefers "shares", which is not among the options.)
- b2-m04-l5-e3: are "guard" and "safeguard" her dissertation wrong beyond doubt? They are as literally possible as "protect"; only the time frame forces "defend".
- b2-m03-l4-w2: "The shop was ___" also takes the two-word "not profitable". It is the same pattern as the brief's own example (illegal), so I left it.
- b2-m03-l1-w3: "enjoyed peace and ___" – I see no rival form (prospering?); please confirm.
- b2-m04-l4-e1: the option "nucleus's" is a weak distractor; the better candidates (nucleon, nucleic) are missing from the lexicon or risky.
- b2-m03-l5-e2 (their/there, taxpayers): the lesson word only controls the pronoun, which is a borderline fit for §4.2.
- b2-m04-l2-w3 "took a quick ___ of the baby's head": confirm that "measure" (the unchanged base) is unnatural here.
- b2-m03-l3-w1 uz "o'ta spekulyativ sarmoya": would "chayqov xarakteridagi" be more natural?
- b2-m04-l5-w3 uz "Manbaga to'g'ri havola berilmasa, iqtibos…": check the wording.
- b2-m03-l2-e2: check the new uz translation "Ijara to'lovingiz ikki haftaga kechikdi".

## B2, модули 5–6 — `english-b2-x03.json`

- b2-m05-l2-w3: should "automatization" be accepted? It is in Merriam-Webster, but I did not find it in the learner's dictionaries. It is not listed now.
- b2-m06-l2-w1: is "was diagnosed twice before a third doctor found the real cause" clearly unnatural? It must be, for misdiagnosed to be the only answer.
- b2-m06-l2-w3: is it acceptable to list "rehab" (an informal clipping)? Also, the base "rehabilitate" is not easier than the lesson word, and the lesson has no easier base.
- b2-m06-l5-e3: can "take a vitamin additive" be defended in any context?
- b2-m05-l1-e3 (interface -> frustrating) and b2-m06-l5-e2 (lifespan -> shorten): the mistake is on a word next to the lesson word. Please confirm that this counts as the noun's own pattern.
- b2-m05-l1-e2: "fell down" contains the marked word "fell", and it is the only two-word option. I kept it because it is the natural calque. Is "The server fell down" wrong beyond doubt (British IT slang has "fell over")?
- b2-m05-l3-e2: the wrong corrections "outdating" and "outdates" are real but rare verb forms, and they are weak distractors.
- b2-m05-l1-w2: is bug -> debug fair, given that de- is not in the level table?
- Uzbek wording to check: "sukut bo'yicha" (b2-m05-l1-e1), "arxiv xodimi" (b2-m05-l1-w3), "motam tutmoqda" (b2-m06-l3-w3), "uchishga nisbatan kuchli fobiya" (b2-m06-l3-e1), "musiqali quticha" (b2-m05-l5-e2).

## B2, модули 7–8 — `english-b2-x04.json`

- b2-m07-l2-e1: is "their *preys*" wrong beyond doubt? Oxford, Cambridge, Longman and COBUILD mark prey as uncountable, but "preys" turns up in some scientific writing. Wiktionary lists both.
- b2-m07-l4-e3: is "hundreds of tons of *coals*" wrong in edited text? "coals" means lumps or embers. The two possessive options (coal's, coals') are weak but wrong.
- b2-m07-l1-e1: the wrong correction "green house" is two words, against one word in the other options. It is still a spelling error.
- b2-m07-l1-w3: should "absorptive" (in Merriam-Webster, not in the lexicon) be accepted? Can "very absorbing" be read literally?
- b2-m07-l2-w2: "wildlife conservator" is rare and not listed.
- b2-m08-l2-w1: is "one of the five nominators will become the party's candidate" clearly impossible? I believe yes.
- b2-m08-l5-e1: "the main topic *on* next week's summit": "of" and "for" would also be correct, but neither is an option, so the task works.
- b2-m08-l3-w3: the uz text uses the card's "gubernator" (in Uzbekistan the usual word is "hokim"). It fits the foreign "elected governor" context.

## B2, модули 9–10 — `english-b2-x05.json`

- b2-m09-l1-e3: is "serve in a jury" wrong beyond doubt in both varieties? Dictionaries give only "on".
- b2-m09-l3-e1: is "prohibit ... of selling" a mistake learners really make? A learner might also expect "to", which is an option and is wrong before "selling".
- b2-m09-l4-e1: check that "a jury convinced the former minister of bribery" cannot be read literally ("convince sb of sth").
- b2-m09-l4-e2: check that "delay a suspect" is impossible in this sense, and that the claim about the law is acceptable as general content.
- b2-m09-l2-e3: "corruption" can also be repaired by inserting a word ("corruption cases"), and the learner might tap "corruption". I judged this marginal and kept the task.
- b2-m10-l1-e1: is "signature" for a photo text clearly wrong, given that a photographer's signature also exists? The added "explaining who is in it" is meant to exclude it.
- b2-m10-l4-e2: is "transfer your true feelings" wrong beyond doubt? "Transfer feelings" exists in psychology.
- b2-m10-l1-w2 and b2-m10-l5-w1 (publisher -er, statement -ment): both are easy forms for B2. I kept them because the lesson's other words were used by other tasks.
- b2-m09-l1-w3 attorneys: still a plural, though it is now pinned by verb agreement. The lesson has no other derivative of B2 level.
- A native check of the Uzbek is needed, especially the new texts of b2-m09-l1-e2 ("sudga da'vo kiritishga qaror qilishdi"), b2-m09-l5-e2 and b2-m10-l1-e1 ("surat ostida ... yozuv").

## B2, модули 11–12 — `english-b2-x06.json`

- b2-m11-l3-e1: is "rhymed *to*" wrong beyond doubt? Learner's dictionaries give only "rhyme with", but "rhyme to" appears in older texts. It is the Uzbek "-ga" calque.
- b2-m11-l4-e1: is "sequel *of*" wrong beyond doubt? It occurs on the web, and older texts use "sequel of" in the sense "consequence".
- b2-m12-l1-w2: could "fully modularized" count as a second right answer? I did not list it: the learner's dictionaries seem to lack it.
- b2-m12-l2-e1: check that "a certificate of knowledge of English" does not survive as a second repair (it is unidiomatic, so I judge it does not).
- b2-m12-l5-w2 (skim -> skimmed): the only demand is the doubled consonant. It may be too close to the "bare -ed" the brief calls too easy at B2, but the lesson words gave no better form within the B2 table.
- b2-m11-l1-w1: is "pure abstractions" (plural) natural enough to stay in accepted? It does no harm to learners, so I kept it.
- b2-m11-l1-e3 (its/it's): the mistake is not really about the word mosaic. The distractor "their" ties it to the noun being singular. I kept it as a B2 point.
- Uzbek: b2-m11-l3-w2 "Taqdirning kinoyasini qarangki"; b2-m11-l4-w2 "kastingda qatnashgach"; b2-m11-l2-w1 "hajvchi".

## B2, модули 13–14 — `english-b2-x07.json`

- b2-m14-l4-w3: the plural "plateaus" is close to a bare -s gap at B2 (the brief calls that too easy). m14-l4's nouns have almost no other forms, so I kept it. Better ideas welcome.
- b2-m13-l3-w3: relieve -> relief is a v -> f stem change, which the brief puts at C1. Kept because the pair is very common; check the level.
- b2-m13-l4-w3: should "unengaged" (rare, not in learner's dictionaries) be accepted for "Bored and ___"?
- b2-m13-l5-w3: should "amoral" be accepted for "deeply ___" about selling fake medicine? I judged it unnatural there.
- b2-m14-l3-e3 (new): check that "As the proverb talks/sounds" is wrong beyond doubt. I avoided "tells" and "reads" because "as the story tells" and "the proverb reads" can be defended.
- b2-m14-l1-e2 (new): check that "itinerary ... rather busy" is natural for a packed travel plan, and the uz "tig'iz".
- b2-m13-l4-e3: predicative "the course is *volunteer*". It turns up in informal job ads; check that it is wrong in edited text.
- b2-m13-l2-e1 and b2-m13-l2-e2 both lead to a person noun (refuge/refugee as a confusable pair, immigration/immigrant as a wrong form). I counted them as different kinds; check that the lesson has enough variety.
- b2-m14-l2-w1: "The ancient ___ that built this temple" is structurally close to the card "This ancient civilization built cities near the river". I judged it not a near-copy; check.
- Uzbek wording to check: b2-m13-l2-w2 (integratsiyalashuvning kalitidir), b2-m14-l5-e2 (mehr-oqibat ko'rmoqdamiz), b2-m14-l3-e2 (ahmoq bo'rilar).

## B2, модули 15–16 — `english-b2-x08.json`

- b2-m16-l2-e1 "a niche *on* the market": "niche in the market" is standard, but "on the market" is a real phrase. Confirm that "niche on the market" is an error.
- b2-m15-l5-e1 "is completely *desert*": dictionaries mark the adjective "desert" as attributive only. Confirm that the predicative use is not acceptable.
- b2-m15-l1-e3: the wrong option "metropolitans" is also a noun (a church archbishop). The sentence would be nonsense, but it is grammatical.
- b2-m16-l5-e2 "*do* a compromise": "make a compromise" is right too but is not an option. "take a compromise" is listed as wrong; check it.
- b2-m16-l2-w3: is "packing" (accepted) natural for plastic wrapping on fruit?
- b2-m15-l3-w3 "the ___ of the new bridge": the gerund "constructing" seems too unnatural to accept. Construct -> construction is also easy for B2.
- b2-m16-l4-e3: could "outsourced its cleaning *for* a private firm" be read as "on behalf of a firm"?
- b2-m16-l3-w3: is "distributer" listed anywhere as a variant spelling? The explanation says "-or, not -er".
- b2-m15-l4-w3 facility -> facilities is a common plural and fairly easy.

## B2, модули 17–18 — `english-b2-x09.json`

- b2-m17-l2-w1: is "The two reports are completely contradicting" ever acceptable without an object? It is not listed.
- b2-m17-l2-e3: "My grandmother would strongly disagree with your bright green hair". Is it natural enough to count as a second place to tap?
- b2-m17-l1-e1: could "claims" be tapped and changed to "admits being"?
- b2-m18-l1-e2: is "attribute ... *on*" a realistic learner error? Is "blames her good health on" absurd enough?
- b2-m18-l1-e1: "The bad reviews got / had the owners improving the menu" is possible if "prompted" is tapped. I judge it far-fetched.
- b2-m18-l1-w2: "contributor to the school magazine" (writing articles) stretches the card's sense of contribute (способствовать / hissa qo'shmoq).
- b2-m18-l5-w1: is "enjoyed peace and political stabilization" excluded firmly enough?
- b2-m18-l5-e3: "emerged *of*" can also be repaired by inserting "out" before the marked word. The learner still taps the right word.
- Uzbek wording to check:
  - b2-m18-l5-e3: "Tun bo'yi ishlagan charchagan ishchilar"
  - b2-m18-l5-e2: "avj oldi"
  - b2-m17-l1-w3: "o'zini dadilroq tutish"

## B2, модули 19–20 — `english-b2-x10.json`

- b2-m20-l5-w2 (base capable, "The ___ of this tiny robot to climb walls"): a learner may type "capacity", which is correct English here ("capacity to do"). It is not a morphological form of capable, so I did not list it. Decide whether to accept it.
- b2-m20-l4-w3 ("in ___ order" -> sequential): the word may be C1, and some editors call the phrase redundant. Also check that "in sequence order" is not acceptable.
- b2-m19-l4-w2 ("postponed ___" -> indefinitely): confirm that "postponed definitely" at the end of the sentence is unnatural.
- b2-m19-l3-e1 ("superior to *lift* weights"): check that the distractor "lifted" ("superior to lifted weights") is clearly wrong.
- b2-m20-l2-w1 (new): check that singular "specification" is excluded by "are" and that the uz "texnik xususiyatlari" is the usual term.
- b2-m19-l2-e3 (new, "entirly"): check that it is a realistic misspelling and not too easy.
- b2-m20-l4-e3 (new): check that "lies beyond the scope of this project" is natural, and that "over" and "after" are impossible.
- b2-m19-l4-e1 ("apparent *for* everyone"): confirm that "for" is wrong in edited writing (only "apparent for all to see" is idiomatic).
- b2-m20-l1-e2 / b2-m20-l3-e3 (of -> from): check how realistic the learner error "of" is.
- Uzbek wording: b2-m19-l3-w2 "o'zini boshqalardan kam his qilardi", b2-m20-l2-w3 "katta moslik", b2-m20-l4-w1 "juda qulay va amaliy" for "very functional".

## C1, модули 1–2 — `english-c1-x01.json`

- c1-m01-l3-e2 "blunt *to* me": is "blunt to sb" truly unacceptable, and is "honest to me" wrong too? I believe both are wrong.
- c1-m01-l4-e2 "gave *up* to temptation": the repair "gave herself up to temptation" inserts a word, so "up" is arguably not the only fault. It is literary; I kept the task.
- c1-m02-l1-e2 "*called* outrage": the same issue with "called forth outrage". I kept it.
- c1-m01-l5-e2 "knack for *find*": a learner may tap "for" and offer "knack to find". It is non-standard, but it does occur informally.
- c1-m01-l1-w3: should untactful (Wiktionary, not in learner's dictionaries) be accepted?
- c1-m01-l2-w1 complacence, c1-m01-l5-w1 ingeniousness, c1-m02-l3-w2 confidante: confirm these extra accepted forms. Unlisted twins I left out: versatileness, vainness (Merriam-Webster run-on entries, very rare).
- c1-m01-l4-e3 (new): confirm that "had mercy on" is the one natural repair. "took mercy on" is not among the options.
- c1-m01-l1-e1: the ru note «сознательный работник — тоже conscientious» is a free equivalence. Check it.
- Uzbek: the new translations for c1-m01-l4-e3 and c1-m02-l5-w2, plus "qo'l siltab qo'ydi" (c1-m02-l2-w2) and "ichida kek saqlab yurdi" (c1-m02-l3-e2).
- c1-m02-l2-e3: the distractors distraction and distrust are weak (obviously nouns), but safe.

## C1, модули 3–4 — `english-c1-x02.json`

- c1-m03-l2-e1: is "accountable before the shareholders" wrong beyond doubt, given that "accountable before the law" and, in EU usage, "before parliament" exist?
- c1-m04-l2-e1: "a foothold on the foreign market". "on the X market" appears in European business English; check that it is wrong in edited British and American text.
- c1-m03-l4-e2: is "made a walkout" impossible, as opposed to merely rare? Also, the correct sentence uses "staged a walkout", the same collocation as the card's example.
- c1-m03-l5-e1: "professionality" — Collins and Wiktionary may list it. Is it non-standard enough to be marked wrong?
- c1-m03-l1-w1 and c1-m03-l4-w2: "made redundant" and "unfair dismissal" are chiefly British terms. They are acceptable, but check that the sentences read naturally to an American reader.
- c1-m04-l1-w3: is "affiliation" right for the clinic–university relationship? Check the ru "официальная связь" and the uz "rasmiy aloqa".
- c1-m04-l3-e3: check the Uzbek translation of the new sentence ("u qanchalik ehtimoldan yiroq ko'rinmasin, tayyorlanib qo'yadi").
- c1-m03-l4-e3: is "all forms of harassers" impossible enough as a wrong correction?
- c1-m04-l4-w1: "Neither side was willing to make a single concession..." is close to the card "Each side made a concession to close the deal". I kept it because the meaning differs; a native reader should judge whether it is a near-copy.

## C1, модули 5–6 — `english-c1-x03.json`

- c1-m05-l1-e2: is "slump of demand" an error in both varieties? It is a calque from the Russian genitive.
- c1-m05-l1-e3: confirm that "stimulus packet" is non-standard in British English too.
- c1-m05-l4-e3: "budgetary frames". "Budgetary framework" exists; confirm that "tight budgetary frames" is wrong.
- c1-m05-l1-w1: is "years of harsh austerities" (plural) acceptable? If so, list it.
- c1-m06-l5-w1: a learner might type "microbiological contamination". It comes from a different root (microbiology), so it is not listed.
- c1-m06-l4-w3: is "Platinum is highly catalytic" natural enough?
- c1-m05-l2-w1: "evaluation" is listed beside "valuation". Should it stay?
- c1-m06-l3-w3: "generality" was added to `accepted`. Is that right, or is it too loose?
- c1-m05-l1-e1 and c1-m06-l1-e3: check that the new participle sentences read naturally. In particular, "then" combined with "Having privatized".
- c1-m06-l5-e1: check the uz explanation, which says to'qima is not fabric here; to'qima also means woven cloth.

## C1, модули 7–8 — `english-c1-x04.json`

- c1-m07-l1-e3: is "reiteration" (for a product version) wrong beyond doubt? This is the weakest task of the file.
- c1-m08-l2-e3: is "general narcosis" an error, and not just a rare technical term?
- c1-m08-l4-e1: is "broke a ligament" wrong beyond doubt, or only non-technical speech?
- c1-m07-l2-e3: "ransomwares" - check that ransomware counts as uncountable in edited writing.
- c1-m08-l1-w3: nauseous / nauseated for "feeling sick". The nauseous = "causing nausea" usage note is a disputed point.
- c1-m08-l1-e3: "suffer from" is the pattern of suffer, not of hypertension. Is that acceptable as a mistake that concerns the lesson word?
- c1-m07-l5-e3 (new): check that "pervaded" and "pervasiveness" give impossible sentences, and that the uz term "bog'lama fe'l" is clear to learners.
- c1-m08-l3-w3: "teams immunized every child ... against polio" is structurally close to the card "Nurses immunize children against measles at school". I kept it because the verb pattern itself requires that shape.
- c1-m07-l2-w2: should "cyber-criminal" (common in British writing; it would be typed as two words) also be accepted?
- c1-m08-l3-w1 (new): check that "reduced transmissions of malaria" is unnatural enough not to be a second answer.

## C1, модули 9–10 — `english-c1-x05.json`

- c1-m09-l1-e2: is "contemplated to resign" wrong beyond doubt in edited writing?
- c1-m09-l5-e3: is "a bruising ego" impossible?
- c1-m09-l1-w2: should "discernable" (a Merriam-Webster variant) stay in accepted? An editor could drop it.
- c1-m09-l4-w1: some dictionaries record "unattentive" as rare. It is not listed.
- c1-m10-l3-w3: could "aquatic" be argued for frogs? The clause "they live both on land and in water" is the only thing that excludes it.
- c1-m10-l5-w1: does "a slow replenishment" read naturally?
- c1-m10-l3-e3: does the mistake ("corals reef") belong to the lesson word reef, or only stand beside it?
- c1-m10-l4-e3: does the passive "have been poached for their ivory" read naturally, and is the uz "fil suyagi uchun noqonuniy ovlandi" right?
- Uzbek terms: "marjon rifi" (c1-m10-l3-e3), "tomchilatib sug'orish" (c1-m10-l5-w3), "biologik parchalanuvchanligini" (c1-m10-l2-w2).

## C1, модули 11–12 — `english-c1-x06.json`

- c1-m11-l2-w2: elect -> electorally is a three-step derivation. Is it fair?
- c1-m11-l2-w3: could "nonpartisan support from both the left and the right" be defended?
- c1-m11-l5-e2: could "the senate agreed to appeal the new fuel tax" be read as acceptable American legal usage?
- c1-m11-l5-e1 and c1-m11-l2-e1: are "mandatory to all men" and "candidacy to the presidency" wrong beyond doubt?
- c1-m12-l2-e1: is "free under bail" wrong in every variety? It is sometimes seen in Indian English.
- c1-m12-l2-w3: "The acquitting of the two officers" (gerund) is not listed. Is it really unnatural?
- c1-m11-l4-e2: is "went to exile" wrong beyond doubt?
- c1-m11-l4-e3: is "made a crackdown" wrong beyond doubt?
- c1-m11-l2-e3: is "Her rural constituency includes..." too close to the card "She represents a rural constituency in the north"? I kept it.
- c1-m12-l3-w3: "forfeited" is a bare -ed with only the no-doubling trap. I did not change it to forfeiture, because "the forfeit of" (noun) could also be accepted.

## C1, модули 13–14 — `english-c1-x07.json`

- c1-m14-l3-e3: is "brandings" wrong beyond doubt? It is marked [U], but plurals turn up in marketing jargon.
- c1-m13-l5-w3: "on a purely ___ basis". Check that "charity" (like "a purely volunteer basis") is not acceptable.
- c1-m13-l5-w2: "a quiet but effective form of ___". Could "advocating" with no object pass?
- c1-m14-l1-w1: "investigatory journalism" is listed as a rare twin. Keep it or drop it?
- c1-m14-l5-e1: censured -> censored is a meaning mistake, decided by "so that no military secrets would leak". The distractor "sentenced" is weak.
- c1-m13-l4-e3: "delete the stigma". Is this a believable learner error? Check that "cancel the stigma" is not acceptable.
- c1-m14-l3-e1 (new): "The bank's sponsorship of the festival has lasted ..." Is it natural?
- c1-m14-l3-e2 (new): check that "take valuable exposure" is impossible.
- c1-m14-l2-e2: "disinformed" as a wrong option. It is a rare real verb form.
- c1-m13-l2-e2: "were expatriated back home". The verb expatriate means to banish; check it as a meaning error.

## C1, модули 15–16 — `english-c1-x08.json`

- c1-m16-l2-e3: "monography" is a rare or obsolete word in the OED. Is it wrong beyond doubt in edited writing?
- c1-m15-l3-e1: Merriam-Webster gives "handwriting" an archaic sense, "manuscript". Does "The handwriting of her first novel was found" read as plainly wrong?
- c1-m15-l5-e2: is "her calm landscapes are timely" excluded beyond doubt by "trends come and go, but"? It is a meaning error only.
- c1-m15-l5-w3: should the twin "poignance" be accepted? I believe learner's dictionaries list only poignancy.
- c1-m16-l4-e1: "Retired people constitute almost half of the town's population" has the same frame as the card ("Women constitute nearly sixty percent of the sample"), but different content. Is it a near-copy?
- c1-m16-l5-e1: "a program where foreign students live..." and "in which" are also correct. Neither is offered; check that the step 2 options are still fair.
- c1-m15-l4-e3 (new): check that no other word gives a repair, and the Uzbek "kadrlar tizmasi".
- c1-m16-l3-e1 (new): is "Learning is greatly facilitated when classes are small and friendly" natural, and is "is facilitating" wrong in every reading?
- c1-m15-l1-w3, c1-m15-l4-w2, c1-m15-l4-w3, c1-m16-l5-w1, c1-m16-l5-w2: these are plain -s plurals (with the variants accepted) or bare -ly adverbs. They are easy for C1, but the noun and adverb lessons give no better forms. A stronger task is welcome if one exists.

## C1, модули 17–18 — `english-c1-x09.json`

- c1-m17-l2-e3: is "brought an analogy" wrong beyond doubt? "made an analogy" is acceptable but is not offered.
- c1-m18-l5-e3: could "acts of diversion" be read as a military feint (a diversionary attack)?
- c1-m18-l1-e1: "carried heavy casualties". "took heavy casualties" is also correct but is not offered.
- c1-m18-l4-e3: is "coupe" a realistic misspelling of "coup"?
- c1-m17-l4-e3: "With typical overstatement..." - check that no ironic reading saves "overstatement".
- c1-m18-l3-e3: "Excavating an ancient grave, the team found..." - "Having excavated" is also correct but is not offered.
- c1-m18-l3-w2: Merriam-Webster also has "chronologic"; c1-m18-l1-w1: "sieged" (rare). Neither is listed.
- c1-m17-l3-e3: "undoubtly" is a non-word as the marked mistake, which the brief allows.

## C1, модули 19–20 — `english-c1-x10.json`

- c1-m20-l5-e2: is "*carries* a striking resemblance" wrong beyond doubt? It turns up now and then in print; bear, have and show are the standard verbs.
- c1-m19-l2-e3: is "The golden *domed* of the palace" a mistake learners really make? I found no better fair task for dome, facade, pillar or atrium.
- c1-m19-l2-e1: is "scaffoldings" accepted anywhere? Oxford and Cambridge mark scaffolding as uncountable.
- c1-m19-l3-e1: confirm that "Under the terms of our tenants" is impossible.
- c1-m20-l3-e3: does anyone accept "drawback at" or "drawback from"?
- c1-m19-l5-w2: will learners type "topography map"? It would be marked as the unchanged base.
- c1-m20-l4-w2: "profoundness" is accepted (Merriam-Webster has it); remove it if no learner's dictionary does.
- c1-m20-l5-w1: Merriam-Webster also lists "homogeneousness", which is not accepted. Check whether it should be.
- c1-m20-l3-e2: does any modern dictionary accept perspective in the sense "prospect"?
- c1-m19-l4-w2 and w3 (gridlocked, carpooling) are fairly easy, but this lesson's words allow little else.

## C2, модули 1–2 — `english-c2-x01.json`

- "Confusable pairs" are 6 different pairs (tacit/taciturn, credible/credulous, rupture/rapture, indignity/indignation, forbidding/foreboding, wary/weary). I read each pair as its own line item of table 4.6, so no kind is over the cap. If the coordinator counts them as one kind, convert c2-m02-l4-e2 (for example to "*For* the organizers' consternation" -> To).
- c2-m02-l3-e3 "livid *on* us" -> with: a learner may repair it by tapping "livid" and changing it to "hard" or "harsh" ("was hard on us"). These are not synonyms, so I kept it. "livid at" is not among the options.
- c2-m01-l1-w1 and c2-m01-l5-w1: magnanimousness and obstinateness are in Merriam-Webster or Collins, not in the learner's dictionaries, so I did not list them. Please confirm.
- c2-m01-l2-e3: is "a hostile worded letter" impossible, and is "belligerently worded" natural?
- c2-m01-l5-e3 "unwavered" and c2-m01-l1-e3 "unassumed" are marked as non-standard. The explanation says waver is intransitive, which simplifies the reason.
- c2-m02-l5-w2 "a dull, ___ job": does the rare adjective "monotone" also fit?
- c2-m02-l2-w2 "The ___ state": I treat "lamented" as unnatural here.
- Uzbek: «ko'rmaganga olib, iltifot ko'rsatishdi» (c2-m01-l1-e1), «maydakashlarcha izoh berdi» (c2-m01-l4-e3), «adovatli janjali» (c2-m02-l3-w3), «shodlanib xabar berardi» (c2-m02-l1-w2).

## C2, модули 3–4 — `english-c2-x02.json`

- c2-m03-l3-e2: is "adulations" wrong beyond doubt? It is marked uncountable in learner's dictionaries, but literary plurals occur.
- c2-m03-l5-e3: confirm "a raising intonation" is non-standard.
- c2-m04-l2-e2: confirm "make legal proceedings" is wrong; bring, take, start and institute are the standard verbs.
- c2-m04-l3-w1: "the mentioned letters" is not accepted; "above-mentioned" is.
- c2-m04-l3-w2: "pursuance" is formal legal English. Check that "in pursuit of the new traffic regulations" is unacceptable here.
- c2-m04-l1-w1: could "expediently" still pass in the new sentence?
- c2-m04-l1-w3: is "The commencing of the new academic year" really unnatural (not accepted)?
- c2-m04-l4-w1: "deferent" (rare twin of deferential) is not accepted.
- c2-m04-l3-e2: the error "a clause whereas" - is it a believable learner mistake? "whereby" is also correct but is not among the options.
- c2-m04-l1-e3 and c2-m03-l4-e2 (instead of + -ing, keep on + -ing): the pattern belongs to the governing phrase more than to the lesson word. They were kept because both lessons' other words are already used.

## C2, модули 5–6 — `english-c2-x03.json`

- c2-m05-l3-e3: "empirism" as the marked mistake. The OED lists it as obsolete or rare, and learner's dictionaries do not have it. Is it safely wrong?
- c2-m05-l3-w3: could "doctrinaire dispute" be accepted? "Doctrine dispute" as a noun adjunct also needs checking.
- c2-m06-l5-w1: is the "even to those who knew him best" clue enough to exclude "fathomable" for every learner?
- c2-m06-l4-w3: retrospectively in the legal sense "with retroactive effect", against the card meaning "взгляд в прошлое". It is the same family; check that this is acceptable.
- c2-m06-l4-e1: deleting "envision" would also give a correct sentence ("She could not live anywhere..."). I kept the task because no learner would delete the lesson word.
- c2-m05-l1-e1: is "moralism" safely wrong in "questions about the moralism of lying"?
- c2-m05-l4-w2: divineness (Merriam-Webster) is not listed. c2-m06-l5-w3: enigmatical is not listed.
- Uzbek to check:
  - c2-m06-l5-e3 "jumboqni keltirib chiqardi"
  - c2-m06-l2-w3 "birinchi asosi" for premise
  - c2-m05-l2-w3 "yuksalib ketgandek noyob bir holat"
  - c2-m06-l3-w2 "mahalliychilik"
- c2-m05-l5-e3: is "are" too easy to rule out as a wrong option? "having" and "has" are the main competitors.

## C2, модули 7–8 — `english-c2-x04.json`

- c2-m08-l2-e2: is "pun with the word X" an error beyond doubt? Dictionaries give "pun on"; check edited usage.
- c2-m07-l2-e1: is "a lull of the talks" clearly wrong? ("a lull of two hours" is fine.)
- c2-m08-l3-w2: is "avoids cheap sentimentalizing" natural enough that it should be listed?
- c2-m07-l1-w3: could a learner fairly type "luminance" in "a star's true ___"? I did not list it: it is a photometric term and not a derivative of luminous.
- c2-m07-l3-e2: the mistake is the preposition with "state" rather than a pattern of "pristine" itself, and "far from any port" is slightly stiff.
- c2-m07-l4-e1: "recoiled of" is not a typical calque (Russian «от» gives from); the task is fair but not very tempting.
- c2-m08-l1-e1: the inner-dialogue reading of "dialogue". Without "with himself" I judged it wrong.
- c2-m07-l2-w2: cacophonic is not listed (it is not in learner's dictionaries).
- Uzbek: marsiyaona (c2-m08-l1-w1), hissiyotbozlik (c2-m08-l3-w2), muzokaralarda qisqa sokinlik (c2-m07-l2-e1; maybe "tanaffus" would be more natural), and the noun rendering in c2-m08-l4-w1 ("ruhiy poklanish bo'lib chiqdi" for an adjective).
- c2-m07-l5: two of the three word_form tasks are -ness (gauntness, haggardness), because the lesson's nouns have no fair derivatives.

## C2, модули 9–10 — `english-c2-x05.json`

- c2-m09-l3-w2: should "reconciliation" be accepted? It does not come from conciliatory by morphology, but it fits the gap.
- c2-m09-l1-w2: "hegemonistic": is it in learner dictionaries? Also check "hegemonical".
- c2-m10-l1-e2 (signed his will *in* duress): "in distress" also gives a grammatical sentence. Also, the p05 c1 already trains "under duress".
- c2-m10-l3-e2 (last will and *testimony*): p05 c1 trains the same idiom with the other gap.
- c2-m09-l2-e3: complicit in is also trained by p05 c3 (now with a different sentence). Is "fully complicit" natural?
- c2-m09-l2-e1: are "a secret setback" and "a secret drawback from every firm" clearly impossible? Could "rollback" (a price rollback) be read as acceptable here?
- c2-m10-l1-e1: is "until counsel arrived" (bare counsel, outside the courtroom) natural to a native ear?
- c2-m10-l2-e2: is "an extensive circumstance" wrong beyond doubt?
- c2-m10-l4-e1: "explicit drugs" is ruled out by meaning only; check that it is acceptable as a wrong option.
- c2-m09-l5-e3 and c2-m10-l5-e3: are "do a plebiscite" and "do reparations" never used, even informally?

## C2, модули 11–12 — `english-c2-x06.json`

- c2-m12-l1-e3: is "paradigm turn" plainly non-standard, given phrases like "the cultural turn"? Are "move" and "jump" impossible?
- c2-m12-l3-e2: does any dictionary still give "solvable" the sense "dissolvable" (archaic)?
- c2-m11-l3-e1: could a learner tap "squandered" (as in "traded/exchanged his inheritance for")? I judged this unlikely.
- c2-m11-l4-e2: "intrinsic for" is non-standard. "intrinsic in" exists but is not among the options.
- c2-m11-l2-e2: "wealthiness" is rare (Merriam-Webster). Is it clearly wrong in "redistribution of ___"?
- c2-m12-l3-w1: Merriam-Webster and Collins list "viscousness", but the learner's dictionaries do not, so following the brief it is not accepted. Decide whether to accept it.
- c2-m11-l5-e2: could "the bank disposes the money" be read as archaic "dispose" (arrange)?
- c2-m12-l2-e1: is the Uzbek "samoviy navigatsiya" natural, or would "astronomik navigatsiya" be the usual term?
- Uzbek: c2-m11-l5-e3 "sindikat orqali berildi"; c2-m12-l4-w2 "kloun-baliq, dengiz aktiniyasi"; c2-m11-l4-w3 (new) "amortizatsiya qilishni ma'qulladi".
- c2-m11-l4-w3 (new): is "a thirty-year amortization of the debt" natural finance English?

## C2, модули 13–14 — `english-c2-x07.json`

- c2-m14-l3-e1: Is "The young deer was pounced on by a hungry tiger at the river" natural enough?
- c2-m14-l1-e2: Could "heavy sleets" pass as a poetic plural?
- c2-m14-l5-e2: Is "silted *down*" acceptable? Deleting "down" also gives a correct sentence ("the harbor silted").
- c2-m13-l4-e1: Could "feel" -> "act/nod" + "drowsily" be read as a second place to click?
- c2-m13-l5-e3: The decrepid/decrepit task is a pure spelling mistake. Is that acceptable at C2?
- c2-m13-l3-w2: Is larynx -> laryngitis acceptable as standard morphology?
- c2-m13-l2-e3: Is "regimental" safely wrong as a noun in "a daily regimental of pills"? ("regime" would be right but is not offered.)
- c2-m14-l5-e3: Is "windmill below the old bridge" absurd enough to rule out?
- c2-m14-l4-w1: Merriam-Webster also lists "cataclysmal". I did not add it to accepted.
- c2-m13-l3-w1: I kept "vertebras" in accepted (Merriam-Webster variant). It costs nothing to accept it.

## C2, модули 15–16 — `english-c2-x08.json`

- c2-m15-l1-e3, "the *land* gentry" -> landed: "land gentry" occurs as a compound noun in some translated history writing. I kept it because the fixed phrase is "landed gentry" and I found no clean alternative. Please confirm or rebuild.
- c2-m15-l5-e1, "ingratiated herself *to*": "to" occurs in journalism. Dictionaries give only "with". Is it wrong beyond doubt?
- c2-m16-l1-e1, "religious *strifes*": Merriam-Webster still lists a countable sense of strife; learner's dictionaries mark it uncountable.
- c2-m15-l4-e3, "fell *in* disrepute": occasionally seen. Is it clearly nonstandard?
- c2-m15-l4-w1, notoriety: Merriam-Webster has "notoriousness" as a run-on entry. It is not listed in accepted.
- c2-m16-l4-w1 and c2-m16-l4-w2: renouncement and contriteness appear in Merriam-Webster and Collins but not in learner's dictionaries. Neither is listed.
- c2-m16-l3-e2: is "ways to circumventing" clearly wrong (a wrong correction)?
- c2-m15-l2-w1: "observation of religious holidays" is dated; a learner who types it is marked wrong.

## C2, модули 17–18 — `english-c2-x09.json`

- c2-m17-l2-w2: "The geneses of these two festivals were quite different" - is the rare plural natural enough, or should the task be dropped?
- c2-m17-l2-w1: is "ceasing" (now accepted) acceptable, or is it so unidiomatic that it should be removed?
- c2-m18-l2-e2: "extraneous for the case" - is it wrong beyond doubt?
- c2-m17-l2-e1: "delay the outset of heart disease" - is it wrong beyond doubt?
- c2-m18-l3-e2: is "Having pinpointed" the only natural repair? "Pinpointing" would also be correct, but it is not offered as an option.
- c2-m18-l5-w1: comic "timing" - ru «умение держать паузу», uz «hazilni ayni vaqtida aytish mahorati».
- c2-m17-l3-e1 and c2-m17-l5-e3: two tasks still use the same "-ing + by" passive template. They are fair but easy to recognize.
- c2-m18-l1-e3: "a plethora of resource" is the least demanding task in the file.
- c2-m18-l5-w3: should the variant spelling "idiosyncracies" be accepted? I did not list it.

## C2, модули 19–20 — `english-c2-x10.json`

- c2-m19-l3-w3: "collaged" as a verb. Merriam-Webster and Collins have it; the learner's dictionaries may list only the noun.
- c2-m19-l2-w2: are crescendoes and crescendi right as accepted plurals? With every plural accepted, this task, porticoes (c2-m19-l3-w1) and medleys are easy. The noun lessons m19-l2 and m19-l3 give few demanding forms.
- c2-m19-l3-e3: the plural "effigys" is easy for C2.
- c2-m20-l2-e2: "passed through" would repair the sentence by inserting a word, but the learner still taps "passed". Is "underwent an ordeal" natural enough?
- c2-m20-l1-e1: can "jealous in his efforts" (the archaic sense "vigilant") be defended?
- c2-m20-l3-w2: should "unsurpassable" be accepted next to "unsurpassed"?
- Twin nouns I left out of `accepted` because I believe no learner's dictionary lists them: austereness (c2-m19-l1-w1), spontaneousness (c2-m19-l4-w1), intractableness (c2-m20-l2-w2). Please confirm.
- c2-m19-l2-e1: "sonata of" is weaker than a calque, and "sonata for" is already trained by the collocation task c2-m19-l2-c1 in english-c2-p10.json.
- Uzbek to check: c2-m20-l4-e1 ("gangib qoldi"), c2-m19-l5-e3 ("ich-ichidan ta'sirlanib munosabat bildirdi"), c2-m20-l5-w3 ("sog'lom sergaklik uyg'otgan").
