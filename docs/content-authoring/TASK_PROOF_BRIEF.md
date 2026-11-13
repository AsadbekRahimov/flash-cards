# Proofreading practice tasks of a vocabulary course (English for Russian and Uzbek speakers)

You are the editor. Another writer produced the practice tasks of some modules; you examine every task and fix what is
wrong, directly in the file. The rules the tasks must meet are in TASK_BRIEF.md (same directory): **read it first, all of
it**, then the lesson files (the cards) of your modules. Thousands of learners are graded by a machine against these
tasks: one unlisted right answer or one false explanation is a real harm. Read slowly. Do not trust the writer.

## What to do with each task

Work as an examiner who wants to prove the task wrong.

### collocation
1. **Attack each wrong option.** Put it into the sentence and ask: is this said in standard British or American English?
   Is the phrase in a learner's dictionary or common in edited text? Does it give another meaningful sentence? If
   "maybe" - the option is not wrong enough: replace it with one that is impossible here (keep three single lower-case
   words of one part of speech and form, the correct one first), and fix the explanation if it named the old one. A
   phrase that lives only in a regional variety and that British and American dictionaries mark as an error may stay
   when it is the typical calque; list it for the native proofreader.
2. Is the right option really the natural one in this sentence, and the only one of the three?
3. Does the sentence use the target word in the meaning of its card? Is the gap not the first word; does an article before
   the gap fit all three options?
4. Translation: the whole sentence, exact and natural in ru and uz.
5. Explanation: is every statement true? Does it explain this collocation (not something else)? Do ru and uz make the
   same claims about English (a note comparing English with Russian or with Uzbek naturally differs)?

### sentence
1. **Look for another correct order.** Go through the nine traps of TASK_BRIEF section 4 for this very sentence. Take
   the fragments and try, one by one: every adverb or adverbial in another place; a free prepositional phrase moved to
   the front (a phrase that completes the verb, adjective or noun - ask about, ready for, a relationship with, admire
   somebody for - is safe); `a` and `the` exchanged between two nouns; the two sides of `is` / `are` or of a comparison
   swapped; coordinated words swapped; clauses swapped; the particle of a phrasal verb moved; adjectives swapped; adverb
   of manner moved; a discourse word (so, then, now) opening a second sentence. Every order that is correct English with the same meaning must be in
   `accepted` (first order = the fragments; at most 6). If there are more than you can list, or you are not sure,
   rewrite the sentence so that only one order is possible - and then `fragments`, `accepted`, `display`, `prompt` and
   `explanation` together. The cheapest repairs first: drop the movable phrase; change the subject so that agreement
   blocks the exchange; turn the sentence into a wh-question. The same words with ANOTHER meaning ("expensive clothes
   are not always elegant") are not a second answer: leave them. The same fragment twice is safe.
2. Conversely: is every listed order really correct English?
3. Do the ru and the uz prompt each lead to exactly this sentence (tense, number, articles implied, nothing added or left
   out)? Could the prompt be translated with the same words in a different order that is NOT accepted?
4. Is the English natural and at the level of the course? Target word in the card's meaning?
5. Explanation true and useful.

### dictation
1. Is the phrase natural English, 3-9 words, with the target word in the card's meaning?
2. Would a learner who hears it know how to write every word? A homophone (their / there, weather / whether) is a
   fault only where the other word would also make sense in this phrase; a name or a number always is. If the phrase can
   be heard as another correct sentence, rewrite it.
3. Translation exact and natural in ru and uz (a natural idiom wins over the card's word).
4. Explanation (if any): the letters it names are the letters of the word; the claim is true. Delete a doubtful one
   (remove the whole `explanation` field).
5. Do NOT edit `accepted` by hand: after changing a `text`, set `"accepted": [text]` and run the checker with
   `--write-accepted`.

### every task
- Russian: natural, literary, «вы», `ё`. Uzbek: natural literary Uzbek in Latin script with the ASCII apostrophe, «Siz»,
  the word an educated speaker in Uzbekistan uses, not a calque from Russian, correct suffixes and x / h, q / k, o' / u.
  Most mistakes hide in the Uzbek.
- The translation of the target word agrees with its card wherever natural.
- An English word quoted in an Uzbek explanation: the suffixes -siz, -li, -dagi go on a noun after it (`to so'zisiz`,
  never `to siz`); a bare case ending may stand apart (`prove ga`). Explanations are at most 300 characters (the checker
  enforces it through the application's validator).
- Safe, neutral content; no names of people; American spelling.
- Coverage of a lesson (at least 9 different target words in its 9 tasks) and the keys must stay as they are.

## Rules of the edit
- Keep `key`, `type`, `lesson`, `level` and the order of the tasks. Change `target_words` only together with the task's
  sentence, and only to a word of the same lesson, keeping the lesson's coverage.
- Fix what is wrong or clearly improvable; do not rewrite good tasks for taste.
- Edit ONLY your file. Keep the layout (`json.dumps(data, indent=2, ensure_ascii=False) + "\n"`); the simplest way is a
  small script that loads the JSON, changes fields by key and dumps it.
- After editing: `php docs/content-authoring/check_practice.php --write-accepted --modules=<M1>,<M2> <file>` until `90 tasks checked, 0 errors`; look at every WARN.

## Report (compact; it is read by the coordinator)
- How many tasks you changed out of 90, by type.
- The substantive fixes as `key: field - old -> new - why` (typo-level fixes may be counted, not listed).
- For the native proofreader: the tasks you still doubt (key, what to check) - not more than about ten, the real doubts.
- The final line of the checker and any WARN you left, with the reason.
