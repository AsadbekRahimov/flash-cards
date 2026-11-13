# Writing practice tasks for a vocabulary course (English for Russian and Uzbek speakers)

You write the **authored practice tasks** of some modules of a course. The course teaches English words to adults whose
language is Russian or Uzbek. Every lesson has 10 word cards (already written, in the lesson files). For every lesson you
write **9 tasks: 3 collocation, 3 sentence, 3 dictation**. Thousands of learners will be graded by a machine against what
you write, so a task with a second right answer that you did not list, a wrong translation or a false statement in an
explanation is a real harm. Work slowly; quality matters far more than speed.

**The checker being green says nothing about quality.** It only finds mechanical faults. In the pilot of this job the
first run was already clean, and every real problem (a second correct word order, an acceptable "wrong" option, an
inexact prompt) was found only in the editor's pass of section 8. That pass is the main part of the work.

## 0. What the learner sees (read this first: the rules below follow from it)

- **collocation** - a sentence with one gap and three words to choose from; exactly one fits. After the answer the learner
  sees the full sentence, its translation and your explanation.
- **sentence** - a sentence in the learner's language (`prompt`) and the English words of its translation shuffled; the
  learner puts them in order. The machine compares the order with the orders you listed in `accepted`, and nothing else:
  **a correct English order that you did not list is marked wrong.**
- **dictation** - the learner hears the phrase (`text`, read by a speech engine) and types it. The machine accepts the
  writings in `accepted` and nothing else. A typed word that differs from the expected one is a mistake.

A lesson's practice opens when the learner has been introduced to the *target words* of its tasks, so every task names the
lesson word(s) it trains in `target_words`.

## 1. Files and tools

