# LexiFlow Pro — Administrator Guide

This guide covers everything the administrator needs to set up and maintain LexiFlow Pro.

---

## Admin Panel Access

URL: `https://yourdomain.com/admin`

Default credentials (change immediately after first login):
- **Email:** `admin@local`
- **Password:** set during seeding (see DevOps guide)

### 2FA Setup (required for admin accounts)
1. Log in to `/admin`.
2. You are redirected to `/2fa/setup`.
3. Scan the QR code with any TOTP app (Google Authenticator, Authy, etc.).
4. Enter the 6-digit code to confirm.
5. Save your **8 recovery codes** — they are shown only once. Store them securely.

On subsequent logins you will be asked for a 6-digit TOTP code. If you lose your phone, enter a recovery code (each code is single-use).

### Panel language
The panel is in Russian (all captions are in `lang/ru/admin.php`). This guide names menu items and buttons in English and
gives the Russian caption in brackets, for example **Content → Stages** (Контент → Модули). Terms: Course = Курс,
Stage = Модуль, Lesson = Урок, Word = Слово, Learner = Ученик, User (staff) = Сотрудник (roles: admin = Администратор,
teacher = Учитель), Telegram group = Telegram-группа, Exam session = Экзамен, Organization = Учебный центр,
Subscription = Подписка, Payment = Платёж, Payment order = Заказ, Provider transaction = Транзакция провайдера,
Promo code = Промокод. Navigation groups: Content = Контент, Learning = Обучение, Administration = Администрирование,
Billing = Оплата. Dates are shown as `28.10.2026` and `28.10.2026 09:27`. The reason an action was refused (publishing a
course, a refund, a discount annulment, adding a learner, linking a group, a lesson import...) is worded in Russian too
(`admin.<screen>.errors.*` in `lang/ru/admin.php`); an error nobody has worded yet reads as one generic sentence, with the
details in the log.

---

## User Management

Navigate to **Users** (Сотрудники) in the left sidebar.

