# Writing lesson files for a vocabulary course (Russian and Uzbek learners of English)

You write the word cards of some modules of one course of a Telegram learning app. The syllabus (modules, lessons, the exact
10 words of every lesson) is FIXED and given to you; you add, for every word: part of speech, Russian and Uzbek translation,
one English example sentence and its Russian and Uzbek translations. The cards are read by real learners, including
schoolchildren, and a native speaker will proofread them later - write them as carefully as a published textbook.

## Files
- One file per lesson: `<pack dir>/mNN-lN.json` (module number two digits, lesson number one digit), e.g. `m07-l3.json`.
- Exactly this shape, keys in this order, UTF-8, 2-space indent, a newline at the end of the file:

```json
{
  "course": "<course slug>",
  "stage": { "number": 7, "title": "<module title ru>", "title_uz": "<module title uz>" },
  "lesson": { "number": 3, "title": "<lesson title ru>", "title_uz": "<lesson title uz>" },
  "words": [
    {
      "word": "withdraw",
      "part_of_speech": "verb",
      "translation": "снимать со счёта",
      "translations": { "ru": "снимать со счёта", "uz": "yechib olmoq" },
      "example": "You can withdraw money at this machine.",
      "example_translations": { "ru": "Вы можете снять деньги в этом банкомате.", "uz": "Bu apparatdan pul yechib olishingiz mumkin." }
    }
  ]
}
```
- `stage` and `lesson` (numbers and both titles) are copied from the syllabus exactly. The words are the 10 words of the
  syllabus, in the syllabus order. Do NOT replace, respell, drop or reorder a word. If a word looks wrong for the lesson or
  impossible to teach under the rules below, still write the best card you can and name it in your report.
- `translation` is always identical to `translations.ru`.
- Look at two or three finished files of an existing course for the tone: `database/data/courses/english-b1/m07-l3.json`,
  `database/data/courses/english-b2/m12-l4.json` (paths from the root of the repository).

## part_of_speech
One of: noun, verb, adjective, adverb, pronoun, preposition, conjunction, interjection. Choose the part of speech in which the
word belongs to THIS lesson's topic at this level; the translation and the example must use the word in that part of speech.

## Translations (`translations.ru`, `translations.uz`)
- The main meaning of the word in this lesson's topic - the one a learner should remember first. Not a definition, not a list:
  one translation, or two close synonyms separated by a comma when one word would mislead. Up to about 4 words, lowercase,
  no final period.
- Form: nouns in the nominative singular; verbs in the infinitive (ru imperfective when natural: "поддерживать"; uz with -moq:
  "qo'llab-quvvatlamoq"); adjectives in the masculine singular (ru) / base form (uz).
- Russian: standard literary Russian, with the letter ё where it belongs (счёт, учёба, ещё).
- Uzbek: standard literary Uzbek in LATIN script with the plain ASCII apostrophe ' for o', g' and the tutuq belgisi
  (o'qish, g'oya, ma'lumot, e'tibor). Never ʻ ’ ‘ ` and never Cyrillic letters. Prefer the Uzbek word a teacher in Uzbekistan
  would use; use an international loanword (kompyuter, immunitet) only when that IS the normal Uzbek word. Do not transliterate
  Russian when an Uzbek word exists.
- The ru and uz translations must mean the same thing as each other and as the word in the example.

## Example (`example`)
- One natural English sentence of 5 to 10 words, starting with a capital letter and ending with . ! or ?
- It contains the word ITSELF or a regular form of it (+s, +es, +ed, +d, +ing, y -> ies / ied, e -> ing), used in the meaning of
  the translation and in the stated part of speech. Not an irregular form, not a different word of the same family
  (for "decide" the example may say "decided", not "decision").
- The sentence shows what the word means (a learner should be able to guess it from the context), fits the topic of the lesson,
  and uses grammar and other vocabulary no harder than the level of the course. Vary the sentences: different subjects, tenses,
  statements and questions; do not start every sentence with "The" or "I".
- American spelling. No brand names, no real people, no politics of real countries, nothing violent, sexual, about drugs,
  alcohol or gambling. Everyday names (Anna, Tom, Aziz, Malika, Mr. Brown) are fine in moderation.

## Example translations (`example_translations.ru`, `example_translations.uz`)
- Faithful, natural translations of the example - how a good translator would say it, not word for word. They must contain
  the translation of the word (in whatever grammatical form the sentence needs), so that the learner sees the card's
  translation at work. Address "you" politely: ru "вы", uz "siz", unless the sentence is clearly said to a child or a friend.
- Same script rules as above (ru Cyrillic with ё; uz Latin with the ASCII apostrophe).

## Check before you report
Run the checker for your modules and fix everything until it prints "0 problems":

    python3 docs/content-authoring/check_pack.py <pack dir> <syllabus file> <module> <module>

It checks the structure and the mechanical rules only. Then re-read every card yourself, the way a proofreader would, for what
no script can see: wrong or secondary meaning, ru and uz saying different things, an example that does not show the meaning,
unnatural Uzbek or Russian, typos. Fix what you find and run the checker again.

## Report
The modules written, the final checker line, and a short list of cards you are not sure about (word, what bothers you).