- Lesson files (the cards): `database/data/courses/<course>/mNN-lN.json` (all paths here are from the root of the repository) - read the files of your
  modules completely before writing: each card has the word, its part of speech, the ru and uz translation and an example.
  **A task uses the word in the meaning of its card** (the card of `bug` says "ошибка в программе" - then the task is about
  software, not insects). Its ru / uz texts use the card's translation of the word (in the needed grammatical form)
  wherever that is natural; when the natural, idiomatic translation of the whole sentence uses another word, **the natural
  sentence wins** (and the explanation may name the card's word).
- Finished files to look at for the layout and the tone (NOT to copy): `database/data/practice/english-b1-p01.json`,
  `database/data/practice/english-b2-p01.json` - read a lesson or two.
- Your output: ONE file `database/data/practice/<course>-pNN.json` (the name is given in your
  assignment) with all the tasks of your two modules, ordered by module, lesson, then c1 c2 c3 s1 s2 s3 d1 d2 d3.
- The checker (it runs the application's own validator, graders and dictation lexicon):
  `php docs/content-authoring/check_practice.php --write-accepted --modules=<M1>,<M2> <your file>`
  It must end with `90 tasks checked, 0 errors, ...`. Every `WARN` line must be looked at: fix it, or keep it only when you
  are sure it is right (say which and why in your report).
- Is a word known to the dictation lexicon? `php docs/content-authoring/lexicon_lookup.php word1 word2 ...`
- Write the file with a small script of your own (Python: `json.dumps(data, indent=2, ensure_ascii=False) + "\n"`), kept
  OUTSIDE the repository. Create nothing else in the repository and change no other file.

## 2. The file

```json
{
  "format": "lexiflow-practice/1",
  "course": "english-b1",
  "items": [ ...tasks... ]
}
```

Common fields of a task, in this order: `key`, `type`, `lesson`, `level`, `target_words`, then the fields of the type.

- `key`: `<b1|b2>-mNN-lN-<c|s|d><1|2|3>` - e.g. `b1-m03-l2-c1` is the first collocation task of module 3, lesson 2.
- `lesson`: `{"stage": <module number>, "number": <lesson number>}`.
- `level`: `"B1"` in the course english-b1, `"B2"` in english-b2.
- `target_words`: one word of THIS lesson, exactly as written in the lesson file (lower case). The target word must stand
  in the English of the task, as itself or as a regular form (-s, -es, -ed, -ing, -er, -est, -ly).
- **Coverage:** the nine tasks of a lesson must target at least 9 different words of the lesson, and should cover all 10:
  give one task two target words. The natural place for two targets is a **dictation** phrase (two lesson words joined by
  `and` are harmless there) or a sentence that needs both words anyway. Do not spend two tasks on one word.

(The three examples below only show the layout: their words need not be words of your lessons.)

## 3. Collocation task

```json
{
  "key": "b1-m07-l3-c1", "type": "collocation", "lesson": {"stage": 7, "number": 3}, "level": "B1",
  "target_words": ["deadline"],
  "text": "We worked all night to ___ the deadline.",
  "options": ["meet", "catch", "keep"],
  "correct": ["meet"],
  "translation": {"ru": "Мы работали всю ночь, чтобы уложиться в срок.", "uz": "Muddatga ulgurish uchun tun bo'yi ishladik."},
  "explanation": {"ru": "meet a deadline — уложиться в срок, успеть к сроку. С deadline говорят meet, а не catch или keep.",
                  "uz": "meet a deadline — muddatga ulgurmoq. Deadline so'zi bilan meet ishlatiladi, catch yoki keep emas."}
}
```

- The **target word stays visible** in the sentence; the gap is its partner: the verb that goes with the noun (meet a
  deadline, set a goal, make a comment), the adjective or adverb (heavy traffic, highly unlikely), the dependent
  preposition (rely on, jealous of, a talent for, mature for her age), the noun of a fixed phrase (sense of humor). For an
  adjective, the natural gaps are its preposition (curious about), its typical noun (a reliable source) or a verb
  (go bald, turn pale).
- Pick, for the three collocation tasks of a lesson, the words that HAVE a strong, common collocation at this level; give
  the other words to the sentence and dictation tasks. The collocation may be the one of the card's example (often it is
  the strongest) - in a NEW sentence.
- `text`: one natural sentence of 6-12 words that makes the meaning clear, capital letter first, final mark, exactly one
  `___` (three underscores), **never as the first word**, and preferably not right after `a` / `an` (the article must fit
  all three options and give nothing away).
- `options`: exactly three single lower-case words of the same part of speech and the same form (three past forms,
  three prepositions...), ordinary words of the level (the checker warns about a word the lexicon does not know - look at
  it, an ordinary word may stay). **The correct one comes first** (the app shuffles them). `correct`: exactly that word.
- **The two wrong options must be wrong beyond doubt.** A good distractor is what a learner tends to choose - a calque
  from Russian or Uzbek (do a mistake, put a goal, make a favor, strong rain), a wrong preposition (depend from, jealous
  to), a near-synonym that does not collocate (feeling of humor) - AND what is not said in this sentence
  in standard British or American English. A distractor may be wrong because the verb needs another structure ("hope you
  success", "arrived a conclusion"): that is fine. Forbidden:
  - a rarer but accepted alternative: make / take a decision, have / take a shower, at / on the weekend, different from /
    to / than, do / conduct / carry out research, in / on the team, fill in / out a form, confident in / of / about,
    remind of / about, complain about / of, angry with / at, get / become + adjective;
  - a word that gives another meaningful sentence;
  - a synonym that also collocates.
  **Test each distractor:** say the whole sentence with it; if a good learner's dictionary could show that phrase, or you
  hesitate - replace it. (A phrase heard only in a regional variety, which dictionaries of British and American English
  mark as an error, may stay when it is the typical calque - but prefer a distractor that is impossible everywhere.) When no option set is safe, choose another gap or
  give the word to another type of task.
- `translation`: the whole sentence (with the right word) in natural ru and uz.
- `explanation` (ru and uz, each at most 300 characters): first the collocation and its meaning, then - when it helps -
  why the other two do not fit, or one more example. Every statement must be true ("X не употребляется с Y" is a claim
  about English: be sure).

## 4. Sentence task

```json
{
  "key": "b1-m07-l3-s1", "type": "sentence", "lesson": {"stage": 7, "number": 3}, "level": "B1",
  "target_words": ["responsibility"],
  "prompt": {"ru": "Кто несёт ответственность за этот проект?", "uz": "Bu loyiha uchun kim mas'uliyatni o'z zimmasiga oladi?"},
  "fragments": ["who", "takes", "responsibility", "for", "this", "project"],
  "accepted": [["who", "takes", "responsibility", "for", "this", "project"]],
  "display": "Who takes responsibility for this project?",
  "explanation": {"ru": "take responsibility for + существительное — брать на себя ответственность за что-то. Who — подлежащее, поэтому does не нужен.",
                  "uz": "take responsibility for + ot — biror narsa uchun mas'uliyatni o'z zimmasiga olmoq. Who ega bo'lgani uchun does kerak emas."}
}
```

- The English sentence: **4 to 8 fragments (5-8 is best), normally one word each**, lower case (only `I` is capital). A
  fragment may be a chunk of two or three words when the words belong together (`"as a result"`, `"in charge of"`,
  `"a contract"`); a sentence may then have up to 10 words. The target word is one of the fragments (or inside one). No
  punctuation inside fragments; contractions (`don't`, `it's`) are one fragment. No personal names, no place names.
- `accepted`: the first order is the fragments themselves. **Then every other order that is also correct English and a
  fair translation of the prompt** (at most 6 in all). The safest task has exactly one such order, so build the sentence
  that way. The traps, one by one:
  1. *Movable adverbs and adverbials* - time, place, frequency and comment words (yesterday, now, every day, often,
     sometimes, usually, recently, finally, soon, already, still, also, probably, however, therefore, too) can stand in
     two or three places. Leave them out. Leave out discourse words too (so, then, now, well): with them the same
     fragments give a second sentence ("So why did she ..."). `always`, `never` and `not` have one place and are safe. When a movable adverb
     IS the target word, use a structure with one possible place (`extremely` before its adjective) or list every place.
  2. *A prepositional phrase that can open the sentence* - "in the morning", "at work", "for me", "with fear", "in my
     opinion", "after the lesson", "because of the rain": "For me this is important" = "This is important for me".
     A phrase that completes the verb, adjective or noun is safe (depends on the weather, lives in a village, responsible
     for the project, a relationship with her parents, admire / thank / blame somebody for something); a free one is not:
     avoid it or list both orders.
  3. *Two articles* - Russian and Uzbek have no articles, so the prompt cannot say which noun has `a` and which `the`:
     "a girl opened the door" / "the girl opened a door". If a sentence has `a` / `an` AND `the` as separate fragments
     and the nouns could exchange them, glue each article to its noun in one fragment (`"a contract"`), or use a pronoun
     subject, a possessive, a plural, `this` / `that`. (The checker warns about this.)
  4. *Reversible "A is B"* - "Breakfast is the most important meal" = "The most important meal is breakfast". Avoid a
     noun phrase on both sides of `is`, or list both orders.
  5. *Coordination and symmetric verbs* - "tea and coffee" / "coffee and tea", "tired but happy"; "introduced us to her
     partner" / "introduced her partner to us", "A met B", "A is similar to B", "A danced with B", and comparisons
     ("he is as stubborn as his father" / "his father is as stubborn as he"): do not build the sentence on two things
     that can change places - or block the exchange by agreement ("they are as stubborn as their father").
  6. *Two clauses* - "We stayed home because it rained" / "Because it rained we stayed home", an `if` / `when` clause
     first or last; a final "I think" / "I know" that can also open the sentence: avoid, or list both.
  7. *Phrasal verbs with a noun object* - "turn off the light" / "turn the light off": avoid or list both.
  8. *Two adjectives* before a noun; an *adverb of manner* ("carefully read the text" / "read the text carefully"); a
     *quantity phrase* ("spend too much time online" / "spend time online too much").
  9. A question and a statement from the same words ("is the report ready" / "the report is ready"), or the same words
     with another meaning ("expensive clothes are not always elegant"), are different sentences: fine, the prompt decides.
  After writing a sentence, **shuffle its fragments in your head and try to build any other grammatical sentence**; if a
  learner could offer it as a translation of the prompt, list it or rebuild the task.
- `display`: the sentence as printed after the answer - capital letter, final mark (`.` or `?`), the same words in the
  same order as the fragments (commas are allowed).
- `prompt`: the sentence in natural ru and uz, final mark. It must lead to exactly this English sentence: same tense, same
  number, nothing added or dropped; natural wording is better than a word-for-word one, as long as the learner can
  tell which English sentence is meant.
- `explanation` (ru and uz, each at most 300 characters): the pattern of the target word or the grammar point of the
  sentence, short and true.

## 5. Dictation task

```json
{
  "key": "b1-m07-l3-d1", "type": "dictation", "lesson": {"stage": 7, "number": 3}, "level": "B1",
  "target_words": ["colleague"],
  "text": "She is my new colleague",
  "accepted": ["She is my new colleague"],
  "translation": {"ru": "Она моя новая коллега", "uz": "U mening yangi hamkasbim"},
  "explanation": {"ru": "colleague: в середине ll, в конце -gue (g-u-e), хотя слышится только «г».",
                  "uz": "colleague: o'rtada ll, oxirida -gue (g-u-e) yoziladi, garchi faqat «g» eshitilsa ham."}
}
```

- `text`: a natural short phrase or sentence of **3 to 9 words, at most 60 characters**, capital letter first, **no final
  full stop** (a question keeps its `?`), no comma, no hyphen, no brackets, no quotes.
- **What is heard must have one spelling.** No names of people, places or brands, no digits, no number words (one, two,
  first...), no abbreviations. No word whose sound is another word that would also make sense here (their / there,
  whether / weather, right / write, to / too / two at the end). A lesson word that is one closed compound (roommate,
  birthplace) is fine - say in the explanation that it is written as one word.
- Ordinary words only. The checker prints `WARN ... LEXICON «word»` for a word the dictation lexicon does not know. If it
  is an ordinary, correctly spelled English word (the lexicon is small: `distant` is missing), you may keep it - **list
  every such word in your report**, it will be added to the lexicon. If you can as easily use a word the lexicon knows, do.
- American spelling in the text (neighbor, organize, traveled). The British spelling of a word is accepted automatically
  when the pair is in the application's list (`lexicon_lookup.php` shows "other spelling accepted too"). **List in your report every
  word of your dictation texts that has a British spelling and for which `lexicon_lookup.php` shows no pair** (for example a form
  such as quarreled / quarrelled) - the pair will be added.
- Write full forms, not contractions (`She is`, `do not`, `I will`). You write `"accepted": [text]` only; the checker
  run with `--write-accepted` adds the other writings the application's rule requires (She's ..., Don't ...). If it says
  the text gives more than 5 extra writings, rewrite the phrase with fewer such places.
- `translation`: the phrase in ru and uz, no final full stop (a question keeps `?`).
- `explanation` is OPTIONAL (ru and uz together, each at most 300 characters): add it only when the target word has a
  real spelling trap (a double or silent letter, -ei- / -ie-, -able / -ible, an ending heard differently, one word or
  two). It must be exact: the letters you name are the letters of the word. No explanation is better than a doubtful
  one - then leave the field out.

## 6. Language rules for every ru and uz text

- Address the learner formally: **«вы»** in Russian, **«Siz»** in Uzbek (-siz, -ingiz forms; never sen / -san / -gin, never
  ты / давай). The checker flags the informal forms it recognises; it also flags a few innocent words that look like them
  (uz words ending in -gin, -ding, -sang such as `g'amgin`; ru words ending in -ешь / -ишь such as `брешь`): rephrase.
- Russian: natural literary Russian, `ё` where it belongs, «ёлочки» for quotes. English `I` with a past verb or a short
  adjective forces a gender in Russian (я готов / готова): prefer a subject or a tense that does not (we, they, she, the
  present); where `I` is needed, the masculine form is the default.
- Uzbek: Latin script, the ASCII apostrophe `'` only (`o'`, `g'`, `ma'no`), natural literary Uzbek, the word an educated
  speaker in Uzbekistan would use, verb at the end; not a calque from Russian. Kinship words that force a choice
  (sister = opa / singil, brother = aka / uka): choose one and keep the ru text compatible.
- An English word quoted in an explanation stays as it is. In Uzbek do not glue a suffix to it with an apostrophe or a
  hyphen: write a noun after it and put the suffix there (`meet fe'li bilan`, `to predlogisiz`, `deadline so'zidan keyin`),
  -siz, -li, -dagi always go on such a noun (never `to siz`: it reads as "to you"). A bare case ending may stand as a
  separate particle, as in the lower packs (`prove ga o'xshaydi`).
- Never the characters `<` or `>`. No double spaces, no line breaks inside a text.
- Russian and Uzbek texts of one task say the same thing. (A note that compares English with the learner's language -
  "русское «с» здесь to", "o'zbekcha «-ga» bu yerda for" - naturally differs; the claims about English must be the same.)
- The same fragment twice in a sentence (two `as`, two `the`) is safe: the machine compares the text, not the pieces.

## 7. Content rules

- The other words of a task are easy: the learner of this course knows the lower courses and common everyday words; do not
  bring in words harder than the course level. Grammar of B1 tasks: the common tenses, modals, passive, first and second
  conditional; B2 tasks may use any natural structure.
- Each English sentence is new: not the example of a card (the checker compares), not a sentence used in another task.
  Vary the patterns inside a lesson.
- Neutral, safe content: nothing violent, sexual, about drugs, alcohol or gambling; no real people, brands, parties,
  countries in conflict. Modules on politics, law and society stay general.
- American spelling (organize, color, center), as in the cards.

## 8. How to work

1. Read this brief, a lesson or two of a finished file, and all ten lesson files of your two modules.
2. For each lesson plan first: which word gets which type (collocation words first: the ones with a strong collocation;
   words with no collocation at this level - surname, twin, nephew - go to dictation or a simple sentence).
3. Write the tasks into your generator script, run it, run the checker; fix until `0 errors`.
4. **Then proofread your own file once more, task by task, as an editor who wants to prove each task wrong:**
   - collocation - is each wrong option impossible here, in every variety of English? does the sentence show the card's
     meaning? is the explanation true?
   - sentence - go through the nine traps of section 4 for THIS sentence; is there any other order? do the ru and the uz
     prompt both give exactly this sentence?
   - dictation - would a learner who hears it know how to write every word?
   - all - are the translations exact and natural, in Russian and in Uzbek? is every statement of the explanations true?
5. Run the checker again after the last edit.

## 9. Report (compact; it is read by the coordinator)

- The final line of the checker, and every WARN you left with the reason.
- `LEXICON:` the ordinary words of your dictation texts that the lexicon does not know (one list).
- `BRITISH:` the words of your dictation texts that have a British spelling with no pair in `lexicon_lookup.php` (one list, as
  `american / british`).
- The tasks you are not sure about (key, what to check) - they go to the editor and to the native proofreader.
- Lesson words you found hard to give a good task to.
