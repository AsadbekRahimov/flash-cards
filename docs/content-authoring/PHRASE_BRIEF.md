# Writing lessons of the phrase course (English phrases for Russian and Uzbek speakers)

You write the phrases of some lessons of the course `english-phrases` of a Telegram learning app: 1 000 everyday English
phrases (greetings, set expressions, idioms, phrasal verbs, proverbs) in 100 lessons of 10. The plan (module, lesson, topic,
level, 3-5 anchor phrases per lesson) is FIXED: `docs/content-authoring/PHRASE_PLAN.md`. For every lesson you choose the
10 phrases and write, for every phrase, its Russian and Uzbek equivalent, a short note on when it is said, an example and
its translations, and the block `phrase` (kind, register, UK/US, key word, literal meaning, level, frequency). The cards are
read by real learners, schoolchildren among them, and a native speaker will proofread them later - write them as carefully
as a published phrasebook.

**The checker being green says nothing about quality.** `check_phrases.py` only finds mechanical faults. A false equivalent,
a wrong register, a dated idiom or an unnatural example is found only by reading - that is the main part of your work.

## 0. What the learner sees (the rules below follow from it)

- **The card**: the phrase (up to 3 lines on a narrow phone), a label of the register ("informal", "formal", ...), "UK" or
  "US" when the phrase belongs to one country, the equivalent in the learner's language, "Literally: ..." (the literal
  meaning), "When it is said: ..." (the hook), the example - read aloud by a speech engine.
- **Choice exercises**: the meaning of the phrase among 4 meanings, the phrase among 4 phrases of the course (2 lines each),
  the phrase heard. The other options are other phrases of the same lesson and course.
- **The gap exercise**: the phrase with its **key word** hidden (`Easy come, easy ____`) and the equivalent as the hint; the
  learner types the key word. The whole phrase is never typed.
- A Free learner gets 10 new cards a day: one lesson of 10 phrases is one day.

## 1. Files

- One file per lesson: `database/data/courses/english-phrases/mNN-lN.json` (module two digits, lesson one digit:
  `m07-l3.json`). Exactly this shape, keys in this order, UTF-8, 2-space indent, a newline at the end. Write the file with a
  small script of your own kept OUTSIDE the repository (`json.dumps(data, indent=2, ensure_ascii=False) + "\n"`).
- `course` is `english-phrases`; `stage` and `lesson` are the number and both titles from the tables of `PHRASE_PLAN.md`,
  letter for letter. Exactly 10 entries.
- The test pack `database/data/courses/english-phrases/` may already hold files of other writers: do not touch them.

```json
{
  "course": "english-phrases",
  "stage": { "number": 1, "title": "Знакомство и вежливость", "title_uz": "Tanishuv va xushmuomalalik" },
  "lesson": { "number": 1, "title": "Приветствия", "title_uz": "Salomlashish" },
  "words": [ ...10 entries... ]
}
```

An entry - keys in this order (`examples` and `synonyms` are optional, the rest is required in this course):

```json
{
  "word": "Long time no see",
  "part_of_speech": "phrase",
  "translations": { "ru": "сто лет не виделись", "uz": "ko'rishmaganimizga ancha bo'ldi" },
  "example": "Aziz! Long time no see! How are you?",
  "example_translations": { "ru": "Азиз! Сто лет не виделись! Как ты?", "uz": "Aziz! Ko'rishmaganimizga ancha bo'ldi! Qalaysan?" },
  "hooks": { "ru": "Радостно говорят старому знакомому, которого давно не встречали", "uz": "Uzoq vaqt ko'rmagan tanishga quvonib aytiladi" },
  "phrase": {
    "kind": "formula",
    "register": "informal",
    "variant": "both",
    "gap": "long",
    "literal": { "ru": "долгое время не видеть", "uz": "uzoq vaqt ko'rmaslik" },
    "cefr": "B1",
    "frequency": 2
  }
}
```

Do not write `translation` (the import takes `translations.ru`), `transcription`, or any key not shown here.

## 2. Choosing the 10 phrases of a lesson

