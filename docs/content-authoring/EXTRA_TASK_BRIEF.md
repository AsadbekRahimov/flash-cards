# Writing «Word form» and «Find the mistake» tasks (English for Russian and Uzbek speakers)

You write the tasks of two practice types for two modules of a course: **word_form** (the learner types the right form of
a given word) and **error_hunt** (the learner finds the one wrong word of a sentence and picks its correction). Every
lesson has 10 word cards (already written). For every lesson you write **6 tasks: 3 word_form, 3 error_hunt** - 60 tasks
in one file. Thousands of learners are graded by a machine against what you write: a second right form you did not list,
a second word that could be "the mistake", a "wrong" option that is acceptable English or a false explanation is a real
harm. The owner of the course asked for **demanding** tasks: easy, mechanical tasks are a fault too (section 7).

**The checker being green says nothing about quality.** In the pilot of this job both files were green on the first run;
every real problem (a second word that also repairs the sentence, a second right form, a near-copy of a card example, a
wrong Uzbek idiom) was found only by reading. The editor's pass of section 9 is the main part of the work.

This brief is complete for these two types. For the ru / uz texts it refers to TASK_BRIEF.md section 6 (same directory):
read that section too.

## 0. What the learner sees (the rules below follow from it)

- **word_form** - the English sentence with one gap `___` and the base word (`decide`). The learner TYPES the word that
  fits. Nothing else is shown before the answer: no translation, no hint how many words to type. The machine compares the
  typed words with the forms you listed in `accepted`, word by word, ignoring capital letters and punctuation; a hyphen
  counts as a space (`well-known` = `well known`); a British spelling counts as the American one when the pair is in the
  application's list. **There is no typo forgiveness**: a misspelled form is wrong (spelling the form is the skill). A
  learner who types the base word unchanged is told "that is the same word". After the answer the learner sees the full
  sentence (your first form in the gap), the other forms you listed, the translation and your explanation.
- **error_hunt** - the sentence as a row of word chips. Step 1: the learner taps the wrong word. Step 2 (always, even
  after a wrong tap: then the real mistake is highlighted): three corrections of that word, in random order; the learner
  picks one. Correct only when both steps are right. After it: the corrected sentence, its translation, the explanation.
  **So a sentence in which ANOTHER word could also be called the mistake makes step 1 unfair**, and the corrections must
  not give the answer away by their look (letter case, length).

## 1. Files and tools

- Lesson files (the cards): `database/data/courses/<course>/mNN-lN.json` (paths from the root of the repository). Read
  the ten files of your modules completely before writing: word, part of speech, ru / uz translation, example.
- Finished files for the layout (NOT to copy, NOT a quality model - they were written by the old short brief):
  `database/data/practice/english-a1-a2-x01.json`, `database/data/practice/english-b2-x01.json`.
- Your output: ONE file `database/data/practice/<course>-xNN.json` (named in your assignment; file `xNN` holds modules
  2·NN-1 and 2·NN), all 60 tasks ordered by module, lesson, then `w1 w2 w3 e1 e2 e3`.
