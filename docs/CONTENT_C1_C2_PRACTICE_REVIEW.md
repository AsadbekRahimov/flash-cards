# Задания практики C1 и C2 — что показать носителю

Приложение к [CONTENT_C1_C2.md](CONTENT_C1_C2.md#задания-практики). Задания написаны и вычитаны Claude; носитель узбекского и преподаватель английского их не читали. Ниже — замечания редакторов по каждому файлу: места, где редактор принял решение, но просит взгляд носителя (спорный «неправильный» вариант сочетания, дополнительный допустимый порядок слов, русская или узбекская формулировка). Это не список ошибок: всё перечисленное проходит проверки, а явные ошибки редакторы исправили сами (79 заданий C1 и 92 задания C2).

Замечания приведены на английском, как их записали редакторы; ключ задания (`c1-m03-l2-c1` — курс, модуль, урок, тип и номер: `c` — сочетание, `s` — предложение, `d` — диктант) находится поиском в файле пакета и в админке («Задания практики»).

Как читать замечания:

- **«confirm … is impossible / is wrong / is not used»** — про неправильный вариант в сочетании: редактор считает, что так по-английски не говорят, и просит подтвердить. Если носитель считает вариант допустимым, вариант заменяют.
- **«confirm the added order / should stay accepted»** — про предложение: редактор добавил ещё один допустимый порядок слов (ученик, собравший его, получит «верно»). Лишний допустимый порядок ученику не вредит; убрать его стоит, только если он звучит неправильно. **«I did not list it»** — обратный случай: порядок не засчитывается, потому что меняет смысл или звучит неестественно.
- **«uz … / ru … natural?»** — узбекский или русский перевод, подсказка или объяснение верны по смыслу, но возможен более естественный вариант.

Написания в диктантах, которые решены при подключении и носителю не нужны: британские `despatch`, `fuelled`, `rancour`, `clamour`, `dishevelled`, `byelaw`, `lacklustre`, `gruelling`, `mediaeval` и американское `demagog` засчитываются через `resources/lexicon/en-us-gb.txt`; дефисные `buy-out`, `pre-eminent`, `non-combatants` добавлены в `accepted` своих диктантов (`OTHER_WRITINGS` в `check_practice.php`).


## C1, модули 1–2 — `english-c1-p01.json`

- `c1-m01-l4-c1`: is "hold the temptation" impossible everywhere?
- `c1-m02-l5-c2`: "do" is safe but no learner would pick it. Is there a safe distractor a learner would actually be tempted by?
- `c1-m02-l5-c3`: the ru translation «Мы в огромном долгу перед нашими учителями» leaves out «благодарность». It is natural; please confirm that this is acceptable.
- `c1-m01-l1-c1`: is the ru explanation's wording «Внимание по-английски «платят»» acceptable in style?
- `c1-m01-l1-d1`: please confirm the uz phrase «talaba» for student in this context.

## C1, модули 3–4 — `english-c1-p02.json`

- `c1-m04-l2-c1`: "cruel rivalry" and "hard rivalry" are calques that are not in dictionaries, but a rare literary "cruel rivalry" exists. Confirm that both are clearly wrong.
- `c1-m03-l3-c3`: "in the payroll" is a common learner error. Confirm that it is not accepted usage.
- `c1-m03-l2-d1`: whether the hyphenated writings "multi-task" and "burn-out" should be accepted. *(Пока не принимаются: объяснение задания учит слитному написанию. Если носитель за — `multitask` / `burnout` добавляются в `OTHER_WRITINGS` в `check_practice.php`, затем `--write-accepted` и объяснение правится.)*
- `c1-m03-l1-s2` (uz): "shuhratparastroq" translates "ambitious" but carries a shade of "vainglorious". Check whether an alternative is better.
- `c1-m04-l1-s1`: confirm that the new mid-position order is acceptable as an answer.
- `c1-m04-l5-d1`: "despatch" is a British variant and is not accepted yet. *(Решено при подключении: пара `despatch` / `dispatch` добавлена в `resources/lexicon/en-us-gb.txt`, британское написание засчитывается.)*

## C1, модули 5–6 — `english-c1-p03.json`

- `c1-m06-l2-c3`: is "put clear parameters for the study" impossible? Also, here "parameters" means limits, which is broader than the card's «параметр».
- `c1-m05-l1-c3`: check that "stimulus bundle" is never used.
- `c1-m05-l5-c3`: check that "subsistence planting" is never used.
- `c1-m05-l3-s1`: the ru prompt adds «от банкротства», which is implied by "bailout". Check that the prompt still leads cleanly to the English sentence.
- `c1-m05-l4-s3`: «бюджетное давление» is a little calque-like in the ru prompt.
- `c1-m06-l3-c2`: the uz translation renders "caveat" as "shart" (condition); the card has "ogohlantiruvchi izoh".
- `c1-m05-l1-s1`: the uz prompt "moliyaviy inqirozda ... ayblaydi" is grammatical; "inqiroz uchun" may sound more natural.

## C1, модули 7–8 — `english-c1-p04.json`

- `c1-m08-l1-c3`: confirm that "caught a seizure" is impossible in all varieties.
- `c1-m07-l4-c2`: is "mechanical breaking" (not braking) acceptable as wrong beyond doubt?
- `c1-m07-l5-c3`: "feel" + object + adjective. "Feel it necessary" exists, but I judge "feel notifications intrusive" non-standard.
- `c1-m08-l4-s1`: the listed reversed order "a sign of poor circulation may be cold hands" is grammatical but stilted. Keep it or drop it?
- Uzbek terms: "to'ldirilgan reallik" (`c1-m07-l3-c2`; is "kengaytirilgan reallik" more usual?), "harakatlantiruvchi tizimlar" (`c1-m07-l4-d1`), "qo'shimcha modul" for plugin (`c1-m07-l1-s3`; "plagin" trips the -gin informal-address check), "issiqlik kameralari" (`c1-m07-l4-d2`).

## C1, модули 9–10 — `english-c1-p05.json`

- `c1-m10-l2-c3`: confirm that "put our own compost" and "set our own compost" are impossible.
- `c1-m09-l1-s1`: confirm the new third order, "She did not contemplate giving up once".
- `c1-m09-l2-c3`: "an obsession on detail" — I judge it nonstandard. Check it.
- `c1-m09-l3-c2`: "in the delusion that" — I judge it not standard. Check it.
- `c1-m10-l5-c2`: "recharge" as the correct option, since it is a technical term.

## C1, модули 11–12 — `english-c1-p06.json`

- `c1-m12-l2-c1`: "released under bail" - check that it is clearly wrong in British and American English.
- `c1-m12-l3-c2`: "single confinement" - check that it is not used.
- `c1-m11-l1-s1`: check that the added order "never once did admit" (emphatic "did") is acceptable.
- `c1-m11-l5-s1`: check the added order with "Years ago" at the front.
- `c1-m11-l5-d3`: the uz translation uses "blanka" for "form". It is common but close to a Russian calque.

## C1, модули 13–14 — `english-c1-p07.json`

- `c1-m14-l5-c3`: is "in a government blacklist" (of people) wrong beyond doubt?
- `c1-m14-l5-c2`: is "a serious break of confidentiality" non-standard?
- `c1-m13-l2-c1`: is "indigenous of this region" non-standard?
- `c1-m13-l5-c2`: is "held a fundraising action" not said in standard English?
- `c1-m13-l4-c3` uz: «ajrashish hali ham sharmandalik tamg'asi hisoblanadi» - is it natural, or should it say the stigma lies on divorce more exactly?

## C1, модули 15–16 — `english-c1-p08.json`

- `c1-m15-l2-c3`: confirm that "her debut recital was visited by…" is clearly wrong.
- `c1-m15-l4-c3`: confirm that "fans were allowed to join backstage" (no object) is wrong.
- `c1-m15-l1-c2`: is "trace the contour" natural for drawing a vase from life? ("draw" is not offered.)
- `c1-m15-l3-s3`: is the new uz «kitoblari orqali o'tadi» natural?
- `c1-m16-l5-s1`: is "Albeit with some hesitation, she accepted the offer." acceptable? It is listed as the second order.

## C1, модули 17–18 — `english-c1-p09.json`

- `c1-m17-l2-c2`: is "lethally flawed" really impossible?
- `c1-m18-l3-c3`: the uz translation uses "Ming yilliklar chegarasida". Would "tutashuvida" be more natural?
- `c1-m17-l4-s3`: is the uz prompt "so'zga chechanlik bilan gapirdiki" natural?
- `c1-m18-l5-s3`: uz "Yolg'izlik" for "isolation". Would "yakkalanib qolish" be better?

## C1, модули 19–20 — `english-c1-p10.json`

- `c1-m19-l3-d1`: is the ru «кондоминиум подешевле» natural? «квартиру в кондоминиуме подешевле» may be better, because in American English a condominium is one unit, while Russian «кондоминиум» is the building or association.
- `c1-m20-l2-c3`: is "minuses" acceptable as a deliberately impossible calque distractor?
- `c1-m19-l4-c2`: confirm that "put anchor" and "made anchor" are impossible.
- `c1-m19-l2-s3`: confirm that "We rarely have seen…" should stay accepted. It is grammatical but marked.
- `c1-m19-l5-s2`: confirm that the fronted "As far as the eye can see, the steppe stretches" should stay accepted.
- `c1-m20-l1-s3`: "For the market, it will take months to stabilize" is a marginal fronting; I did not list it.
- `c1-m19-l1-c3` (responsible for) and `c1-m20-l4-c2` (out of sheer curiosity): both train the pattern of a neighboring word rather than the target word's own collocation, and both are easy for C1.
- `c1-m20-l4-c2` uz: «sof qiziqish yuzasidan» - would «faqat qiziqqanidan» be more natural?
- `c1-m19-l2-c1` uz: «konstruktor-muhandis» as the translation of "structural engineer".

## C2, модули 1–2 — `english-c2-p01.json`

- `c2-m01-l3-c3`: is it acceptable that the gap trains "a man of few words" rather than a collocation of taciturn?
- `c2-m01-l1-s2`, `c2-m01-l1-s3`, `c2-m01-l5-s1`, `c2-m01-l3-s2`: confirm that the parenthetical orders I added are acceptable. The `c2-m01-l3-s2` order is literary.
- `c2-m01-l4-s1`: I did not add "I would not, were I less credulous, have lost my savings" because I find it unnatural. Confirm.
- `c2-m02-l1-c2` (uz): check "xurillardi" for a cat purring and "iliq o'choq" for a fireplace.
- `c2-m02-l2-c2` (ru, uz): "в связи с утратой" and "motam munosabati bilan bir haftalik ta'til".
- `c2-m01-l2-c2`: "a vindictive stripe" should be checked by a native speaker, because of "of every stripe".

## C2, модули 3–4 — `english-c2-p02.json`

- `c2-m04-l2-c1`: "swore an affidavit" is chiefly British and Commonwealth usage; American English prefers "swore out" or "signed an affidavit". Confirm it is acceptable as the answer.
- `c2-m03-l1-s1`: the fragments "more" and "people" are separate, so "…people would read it more" is possible. It has another meaning, so I did not list it; check that you agree.
- `c2-m03-l2-d3`: "the lazy" is used as a noun for people; check that it reads naturally.
- `c2-m03-l5-d1`: check the Uzbek «hazil tuyg'usi» against «yumor tuyg'usi».
- `c2-m04-l3-s1`: check that "Hereby we accept…" is acceptable as an accepted order.
- `c2-m03-l3-s1`: in the ru «…заслуживает того, чтобы её отметили», «её» can refer to the person or to the quality; check that it reads well.

## C2, модули 5–6 — `english-c2-p03.json`

- `c2-m05-l2-c2`: the distractor "put" (calque of "tahdid solmoq").
- `c2-m06-l1-c1`: "from his own volition" (rare non-standard).
- `c2-m06-l4-s1`: is "bunchalik kuchli oldindan sezish hissi" natural Uzbek? Would "ko'ngli sezmoq" be better?
- `c2-m06-l4-s3`: the ru "увековечивает память об основании города" is slightly stiff for a festival. Is the uz "shaharga asos solinganini xotirlaydi" natural?
- `c2-m06-l1-s2`: the new Uzbek prompt.
- `c2-m06-l3-c3` and `c2-m06-l5-c2`: both test the same pattern, prove + adjective (proved illusory / proved elusive). Both are correct, but the pattern repeats.
- `c2-m06-l5-c3`: "at best" carries the collocation; nebulous is the weaker partner.

## C2, модули 7–8 — `english-c2-p04.json`

- `c2-m08-l3-s2`: is the uz prompt "Roman o'ta hissiyotlilik bilan chegaradosh." natural? The figurative "chegaradosh" should be acceptable, but please check.
- `c2-m07-l2-s1`: uz "gulduros ovozi" for sonorous. "gulduros" is the card's word, but it leans towards "thunderous".
- Literary terms in uz: marsiya (`c2-m08-l1-d1`), lavha (`c2-m08-l1-d3`), ko'chim (`c2-m08-l2-d3`), tazod (`c2-m08-l2-s3`), jonlantirish (`c2-m08-l2-s2`).
- `c2-m08-l1-s1`: uz prompt "...o'qigan ma'qul" for "is best read". Does it carry "best" strongly enough?
- `c2-m08-l4-d3`: ru "сделал отступление об архетипах". Check whether this is natural.
- `c2-m07-l5-s1`: please confirm the new fronted order is acceptable.

## C2, модули 9–10 — `english-c2-p05.json`

- `c2-m09-l3-s3`: is the emphatic order "a period of detente did begin only after the summit" acceptable? I added it to be safe.
- `c2-m09-l3-s2`: the listed reversed order "What ended in open war began as brinkmanship". Is it a fair translation of the prompt?
- `c2-m09-l5-c1`: "falls under the purview" also exists. It is not an option, but please confirm "among" and "between" are impossible.
- `c2-m09-l5-c3`: "staged a filibuster" is less common than "launched/mounted". It is correct, but please confirm "gave/played" are impossible.
- `c2-m10-l2-d3`: "a premeditated lie" is natural but rarer than "premeditated murder". Please check it.
- `c2-m10-l2-d1`: "Circumstantial evidence alone cannot incriminate him". Would "convict" sound more natural to a native speaker?
- `c2-m09-l2-d1`: the Uzbek «ulush tarzidagi poralar» follows the card but is stiff. Consider a more natural Uzbek word for "kickbacks".
- `c2-m10-l5-d3`: internment / interment sound alike. "The interment of noncombatants is unlawful" makes little sense, so this is probably safe, but please confirm.

## C2, модули 11–12 — `english-c2-p06.json`

- `c2-m11-l1-c1`: confirm that "doubts in the bank's solvency" is wrong in every variety of English.
- `c2-m11-l3-s2`: "it was the library that survived thanks to her largesse" can be built from the same fragments. It is grammatical but puts the emphasis on another word than the prompt does, so I did not list it. Check that this is acceptable.
- `c2-m12-l1-c2`: confirm that "approach on treatment" is never acceptable.
- `c2-m12-l2-c2`: confirm that "oscillated among two points" counts as an error.
- `c2-m11-l3-s3`: check the ru prompt word «Угощение было таким щедрым» for "lavish feast"; «обильным» may be more natural.
- `c2-m12-l1-d3`: check the uz word "sig'madi" for "did not fit her schema"; "to'g'ri kelmadi" may be more natural.

## C2, модули 13–14 — `english-c2-p07.json`

- `c2-m13-l5-s2` (Rarely do young doctors choose...): check that emphatic "young doctors do rarely choose geriatric medicine" need not be accepted.
- `c2-m14-l3-s3`: check that the fronted object "such a swarm of locusts the farmers had never seen" need not be accepted.
- `c2-m14-l3-s1`: check that "prowl the outskirts of villages" is natural.
- `c2-m14-l4-c3`: "spell calamity" versus the more frequent "spell disaster".
- `c2-m14-l1-c2` and `c2-m14-l2-c3`: the figurative idioms tempest in a teapot and wither on the vine.
- `c2-m13-l4-c2`: confirm that "under excruciating pain" is not accepted usage.

## C2, модули 15–16 — `english-c2-p08.json`

- `c2-m15-l4-s1`: a learner may order the fragments as "The scandal could not even tarnish his reputation". It is not accepted because the meaning differs. Please confirm.
- `c2-m16-l5-s1`: three orders of "overpowered by exhaustion" are accepted. Please check that "at her desk" cannot naturally open the sentence. Also check "overpowered" against "overcome".
- `c2-m15-l3-s2`: "Were he to tell the truth..." is accepted in 2 orders. Please check that both are complete.
- `c2-m15-l5-c2`: please confirm that "pander with" and "pander over" are impossible.
- `c2-m15-l1-s2` uz: "Urushning bahosini aynan dehqonlar tabaqasi to'ladi" means "paid the price of the war". Please check that it is close enough to "paid for the war".

## C2, модули 17–18 — `english-c2-p09.json`

- `c2-m17-l2-c2`: is "dusk" safe as a distractor in "heralded the ___ of a new era"?
- `c2-m17-l4-c2`: is "did a metamorphosis" impossible, and "passed a metamorphosis" too (without "through")?
- `c2-m17-l1-s2`: is "She never had sat through…" acceptable? I listed it.
- `c2-m18-l4-s2`: is "The evidence seemed irrefutable against him" acceptable? It is not listed.
- `c2-m17-l1-c2`: is "Hope flows eternal" impossible as a distractor?
- `c2-m18-l2-d2`: the uz "Choy qishning eng mumtoz ichimligi" and the ru «самый что ни на есть»: are they natural?
- `c2-m17-l2-d1`: "Early flowers are a harbinger of spring" has a plural subject with a singular complement. It is a common pattern; please confirm.

## C2, модули 19–20 — `english-c2-p10.json`

- `c2-m20-l1-s3`: ru prompt «немного чересчур рьяный» - is this register acceptable, or should it be «несколько излишне рьян»?
- `c2-m19-l1-c3`: the gap tests prefer … to, not a collocation of understated. Acceptable?
- `c2-m20-l4-c3`: confirm that «the top of folly» is not used.
- `c2-m19-l5-s2`: confirm that «It sold well, highbrow as the novel is» (cataphoric it) is rare enough to leave out.
- `c2-m20-l2-c2`: ru «в неловком положении» for «awkward predicament». The card says «затруднительное положение».