- **Frequent, current, spoken.** A phrase a native speaker really uses today in the situation of the lesson - not a phrase
  found only in lists of "English idioms". Ask: would a British or American person of 25 say this to a friend, a colleague,
  a shop assistant this year? Dated or bookish idioms (*it's raining cats and dogs* is famous but rare in real speech) need a
  reason: keep one only if learners meet it everywhere, and mark `frequency` 3.
- **The anchors** of the plan are the core of the lesson: use them unless one is wrong for the topic or the level - then
  replace it and say so in the report. Add 6-7 phrases of the same topic.
- **The level.** `cefr` of each phrase is within the level band of its module (`PHRASE_PLAN.md` §2) or one level below.
  Inside a module, the earlier lessons hold the more frequent phrases.
- **Nothing twice.** A phrase never repeats in the course - not even with an article, another capital letter, final
  punctuation or apostrophe (`A piece of cake` = `piece of cake!`). The checker compares every file of the pack. Two
  variants of one phrase (`Can I have the bill?` UK / `Can I get the check?` US) are two entries only when both are frequent.
- **Mix the kinds** inside a lesson where the topic allows (formula, fixed, idiom, phrasal verb, proverb; §4).
- **Sources and rights.** Collect candidates in several places and check frequency in open data (Google Books Ngram); check
  doubtful phrases by hand. **Never copy** definitions, examples or lists from Cambridge, Oxford, Longman, Merriam-Webster,
  Macmillan, Collins, Wiktionary or idiom websites: every text of the card is written by you. The editor searches your
  examples for exact matches.

## 3. The phrase (`word`)

- The canonical form, at most **50 characters** (the import allows 100, the card does not).
- **A whole utterance** (formulas, proverbs, a question) is written as said: capital letter, `?` or `!` where it belongs, no
  final period - `How's it going?`, `No way!`, `Better late than never`. **A part of a sentence** (an idiom, a phrasal verb, a
  fixed expression) - small letter, no final punctuation: `break the ice`, `figure out`, `by the way`.
- The article belongs to the phrase when it is always said: `a piece of cake`, `the last straw`.
- A slot is written with a pronoun or `someone` / `something`: `make up your mind`, `pull someone's leg`, `get on my nerves`.
  Never `sb`, `sth`, slashes or brackets (`on/at`, `(the)`).
- The straight apostrophe `'` in English too.

## 4. The block `phrase`

| Field | What to write |
|---|---|
| `kind` | `formula` - a ready reply or greeting said as a whole (*No worries*, *How's it going?*); `fixed` - a set expression used inside a sentence, meaning clear from its words (*by the way*, *on time*, *take a break*); `idiom` - the meaning is figurative, not the sum of the words (*a piece of cake*, *break the ice*); `phrasal_verb` - verb + particle(s) (*figure out*, *get along with*); `proverb` - a saying with a lesson (*better late than never*) |
| `register` | `neutral` - fine anywhere; `informal` - friends, family, colleagues you know; `formal` - letters, official speech, a client; `slang` - young, very casual speech (rare in the course: module 19 and single cases); `rude` is **never** used |
| `variant` | `both` (default); `uk` or `us` only when people of the other country do not say it or it sounds foreign to them |
| `gap` | the key word - see §5 |
| `literal` | `{ "ru": ..., "uz": ... }` - the word-for-word meaning, see §7. Leave the key out when it adds nothing |
| `cefr` | `A1`...`C2`: the level at which a learner should understand and use the phrase |
| `frequency` | `1` - very frequent (heard every day), `2` - frequent, `3` - met now and then |
| `synonyms` | optional: up to 10 English phrases with the same meaning and register (`win some, lose some` for *easy come, easy go*). Not the phrase itself |

## 5. The key word (`gap`)

The learner sees the phrase without this word and types it. Choose the word that **carries the meaning** and that the
learner can recall only if they know the phrase:

- the noun or verb of the image: `cake` in *a piece of cake*, `ice` in *break the ice*, `moon` in *over the moon*;
- in a phrasal verb - the particle when it makes the meaning (`up` in *give up*: *give in*, *give away* mean other things);
  the verb when the particle is the usual one (`figure` in *figure out*);
- in a formula - the word that is not obvious from grammar: `going` in *How's it going?*, `worries` in *No worries*.

Mechanical rules (the import refuses the file otherwise): one word of Latin letters only - no apostrophe, space or hyphen
(`How's` cannot be a gap: take `going`); it stands in the phrase **exactly once** as a whole word, case-insensitively
(`easy` in *easy come, easy go* stands twice - take `come` or `go`); it is not the whole phrase (*Cheers!* cannot be a phrase
of this course at all). A part of a hyphenated word may be the gap: `going` in *easy-going*.

Content rules (the checker): never an article, a pronoun, `to`, `of`, `and`, `or` or a form of `be` - anyone guesses them; the
same key word at most once in a lesson and at most 20 times in the course. Vary.

## 6. The equivalent (`translations.ru`, `translations.uz`)

- What a Russian / Uzbek speaker **says in the same situation**, in the same register - not a word-for-word translation:
  *a piece of cake* - «проще простого» / «juda oson», not «кусок торта». The literal meaning goes to `literal`.
- If the language has its own proverb or set phrase with the same meaning and tone, use it (*better late than never* -
  «лучше поздно, чем никогда» / «kech bo'lsa ham, hech qachondan yaxshi»). If it has none, a short plain rendering of the meaning.
- At most **60 characters**, small letter, no final period; a question keeps `?`. Never the English phrase itself.
- Russian: standard literary Russian with `ё`. Address: the phrase decides - informal phrases on «ты» («береги себя»), neutral
  and formal ones on «вы».
- Uzbek: standard literary Uzbek in LATIN script with the ASCII apostrophe `'` (o', g', ma'no) - never ʻ ’ ‘ ` and never
  Cyrillic. The words an educated speaker in Uzbekistan uses; no calques from Russian. Informal on «sen», neutral and formal on «siz».
- ru and uz say the same thing.

## 7. The literal meaning (`phrase.literal`)

- Only when the words of the phrase say something different from its meaning and knowing it helps to remember the phrase:
  idioms, proverbs, many formulas (*How's it going?* - «как оно идёт?»).
- Both `ru` and `uz`, or neither. Never the same as the equivalent (if the literal meaning IS the equivalent - *Good morning* -
  leave `literal` out). Word for word but grammatical: «сломать лёд», not «ломать тот лёд».

## 8. When it is said (`hooks`)

One sentence in ru and in uz, up to about 120 characters: the situation, who says it to whom, the tone. *Говорят без
сожаления о том, что досталось без труда.* Not a translation and not a definition. If the phrase can offend or sound cold in
the wrong place, say so (*It's on me* - only when you really pay).

## 9. The example (`example`, `example_translations`)

- A short dialogue of **two lines joined by ` — `** (an em dash with spaces), or one sentence. Never `A:` / `B:` - the speech
  engine reads letters aloud. Up to 25 words; capital letter at the start, `.` `!` `?` at the end.
- It **contains the phrase**. Allowed changes: a form of the verb (*broke the ice*, *made up my mind*), another possessive or
  object pronoun (*your* -> *my*, *me* -> *her*), `someone` replaced by 1-3 words (*pulling your leg*), the particle of a
  phrasal verb moved after a short object (*figure this map out*), the leading article dropped.
- The example shows the situation of the hook, so that the learner could guess the meaning. Natural speech at the level
  of the module; vary the speakers and situations. Everyday names (Anna, Tom, Aziz, Malika, Mr. Brown) are fine.
- `example_translations`: faithful, natural ru and uz, keeping the two lines and the dash; the equivalent of the phrase
  appears in them in the needed form where natural.
- `examples` (optional, up to 10): more examples in the same format with `translations` {ru, uz}; the same rules.

## 10. Safety

No swearing (*damn*, *hell*, *crap* included), alcohol, drugs, gambling, sex, pork, violence as a topic, real brands,
real people, politics or religion. An idiom with a figurative violent image (*kill two birds with one stone*) is fine; its
example is peaceful. The checker knows a list of forbidden words (`FORBIDDEN` in the script) - the list is not the rule, the
rule is this paragraph.

## 11. Good and bad entries

Good (a proverb):

```json
{
  "word": "easy come, easy go",
  "part_of_speech": "phrase",
  "translations": { "ru": "легко досталось — легко потерялось", "uz": "oson kelgan narsa oson ketadi" },
  "example": "I lost the fifty dollars I won yesterday. — Oh well, easy come, easy go.",
  "example_translations": { "ru": "Я потерял пятьдесят долларов, которые вчера выиграл. — Ну что ж, легко досталось — легко потерялось.", "uz": "Kecha yutgan ellik dollarimni yo'qotib qo'ydim. — Nima ham derdik, oson kelgan narsa oson ketadi." },
  "hooks": { "ru": "Говорят без сожаления о том, что досталось без труда", "uz": "Osonlikcha qo'lga kirgan narsa haqida afsuslanmasdan aytiladi" },
  "phrase": { "kind": "proverb", "register": "informal", "variant": "both", "gap": "come",
              "literal": { "ru": "легко пришло, легко ушло", "uz": "oson keldi, oson ketdi" }, "cefr": "B1", "frequency": 2,
              "synonyms": ["win some, lose some"] }
}
```

(`gap` is `come`, not `easy`: `easy` stands in the phrase twice.)

Bad, and why:

| Entry | What is wrong |
|---|---|
| `"word": "piece of cake"` next to `"A piece of cake"` in another lesson | the same phrase twice (`DUPLICATE_PHRASE`) |
| `"translations": {"ru": "кусок торта"}` for *a piece of cake* | a literal translation instead of the equivalent; «кусок торта» belongs in `literal` |
| `"gap": "the"` in *break the ice* | an article: anyone types it without knowing the phrase |
| `"gap": "How's"` | an apostrophe: the import refuses the file |
| `"example": "A: Thanks! B: No worries."` | speaker labels are read aloud; write `Thanks! — No worries.` |
| `"example": "He told a joke to start the conversation."` for *break the ice* | the phrase is not in the example |
| `"literal": {"ru": "лучше поздно, чем никогда"}` with the same equivalent | literal repeats the equivalent - leave it out |
| `"uz": "ko’rishguncha"` | a typographic apostrophe; write `ko'rishguncha` |
| `"register": "neutral"` for *What's up?* | the register lies: it is `informal` |
| `"variant": "both"` for *Can I have the bill?* | Americans ask for the check: `uk` |
| `"word": "Don't count your chickens before they've hatched, as people say"` | not the canonical form, over 50 characters |

## 12. Checklist before you report

1. 10 entries per lesson; `stage` and `lesson` exactly as in the plan; keys and order as in §1.
2. Every phrase frequent and current at the level of its module; none repeats in the course.
3. `kind`, `register`, `variant` honest; no `rude`.
4. `gap` is the key word of the meaning, once in the phrase, not a function word, not repeated in the lesson.
5. ru and uz equivalents say the same thing in the same register, up to 60 characters; Uzbek in Latin with `'`.
6. `literal` only where it differs and helps; both languages.
7. The hook says when and to whom.
8. The example contains the phrase, is natural, two lines with ` — ` or one sentence; its translations are faithful.
9. Nothing copied from a dictionary or an idiom site; nothing unsafe.
10. The checker is clean for your files:

    python3 docs/content-authoring/check_phrases.py database/data/courses/english-phrases

    Every line is `ERROR <CODE> <file> #<entry> «<phrase>»: <reason>`. Fix every error in YOUR files. A `DUPLICATE_PHRASE`
    with a file of another writer: keep your phrase only if it belongs to your topic more, and name it in the report - the
    coordinator decides. `--summary` prints the counts of kinds, registers, levels and the most used key words.

Then re-read every entry as an editor would (`PHRASE_PROOF_BRIEF.md`): the meaning, the register, the Uzbek, the example.

## 13. Report

The lessons written; the last line of the checker; anchors you replaced and why; the phrases you doubt (file, phrase, what
bothers you) - especially Uzbek equivalents of idioms and proverbs, which go to the native speaker.