- The checker (it runs the application's own validator, graders and lexicon):
  `php docs/content-authoring/check_practice.php --modules=<M1>,<M2> <your file>`
  It must end with `60 tasks checked, 0 errors, ...` (one exception: lexicon errors you report, section 8). Read every
  `WARN`: fix it, or keep it only when you are sure (say which and why in your report).
- Does the lexicon know a word? `php docs/content-authoring/lexicon_lookup.php word1 word2 ...` - run it on every right
  form and every unusual word BEFORE you build a task on it. **"known" does not prove the spelling**: the lexicon accepts
  any regularly built form of a word it knows, so `goed`, `rised`, `childs`, `wifes`, `informations`, `advices`, `fastly`
  are all "known". Your own knowledge must check every form.
- Write the file with a small script of your own (Python: `json.dumps(data, indent=2, ensure_ascii=False) + "\n"`), kept
  OUTSIDE the repository (your scratchpad). Create nothing else in the repository and change no other file - in
  particular **never edit `resources/lexicon/*`** (section 8 says what to do instead).

## 2. The file and the common fields

```json
{
  "format": "lexiflow-practice/1",
  "course": "english-b2",
  "items": [ ...tasks... ]
}
```

Fields of a task, in exactly this order - word_form: `key, type, lesson, level, target_words, text, base, accepted,
translation, explanation`; error_hunt: `key, type, lesson, level, target_words, text, options, correct, translation,
explanation`. No other field. All are required, `translation` and `explanation` in both `ru` and `uz`.

- `key`: `<prefix>-mNN-lN-<w|e><1|2|3>`; prefix `a1a2`, `b1`, `b2`, `c1` or `c2` (course english-a1-a2 ... english-c2).
  `b2-m03-l2-e1` = first error_hunt of module 3, lesson 2.
- `lesson`: `{"stage": <module>, "number": <lesson>}`.
- `level`: `"B1"`, `"B2"`, `"C1"`, `"C2"` by course; in english-a1-a2 `"A1"` in modules 1-10, `"A2"` in modules 11-20.
- `target_words`: the lesson word the task trains, lower case, exactly as in the lesson file - normally ONE (two only
  when the task really trains both). What it must be is defined per type (sections 3.2, 4.2).
- **Coverage:** the six tasks of a lesson target **six different words of the lesson**. Only when the lesson's words do not
  give six fair tasks (a lesson of interjections or pronouns) may it be five; say so in the report. Never two tasks of one
  type on one word.
- `translation`: the whole correct sentence (word_form: with the answer; error_hunt: corrected) in natural ru and uz,
  ending with `.`, `!` or `?`, at most 200 characters.
- `explanation`: ru and uz, each at most 300 characters (section 6).

## 3. Word form (`word_form`)

```json
{
  "key": "b2-m07-l3-w1", "type": "word_form", "lesson": {"stage": 7, "number": 3}, "level": "B2",
  "target_words": ["legal"],
  "text": "In many countries it is ___ to drive without a seat belt.",
  "base": "legal",
  "accepted": ["illegal"],
  "translation": {"ru": "Во многих странах ездить без ремня безопасности незаконно.",
                  "uz": "Ko'p mamlakatlarda xavfsizlik kamarisiz mashina haydash noqonuniy."},
  "explanation": {"ru": "Смысл требует «незаконный». Перед l отрицательная приставка il-, а не un- или in-: legal → illegal (две l).",
                  "uz": "Ma'no «noqonuniy» so'zini talab qiladi. l harfidan oldin inkor old qo'shimchasi un- yoki in- emas, il-: legal → illegal (ikkita l)."}
}
```

### 3.1 The sentence (`text`)

- One natural sentence, capital letter first, final `.` `!` or `?`, exactly one gap written `___` (three underscores).
  **The gap is never the first word** (the sentence shown after the answer would start with a small letter).
- Length: A1-A2 6-12 words, B1-B2 7-14, C1-C2 8-16 (the checker warns above 16).
- **The sentence alone must force one form.** The learner has no translation; any form of the base word's family that
  gives a correct, natural sentence is a right answer, whatever it means. So the clue must be IN the sentence: an article
  or determiner (noun), `very` / `too` (adjective), a verb to describe (adverb), `than` (comparative), `the ... of / in`
  (superlative), the subject and a time word or a second verb (tense and person), the meaning of the other clause
  (positive or negative). The base word itself must not fit naturally: a learner who leaves it unchanged is marked wrong.
- **The gap is one word** whenever possible; everything around it stays in the text (`She is the ___ student in our
  class.` -> `best`; not `She is ___ student` -> `the best`). A two- or three-word answer only when the form itself has
  several words: `more careful` (A2+), `has been`, `will have finished` (B2+). The learner is not told the number of
  words: if a natural answer of another length exists, rebuild the sentence or list it (the checker warns when your
  forms have different numbers of words).
- No contractions in the gap or in the answer; no names of people, places or brands; American spelling (section 6).

### 3.2 The base word, the answer and the target

- `base`: a real, ordinary word at or below the level, in its dictionary form (infinitive, singular, positive degree;
  `I` for the pronoun forms), lower case except `I`, one word (the validator allows two; do not use it). Check it with
  `lexicon_lookup.php`.
- The answer is the base word changed by **standard morphology**: an inflection (plural, 3rd person, past, participle,
  `-ing`, comparative, pronoun case) or a derivation with a regular affix, possibly with a spelling change. Base and
  answer share their core meaning (`decide -> decision`, `deep -> depth`, `pronounce -> pronunciation`). Not a pair that
  is related only by origin (`proceed -> procedure`: "go on" vs "a method"). Not the base itself (the validator refuses
  it). Not a form that already stands in the sentence (the checker refuses it).
- `target_words`: **the lesson word must be the base or the answer** - the word whose form the learner produces. Both
  are fine: base = lesson word (`legal -> illegal`) or answer = lesson word with a base outside the lesson
  (`sign -> signature` in a lesson that has `signature`; then the base must be an easier word the learner knows). The
  checker also accepts a lesson word that merely stands elsewhere in the sentence; **do not use that**, with one
  exception: in a lesson where fewer than three words have any form the level allows (interjections, function words),
  ONE word_form of the lesson may train a form of another word in a sentence built round the lesson word - say so in the
  report.
- The task uses the lesson word **in the meaning of its card**. The form may change the part of speech (that is the
  point of the type: `decide -> decision`; the card interjection `welcome` -> the verb `welcomed` "greeted gladly"), but
  not the sense (a card `bug` = software fault never becomes an insect).
- Irregular answers are fine (`child -> children`, `go -> went`, `good -> better`) when the lexicon knows them; then the
  target must be the base, not the irregular answer (the machine finds a target only as itself or a regular form).

### 3.3 Second right answers (the main danger)

Fill the gap, in your head, with **every** form of the base word's family: every tense and aspect, active and passive,
singular and plural, 's, positive and negative, `-ed` and `-ing`, the noun of the person and of the thing, the gerund,
the comparative and the superlative, the British and the American form. Each one that gives a correct, natural sentence
is a right answer: rebuild the sentence so that it is not (preferred), or list it in `accepted` (at most 4 forms; the
first is the canonical one, shown in the full sentence). The traps met in the pilot:

1. **Tense not pinned.** `My mother ___ the kitchen every day.` takes `cleans` AND `cleaned` (habit in the past). Pin it
   with a second verb (`... every day, and I wash the dishes`), `now`, `yesterday`, `last year`, `since`, `tomorrow`.
   `every day` / `always` alone pin nothing. Watch present simple vs continuous: `She ___ dinner now` wants `is cooking` -
   two words; put `is` in the text: `She is ___ dinner now.`
2. **Number not pinned.** `I bought ___ for the kids` (gift / gifts). Pin it with `a`, `many`, `two`, `this`, `these`, or
   the verb (`The ___ are`). `person -> people`: avoid (`persons` is a correct formal plural). The possessive `'s`
   needs its number pinned too: `my ___ room` takes `sister's` and `sisters'`; `My only ___ room is upstairs.` does not.
3. **Positive or negative.** `My boss was very ___` takes `sympathetic` and `unsympathetic`. The other half of the
   sentence must exclude one: `... and gave me a week off.`
4. **`-ed` / `-ing`** (`bored` / `boring`, `interested` / `interesting`), **active / passive**, **participle / past**:
   the subject decides (a person feels -> `-ed`); `is ___` can take a participle AND an `-ing` (`The shop is ___` ->
   `closed`, `closing`): pin with `now`, `on Sundays`, a by-phrase.
5. **Gerund or noun.** `Effective ___ saves time` takes `delegation` and `delegating`; `The ___ of new staff` takes
   `recruitment` and `recruiting`. Pin the noun with `a / an` or a plural (`a clear ___ of tasks` is still risky: say
   it aloud with the gerund), or list both.
6. **Person, thing or institution.** `We hired an experienced ___` takes `consultant` and `consultancy`;
   `employee / employer / employment`; `production / productivity / product`. Use a frame that only one fits:
   `She works as a ___.`
7. **Twin derivations.** `modesty / modestness`, `humility / humbleness`: if a good learner's dictionary (Oxford,
   Cambridge, Longman, Merriam-Webster learner's) lists the twin, list it too - or, if the lexicon lacks it, choose
   another task. A form no such dictionary has (`diligentness`) is no answer.
8. **Comparatives with two forms.** Two-syllable adjectives such as `clever, quiet, polite, simple, narrow, gentle,
   friendly, likely, common, pleasant, stupid` take `-er` AND `more`: avoid them or list both (different lengths: the
   checker warns, that is expected). Adjectives in `-y` (`happy`, `easy`, `busy`) take `-ier`; `more happy` is not listed.
9. **Demonstratives and pronouns.** base `this` in `Take ___ chairs` takes `these` and `those`: pin with `here` /
   `over there`, or with the sentence (`... I am sitting on.`). base `I`: `me` / `my` / `mine` / `myself` - the slot
   must take one.
10. **British / American.** `learned / learnt`, `dreamed / dreamt`, `burned / burnt`, `spelled / spelt`, `got / gotten`
    (`have ___` (get)), `proved / proven`: list both or avoid. When `lexicon_lookup.php` shows "other spelling accepted
    too", the machine accepts the British spelling by itself: list nothing. A British spelling with no pair
    (`judgement`): list it in `accepted` and in the `BRITISH:` line of your report. `sat` / `stood` after `is` (British
    `is sat`): avoid `-ing` of `sit`, `stand`, `lie`.
11. **Linking verbs** (`look, seem, feel, sound, smell, taste, become, get`) take an adjective, not an adverb: never an
    adverb gap after them.

### 3.4 The forms of each level

A level may use every form of the levels below it. The words of the rest of the sentence stay below the level.

| Level | Forms |
|---|---|
| A1 | plural, regular and common irregular (`babies, wives, shelves, children, men, women`); 3rd person `-s` (`watches, studies`); past simple, regular and common irregular (`stopped, studied, went, met`); `-ing` after `am/is/are` in the text; pronoun case, number and possession (`I -> me / my / mine`, `we -> us / our`, `they -> them / their`, `this -> these`); comparative and superlative of short adjectives (`taller, happier, the biggest` with `the` in the text; `good -> better`); possessive `'s`; a past participle in a fixed A1 chunk (`What is this ___ in English?` -> `called`; `get married`); a related word where both are A1-A2 words (`thank -> thanks`, `sing -> singer`). |
| A2 | irregular past and participle (`bought, taught, written`), present perfect with `have/has` in the text; `more / most` + long adjective (two words), `-ly` adverbs (`happily, carefully`), `-er / -or` nouns, `-ful / -less`, `un-` with common adjectives, `-ed / -ing` adjectives, possessive pronouns. |
| B1 | `-tion / -sion / -ment / -ness / -ity / -ance`, `-able / -ible / -ous / -al / -ive / -y`, `un- / in- / dis-`, `-en` verbs (`wide -> widen`), passive participles, gerund after a verb or preposition (`enjoy swimming`). |
| B2 | `im- / ir- / il- / in- / non-` by the first letter, `-ance / -ence`, `-ant / -ent`, `-ist / -ee / -ian`, `en- / -en / -ify / -ize`, `mis- / over- / under- / re-`, **double derivation** (`care -> careless -> carelessness`, `nation -> national -> nationality`), compound adjectives (`well-paid`), `has been`, `will have finished` (2-3 words). |
| C1 | stem changes (`pronounce -> pronunciation`, `maintain -> maintenance`, `deep -> depth`, `generous -> generosity`, `repeat -> repetition`, `intend -> intention`), formal nominalization, `counter- / mal- / pseudo- / co-`, a choice between two derivatives that the meaning of the sentence decides (`sensible / sensitive`). |
| C2 | pairs that differ in meaning: `economic / economical`, `historic / historical`, `classic / classical`, `respectful / respectable / respective`, `imaginary / imaginative`, `industrial / industrious`, `momentary / momentous`, `credible / credulous`; rarer but standard academic derivations (`-ism, -itude, -ency`); negative prefixes on abstract words (`irreversible, inexplicable, indispensable`). |

Lessons of interjections, pronouns or adjectives at A1 use what their words allow: pronoun forms, comparatives,
related words, participles of the A1 list. A lesson of nouns at A1 should not give three plain plurals: use an irregular
plural, a possessive, a related word.

## 4. Find the mistake (`error_hunt`)

```json
{
  "key": "b1-m07-l3-e1", "type": "error_hunt", "lesson": {"stage": 7, "number": 3}, "level": "B1",
  "target_words": ["depend"],
  "text": "Our plans for the weekend depend *from* the weather.",
  "options": ["on", "of", "at"],
  "correct": ["on"],
  "translation": {"ru": "Наши планы на выходные зависят от погоды.", "uz": "Dam olish kunlaridagi rejalarimiz ob-havoga bog'liq."},
  "explanation": {"ru": "depend on — зависеть от: после depend всегда on. Русское «от» здесь не from.",
                  "uz": "depend on — biror narsaga bog'liq bo'lmoq: depend fe'lidan keyin doim on predlogi keladi."}
}
```

### 4.1 The sentence (`text`)

- One natural sentence with **exactly one mistake**, the one word marked with asterisks around it: `*from*`. Mark the
  whole word, with the punctuation outside: `*informations*.`, `*it's*` (an apostrophe or hyphen inside a word is part
  of it). The text without the asterisks is what the learner sees, so apart from the mistake it must be perfect.
- Words are split at the spaces; punctuation sticks to its word. So: single spaces, no space before a mark, no dash or
  other mark standing alone between spaces. Capital letter first, final `.` `!` or `?`.
- Length: 4-16 words (the validator); A1-A2 6-10, B1-B2 7-12, C1-C2 8-14 (the checker warns above 14: the chips wrap on
  a small phone). At most 160 characters.
- Neither sentence of a task may repeat a card example, not even nearly (the checker compares exact sentences only):
  the card's `My conscience did not let me lie to her` and a task `My *conscious* would not let me keep the money` are the
  same sentence for a learner; so are `Tom is too immature to live alone` and `He is still too ___ to live on his own`.
  Nor may it repeat another task.
- No names of people, places or brands; no digits; American spelling.

### 4.2 The target word

- The lesson word must stand in the corrected sentence **as itself or a regular form** (`-s, -es, -ed, -ing, -er, -est,
  -ly, 's`): an irregular form is not found (a target `oversee` in a sentence with `overseen` is an ERROR). So the
  irregular forms of a lesson word can be trained only by word_form, with the lesson word as the base.
- **The mistake must concern the target word**: the word itself (its form, its countability, a confusable twin, a false
  friend), or its pattern - its preposition, its article, the verb form it takes (`suggest + -ing`), the agreement it
  controls. A grammar mistake somewhere else with the lesson word as decoration (`chairperson` in a `said / told`
  sentence) is not a task of this lesson. Exception: in a lesson of interjections or function words, where a word has no
  mistake of its own, the mistake may be a point of the level in the same short clause as the lesson word (`Say hello to
  *you* mother`) - at most one such task per lesson.

### 4.3 One mistake, one place to click

Step 1 is fair only when **no other word of the sentence can be called the mistake**. Test every other word: replace it,
in your head, with a word a learner of this level could think of - its other form, its other number or gender, a
near-synonym, another function word (`and -> or`) - and see whether the sentence becomes correct while the marked word
stays. Also insert or delete one word anywhere. If any such repair works, the learner who taps that word is marked wrong
for a good answer: pin the sentence or rebuild it. (Replacing a content word with an unrelated one - `a hour -> a day` -
is no repair a learner thinks of; ignore it.)

- **Agreement** (`have / has`, `is / are`, the 3rd person `-s`): pin the subject so that only the verb can change.
  `My father *have* a small car` is also repaired by `father -> parents`; `She *like* tea` by `She -> They`. Pin by a
  second word that agrees: `My father *have* a small car, but he rarely drives it.`; `Each of the rooms`, `Only my
  sister`, `two`, `own`. `My grandfather and grandmother *is* at home` is also repaired by `and -> or`: write `Both my
  grandfather and grandmother *is* at home.`
- **Gender** (`he / she`, `his / her`): one-sided only with TWO clues, because the noun can change too: `My grandmother
  is an old woman, but *he* still works in the garden.` (grandmother + woman); `My grandfather is old, but he still
  drives *her* own car.` (`own` ties the possessive to `he`). `her` after a male subject alone is no mistake (`He loves
  her job.`).
- **Dependent prepositions:** test whether the head word could change instead. `We arrived *to* the airport` is also
  repaired by `arrived -> went / got`; `I am very interested *on* art` by `interested -> keen`. Choose a head with no
  such twin at the level (`depend`, `consist`, `rely`), or give the sentence a frame where the twin does not fit.
- **Articles and countability:** `gave me *an* advice` - a learner may tap `advice` (`a tip`). Prefer the mistake inside
  one word: `some useful *advices*`. `a / an` before a vowel sound is unambiguous but easy (section 7).
- **Insertion and deletion.** A correction may have two or three words, but a repair that only ADDS a word (`It depends
  *the* weather`, `I want *go* home`) leaves the learner guessing which neighbour to tap: do not write it. Adding a word is
  allowed only when the marked word itself also changes: `The report must *authorize* by the director.` ->
  `be authorized` (wrong corrections of the same shape: `be authorizing`, `have authorize`). Inversion (`Rarely *we*
  see` -> `do we`) is a pure insertion too: not this type. A mistake that is an EXTRA word
  (`discuss *about*`, `enter *into* the room`, `married *with*` if fixed by deletion) cannot be taught by this type: no
  correction can delete a word. Use it only where a replacement repairs it (`married *with* a doctor` -> `to`). If
  deleting the marked word ALSO gives a correct sentence (`My birthday is *in* the fifth of May`), prefer another
  sentence: the learner who would delete it finds no such option.
- **Read the sentence without the marked word as an examiner**: everything else must be correct in standard British AND
  American English, in writing.

### 4.4 The mistake must be a mistake beyond doubt

Only mistakes that grammars and learner's dictionaries of British and American English mark as errors, in edited
writing. **Not**:
- usage that is disputed or accepted in informal standard English: split infinitive, `data is`, singular `they`,
  `different than / to`, `less` with plural nouns (`fewer / less`), `who` as an object (allowed: `whom` after a
  preposition only - `the man to *who* I spoke`), `like` as a conjunction, `try and`, `if I was`, `can` for permission,
  `me and my friend` as a subject in speech, `none are`, sentence-final prepositions, `hopefully`;
- one variety against the other: `at / on the weekend`, `in hospital`, `in future`, `have got / have`, `gotten`,
  collective nouns with a plural verb (`the team are`), the past simple with `just / already / yet` (`Did you finish
  yet?` is standard American), `learnt / learned`, `practise / practice`;
- the subjunctive after `insist / suggest / essential that` (`that he goes` is standard British);
- a rarer but accepted alternative (as TASK_BRIEF §3: `interfere in / with`, `outlook on / to`, `consult / consult
  with`, `on / in the shortlist`, `angry with / at`, `different from / to`).

### 4.5 The three corrections (`options`, `correct`)

- Exactly three, each at most three words and 40 characters, **the right one first** (the app shuffles them);
  `correct` holds exactly that text. None of them is the marked word itself.
- **Each wrong correction, put in place, gives a sentence that is wrong beyond doubt** (the test of TASK_BRIEF §3: say
  the whole sentence with it; if a dictionary could show it, or you hesitate, replace it). A wrong correction that is
  correct only in the passive or in another structure is still a risk: avoid it (`filled her classmates *by* envy`).
- **Real words only**: a non-word (`rised`, `goed`) is allowed as the MARKED mistake, never as a correction - it would
  be eliminated at once. Good wrong corrections are what a learner of this level would choose: the other member of the
  confusable pair (`rose / raised`), the calque preposition (`from` for «от»), the over-corrected form, a form of the
  same word in the wrong role (`significant / significantly / significance`).
- **The look of the options gives nothing away.** Same part of speech or same kind of word where possible; same number of
  words (`do we / we do / did we`), unless the shape is natural (`its / it / it is`). Not a word that already stands
  elsewhere in the sentence (the checker warns: a learner sees it is a repeat).
- **Letter case** (the validator refuses a fault, code `options_case`; the checker shows it as an ERROR):
  - mistake in the **first word**: every correction starts with a capital letter (`*Who's* bag is this on the floor?`
    -> `Whose / Who / Whom`);
  - mistake **elsewhere**: the corrections that start with a letter all start with letters of one case - in practice all
    lower case. **Exception:** the pronoun `I` and its contractions `I'm`, `I've`, `I'll`, `I'd` are written with a capital
    anywhere, so they count as neither case and may stand among lower-case corrections: `Every morning *me* walk to school
    with our dog.` -> `I / my / myself`. Only these single forms are exempt: a correction of two words that starts with
    `I` (`I am`) counts as a capital and may not stand among lower-case ones. An option that does not start with a letter
    has no case.
- The same option set at most twice in a file.

### 4.6 Mistakes for each level

A level may use the mistakes of the levels below it; the mistake should be one a learner of THIS level still makes, and
it must belong to the target word (section 4.2).

| Level | Mistakes |
|---|---|
| A1 | `am / is / are`; `have / has`; 3rd person `-s` (`She *like*`), `don't / doesn't`; plural nouns (`*childs*`, `two *book*`); `a / an`; `this / these`, `that / those`; subject / object pronoun (`*me*` as subject, `with *we*`); possessive adjective (`*you* phone`), `his / her` (pinned); `there is / are`; `in / on / at` with times and days; place prepositions where the other one is impossible (`We live *on* a small town` -> `in`); `can + base` (`can *swims*`); `-ing` after `am/is/are` (`I am *work*`). |
| A2 | past simple, irregular (`*buyed*`) and of `be` (`was / were`); `didn't + base` (`didn't *went*`); `much / many`, `some / any`; comparatives and superlatives in one word (`*gooder*`, `*badder*`, `the *goodest*`); `-ly` adverbs; prepositions of place and movement (`arrive *to*`, see 4.3); `going to / will` forms; `have to`; possessive pronouns (`*my*` without a noun); `-ed / -ing` adjectives; `say / tell`; basic `make / do`; uncountables inside one word (`*furnitures*`, `*homeworks*`); verb + `-ing` (`enjoy *swim*`); `listen / hear`, `look / see / watch`, `borrow / lend`. |
| B1 | tense forms pinned by a time word (present perfect with `since / for`, past with `ago / yesterday`), past continuous, passive forms (`was *build*`), conditional forms (`If it *will* rain`), `used to`, dependent prepositions, `make / do`, false friends (`magazine` = shop, `fabric` = factory, `accurate` = tidy, `sympathetic` = nice, `artist` = actor, `control` = test), countability (`informations, advices, news *are*`), the verb after another verb in the wrong form (`enjoy *swim*` -> `swimming`, `decided *going*` -> `to go`), reflexive pronouns, relative `who / which`, `few / little`, `so / such`, `too / enough`, `remember / remind`, `lose / miss`, `win / beat`. |
| B2 | `affect / effect`, `rise / raise`, `lie / lay`, `its / it's`, `whose / who's`, `than / then`, `lose / loose`, `advice / advise`, `beside / besides`, `especially / specially`; adjective vs adverb; participle adjectives; reported speech forms; third and mixed conditional, `wish` + past; causative (`had my car *repair*`); passive with modals; verb patterns repaired in one word (`look forward to *see*` -> `seeing`, `am used to *live*` -> `living`); dependent prepositions of abstract nouns; false friends (`actual`, `eventual`, `sensible`, `pretend`, `fabric`). |
| C1 | collocation (`*make* a crime` -> `commit`, `*do* an effort`); `principal / principle`, `complement / compliment`, `stationary / stationery`, `discreet / discrete`, `elicit / illicit`; `hard / hardly`, `late / lately`, `high / highly`, `near / nearly`; participle clauses (`*Considered* the cost` -> `Considering`); `despite / in spite of` forms; formal dependent prepositions (`comply *to*`, `consist *from*`); uncountable abstract nouns (`evidences`, `equipments`). |
| C2 | `imply / infer`, `continual / continuous`, `historic / historical`, `economic / economical`, `flaunt / flout`, `prescribe / proscribe`, `allude / elude`, `adverse / averse`, `militate / mitigate`, `credible / credulous`, `tortuous / torturous`, `deprecate / depreciate`; `whom` after a preposition; fixed idioms with a wrong word (`a *mute* point` -> `moot`); the C2 collocations of the lesson word; subtle prepositions of C2 words (`oblivious *at*`, `tantamount *with*`). |

C1-C2 confusables: look each use up in a learner's dictionary; if the dictionary lists the "wrong" use with only a usage
note (`infer` for `imply`, `disinterested` for `uninterested`), it is disputed - mark only the direction no dictionary
accepts, or choose another pair.

**Variety, per file (30 error_hunt tasks):** the three error_hunt tasks of a lesson are three different kinds of
mistake; one kind (a line item of the table: `a / an`, `is / are`, dependent prepositions, ...) at most 5 times in the
file, **all preposition mistakes together at most 9**; the same contrast (`a -> an`, `affect -> effect`) at most twice.
Count them in your plan (section 9) and give the counts in your report.

## 5. Agreement of the two types inside a lesson

The six tasks of a lesson are six different lesson words (section 2). Give word_form to the words that HAVE a form or
derivative worth practising at this level, error_hunt to the words that have a typical mistake (a preposition, a
countability trap, a confusable twin, a false friend). Words with neither (`surname`, `twin`) are left out; the p-files
train them.

## 6. ru and uz texts, explanations

- All the rules of **TASK_BRIEF.md section 6** apply: «вы» / «Siz», `ё`, «ёлочки», Uzbek in Latin with the ASCII `'`,
  the word an educated speaker in Uzbekistan uses, no calque from Russian, kinship words chosen and kept compatible,
  English words in an Uzbek explanation with the suffix on a following noun (`depend fe'lidan keyin`), no `<` `>`, no
  double spaces, ru and uz making the same claims about English.
- The translation renders the CORRECT sentence; the card's translation of the lesson word wherever it is natural, the
  natural sentence otherwise.
- **The explanation names the clue and the rule**, in 1-2 short sentences: word_form - which word of the sentence asks
  for which form, and the spelling of the form (`после her нужно существительное: diligent → diligence; -ent → -ence`);
  error_hunt - why the marked word is wrong and what the rule is, and, when useful, why a tempting wrong correction is
  wrong or how ru / uz misleads (`русское «от» здесь не from`). Never "because it is correct". Every statement must be
  true: `X never takes Y` is a claim about English - be sure.
- Pitfalls the pilot editors fixed (do not repeat them): uz dates `5-mayda`, not `beshinchi mayda`; help with a subject
  `ingliz tilidan yordam bermoq`, not `-da`; cousins `amakivachcha-xolavachchalar`; `mehribon` is said of people, not
  of a letter (`samimiy maktub`); a possessive suffix that changes the meaning (`eng qadrlaydigan xislatim` = "my
  trait"); calques of give + noun (`qarashni berdi` -> `qaray boshladi`); idioms with a second meaning (`ishdan javob
  olmoq` also = be dismissed -> `ta'tilga chiqmoq`); the usual order `bobom bilan buvim`; ru `обе сестры` (not
  `сёстры`); ru `дать взгляд` -> `стала смотреть`.
- A story must be logical (new neighbors welcome the newcomers, not the other way round).

## 7. Demanding but fair

**Demanding**: the learner must apply a rule, not recognise a pattern seen a moment ago. **Fair**: exactly one right
answer, decided by the sentence alone, by a rule of the level, in standard English; the rest of the sentence easy.

| Level | Demanding | Not fair / too easy |
|---|---|---|
| A1-A2 | a spelling change (`studies, stopped, happier, wives`), an irregular form, a clue one or two words away (`My sisters ___ (be) at school` -> `are`), a pinned subject, the uz / ru calque as the trap | a clue that needs grammar above the level; three plain `+s` tasks in a lesson; more than 3 `a / an` tasks in a file |
| B1-B2 | the part of speech decided by syntax (article + adjective + gap), a negative prefix demanded by the other clause, a choice between competing affixes (`-ance / -ence`, `-able / -ible`, `im- / in- / un-`), double derivation; mistakes in verb patterns, countability, false friends, confusable pairs | a bare `-s / -ed` gap; an error_hunt whose mistake is the first word after the lesson word every time; a distractor that is obviously no word or the wrong part of speech everywhere |
| C1 | stem changes, nominalization in a formal sentence, a derivative chosen by meaning; collocation and confusable-word mistakes in a sentence where nothing else looks odd | a clue that only an idiom known to native readers gives; register disputes |
| C2 | meaning pairs (`economic / economical`) where the sentence states the meaning that decides; rare but standard derivations; subtle confusables and idioms | a mistake that is a style preference; a word a C2 dictionary marks as rare or archaic; a gap two forms fill with different but valid meanings |

At every level: the clue is in the sentence, not in the translation (the learner has none); the mistake is not betrayed
by an odd sentence around it; the explanation names the clue. If you hesitate whether a task is fair - it is not:
rebuild it.

## 8. The lexicon (do not edit it: report)

The checker rejects (ERROR) a right form of a word_form, and every word of an error_hunt's corrected sentence, that the
application's lexicon does not know; it warns (WARN) about an unknown context word of a word_form. The lexicon is small
even for common derivatives (`arrogance`, `tolerance`, `imitation`, `charismatic`, `oversaw`, `goodbyes`, `impatient`,
`irregular`, `irrelevant`, `unreliable` were missing when the pilot was written).

1. Check every planned right form and every unusual word with `lexicon_lookup.php` first.
2. Prefer a task whose words the lexicon knows - but **not at the price of a dull task**: the best form of a lesson word
   is worth keeping.
3. If an ordinary, correctly spelled word is missing and the task is the right one for the lesson, keep it and list the
   word in your report under `LEXICON:` (with the key of the task). Do NOT add it to `resources/lexicon`. The coordinator
   adds the listed words and runs the checker again.
4. Your final checker run may then show errors, but **only** `... is not in the lexicon` errors for words of your
   `LEXICON:` list. Any other error must be fixed.
5. A WARN `«x» is not in the lexicon` for a context word: keep the word if it is ordinary and list it under `LEXICON:`
   too; a capitalised unknown word inside an error_hunt is taken for a name - remove it.

## 9. How to work

1. Read this brief, TASK_BRIEF.md section 6, two lessons of a finished x-file (layout only) and all ten lesson files of
   your modules.
2. **Plan each lesson first**, in your generator script, as a table: lesson word -> type -> form (word_form) or kind of
   mistake (error_hunt) -> clue / pin. Six different words; the variety caps of 4.6 and the kinds of 3.4 over the file.
   Run `lexicon_lookup.php` on all planned right forms.
3. Write the tasks into the script, write the file, run the checker; fix until `0 errors` (section 8.4).
4. **Then proofread your own file, task by task, as an editor who wants to prove each task wrong** - the checklist of
   EXTRA_TASK_PROOF_BRIEF.md (same directory) is the one the editor will use against you:
   - word_form: fill the gap with every form of the family (3.3); is the base form itself unnatural here; is the gap one
     word; does the explanation name the real clue?
   - error_hunt: try every other word (4.3); is the mistake wrong beyond doubt in both varieties (4.4); is each wrong
     correction impossible; does the right one give a natural sentence; case and look of the options?
   - all: the card's meaning; no near-copy of a card example; translations exact and natural in ru and uz; every
     statement of the explanations true?
5. Run the checker again after the last edit.

## 10. Report (compact; it is read by the coordinator)

- The final line of the checker; every WARN you kept, with the reason; every remaining error (only lexicon ones).
- `LEXICON:` the words the lexicon lacks, as `word (key)`, one list.
- `BRITISH:` British spellings you listed in `accepted` that have no pair in `lexicon_lookup.php`, as `american / british`.
- Counts: error_hunt by kind of mistake (prepositions in total), word_form by kind of form.
- The tasks you are not sure about (key, what to check) - for the editor and the native proofreader; not more than about
  ten, the real doubts.
- Lesson words you found hard to give a fair, demanding task to, and the lessons with fewer than six target words.
