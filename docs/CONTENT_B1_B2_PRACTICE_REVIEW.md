# Задания практики B1 и B2 — что показать носителю

Приложение к [CONTENT_B1_B2.md](CONTENT_B1_B2.md#задания-практики). Задания написаны и вычитаны Claude; носитель узбекского и преподаватель английского их не читали. Ниже — замечания редакторов по каждому файлу: места, где редактор принял решение, но просит взгляд носителя (спорный «неправильный» вариант сочетания, дополнительный допустимый порядок слов, узбекская формулировка). Это не список ошибок: всё перечисленное проходит проверки, а явные ошибки редакторы исправили сами.

Замечания приведены на английском, как их записали редакторы; ключ задания (`b1-m03-l2-c1` — курс, модуль, урок, тип и номер: `c` — сочетание, `s` — предложение, `d` — диктант) находится поиском в файле пакета и в админке («Задания практики»).

Как читать замечания:

- **«confirm … is wrong enough / is rejected»** — про неправильный вариант в сочетании: редактор считает, что так по-английски не говорят, и просит подтвердить. Если носитель считает вариант допустимым, вариант заменяют.
- **«confirm the added order»** — про предложение: редактор добавил ещё один допустимый порядок слов (ученик, собравший его, получит «верно»). Лишний допустимый порядок ученику не вредит; убрать его стоит, только если он звучит неправильно.
- **«uz … check naturalness»** — узбекский перевод или объяснение верны по смыслу, но возможен более естественный вариант.


## B1, модули 1–2 — `english-b1-p01.json`

- `b1-m02-l1-c2` distractor `with` ("got engaged with a young doctor"): learner's dictionaries mark it as an error, but it is heard in Indian English. Kept as the main ru calque.
- `b1-m02-l1-c3` distractor `take` ("take a divorce"): not British or American, but exists in Indian English; it is the calque of «взять развод». I found no safer distractor that is still tempting ("do a divorce" and "have a divorce" are no better).
- `b1-m02-l2-c1` distractor `gave` ("gave me a big favor"): I judge it non-standard; confirm.
- `b1-m01-l2-d2` uz "Bu hayot va o'lim masalasi": the set idiom is "hayot-mamot masalasi". I kept the card's word o'lim.
- `b1-m02-l1-d3` uz "O'g'il jiyanim va qiz jiyanim biz bilan yashaydi": heavy, but follows the cards.
- `b1-m02-l4-s1` uz "taksida borishni" (my change): confirm it is preferred to "taksi olishni".
- `b1-m01-l5-s3` ru «Я восхищаюсь им за его упорный труд»: slightly bookish. Also decide whether "For his hard work I admire him" should count as a second order; I treated the phrase as a complement of admire. *(Решено после ревью: этот порядок добавлен в `accepted`.)*
- `b1-m02-l1-s1` uz "Uning ota-onasi bilan o'zaro munosabati yaxshi": is "o'zaro" natural here? Also decide whether "With her parents she has a good relationship" should count as a second order. *(Решено после ревью: этот порядок добавлен в `accepted`.)*
- `b1-m01-l3-s3` uz "Kutib turganingiz mulohazali ish bo'ldi" and `b1-m01-l3-s1` uz "otasi" against "otalari" for "their father".
- `b1-m01-l4-d2` uz "qora" for "dark" hair (ru has «тёмные»).

## B1, модули 3–4 — `english-b1-p02.json`

- b1-m04-l4-c1: uz "ta'mi juda boy" / "«boy» ta'm" for rich flavor — common in advertising, but check that it reads as literary Uzbek. Also confirm "a very costly flavor" is impossible enough as a distractor.
- b1-m03-l4-s3: confirm the two added orders are wanted as accepted answers.
- b1-m04-l4-s3: confirm "eat eggs raw" as an accepted answer.
- b1-m04-l4-s2: confirm the decision not to accept "So why is this soup salty?".
- b1-m03-l3-d2: uz "Sumkalaringizni ochib bo'shatdingizmi?" follows the card (ochib bo'shatmoq) and sounds slightly bookish.
- b1-m03-l2-c1: "for the party" is given as "к празднику" / "bayramga" — natural, but not literally "вечеринка / ziyofat".
- b1-m04-l5-d1: check that the speech engine reads "buffet" as /bəˈfeɪ/.
- b1-m04-l1-d1: with a British voice "sauce" sounds like "source"; it makes no sense in the phrase, so I kept it.
- b1-m03-l3-d1 and b1-m04-l5-c3: "o'chirgich" (switch, as on the card) and "o'chirg'ich" (eraser) are spelled differently — check the norm for each.

## B1, модули 5–6 — `english-b1-p03.json`

- b1-m05-l3-c2: distractor `make` in "The doctor will ___ you an injection". It is the typical calque of «сделать укол» and wrong in this double-object construction, but "an injection is made into the muscle" exists in medical prose. Confirm it is wrong enough.
- b1-m06-l4-c2: distractor `to` in "we are going ___ an excursion to the mountains". Non-standard, but heard from non-natives; confirm.
- b1-m05-l2-c2: confirm "heavy illness" and "hard illness" are unacceptable in every variety. Also whether "After his serious illness" reads as naturally as "After a serious illness".
- b1-m05-l2-s3: "She almost felt so weak that she fainted" is grammatical but means something else; I left it unlisted.
- b1-m05-l3-s2: "healing well" as a single fragment makes the task a little easy (5 fragments); acceptable?
- b1-m06-l2-c1: uz "tezlik cheklovi" kept; a native may prefer "ruxsat etilgan tezlik".
- b1-m05-l3-s1: uz prompt "kasallikni ... tuzatishni" follows the card (cure = tuzatmoq). "Davolash" is more usual but is the card word for treat; confirm.
- b1-m06-l3-s2: uz "ayni bir aviakompaniya bilan uchaman" - confirm it reads naturally.

## B1, модули 7–8 — `english-b1-p04.json`

- b1-m07-l2-s2: "For students this job is not suitable" is grammatical as a contrastive fronting; not listed, following the brief's rule for complement phrases. Decide whether to add it to `accepted`. *(Решено после ревью: этот порядок добавлен в `accepted`.)*
- b1-m08-l4-s2: "The store has all its prices reduced" is grammatical (causative or stative), but a different meaning; not listed.
- b1-m07-l2-c3: "is passing an internship" - the most plausible distractor in the file (ru/uz calque); I judge it wrong in standard English.
- b1-m08-l5-c3: "made of genuine skin" - not said of a jacket, though "genuine snakeskin" exists.
- b1-m08-l2-s3: uz prompt "Bu katta pul summasi." - correct, but a native may prefer "Bu katta miqdordagi pul."
- b1-m08-l1-c2: uz translation "bankdan ko'p pul qarzdor" - check it reads naturally.
- b1-m08-l5-s3: ru «Ваша обратная связь…» follows the card word and is bureaucratic; kept because «отзыв» is another card of the lesson (review).
- b1-m08-l5-d3: uz "taomlarning xilma-xilligi juda katta" mirrors the card; "taomlar juda xilma-xil" would be more idiomatic.
- b1-m07-l2-c1: ru «не соответствовал требованиям этой работы» - slightly stiff.

## B1, модули 9–10 — `english-b1-p05.json`

- `b1-m10-l5-c3`: "because of a technical ___" problem / trouble / damage. The distractors are wrong only through the article ("a trouble", "a damage"); "technical trouble" without the article exists. I kept it because it is ungrammatical beyond doubt and the explanation says why. This is the weakest collocation task in the file.
- `b1-m10-l2-c3`: "the folder ___ your desktop" on / in / at. Confirm "in your desktop" is rejected; it appears in non-native writing because of the Desktop folder.
- `b1-m09-l2-c1`: confirm "enroll to a course" is rejected.
- `b1-m09-l4-d2`: "This game trains memory and logic". "Train" without "your" is acceptable but slightly bare; "trains your memory" would be more idiomatic.
- `b1-m10-l4-c1` uz: «test natijalariga kirish huquqi» keeps the card's translation of access; it is slightly bookish for results.
- `b1-m10-l5-c2` uz: «Yangi versiyaga bepul yangilashingiz mumkin» drops the object; «o'tishingiz» would be the more idiomatic verb, but the card's word was kept.
- `b1-m10-l4-s3` uz prompt: «boshqa foydalanuvchi nomini» can formally be read as "another user's name". The fragments leave no doubt, so I left it.
- `b1-m09-l3-d2` ru: «в коротком тесте» follows the card for quiz; plain «в тесте» may read more naturally.

## B1, модули 11–12 — `english-b1-p06.json`

- b1-m11-l3-c2: distractor `feeling` ("a good feeling of rhythm") - the calque, kept by analogy with the brief's "feeling of humor"; "a feeling for rhythm" exists with another preposition.
- b1-m11-l2-c2: distractor `general` - "general character" exists in another sense, but is nonsense in "... of the story is a young doctor".
- b1-m12-l5-s1: confirm the added order reads as acceptable.
- b1-m12-l5-c1: `to` ("go to a hike") and b1-m12-l2-c2: `won` ("won her opponent") - typical learner calques, non-standard; confirm they are wrong enough.
- b1-m11-l5-c1: `untie` this puzzle - fine for an ordinary puzzle.
- b1-m12-l2-s3: "heavy defeat" leans British, though dictionaries of both varieties have it.
- b1-m12-l1-s3: "athletics" in the card's British sense (track and field).
- b1-m11-l3-d1: uz "Bastakor chiroyli ohang yozdi" follows the card (melody = ohang); "kuy" would be more idiomatic but is the card word of `tune`.
- b1-m12-l3-d3: uz "Mashg'ulot bir soat davom etadi" drops "training", kept as the natural wording.

## B1, модули 13–14 — `english-b1-p07.json`

- `b1-m13-l2-c2`: is "the spider's net" wrong enough as a distractor?
- `b1-m13-l5-c3`: "This rare bird is now an endangered kind / sort" — unidiomatic rather than ungrammatical. A safer set would be species / view / tour (view is the calque of «вид», tour sounds like Uzbek «tur»).
- `b1-m13-l3-s3` and `b1-m14-l2-s3`: are the fronted orders I added acceptable ("For swimming the waves were too high", "In space is there any air")?
- I did not list a fronted place phrase for the other questions (`b1-m13-l3-s2` "On the horizon can you see that ship?", `b1-m14-l2-s2`, `b1-m13-l1-s1`). The brief treats a question as blocking this, and their prompts do not invite it. *(После ревью: для `b1-m14-l2-s2` порядок «In the universe are we alone» добавлен в `accepted`; два других задания — как было.)*
- `b1-m14-l3-s2`: "To clean windows this liquid is used" is not listed; I judged it too stilted to count.
- `b1-m13-l4-c1` uz: «Dovul … sohilga yopirildi» for "hit the coast".
- `b1-m14-l3-c2` uz: «qabartma kukuni» for baking powder.
- `b1-m13-l5-c2` uz: «Quyosh sistemasi» is kept; «Quyosh tizimi» is the other current term.
- `b1-m13-l1-d2`: "wildlife" is translated with the card's «дикая природа» / «yovvoyi tabiat»; «дикие животные» would be more exact.

## B1, модули 15–16 — `english-b1-p08.json`

- **b1-m16-l4-c1**: distractor `than` ("prefer walking than taking the bus") - a marked error, but heard colloquially.
- **b1-m16-l4-c3**: distractor `causes` ("for obvious causes") - unidiomatic near-synonym; confirm it is wrong enough.
- **b1-m15-l5-c1**: distractor `on` ("participated on the competition") - "participate on" exists in American English with panel / committee / team; I judged it impossible with "competition".
- **b1-m15-l5-c3**: distractor `since` ("is valid since the end of the month") - non-standard, but a learner could read it as "valid from".
- **b1-m15-l2-s3**: only "Nobody knows when the trial will start" is accepted; the fronted "When the trial will start nobody knows" is grammatical but marked, and I did not list it.
- **b1-m15-l3-s2**: "This team will investigate a special accident" is buildable from the fragments but has another meaning, so it is not listed (rule 9).
- **b1-m16-l1-s1**: ru prompt «Мы очень рады нашему новому дому» for "delighted with our new house" - acceptable; «очень довольны нашим новым домом» might be more natural.
- **b1-m15-l3-c1**: uz explanation uses `budilnik` (common loan) rather than `uyg'otkich soat`.
- **b1-m16-l3-d2** (new): uz `vahima uyg'otdi` for "caused panic" - check the collocation.

## B1, модули 17–18 — `english-b1-p09.json`

- `b1-m17-l2-s2`: is "Afterwards, do you know what happened?" a fair second order? I judged it not equivalent and left it out.
- `b1-m17-l1-s3`: confirm "Together, let's watch the sunset" deserves to be accepted.
- `b1-m17-l2-s1`: confirm "They live in a house recently built" (no complement after the participle) is acceptable as a full sentence.
- `b1-m17-l2-c2`: the distractor `long` is not strictly the same part of speech as `last` / `spend`.
- `b1-m17-l3-c2`: confirm "there is still place for improvement" is rejected in both British and American English.
- `b1-m17-l4-c3`: does "on purpose" count as the card's sense «цель, назначение»?
- `b1-m18-l2-c2`: cotton picking as a topic for the Uzbek audience. Also, the card example uses cotton as fabric and the task uses the plant; the ru / uz words cover both.
- `b1-m18-l5-s1`: the uz prompt relies on the dash in "Bu — yilning eng zerikarli ma'ruzasi edi" so it is not read as "this year's"; ru «лекция года» — check naturalness.
- `b1-m17-l4-d3`: uz "Qor yog'ishining ozgina ehtimoli bor" — left as is; check naturalness.
- `b1-m18-l4-d1`: ru «У нашего офиса центральное расположение» — acceptable but slightly formal; left.

## B1, модули 19–20 — `english-b1-p10.json`

- `b1-m20-l4-s3`: confirm the three lenient orders - "she works with children mainly", "mainly she works with children", "she works with mainly children".
- `b1-m20-l5-s2`: confirm "we never have been in such a situation" as an accepted order (the explanation still teaches never between have and been).
- `b1-m20-l3-s3` (new): confirm that "Besides, we have no choice anyway." has no second order; uz "baribir boshqa ilojimiz yo'q" uses the idiom, with "boshqa" added.
- `b1-m20-l1-d3` (new): "He had a car yet decided to walk instead" - confirm it reads naturally without a comma before yet.
- `b1-m20-l3-d2` and `b1-m20-l4-d1`: opening "Meanwhile" / "Unfortunately" with no comma - kept; rewrite if the house rule is stricter.
- `b1-m19-l2-d3` uz "yuqori tugmasini qadang": "tugma qadamoq" can also mean to sew a button on; "tugmasini soling" is the alternative.
- `b1-m19-l4-c2`: the distractors preservation / conservation are look-alikes above B1 level; the task is valid but tests word form more than collocation.
- `b1-m19-l2-s2`: "the seller" or "the florist"?
- `b1-m20-l1-c3` uz "Eski stuldan tashqari xona bo'm-bo'sh edi": correct; "Bitta eski stulni hisobga olmaganda…" may read better.

## B2, модули 1–2 — `english-b2-p01.json`

- `b2-m01-l5-c1` outlook on / at / **to** life: "outlook to life" is non-standard (regional, by analogy with "attitude to life"; the uz calque of -ga). Kept as the typical calque; confirm that it is not acceptable, otherwise replace `to` with `by`.
- `b2-m01-l2-c1` "black with envy": kept as the ru calque («чёрная зависть»); confirm it cannot pass.
- `b2-m02-l5-s3` uz prompt "Ko'ngil qo'ygan kasbini topishi uchun unga yillar kerak bo'ldi" (card wording of vocation; logically "the profession he had already set his heart on") - check naturalness.
- `b2-m01-l2-d2` uz "Nega bunchalik badgumonsiz?" - badgumon (card word) is closer to "suspicious" than "cynical"; -siz may be read as "without".
- `b2-m01-l3-c2` ru «С вашей стороны было очень внимательно принести еду для всех» - acceptable, slightly stiff («любезно / заботливо» would be smoother but leaves the card word).
- `b2-m02-l3-c3` uz "Belgilangan tezlikdan oshib ketgan haydovchilar" (card verb; everyday Uzbek says "tezlikni oshirgan").
- `b2-m01-l4-d1` "Children imitate the conduct of adults" - correct but formal; kept because it carries two target words.
- `b2-m01-l2-d1` ru «импульсивен и незрел» - short forms are bookish; correct.

## B2, модули 3–4 — `english-b2-p02.json`

- b2-m03-l3-s1: is "Never has this company paid a dividend" wanted as an accepted order? It is correct but formal.
- b2-m04-l3-s2 and b2-m04-l4-s2: the added fronted orders ("In the dark, can this sensor ...?", "Without light, how do these tiny organisms survive?") - confirm they are acceptable.
- b2-m03-l4-s1: "Far more economical is the new engine" (literary inversion) is not listed - confirm it should stay out.
- b2-m04-l4-s3: "Around the nucleus, electrons move" is not listed; I treated "around the nucleus" as completing the verb.
- b2-m04-l1-c3: "do predictions" is informal and non-standard, the same type as the brief's "do a mistake" - kept.
- b2-m03-l1-c1: "personal sector" was once a term of UK national accounts (households); nobody "works in" it, so I kept it, but the explanation says «о секторе экономики так не говорят».
- b2-m03-l4-c3: "heavily profitable" is kept as a distractor - extremely rare, but worth a glance.
- Uzbek, b2-m03-l3-c3: «neft narxi ustida spekulyatsiya qilib» - or «narxi bilan»? The card's own example avoids the construction.
- Uzbek, b2-m03-l1-c2: «retsessiyaga kirdi».
- Uzbek, b2-m04-l3-s2: «datchik» (Russian loan) against «sensor».

## B2, модули 5–6 — `english-b2-p03.json`

- `b2-m05-l1-c1`: is "compatible to / for the new phones" wrong enough as distractors?
- `b2-m05-l4-c1`: is "vulnerable for attacks" wrong enough?
- `b2-m05-l2-c3`: "ride a drone over the city" — I judged it impossible for the card's camera drone; confirm.
- `b2-m05-l3-s1`: confirm the added order "We need our education system to modernize" should be accepted.
- `b2-m06-l5-s2`: "Your lack of sleep weakens immunity" is grammatical but attaches "your" differently from both prompts, so it is not listed. Confirm.
- `b2-m06-l3-s2`: "In asking for help there is no shame" is a rhetorical fronting, not listed. Confirm.
- `b2-m06-l5-d2`: "Everyone has a different metabolism" — a learner may type "Every one"; I kept it as a basic word.
- `b2-m05-l4-c2`: Uzbek "qat'iy maxfiy" for "strictly confidential" — or is "mutlaqo maxfiy" the more usual phrase?

## B2, модули 7–8 — `english-b2-p04.json`

- b2-m08-l1-c2: is "Parliament received a new law on education" impossible enough? It is the «qabul qilmoq» calque; I kept it.
- b2-m08-l1-c3: confirm "The empire slipped after many years…" cannot be read as "declined".
- b2-m07-l3-s3: confirm the added order "…which are poisonous plants" is acceptable English.
- b2-m07-l1-s1: confirm the added order "…absorb heat more".
- b2-m07-l2-s1: confirm "Only bats are the mammals that can fly" should stay unlisted.
- b2-m07-l1-s2: "In their greenhouse what do they grow?" is not listed; I treated a fronted phrase before a wh-question as not standard.
- b2-m08-l4-d3: "The mandate gives him the power of veto" is good English but a slightly contrived statement; "power of veto" is the more British wording (American "veto power").
- b2-m08-l2-d2: "the names of all candidates" - a native may prefer "all the candidates".
- Uzbek terms kept from the cards: tashlanma (emission), o'simlik qoplami (vegetation), uglerod izi (carbon footprint), merlikka (for mayor, b2-m08-l2-s1).

## B2, модули 9–10 — `english-b2-p05.json`

- `b2-m10-l2-s1`, `b2-m10-l5-s2`: are the added "never should / never would" orders and the inversions acceptable as correct answers? This departs from the brief's "never has one place"; drop the extra orders if the course wants only the textbook position.
- `b2-m10-l4-s2`: is "Convey to your parents my thanks" acceptable with such a short object?
- `b2-m09-l2-c2`: "tell the theft to the police" and "inform the theft to the police" – I judged both wrong.
- `b2-m10-l4-c3`: "cleared out / cleared off the misunderstanding" – I judged both wrong.
- `b2-m09-l1-c2`: uz «qasam ichgan holda» for "under oath".
- `b2-m09-l1-c3`: uz «sudga da'vo kiritmoq».
- `b2-m10-l3-c1`: uz «virusli bo'lib ketdi».
- `b2-m09-l4-c1`: uz «to'sig'ini» for "his fence" (alternatives: devor, panjara).
- `b2-m09-l5-c3`: uz «singan shisha» versus «shisha siniqlari».
- `b2-m10-l4-d3`: uz «hayratdan xitob qildi».

## B2, модули 11–12 — `english-b2-p06.json`

- `b2-m12-l1-c2`: confirm "visit one tutorial a week" is an error for a participant (the «посещать» calque). I judged it wrong enough, but "visit a class" exists for an observer.
- `b2-m11-l5-s3`: confirm the added order "That these letters are authentic nobody doubts" is acceptable.
- `b2-m12-l3-s2`: "From her silence what did you infer" is not listed; I judged the fronted complement of `infer` too marked. *(Решено после ревью: этот порядок добавлен в `accepted`.)*
- `b2-m12-l4-s2`: the topicalized "The notion that money solves everything I reject" is not listed (ordinary object fronting, as in any SVO sentence).
- `b2-m12-l3-c3` uz: «Mulohazalaringiz yo'nalishini tushuna olmayapman» for "line of reasoning" is understandable, but a native may prefer another noun.
- `b2-m11-l2-c1` uz: «masalning qissadan hissasi» — I believe it is idiomatic; confirm.
- `b2-m11-l1-c3` uz: «rassomlik matosiga chizilgan» is the card's word for canvas; confirm it reads naturally.
- `b2-m11-l4-d3` uz: «Hamma davomini kutyapti», a bare «davomi» for "the sequel".
- `b2-m11-l3-s2`: «В каждой главе другое место действия» / «voqea joyi boshqa» for "a different setting" («своё» / «boshqacha» are possible alternatives).
- `b2-m12-l1-s3`: "faculty" = факультет is British usage (as on the card); in American English "the dean of our faculty" reads differently.

## B2, модули 13–14 — `english-b2-p07.json`

- `b2-m13-l5-c3`: "a status sign / mark" — neither is an English compound, but `sign` is the closest distractor in the file.
- `b2-m14-l4-c3`: "the upper / top hemisphere" — not the geographic term, but understandable; confirm both are rejected.
- `b2-m14-l2-c3`: confirm "the senior civilizations" is rejected.
- `b2-m14-l2-c1`: confirm "to remain its cultural heritage" is rejected.
- `b2-m14-l3-c3` uz: «javob berishdek xushmuomalalik ham ko'rsatmadi» — grammatical, but check it reads naturally.
- `b2-m14-l5-s1` and `b2-m14-l5-s3`: the added fronted orders ("Abroad, did you…", "In this desert, nomads have always lived") are grammatical but marked; confirm they should be accepted.
- `b2-m14-l2-c2`: "left a great legacy to future generations" — `to` rather than `for`; check it sounds natural.
- `b2-m13-l5-d3`: "social convention" is rendered «общепринятые правила» / «umum qabul qilingan qoidalar» after the card; «условности» might be more idiomatic.

## B2, модули 15–16 — `english-b2-p08.json`

- `b2-m16-l3-c1`: distractor `on` in "a growing demand ___ organic food". I kept it as the typical calque of «спрос на». "Demand on" does exist in the sense of strain (demand on resources), so please confirm "demand on organic food" reads as an error.
- `b2-m15-l2-s2`: confirm the two added fronted orders are acceptable English.
- `b2-m15-l4-c3`: confirm "offers its guests modern amenities such as a pool" is natural, and that "suggests / proposes its guests amenities" is rejected.
- `b2-m15-l4-c1`: distractor `scores`. "Utility score" is a technical term in another sense of utility; I judged it far enough from this sentence.
- `b2-m15-l5-s3`: "transport hub" against American "transportation hub".
- `b2-m15-l3-d1`: "These premises are for rent" — check naturalness.
- `b2-m16-l3-s3`, `b2-m15-l1-s1`: the fronted orders "From a street vendor we bought these flowers" and "On the outskirts of town we used to live" are not listed. I treated both phrases as verb complements, which the brief calls safe.
- `b2-m16-l3-d2`: "on sale" is translated as «со скидкой / chegirma bilan» (American sense); British usage reads it as simply "available for sale".
- Uzbek worth a native look: `b2-m16-l4-s1` prompt («loyihani amalga oshirib bo'lishiga shubha qilaman»), `b2-m15-l5-d3` («go'zal bo'ladi» for "looks beautiful"), `b2-m15-l2-s3` («kechikib qoldik» for "missed").

## B2, модули 17–18 — `english-b2-p09.json`

- `b2-m17-l5-s2` - confirm "Send us an email alternatively" deserves to be accepted.
- `b2-m17-l5-s3` - confirm "Similarly the other students reacted" and "The other students similarly reacted" as accepted orders.
- `b2-m17-l5-d2` - new text "It is indeed cheaper and moreover it lasts longer": natural when read without commas?
- `b2-m17-l5-d3` - "The room is small and furthermore it is noisy": register (formal linker in an everyday complaint).
- `b2-m17-l2-c3` - distractor `said` ("Nobody said any objection").
- `b2-m18-l5-c3` - distractor `pursue` ("storms pursue a similar pattern").
- `b2-m17-l3-d3` - uz "Inshongiz" (the writer changed it from "Inshoyingiz"): which possessive form of `insho` is standard?
- `b2-m17-l4-c1` - "public transport" is British vocabulary (American: public transportation); it is a collocation sentence, not a dictation, so I left it.

## B2, модули 19–20 — `english-b2-p10.json`

- `b2-m19-l5-c3` widespread among / **between** / inside young people — confirm `between` is unacceptable.
- `b2-m20-l1-c3` determine the cause / **reason** / fault of the accident — "reason of" is the typical calque that learner's dictionaries mark as an error (reason for); kept on that ground.
- `b2-m20-l3-c3` incorporated several games into / **to** / onto her lessons — confirm `to` is unacceptable (dictionaries give only in / into / within).
- `b2-m20-l1-c2` new distractor `to` ("classified to mammals") — confirm it is impossible.
- `b2-m19-l4-c1` uz "hech qanday yaqqol sababsiz" — card word; "ko'rinarli sababsiz" may be more idiomatic.
- `b2-m20-l3-c1` uz "ko'z aloqasini saqlab turadi" — kept; the explanation glosses it.
- `b2-m20-l5-d1` uz "qatnov chastotasi" — kept (card word).
- `b2-m20-l1-s2` uz prompt "…degan xulosa qilingan" for present "concludes" — natural report style, and the fragments allow only `concludes`; say if you want it as "xulosa qilinadi".