### Create a teacher account
1. Click **New User** (Создать сотрудника).
2. Fill in name, email, password, `telegram_user_id` (the teacher's Telegram numeric ID).
3. Set **Active** (Активен) = true.
4. Assign the role **teacher**.
5. Save.

> The teacher's `telegram_user_id` must be correct — this is what links their Telegram identity to the platform.

### Assign a teacher to a group
1. Open the teacher's user record.
2. In the **Teacher Groups** relation tab, add the relevant groups.

### Deactivate a user
Set **Active** (Активен) = false. The user cannot log into the admin panel and the bot ignores their commands.

---

## Telegram Group Management

Navigate to **Telegram Groups** (Telegram-группы).

New groups appear here automatically with status `pending` when the bot is added to them.

| Status | Meaning |
|---|---|
| `pending` | Bot joined the group but admin hasn't approved it yet |
| `active` | Fully operational — teachers can run sessions |
| `disabled` | Group is deactivated; bot ignores all commands |

### Activate a group
1. Open the group record.
2. Set **Status** (Статус) = `active` (Активна).
3. Link at least one teacher via the **Teacher Groups** tab.
4. Save.

### Set the course of a group
Open the group record and pick **Course** (empty = the `default` course). The teacher commands
`/start_training <stage> <lesson>` and `/start_exam <stage> <lesson>`, and the lesson picker of
`/start_training`, look for the stage number **inside this course** only: two courses may both have
«Модуль 1 / Урок 1». Only admins can change the course of a group.

### Set the bot language of a group
The bot writes to the group (command replies, lesson buttons, exam leaderboard) in Russian by default.
To switch a group to Uzbek (Latin), open the group record, set **Bot language** (Язык бота) = `O'zbekcha` and save
(**Default** (По умолчанию) = Russian). The choice is stored in the group's `meta.locale` and survives the bot being
removed and re-added. Personal messages (e.g. review reminders) follow each learner's interface language
(`learners.interface_locale`; for a new learner it starts from the Telegram language: `uz*` → Uzbek, anything else → Russian).
The Web App follows the learner's Telegram language.
The group language also decides the language of **exam questions** (answers and distractors) and of the
translations on cards in a group training session; personal sessions use the learner's own language.

---

## Content Management

Navigate to **Content → Courses / Stages / Lessons / Words** (Контент → Курсы / Модули / Уроки / Слова).

Content hierarchy: **Course → Module (stage) → Lesson → Word**. Stage numbers are unique *within a course*.
The platform course `default` (created by the seeder or by the first import) receives everything that does
not name a course.

A module's **title is optional**. A module without one (the field is left empty) is shown to learners as
**«Модуль N»** (in Uzbek **«N-modul»**) in their own language, and in the admin tables as «Модуль N» too; give it a
title only when it is worth reading in one language for everybody (a learner who reads Uzbek sees a Russian title as it is).

### Courses (admins only)
**Content → Courses** (Контент → Курсы) lists the catalog. A course has a unique `slug` (Latin lowercase, digits, dashes;
it cannot be changed after creation), a **title in every language** (RU and UZ are both required),
an optional description, level (A1–C2), goal (General / School / IELTS / SAT), visibility
(`public` — in the catalog, `unlisted` — by link or group, `private`) and a position in the catalog.
The **Modules (stages)** table (Модули курса) on the course page adds and edits the modules of the course; lessons and
words are edited on the stage / lesson screens.

**Status.** The status is never typed into the form — it moves with the buttons on the course page (and in the
list), each move is written to the audit log (`course.status_changed`):

| From | Button | To |
|---|---|---|
| `draft` | Send to review (Отправить на проверку) | `review` |
| `review` | Publish (Опубликовать) / Back to draft (Вернуть в черновик) | `published` / `draft` |
| `published` | Archive (Архивировать) | `archived` |
| `archived` | Back to draft (Вернуть в черновик) | `draft` |

The `default` course cannot be archived (it serves every group that has no course of its own). Archived courses are not
offered in the group **Course** select.

**Publishing is checked.** A course is published only when (1) its title exists in every language, (2) it has
at least one lesson with active words, and (3) **at least 95% of its active words have a translation in every
language (RU and UZ; a blank text is no translation, and the word's main Translation field counts as the RU text)**; otherwise a red message in Russian says what is missing (e.g. *«Переводы (RU, UZ) есть только
для 18 из 20 слов (90%), а нужно не меньше 95%.»*). The threshold is `CONTENT_PUBLISH_TRANSLATION_THRESHOLD`
(default `0.95`). The check applies to **new publications only**: a course that is already published is never
re-checked, and old content without an Uzbek translation is shown in Uzbek-language sessions with the Russian
text and a small note *"No translation in your language yet — Russian shown"*.

### Translations
Every word has a Russian text (the **Translation** field, also stored as the `ru` translation) and optional
translations into other interface languages. To edit them: **Content → Words** (Контент → Слова; or the **Translations** (Переводы) button
next to a word on the lesson screen) → open the word → **Translations** table:
- **Create** (Добавить перевод) adds a language (one row per language and word); **Edit** changes the text. A changed text becomes
  a `manual` translation and loses its *Reviewed* mark; **Mark reviewed** (Отметить проверенным) is for the native-speaker check.
- Editing the `ru` row also updates the word's main Translation, and editing the main Translation on the
  lesson screen updates the `ru` row, so the two never differ.
- Cards and exams use the translation of the session language and fall back to Russian (see Courses above).
Words are never deleted. The **Active** flag on the word screen hides a word: an inactive word is not counted in the
course word counter or the publishing check, is not asked in exams and is not used as a wrong answer option. Switching
the flag (or adding a word on the lesson screen) refreshes the lesson cache and the course counter at once.
Exam wrong options come from the same lesson / stage first, then other stages of the same course, and only when the
course has too few words from published, non-private courses — never from draft, review, archived or private courses.

### Importing a lesson via JSON (recommended)
**Admins only.** In this phase content belongs to the platform: the **Import Lesson** (Импорт урока) page is hidden from
teachers (direct link → *403*), and the importer itself refuses any user who is not an active admin
(nothing is written). Seeders and artisan commands run without a user and are allowed only in the console.
1. Go to **Import Lesson** (Импорт урока) in the left sidebar.
2. Click **Download sample** (Скачать пример) to get the JSON template.
3. Prepare your JSON file following the rich admin schema:
```json
{
  "course": "english-a1",
  "stage": {"number": 1, "title": "Beginner", "description": "Optional"},
  "lesson": {"number": 1, "title": "Greetings"},
  "words": [
    {
      "word": "hello",
      "translations": {"ru": "привет", "uz": "salom"},
      "example": "Hello, how are you?",
      "example_translations": {"uz": "Salom, qalaysiz?"},
      "part_of_speech": "interjection",
      "transcription": "həˈloʊ"
    }
  ]
}
```
- `course` (optional): the **slug of an existing course** (create it under Courses first). Without it the lesson
  goes into the `default` course. An unknown slug rejects the whole file.
- `translations` (optional): `ru` / `uz` texts. The Russian text may also be given as `translation` (the old
  field; if both are present they must be equal). Russian is always required. `example_translations` is optional.
- `stage.title` (optional): the name of the module. A file without it (or with a blank one) creates a module with
  **no name**, which learners see as «Модуль N» / «N-modul», and never changes the name an existing module already has.

The old files keep working unchanged — without `course` and `translations`:
```json
{
  "stage": {"number": 1},
  "lesson": {"number": 1},
  "words": [{"word": "hello", "translation": "привет"}]
}
```
The original PRD schema is also supported for compatibility (`course` may be added to it too):
```json
{
  "stage": 1,
  "lesson": 1,
  "vocabulary": [
    {
      "word": "hello",
      "translation": "привет",
      "part_of_speech": "interjection",
      "transcription": "həˈloʊ",
      "example": "Hello, how are you?"
    }
  ]
}
```
4. Upload the file and click **Import** (Импортировать).
5. Review the report: `added / updated / skipped / translations new+updated / errors`.

### Import rules
- Max file size: 2 MB.
- Max 500 words per file.
- Allowed `part_of_speech`: `noun`, `verb`, `adjective`, `adverb`, `pronoun`, `preposition`, `conjunction`, `interjection`.
- Allowed translation languages: those enabled for the platform (now `ru`, `uz`).
- Duplicate words within the same lesson are rejected.
- Importing again with the same course/stage/lesson numbers **updates** existing words (upsert); an identical
  file changes nothing (all words are *skipped*). A changed translation overwrites the stored one — including a
  manual edit — and removes its *Reviewed* mark, so re-importing a corrected pack sends the changed texts back to review.
- If any validation error occurs, **nothing is written** (atomic transaction).
- The course's word counter is refreshed after every import.

### Word audio (pre-generation)
Cards play a pre-generated mp3 when the word has one; otherwise the app falls back to the browser voice / live TTS. After importing a lesson pack or publishing a course, ask the developer/DevOps to run `php artisan audio:pregenerate <course-slug>` (it only queues the words that do not have current audio yet; `--force` redoes all). It runs in the background — voiced words appear within minutes. Editing a word's English text clears its audio and queues a new voice-over automatically. Inactive words are skipped. Publishing does not start it automatically.

### Manual word editing
Open any Stage → Lesson → click on a word to edit its translation, example, or transcription.

---

## Practice tasks (docs/16)

Navigate to **Practice → Practice tasks / Import tasks** (Практика → Задания практики / Импорт заданий). **Admins only**: a teacher
gets *403*, and the cabinet of a center has no such screen. The practice itself is off until `PRACTICE_ACCESS` in `.env` says
otherwise: `off` (default, nobody), `pilot` (only the learners put into the pilot, see *Learners* below) or `all`.

### Switching the practice on

Four learner-facing modes (*Найди пары*, *Подбери сочетание*, *Собери предложение*, *Слушай и пиши*) are switched by `.env` (read from `config/practice.php`);
after a change run `php artisan config:cache` and restart the queue workers (`docs/DEVOPS_GUIDE.md` §14).

| Variable | Values | What it does |
|---|---|---|
| `PRACTICE_ACCESS` | `off` (default) · `pilot` · `all` | Who may practise. `off` — nobody: the entries disappear from the TWA and every practice endpoint answers 403. `pilot` — only the learners with the pilot mark (below). `all` — everybody. Anything else (a typo) counts as `off`: a mistake in `.env` never opens the practice to everybody |
| `PRACTICE_PLAN` | `all` (default) · `pro` | `pro` — the practice is a Pro feature: a Free learner still sees the entries, with a **Pro** chip, and is shown the paywall instead of the hub (the server answers 403 `practice_pro_required`). Applies on top of `PRACTICE_ACCESS` |
| `PRACTICE_MODE_PAIRS` · `_COLLOCATION` · `_SENTENCE` · `_DICTATION` | `true` (default) · `false` | Switches one mode off. The hub shows it as «Выключено» and **no new session** of that mode can be opened; sessions already started are played to the end |
| `PRACTICE_BATTLE` | `true` (default) · `false` | The switch of the 1-on-1 battle (the hub hides the battle card, new invitations are refused; matches that are on go to the end). The battle itself is described in the battle chapter of this guide when it ships (task 18.7c) |

Recommended roll-out: keep `off` while the tasks are imported and checked; set `pilot`, put a few learners into the pilot (button on **Learners**), watch them; then `all`. To stop the practice in a hurry
set `PRACTICE_ACCESS=off`: the sessions that are open die by their own term (24 hours; `practice:close-expired` runs every 15 minutes), nothing of the lessons, the streak or the leagues is touched —
the practice never writes them. Tasks, versions and results stay in the database whatever the switch says.

**Three types of task** (the fourth mode, *pairs*, needs no task: it is made of the words of the course): *Подбери сочетание*
(a phrase with one gap `___`, three options, one or two of them correct), *Собери предложение* (fragments the learner puts in order; the
accepted orders, an optional canonical writing to show after the answer), *Слушай и пиши* (a phrase the learner hears and writes; other
accepted writings are optional). Every task has a **key** (Latin lower case, digits, `.` `-` `_`; unique in the course; chosen once), a course,
an optional lesson ("Модуль N · Урок M"; empty = a task of the whole course), a level and the **target words** — active words of the course.
A task is handed out to a learner only when the learner has been introduced to *all* its target words.

**Versions.** The text of a task is never changed: **Save** writes a *new version* (a draft). The task moves by buttons:
**Проверено** (the current version has been checked) → **Опубликовать** (learners get this version; the previous published one is
superseded, but whoever is playing it finishes with it) · **В архив** (nobody is given the task any more) · **Вернуть** (back from the archive).
A draft cannot be published directly. A published task that has been edited stays published with the **old version handed out**; the list marks
it «Есть неопубликованная правка» until the new version is checked and published. Publishing is stricter than saving: at least one target word and a text
in **both** languages (the meaning/prompt and explanation of a sentence, the translation and explanation of a collocation, the translation of a dictation).
The audio of a dictation is made in the background after saving; the column **Аудио** shows `В очереди / Готово / Ошибка`, the button **Сгенерировать аудио
заново** voices the current version again. A dictation whose audio is not ready is not handed out. Every move is written to the audit log
(`practice_item.reviewed / published / archived / restored`).

**Import (JSON, format `lexiflow-practice/1`).** The page **Import tasks** takes a file with `"course"` (the slug of an existing course, not a deck) and a
list `"items"` (1–300 tasks, ≤ 2 MB); **Download sample** gives a valid file. The same service runs as
`php artisan practice:import {file} [--publish]`. Rules:
- every task has a `key`; importing the same file again **skips** what is identical, writes a **new version** for a changed task, and refuses the key of an
  **archived** task (restore it first). A changed target word list counts as a change; a changed lesson or level is written without a new version;
- `target_words` are texts of active words of the course (case does not matter); `lesson` is `{"stage": N, "number": N}`; sentences give the accepted
  orders as lists of the fragment texts, collocations name the correct options by their text; an unknown field is an error, so a misspelt name never loses text;
- **all or nothing**: any fault refuses the whole file, and the report lists every fault with the **number of the task and its key**;
- tasks are written as **drafts**; the box **«Опубликовать сразу (только проверенный контент)»** (`--publish`) writes them as published (and publishes identical
  drafts of an earlier import). It is your confirmation that the texts are checked — a file cannot publish itself. The strict rules of publishing apply.

A file looks like this (the real pilot package, `database/data/practice/pilot-a1-a2.json`, has 90 such tasks):

```json
{ "format": "lexiflow-practice/1", "course": "default",
  "items": [
    { "key": "pilot-time-s01", "type": "sentence", "lesson": {"stage": 1, "number": 2}, "level": "A1", "target_words": ["monday", "friday"],
      "prompt": {"ru": "…", "uz": "…"}, "fragments": ["I", "…"], "accepted": [["I", "…"]], "explanation": {"ru": "…", "uz": "…"} },
    { "key": "…-c01", "type": "collocation", "lesson": {"stage": 2, "number": 1}, "target_words": ["meeting"],
      "text": "Let's ___ a meeting on Monday.", "options": ["have", "make", "do"], "correct": ["have"],
      "translation": {"ru": "…", "uz": "…"}, "explanation": {"ru": "…", "uz": "…"} },
    { "key": "…-d01", "type": "dictation", "lesson": {"stage": 1, "number": 2}, "target_words": ["tomorrow"],
      "text": "See you tomorrow", "accepted": ["See you tomorrow"], "translation": {"ru": "До завтра", "uz": "Ertagacha"} }
  ] }
```

The pilot package: 90 tasks — ten tasks of each of the three types in each of three lessons of the `default` course: *Module 1 · Lesson 2* (time and days of the week, A1), *Module 1 · Lesson 3* (family, A1), *Module 2 · Lesson 1* (work, A2). Its target words and lessons must exist in the course, otherwise the whole file is refused.
The Uzbek texts of the pack wait for a native speaker's review (`docs/I18N_UZ_REVIEW.md`). On a local machine the seeder `PracticeDemoSeeder` (part of `db:seed`) imports it published, puts the demo learners into the pilot and — with no `TTS_SERVICE_URL` — voices the phrases itself
with `ffmpeg` + `flite`. **On production import it as drafts** (`php artisan practice:import database/data/practice/pilot-a1-a2.json`), read the tasks in the list, press **Проверено** and **Опубликовать** (or re-import with `--publish` once the texts are checked).

### Audio of the dictations

A dictation task is voiced in the background after it is saved or imported (`GeneratePracticeAudioJob`, queue `low`, a queue worker must run): the column **Аудио** shows `В очереди` (the job waits or is being retried),
`Готово`, or `Ошибка` with the reason (for example, the `tts` service is not reachable). A dictation without ready audio is **not handed out** to learners: when too few are voiced, the hub of the lesson says
«Записи для заданий ещё готовятся» and the mode stays closed. The button **Сгенерировать аудио заново** voices the current version again (use it after a failure, once the `tts` service is back). Editing the text of a task makes a new version and a new voice-over; the
old file stays for the sessions that are playing the old version. The voice is the one of the word audio (`TTS_VOICE`, `TTS_SERVICE_URL`; bumping `TTS_AUDIO_VERSION` re-voices everything under new file names). The phrases are stored in the private directory `storage/app/practice-audio`, which the web server does **not** serve — learners hear
them only through the API (`docs/DEVOPS_GUIDE.md` §10.2). The *word* part of a dictation uses the ordinary pre-generated word audio (`php artisan audio:pregenerate <course-slug>`, see *Word audio* above): a word without current audio is simply not taken.

---

## Learners

Navigate to **Administration → Learners** (Администрирование → Ученики). An admin sees every learner; a teacher sees only the active members
of their own groups, with name / username and the learning metrics of their groups (no Telegram ID, plan,
onboarding state, subscriptions or events).

**List (admin).** Columns: name, @username, Telegram ID, **Plan** (`Free`, `Pro` — own subscription,
`Pro (sponsored)`, shown as `Pro (от центра)` — through an organization), blocked, onboarded, **Referrals** (how many learners this one
invited, rejected ones excluded), last seen.

- **Search** (box above the table): by name, by @username (`@anna` and `anna` both work, partial match) or by
  **Telegram ID** (exact: type the whole number, `12345` does not find `123456789`).
- **Filters:** *Group*; *Plan* — Free / Pro (own **or** sponsored; the rule is the same as in the bot and the TWA:
  a subscription counts until its end, an auto-renewing provider gets the grace days, a suspended organization or a
  member who left sponsors nothing); *Onboarding* — Done / Not done.
- **Block / Unblock** (Заблокировать / Разблокировать; row button, confirmation required): the learner's next TWA request answers
  `401 learner_blocked` (the TWA is closed to them) and review reminders stop; progress is kept. Every change is written to the audit log
  (`learner.blocked` / `learner.unblocked`). Unblocking restores access at once.

- **Practice pilot** (Пилот практики; column, filter *In the pilot / Not in the pilot*, and the button **Включить в пилот практики** /
  **Исключить из пилота** on the row and on the learner page; admin only): with `PRACTICE_ACCESS=pilot` only the learners in the pilot get the practice.
  Every real change is written to the audit log (`learner.practice_pilot_changed`: `from`, `to` — the moment the learner entered the pilot, or empty).
- **SRS algorithm** (Алгоритм повторений; row button, admin only): switches one learner between **SM-2** and **FSRS-5**. The choice
  is made automatically when a learner is created (`FSRS_ROLLOUT_PERCENT` in `.env`: the share of *new* learners
  that gets FSRS, `0` by default = nobody; changing it never moves existing learners). Switching to FSRS seeds the
  memory of the learner's graduated cards from their SM-2 state; switching back to SM-2 changes no card. Every real
  change is written to the audit log (`learner.scheduler_changed`: from, to, number of initialised cards). The learner page
  shows the current algorithm ("(default)" = no own bucket, the platform default applies). Rollback of the whole
  experiment: `FSRS_ROLLOUT_PERCENT=0` and `UPDATE learners SET scheduler = 'sm2'`.

**Learner page (admin).** Besides the profile it shows the onboarding state and the **effective plan** (source:
captioned *Free* / *Pro* / *Pro (от центра)* (sponsored) as in the list, then the organization #N for sponsored Pro and until when), and three read-only tabs:

- *Subscriptions* — the learner's own subscriptions (plan, status, provider, until, reason). The button
  **Open in Subscriptions** (Открыть в разделе «Подписки») opens the Billing screen filtered to this learner, where Grant Pro / Revoke live.
  Sponsored Pro is a subscription of the organization and is not listed here.
- *Recent events* — the last 50 product events of the learner (time, name, properties). The properties contain
  no personal data by design (no promo codes, no message text). The login event `twa.authenticated` is hidden
  (it is written on every TWA open); pick it in the *Event* filter to see it, or pick any other name to narrow the list.
- *Last 14 days* — daily stats: reviews, correct answers, new words, time, whether the daily goal was met.
- *Referrals* — the learners this one invited ("Invite a friend", docs/13 §7.1): the invited learner (link to
  their page), the state (`pending` — came, not active yet; `activated` — studied 3 days × 10 cards within
  14 days; `rewarded` — both got 7 days of Pro; `rejected` with the reason: `self`, `existing_learner`,
  `referrer_capped` — legacy rows only, `flagged`; an inviter who already collected 10 rewards in 30 days / 50 in total gets no further reward, but the invited learner is still rewarded: the row stays `rewarded`), the
  **Flagged** mark and the dates. A referral is flagged automatically when the inviter collected 20 or more
  links within 24 hours (a farm is likely); its reward waits. Two row buttons for a flagged row, both written
  to the audit log: **Clear flag** (Снять пометку; `referral.unflagged`) — the referral is genuine, an activated one is
  rewarded at once; **Reject** (Отклонить; `referral.rejected`) — no reward will ever be granted. The numbers come from
  `config/referrals.php` (`REFERRALS_*` in `.env`). The invite link needs `TELEGRAM_BOT_USERNAME`; without
  it the learners only see their code.

---

## Billing: subscriptions, payments, promo codes, organizations

Navigate to **Billing → Subscriptions / Promo codes / Organizations / Payments / Payment orders / Provider
transactions** (Оплата → Подписки / Промокоды / Учебные центры / Платежи / Заказы / Транзакции провайдеров). These screens are for **admins only** (teachers do not see them; an inactive admin does not either).
Free learners get 20 new words a day; **Pro** removes the limit. Pro comes from a subscription of the learner, or from
the subscription of an **organization** whose group the learner is an active member of (sponsored Pro). Online payment
(Telegram Stars, Payme, Click) is switched on per provider by DevOps (see DEVOPS_GUIDE §17); until a provider is enabled
the payment screens stay empty and Pro is granted by hand or with promo codes.

### Subscriptions
The table shows every subscription: subject (learner or organization), plan, status, provider (`manual` — granted
by an admin, `promo` — from a promo code), start, **Until** (empty = no end), who granted it and the reason.
Filters: status, provider, plan, subject, and *Live status* (live = trialing / active / past due).

- **Grant Pro** (Выдать Pro; button above the table): pick the subject — a *Learner* (search by name, @username or Telegram ID)
  or an *Organization* — and either switch on **No end date** or set **Pro until**. The **Reason** is required and
  is stored in the audit log (`subscription.granted`). If the subject already has a live subscription it is
  extended / replaced in place (no second subscription is created).
  If the learner has a **Stars subscription with auto-renewal**, the form warns that Telegram keeps charging them and
  that a manual grant replaces the row (the renewal can no longer be canceled from LexiFlow): ask the learner to turn
  the renewal off in /pro first, then tick **I understand: grant anyway** (Понимаю, всё равно выдать). If the date is earlier than the end of a
  period the learner has already paid for, the **Pro until** hint says it will shorten the paid period.
  For an *Organization* you can also pick the **Tier** (Tutor 30 seats, Center S 100, Center M 300, Center L
  unlimited — the table is `billing.org_tiers`): it sets the subscription's tier and **seats limit** and is written to
  the audit log. This is how the Center L tier (not sold online) and a paid-by-invoice center are activated. Leave it
  empty to grant without a seat limit (the previous behaviour). A tier on a Learner grant is ignored. The table also
  has *Tier* and *Seats* columns.
- **Manual activation of a center (invoice flow).** A center owner presses *Request an invoice* in the `/org`
  cabinet (Billing): you get an e-mail "Invoice request from center …" (to `BILLING_INVOICE_REQUEST_EMAIL`, else
  `SITE_LEGAL_EMAIL`; *Reply-To* is the owner's address; the request is audited as `organization.invoice_requested`).
  Send the invoice (the owner can also open a printable one from Billing → *Orders* once an online order exists;
  the seller details come from `SITE_LEGAL_*`), and after the money arrives use **Grant Pro** → *Organization* →
  **Pro until** (period end) + **Tier**. The seats limit is set from the tier and the waiting list is seated
  automatically right after the grant. Center L is always done this way.
- **Annual payment of centers.** The cabinet sells **1 month** or **1 year** (Payme / Click). The year price is the
  tariff's month price x 12 minus `BILLING_ORG_YEAR_DISCOUNT_PERCENT` (default 17; `0` = no discount), rounded to whole sums; `BILLING_PRICE_<TIER>_YEAR_UZS` sets an explicit price instead. A year is added
  to the end of the live period (365 days); a refund takes back exactly the year it paid; renewal reminders work as
  for a month. A changed discount affects new orders only: an open order keeps its amount. A tier cannot be changed
  online during a year either (below).
- **Changing a center's tier is always manual.** While a center's subscription is live, the cabinet sells only a
  *renewal of the current tier*; an attempt to pay for another tier online is refused with "To change the tariff,
  contact us" (a different tier applied to the whole prepaid period would turn 12 months of Tutor + 1 month of
  Center M into 13 months of Center M, or strip a prepaid Center M down to Tutor seats). Agree the change with the
  owner (extra payment or a credit on the next invoice) and apply it with **Grant Pro** → *Organization* → **Tier**
  + **Pro until**; the seats limit changes at once and the waiting list is seated.
- **`tier_mismatch` on a payment order.** If an online order was created for one tier and paid after the tier had
  been changed by hand (or by another payment), the money is credited and the period extended, but the tier and
  the seats are **left as they were**: the order carries `meta.tier_mismatch = true` (with `tier_mismatch_live_tier`),
  the log has `billing.org_tier_mismatch` and the audit log an entry `subscription.tier_mismatch`. Look at such
  orders (Billing → **Payment orders** (Оплата → Заказы), the order's meta): decide with the owner whether the paid month is for the
  live tier (nothing to do) or the ordered one (Grant Pro with the right tier, or a refund).
  Such orders are rare by construction: a Payme / Click payment on an organization order whose tier is not the live
  one is refused before the money is taken (Payme `-31053`, Click `-9`), and a payment that sets or keeps a tier
  cancels the center's other pending orders of another tier (*Canceled reason* `tier_changed`, see below) — only
  a payment that was already in flight at the provider gets through.
  In the **Payment orders** table such an order has a red **Tier mismatch** badge ("tier mismatch (live: tutor)"),
  there is a **Tier mismatch** filter, and the dashboard tile **Tier mismatches** (Payment webhook errors, last
  24 h) counts the ones paid in the last day. In the center's `/org` Billing page the buttons for any tier other than
  the live one are disabled with the hint "contact us"; renewing the current tier works as usual.
- **Promo / referral days on top of a Stars subscription.** Days of a promo code or a referral reward are added to
  the end of the live subscription of the learner. If it is a **running Stars auto-renewal**, those days only insure
  against a failed charge and are **not carried over**: the next Telegram renewal sets the end from Telegram's own
  period, so the extra days are absorbed. If the Stars subscription is already **canceled**, the days extend it and it
  **stays canceled** (live until the extended end, no grace, reminders work); the learner can still resume it in /pro.
- **Revoke** (Отозвать; row button, live subscriptions only): ends the subscription now; **Reason** required, audit
  `subscription.revoked`. The learner loses Pro within seconds (the cached plan is dropped at once).
- A subscription that ran out of time is closed automatically by the hourly job `subscriptions:expire`.

### Payments, payment orders, provider transactions
Three read-only screens (nothing can be created, edited or deleted there):

- **Payments** — the ledger: one row per provider event. *Kind*: `charge` (first payment), `renewal` (Stars
  auto-renewal), `refund` (the mirror row of a returned charge). *Status*: `succeeded`, `refunded` (the charge was
  returned), `failed` (money arrived that could not be credited — amount / currency mismatch, dead order, orphan
  renewal; **the learner got no Pro, support has to look at it**). Amounts are shown in whole sums (UZS) or Stars
  (XTR). Filters: status, kind, provider, currency, subject. Search by provider charge / event id. Open a row for the
  details, the links to its order / provider transaction and the (masked) raw provider payload.
- **Payment orders** — the intent to buy and the only source of the amount: subject, tier (organizations), period,
  provider, amount after discount and list price, status (`pending` → `paid` / `canceled` / `expired` / `failed`),
  expiry, who created it (`twa`, `bot`, `org_panel`, `admin`). Pending orders expire by themselves after
  `BILLING_ORDER_TTL_MINUTES` (job `billing:expire-orders`). Offer bookkeeping badges (columns, also on the order page):
  *Canceled reason* `offer_moved` (the learner's discount moved to a newer order) / `offer_redeemed` (the discount was
  used by another payment) / `tier_changed` (a center's order of one tier was closed because a payment set another
  tier on its subscription; the meta carries `tier_changed_by_order_id` and `tier_changed_to`) — all normal and harmless; *Paid after cancel* — a payment that was already in flight
  reached such a canceled order and was credited in full (the money is never lost); **Double discount** (red) — that
  late payment came after the discount had been used by another order, so the learner got the discount twice. Filter
  **Double discount** lists them. What to do: nothing is required to keep the customer — the subscription is already
  valid; if the second discount is not acceptable, agree on a correction with the owner (the case is rare: it needs two
  payments to be confirmed within seconds). Look at the matching offer (**Learners → Offers**) for the first order.
- A red **Double Stars subscription** badge (filter of the same name) marks an order of a learner who already had a
  running Stars subscription when this one was paid — the second invoice was already open at the payment screen. The
  money is credited (Pro is extended) and both Telegram subscriptions are remembered; **nothing is canceled
  automatically**. What to do: ask the learner whether they want the second one refunded (**Payments → Refund** on that
  payment), and note that **Cancel auto-renewal** (the learner in /pro) stops both subscriptions at Telegram. The dashboard
  tile **Double Stars subscriptions** counts such orders of the last 24 hours; normally 0. A pending Stars *month* order is
  also closed by us (*Canceled reason* `already_subscribed`) when the learner's Stars subscription starts; Stars orders
  are no longer expired by the scheduler (their deadline is checked when the learner pays).
- **Provider transactions** — Payme / Click transaction state (created → performed / canceled, cancel reason) and the
  journal of webhook calls (`history`).

**Secrets are never shown.** The raw payloads, the order meta and the webhook journal are masked on screen: values
under keys that look like secrets (keys, signatures, tokens, `Authorization`, the order token carried as
`invoice_payload` / `order_id` / `merchant_trans_id`) appear as `[redacted]`, bot tokens inside text too; the order
token is shown shortened (`AbCd…wXyZ`) and the payment link of an order is not shown at all. If you need a value that
is masked, look at the provider's cabinet.

#### Refund
Open **Payments** (Платежи) and use **Refund** (Вернуть деньги) on a `succeeded` charge or renewal (row button or the button on the payment page).
Only an admin can; the **Reason** is required and is written to the audit log (`payment.refunded`, with the reason,
the payment, the amount, whether the subscription was ended, your IP).

1. **Telegram Stars** — the money is returned through the Bot API (`refundStarPayment`) when the Stars adapter is
   deployed (task 2A.3); then the refund is recorded.
2. **Payme / Click** cannot be refunded from the panel. First return the money in the provider's cabinet. Then press
   **Refund**, switch on **"The money was already returned by hand in the provider's cabinet"** (it is never on by
   default: you assert it yourself) and give the reason: only the local record is made. Without the switch the panel
   refuses ("Return it in the provider's cabinet…") and changes nothing. Do not switch it on if the money has not really been
   returned.
   - *Payme*: a refund made in the Payme cabinet normally reaches us by itself (Payme calls `CancelTransaction`) and
     appears as a `refund` row without your help; record it by hand only if it did not arrive.
   - *Click*: Click sends **no** refund notification, so every Click refund is recorded this way: refund in the Click
     cabinet (by the `click_trans_id` shown as *Charge id*), then **Refund → returned by hand** here.
3. Recording the refund marks the charge `refunded`, adds a `refund` row, and **ends the subscription now** if this
   payment paid for the period that is running (the learner is back on Free within seconds). A refund of an older,
   already used period only marks the ledger.
4. A refund can be recorded **once**: the button disappears from a refunded charge, and a second click or a second
   admin gets "already refunded" with no second provider call.

If the provider returned the money but the panel then reported an error, do not repeat the refund blindly: check the
**Payments** list; if the charge is still `succeeded`, repeat with the "returned by hand" switch (the money is already
back) and tell DevOps (log `billing.admin_refund_unrecorded`).

### Promo codes
**Billing → Promo codes → New** (Оплата → Промокоды → Создать промокод). The code is suggested automatically (10 characters); you may type your own —
spaces and dashes are ignored and the code is stored in upper case (`summer-2026` → `SUMMER2026`), 6–32 Latin
letters / digits, unique. Set the **plan**, **duration (days)**, optional **max redemptions** and **valid until**,
and a note (who the code is for). *Valid until* (like *Pro until* in Grant Pro) is entered in local time
(Asia/Tashkent by default) and stored in UTC. Creation is written to the audit log (`promo.created`), every edit of
the limits / validity / *Active* / note too (`promo.updated`, with the old and new values).

- *Redeemed* is read-only: it grows only when a learner redeems the code. One learner can redeem a code once.
- To stop a code, switch **Active** off (edit). A code that was already redeemed cannot be deleted — only deactivated.
- The learner enters the code in the TWA ("Have a promo code?" on the finish screen) or sends `/promo CODE` to the
  bot in a private chat. A learner who already has Pro with an end date gets the days added to it.

### Offers (personal discounts)
A learner who **loses a seated place** in a center (left, or removed) and has no personal Pro gets a one-time offer
**30 % off the first month, 14 days** (`BILLING_POST_ORG_DISCOUNT_PCT`) and a bot message with the pay buttons. Waitlisted
members and learners who already pay get nothing. The discount is applied to the order on the server; the offer is used up
only by a **paid** order (an expired or canceled order leaves it available until its own deadline).

- **Learners → open a learner → Offers** (admin only): kind, discount, state (*open / redeemed / expired*), deadline, the
  center it came from and the order that used it. Read-only.
- **Annul** on an open offer makes it expire at once (use it when the discount was given by mistake). It is written to the
  audit log (`offer.annulled`). A used or expired offer has no button.

### Organizations (sponsored Pro for a learning center)
1. **Billing → Organizations → New** (Оплата → Учебные центры → Создать учебный центр): name, slug (Latin, cannot be changed), time zone, language.
2. Open the organization → **Groups** table → **Link group** (Привязать группу) and pick the Telegram group (only groups that belong
   to no organization are offered). **Unlink** takes it away again. Both are written to the audit log
   (`organization.linked_group` / `organization.unlinked_group`).
   Make the bot an **administrator** of the group: then Telegram reports every leave / removal; without admin rights
   only the service message "left the group" arrives, which can be missed (a member who left may keep sponsored Pro).
3. **Billing → Subscriptions → Grant Pro** (Оплата → Подписки → Выдать Pro) with the *Organization* subject (pick the **Tier** to set its seat limit).

Every **active member of an active linked group** now has Pro (source "sponsor" in `/me`); after unlinking, leaving the
group, suspending the organization or the end of the subscription they are back on Free (their progress stays).
**Status: Suspended** switches the sponsorship off without deleting anything; a suspended center cannot pay online
either — its pending orders are refused at Payme (`-31054`) and Click (`-9`) until the status is back to Active; every
status change is written to the audit log (`organization.status_changed`). An organization with a live subscription or linked groups cannot be
deleted (the Delete button explains why): revoke the subscription and unlink the groups, or just suspend it.
Changes apply within seconds.

---

## Dashboard Widgets

| Widget | Who sees it | What it shows |
|---|---|---|
| Total Learners (Всего учеников) | admin, teacher | Learners visible to you (admin: all; teacher: active members of your groups) and how many were active in the last 7 days (`last_seen_at`) |
| Exams Last 30 Days (Экзаменов за 30 дней) | admin, teacher | Number of exam sessions in the past month |
| Activity Chart (Активность тренировок за 30 дней) | admin, teacher | Line chart of **repetitions per day** over the last 30 days: how many cards were answered in training sessions that day (the dataset is called «Повторений»). It is not the number of learners: a learner who answers 30 cards counts 30. Admin: all sessions, including personal practice; teacher: group trainings of their own groups only |
| Top Learners (Лучшие ученики за 30 дней) | admin, teacher | Top 10 learners by reviews in group trainings (last 30 days; a learner's personal practice is never counted for teachers) |
| Hardest Words (Самые трудные слова за 30 дней) | admin, teacher | Top 20 words with lowest accuracy |
| Pro subscriptions (Подписки Pro) | **admin only** | *Pro learners* — learners whose effective plan is Pro right now (own or sponsored, each counted once), with the split "own subscriptions / sponsoring organizations"; *Pro ending in 7 days* — own and organization subscriptions whose paid access ends within a week (for an auto-renewing provider: the end of the grace period) |
| Onboarding funnel — 7 days (Первичная настройка — 7 дней) | **admin only** | Learners who started onboarding in the last 7 days (`onboarding.started`), how many of them have completed it (`onboarding.completed`) and the conversion %. Distinct learners; a learner who started earlier and finished this week is not in the cohort |
| Organizations — members / seats (Учебные центры — места и очередь) | **admin only** | Per organization: active groups, **members** (distinct learners with an active membership in an active group of the organization; deleted learners are not counted), **seats** (`seats_limit` of the organization's live subscription; empty when none was set) and whether it is sponsoring Pro |
| SM-2 vs FSRS — 28 days (SM-2 и FSRS за последние 28 дней) | **admin only** | The SRS A/B, one figure per algorithm (`SM-2 / FSRS`, from `training_reviews.scheduler`): *pass rate of due cards* (graduated cards answered 3+ out of 5), *reviews per graduated word* (fewer at the same pass rate means FSRS is more efficient), *D7 retention by bucket* (learners who signed up 28–7 days ago and still studied 7+ days after signing up). `—` = no data yet; with `FSRS_ROLLOUT_PERCENT=0` and no manual switches the FSRS side stays empty |
| Revenue, last 30 days (Выручка за 30 дней) | **admin only** | One figure per currency, **UZS and XTR are never added together**. *Revenue* = the charges and renewals of the last 30 days (by payment time) that are still `succeeded`, i.e. gross minus the `refunded` ones; the line below shows the number of payments, the gross and the refunded amount. Failed events and the `refund` bookkeeping rows are not counted; a charge older than 30 days that is refunded today does not change this figure |
| Payment webhook errors, last 24 h (Ошибки вебхуков оплаты за 24 часа) | **admin only** | *Failed payments* — ledger rows recorded as `failed` in the last 24 hours (by provider); *Canceled provider transactions* — Payme / Click transactions canceled in that time (a customer closing the page also cancels, so only a spike matters). Any failed payment deserves a look: open **Payments → Status: failed**. *Double discounts* — orders paid in the last 24 hours with the red **Double discount** badge (**Payment orders → filter Double discount**); normally 0. *Double Stars subscriptions* — orders paid in the last 24 hours with the red **Double Stars subscription** badge (**Payment orders → filter Double Stars subscription**); normally 0 |

The *admin only* widgets show platform-wide data: a teacher never sees them (they are not on the dashboard and
cannot be opened directly; an inactive admin gets the same). The seat limit is informational in
this part of the panel (enforcement belongs to the organization-membership work, 2B): it is filled when the
subscription has a tier — chosen in Grant Pro or bought online — and empty otherwise; members are derived from group
membership.

The Pro and funnel figures are cached for 2 minutes, so a new subscription or event may take up to that long to show
up. The funnel reads `product_events` through the `(name, occurred_at)` index; events older than the retention period
(`PRODUCT_EVENTS_RETENTION_DAYS`, 400 days) are pruned and do not affect a 7-day window.

---

## Exam Logs

Open **Learning → Exam Logs** (Обучение → Экзамены) to see every exam session, its group, lesson, status, dates, participant count, and answer count. Open a session for two read-only drilldowns:

- **Learner results** — score, correct/answered totals, total response time, and rank.
- **Per-word answers** — learner, word, selected and correct translations, correctness, score, and server-measured response time.

Teachers see only exams from their assigned groups; administrators see all groups.

---

## Telegram Bot Configuration

### Register the webhook (once after deploy)
```bash
php artisan telegram:set-webhook
```

### Command menu and the «LexiFlow» button (once after deploy)
```bash
php artisan telegram:setup-bot
```
Sets the list of bot commands that Telegram shows after «/» (Russian and Uzbek; private chats and group chats have
their own lists; in a group the teacher's commands `/start_training`, `/start_exam`, `/close_exam` are shown to the group
administrators only, every other member sees `/help`; a teacher who is not an administrator of the group types the
command by hand) and the permanent **«LexiFlow»** menu button that opens the Mini App at `{TWA_BASE_URL}/twa/`.
Telegram accepts the button only for an https address: with any other address the command warns and skips the button
(the command list is still set). It is safe to repeat — run it again after a deploy that changes the bot's commands.
Without it the «/» menu stays empty. See `docs/VPS_DEPLOY_GUIDE_RU.md` for the BotFather side (Main Mini App).

### Remove the webhook
```bash
php artisan telegram:set-webhook --delete
```

### Required `.env` values
```env
TELEGRAM_BOT_TOKEN=          # Bot token from @BotFather
TELEGRAM_URL_SECRET=         # Random hex string (openssl rand -hex 24)
TELEGRAM_HEADER_SECRET=      # Random hex string (openssl rand -hex 24)
TELEGRAM_WEBHOOK_URL=        # Your public HTTPS domain
```

---

## Backups

Backups are scheduled automatically:
- `01:00` — clean old backups
- `01:30` — run backup
- `02:00` — monitor backup health

### Manual backup
```bash
make backup
# or in production:
docker compose -f docker-compose.prod.yml exec -T app php artisan backup:run
```

Backup files are encrypted with `BACKUP_ARCHIVE_PASSWORD`, verified after creation, and stored in `storage/app/private/Laravel/` (local disk) or on S3 if `BACKUP_DISKS=local,s3` is set. Keep that password in a separate secret store; it is required for restore.

### Restore from backup
1. Extract the `.zip` backup file.
2. Inside you find a `.sql` dump of the PostgreSQL database.
3. Restore with:
```bash
psql -U lexiflow -d lexiflow < dump.sql
```

---

## Security Checklist

| Item | Status |
|---|---|
| `APP_DEBUG=false` in production | Must be set in `.env` |
| `APP_KEY` is unique | Generated with `php artisan key:generate` |
| 2FA enabled for admin account | Setup on first login |
| Webhook secrets set | `TELEGRAM_URL_SECRET` + `TELEGRAM_HEADER_SECRET` |
| IP allowlist in production | Set `TELEGRAM_IP_ALLOWLIST_ENABLED=true` in `.env` |
| HTTPS configured | Via Certbot (see DevOps guide) |
| Database backups running | Verify in backup monitor emails |
| Backup archive encryption | Set a unique `BACKUP_ARCHIVE_PASSWORD` of at least 32 random bytes |

---

## Frequently Asked Questions

**A teacher says the bot ignores their commands.**
Check: (1) group status is `active`, (2) teacher is linked to the group, (3) teacher's `telegram_user_id` is correct.

**"Stage not found" error when starting training.**
The requested stage/lesson hasn't been imported yet — or it exists in a different course. The teacher commands look
for the stage number in the **course of the group** (group record → Course; empty = the `default` course).

**A learner says they paid but have no Pro.**
Open **Billing → Payments** (Оплата → Платежи) and search by the charge id (from the receipt / Telegram) or filter by the learner's subject.
`succeeded` + an active subscription (**Subscriptions**) means access is there (ask the learner to reopen the app; the
plan cache is dropped on payment). A `failed` row means the money arrived but could not be credited (see the raw payload
and **Payment orders** for the reason: expired order, amount mismatch): fix it by granting Pro (**Grant Pro**) or refund
the payment, and tell DevOps if it looks systematic. No row at all: the provider's webhook did not reach us (DEVOPS_GUIDE §17).

**Admin panel shows a blank page after login.**
Run `php artisan optimize:clear` (or `make cache-clear`) to clear cached views.

**How do I reset the 2FA for an admin?**
Via database:
```sql
UPDATE users SET two_factor_secret = NULL,
                 two_factor_recovery_codes = NULL,
                 two_factor_confirmed_at = NULL
WHERE email = 'admin@local';
```
Then the user goes through the 2FA setup flow again on next login.
