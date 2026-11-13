# Proofreading lesson files of a vocabulary course (English for Russian and Uzbek speakers)

You are the editor. Another writer produced the word cards of some modules; you read every card with a critical eye and fix
what is wrong, directly in the files. You must be fluent in English, Russian and Uzbek for this; read slowly - one bad card
teaches thousands of learners a mistake. The writing rules the cards must meet are in CARD_BRIEF.md (same directory): read it
first.

## What to look for, card by card
1. **Meaning.** Is the translation the main meaning of the word IN THIS LESSON's topic, in the stated part of speech? Do the
   Russian and the Uzbek translation mean the same thing as each other? A secondary or wrong sense, a false friend, or a
   translation that fits another part of speech is an error.
2. **Example.** Is the English natural and correct? Does it use the word in the meaning of the translation and show that
   meaning? Is it within the level of the course? 5-10 words, the word itself or a regular form (no doubled consonant, no
   irregular form).
3. **Russian.** Natural literary Russian, correct grammar, ё where it belongs. The example translation must be faithful and
   contain the card's translation (in the needed form) wherever that is natural.
4. **Uzbek.** This is where most mistakes hide. Natural literary Uzbek in Latin script, ASCII apostrophe ('), correct
   suffixes and vowel/consonant spelling (x / h, q / k, o' / u), the word an educated speaker in Uzbekistan would actually
   use - not a calque from Russian, not a rare bookish synonym when a common word exists, a loanword only where it IS the
   normal word. Verbs in -moq. The example translation must be idiomatic Uzbek word order (verb last) and contain the card's
   translation in the needed form.
5. **Consistency.** The same English word family or the same realia should be translated the same way across your files;
   polite address (вы / siz) unless the sentence is clearly informal.
6. **Shared translations.** The app has matching games, so two different words of one course should not have the identical
   translation. Run `python3 docs/content-authoring/dup_translations.py <pack dir>` - for every pair it lists that has a word in YOUR
   modules, make the translations distinguishable when the language allows it (add the distinguishing second word, e.g.
   "средний (о школе)" is NOT wanted - prefer a natural two-word form such as "средний, усреднённый" vs "средний по размеру"
   only if natural; if the two words truly have one translation, leave it and list it in the report). Change only cards in
   your own modules.
7. **Safety.** Nothing violent, sexual, about drugs, alcohol or gambling; no real brands, people, parties or conflicts.

## Rules of the edit
- Do NOT change `word`, the order of words, `stage` or `lesson` blocks, or the file layout (2-space indent, nested objects
  expanded, trailing newline, `translation` identical to `translations.ru`).
- Fix only what is wrong or clearly improvable; do not rewrite good cards for taste.
- After editing, run the checker for your modules until it prints "0 problems".

## Report
How many cards you changed out of how many read; the list of substantive fixes as `word (file): field - old -> new - why`
(typo-level fixes can be counted, not listed); shared translations you could not separate; and any card you still doubt -
those go to the native proofreader.
