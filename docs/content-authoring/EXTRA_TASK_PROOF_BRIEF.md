# Proofreading «Word form» and «Find the mistake» tasks (English for Russian and Uzbek speakers)

You are the editor. Another writer produced the word_form and error_hunt tasks of two modules (one x-file, 60 tasks);
you examine every task and fix what is wrong, directly in the file. The rules the tasks must meet are in
EXTRA_TASK_BRIEF.md (same directory): **read it first, all of it**, then TASK_BRIEF.md section 6 (the ru / uz rules),
then the ten lesson files (the cards) of your modules. Thousands of learners are graded by a machine against these
tasks: one unlisted right form, one second word that could be "the mistake", one acceptable "wrong" correction or one
false explanation is a real harm. In the pilot the editors changed 12 and 23 of 60 tasks after the writers had proofread
their own files. Read slowly. Do not trust the writer, and do not trust the checker: it only finds mechanical faults.

## What to do with each task

Work as an examiner who wants to prove the task wrong. Remember what the learner has: for word_form only the English
sentence with its gap and the base word - no translation, no word count; for error_hunt the sentence as chips, then three
corrections of the word he tapped.

### word_form
1. **Look for a second right form.** Fill the gap with every member of the base word's family: every tense and aspect,
   active / passive, singular / plural, `'s`, positive / negative (`un-, in-, im-, dis-`), `-ed / -ing`, the gerund, the
   noun of the person / the thing / the institution (`consultant / consultancy`), the other derivative of the same root
   (`productivity / production`), comparative / superlative, `more` + adjective, the British and the American form
   (`learned / learnt`, `got / gotten`). Any that gives a correct, natural sentence - whatever it means - is a right
   answer. Rebuild the sentence so that it is not (the cheapest pin: a second verb for the tense, `a` or a plural for the
   number, a clause that decides positive / negative, `as a ___` for a person), or list it in `accepted` (at most 4, the
   canonical one first). The traps are listed in EXTRA_TASK_BRIEF section 3.3: go through all eleven for this sentence.
2. **A form of another length.** Could a learner naturally type two words (`is cooking`, `more happy`, `will clean`,
   `the best`) or one where the key has two? Rebuild so that the natural answer has the key's length, or list it.
3. **The base itself.** Does the unchanged base word fit naturally? Then a learner who leaves it is marked wrong: rebuild.
4. **Is the key right?** Is every listed form correct English, correctly spelled (the lexicon calls `wifes`, `childs`,
   `rised` "known"), natural in this sentence?
5. Base: a real, ordinary word of the level, in its dictionary form, related to the answer by standard morphology with
   the same core meaning (not `proceed -> procedure`). The lesson word is the base or the answer, in the card's meaning.
6. Level: is the form in the table of EXTRA_TASK_BRIEF 3.4 for this level or below? Is the task demanding (section 7)
   - not a bare `-s` / `-ed` at B1 and above?
7. Explanation: does it name the clue that is really in the sentence, and the rule / spelling of the form? Is it true?

### error_hunt
1. **Look for a second place to click.** Take every other word of the sentence and try: its other form, its other number
   or gender, a near-synonym of the level (`arrived -> went`, `interested -> keen`, `father -> parents`), another function
   word (`and -> or`, `the -> a`), and inserting or deleting one word anywhere. If one such change makes the sentence
   correct while the marked word stays, a learner who taps that word is marked wrong for a good answer: pin it (a second
   agreeing word, `both ... and`, `own`, a second gender clue) or rebuild the task. Replacing a content word by an
   unrelated one (`a hour -> a day`) does not count.
2. **Is the marked word wrong beyond doubt** in standard British AND American English, in edited writing? Not a disputed
   usage, not one variety against the other, not an accepted alternative (EXTRA_TASK_BRIEF 4.4 lists them). Is it wrong
   in every reading of the sentence (`I *lay* on the beach yesterday` is correct)?
3. **Read the corrected sentence:** natural, everything else perfect, the right correction the natural one?
4. **Attack each wrong correction:** put it in place and say the whole sentence. Is it said in standard English, in any
   structure (`was filled by envy` exists)? Is it in a learner's dictionary or common in edited text (`outlook to life`,
   `interfere with`)? If "maybe", replace it with one that is impossible here and still tempting (the other member of the
   pair, the calque, the wrong form of the same word) - a real word, never a non-word.
