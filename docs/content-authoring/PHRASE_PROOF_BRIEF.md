# Proofreading lessons of the phrase course (English phrases for Russian and Uzbek speakers)

You are the editor. Another writer produced the phrases of some lessons of the course `english-phrases`; you read every entry
and fix what is wrong, directly in the files. The rules the entries must meet are in `PHRASE_BRIEF.md` (same directory):
**read it first, all of it**, then the plan of your lessons in `PHRASE_PLAN.md`. One false equivalent or a wrong register
teaches thousands of learners to say something odd - or rude - in a real conversation. Read slowly. Do not trust the writer.

Two kinds of reader are needed, and the work is split by language:

- **The English check** - by an editor fluent in English (ideally a native speaker): the phrase, its use, the example.
- **The Uzbek check** - by a native speaker of Uzbek: the Uzbek equivalent, literal meaning, hook and example translation.
  The Russian texts are checked by whichever of the two is fluent in Russian (normally both are).

If you are only one of the two, do your part fully and list the entries the other part must look at.

## 1. What the English check proves wrong

Work as an examiner who wants to prove the entry wrong.

1. **Is the phrase real and current?** Would a native speaker say it today, in this exact form, in the situation of the
   lesson? A phrase from lists of idioms that people do not actually use, a dated one, a wrong word in a set phrase
   (*on the other side of the coin* for *the other side of the coin*), a mixed-up idiom - an error. Is `frequency` honest?
2. **The meaning.** Does the equivalent give THE meaning of the phrase in this lesson, not a secondary one or a meaning
   from another situation? (*I'm off* - "I'm leaving", not "I'm ill".) Does a phrase with two common meanings need a
   different example or hook?
3. **The register.** Could a learner offend someone or sound strange by using the phrase where the card lets them? Is
   `informal` / `formal` / `slang` right? Anything rude or with a rude second meaning - out, and say so.
4. **UK / US.** Is `variant` right? Would an American or a Briton find the phrase foreign? Spelling of the example follows
   the variant (`colour` in a `uk` example is fine; in a `both` one use the American spelling).
5. **The level.** `cefr` within the band of the module (or one below)?
6. **The key word.** Is `gap` the word that carries the meaning? Could the learner type another word that also fits the gap
   (*a piece of ____*: `cake`, but also `pie`?) - if so, is the other word really wrong in this phrase? A gap with a second
   right answer is an error: choose another key word.
7. **The example.** Natural English? Does it show the situation of the hook? Does it contain the phrase (allowed changes:
   PHRASE_BRIEF §9)? Two lines joined by ` — `, no `A:` / `B:`? Search the example (in quotes) on the web: an exact match in a
   dictionary or idiom site is a copy - rewrite it.
8. **The literal meaning** (ru) - really word for word, and different from the equivalent?
9. **Duplicates** - the checker finds the same phrase; you look for near-duplicates in your lessons (*take it easy* and
   *take things easy*) and keep one.

## 2. What the Uzbek check proves wrong

1. **The equivalent.** Is this what an Uzbek speaker says in the same situation, with the same tone? A calque from Russian
   or a word-for-word translation of the English is an error. For a proverb or an idiom: does Uzbek have its own proverb or
   set phrase with the same meaning and tone (*Ko'z qo'rqoq, qo'l botir*)? If yes - prefer it, unless its image would mislead.
   If no - a short plain rendering of the meaning.
2. **The register in Uzbek**: «sen» for informal phrases, «siz» for neutral and formal ones; no bookish word where people
   use a common one, no rough word where the English is neutral.
3. **Spelling**: Latin script, ASCII apostrophe `'` (o', g', tutuq belgisi: ma'no, e'tibor), correct x / h, q / k, o' / u,
   suffixes, word order (verb last). The checker finds Cyrillic and typographic apostrophes, not misspelt words.
4. **The literal meaning** (uz): word for word and grammatical; different from the equivalent.
5. **The hook** (uz): when and to whom - and it says the same as the Russian hook.
6. **The example translation** (uz): faithful and natural, the two lines and the dash kept; the equivalent of the phrase in
   the needed form where natural.
7. ru and uz say the same thing as each other.

## 3. Rules of the edit

- Do NOT change `stage`, `lesson`, the number or order of entries, or the file layout (`json.dumps(data, indent=2,
  ensure_ascii=False) + "\n"`; the simplest way is a small script that loads the JSON, changes fields and dumps it).
- You may replace a phrase that is wrong for the course (not current, wrong level, near-duplicate) - with a phrase of the
  same topic, and the whole entry rewritten; say so in the report.
- Fix what is wrong or clearly improvable; do not rewrite good entries for taste.
- After editing run the checker until your files have no errors:

      python3 docs/content-authoring/check_phrases.py database/data/courses/english-phrases

## 4. The form of your remarks

The report is read by the coordinator; doubts go on to the owner's native speaker (`docs/CONTENT_PHRASES_REVIEW.md`).
Keep it compact:

- **Counts**: entries changed out of entries read, by field (`word`, `translations`, `literal`, `hooks`, `example`, `phrase.*`).
- **Fixes** - one line each, typo-level fixes only counted:

      m03-l2 #4 «Here you go» | translations.uz | "mana sizga" -> "mana, oling" | "mana sizga" sounds like a reproach

- **Replaced phrases**: `file #n «old» -> «new» - why`.
- **For the native speaker** - not more than about ten real doubts per two modules:

      m14-l5 #2 «Too many cooks spoil the broth» | translations.uz | a plain rendering of the meaning | is there an Uzbek proverb with this meaning and tone?

- The last line of the checker.