5. **The look of the options:** the right one first; same kind and same number of words; none of them a word that already
   stands in the sentence; the letter case (EXTRA_TASK_BRIEF 4.5: all capital for a mistake in the first word, otherwise
   one case, with `I`, `I'm`, `I've`, `I'll`, `I'd` neutral).
6. **Repairs by insertion:** a correction that adds a word is allowed only when the marked word itself changes too
   (`*authorize*` -> `be authorized`). A pure missing word (`depends *the* weather`) or an extra word that only deletion
   repairs: rebuild.
7. Target: the mistake concerns the lesson word (its form, its preposition, its pattern, its confusable twin) - not a
   grammar slip somewhere else with the lesson word as decoration (one such task per lesson only in lessons of
   interjections or function words). The lesson word stands in the corrected sentence as itself or a regular form.
8. Level and demand: is the mistake one a learner of THIS level makes, from the table of 4.6? Not the trivially obvious
   one?
9. Explanation: why the marked word is wrong and what the rule is; true; ru and uz make the same claims about English.

### every task
- The sentence is natural, logical (who welcomes whom), at the level; no names of people, places or brands, no digits;
  American spelling.
- Not a copy and not a near-copy of a card example (the checker compares exact sentences only; compare with the cards
  yourself), not a copy of another task.
- Translation: the whole correct sentence, exact and natural, in ru and in uz; the card's translation of the lesson word
  where natural.
- Russian: natural, literary, «вы», `ё`. Uzbek: natural literary Uzbek in Latin script with the ASCII apostrophe, «Siz»,
  the word an educated speaker in Uzbekistan uses, not a calque from Russian, correct suffixes and x / h, q / k, o' / u.
  Most mistakes hide in the Uzbek (EXTRA_TASK_BRIEF section 6 lists those of the pilot).
- English words in an Uzbek explanation keep -siz, -li, -dagi on a noun after them (`depend fe'lidan keyin`).

### the whole file
- Coverage: the six tasks of a lesson target six different lesson words (five only where the lesson cannot give six).
- Variety: count the error_hunt tasks by kind of mistake - three different kinds in a lesson, one kind at most 5 times in
  the file, all prepositions together at most 9, one contrast (`a -> an`) at most twice, one option set at most twice.
  A lesson's three word_form tasks: not three of one kind where the words allow more. Fix what is over the cap by
  rewriting the weakest tasks (keeping their lesson word where possible).

## Rules of the edit
- Keep `key`, `type`, `lesson`, `level` and the order of the tasks. Change `target_words` only together with the task's
  sentence, and only to a word of the same lesson that keeps the lesson's coverage.
- Fix what is wrong or clearly improvable, and what is too easy for the level; do not rewrite good tasks for taste.
- Edit ONLY your file. Keep the layout (`json.dumps(data, indent=2, ensure_ascii=False) + "\n"`); the simplest way is a
  small script outside the repository that loads the JSON, changes fields by key and dumps it. Never edit the lexicon:
  an ordinary word it lacks goes into your report under `LEXICON:` (EXTRA_TASK_BRIEF section 8).
- After editing: `php docs/content-authoring/check_practice.php --modules=<M1>,<M2> <file>` until
  `60 tasks checked, 0 errors` - or until the only errors left are `... is not in the lexicon` for words listed under
  `LEXICON:` (the writer's and yours); look at every WARN.

## Report (compact; it is read by the coordinator)
- How many tasks you changed out of 60, by type.
- The substantive fixes as `key: field - old -> new - why` (typo-level fixes may be counted, not listed).
- The final line of the checker, every WARN you left and every remaining (lexicon) error, with the reason.
- `LEXICON:` the full list for the file (the writer's words still needed, plus yours), as `word (key)`.
- Counts after your edit: error_hunt by kind of mistake (prepositions in total), word_form by kind of form.
- For the native proofreader: the tasks you still doubt (key, what to check) - not more than about ten, the real doubts.
