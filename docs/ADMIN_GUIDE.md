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

---

## User Management

Navigate to **Users** in the left sidebar.

### Create a teacher account
1. Click **New User**.
2. Fill in name, email, password, `telegram_user_id` (the teacher's Telegram numeric ID).
3. Set **Active** = true.
4. Assign the role **teacher**.
5. Save.

> The teacher's `telegram_user_id` must be correct — this is what links their Telegram identity to the platform.

### Assign a teacher to a group
1. Open the teacher's user record.
2. In the **Teacher Groups** relation tab, add the relevant groups.

### Deactivate a user
Set **Active** = false. The user cannot log into the admin panel and the bot ignores their commands.

---

## Telegram Group Management

Navigate to **Telegram Groups**.

New groups appear here automatically with status `pending` when the bot is added to them.

| Status | Meaning |
|---|---|
| `pending` | Bot joined the group but admin hasn't approved it yet |
| `active` | Fully operational — teachers can run sessions |
| `disabled` | Group is deactivated; bot ignores all commands |

### Activate a group
1. Open the group record.
2. Set **Status** = `active`.
3. Link at least one teacher via the **Teacher Groups** tab.
4. Save.

### Set the course of a group
Open the group record and pick **Course** (empty = the `default` course). The teacher commands
`/start_training <stage> <lesson>` and `/start_exam <stage> <lesson>`, and the lesson picker of
`/start_training`, look for the stage number **inside this course** only: two courses may both have
"Stage 1 / Lesson 1". Only admins can change the course of a group.

### Set the bot language of a group
The bot writes to the group (command replies, lesson buttons, exam leaderboard) in Russian by default.
To switch a group to Uzbek (Latin), open the group record, set **Bot language** = `O'zbekcha` and save
(**Default** = Russian). The choice is stored in the group's `meta.locale` and survives the bot being
removed and re-added. Personal messages (e.g. review reminders) follow each learner's interface language
(`learners.interface_locale`; for a new learner it starts from the Telegram language: `uz*` → Uzbek, anything else → Russian).
The Web App follows the learner's Telegram language.
The group language also decides the language of **exam questions** (answers and distractors) and of the
translations on cards in a group training session; personal sessions use the learner's own language.

---

## Content Management

Navigate to **Content → Courses / Stages / Lessons / Words**.

Content hierarchy: **Course → Module (stage) → Lesson → Word**. Stage numbers are unique *within a course*.
The platform course `default` (created by the seeder or by the first import) receives everything that does
not name a course.

### Courses (admins only)
**Content → Courses** lists the catalog. A course has a unique `slug` (Latin lowercase, digits, dashes;
it cannot be changed after creation), a **title in every language** (RU and UZ are both required),
an optional description, level (A1–C2), goal (General / School / IELTS / SAT), visibility
(`public` — in the catalog, `unlisted` — by link or group, `private`) and a position in the catalog.
The **Modules (stages)** table on the course page adds and edits the modules of the course; lessons and
words are edited on the stage / lesson screens.

**Status.** The status is never typed into the form — it moves with the buttons on the course page (and in the
list), each move is written to the audit log (`course.status_changed`):

| From | Button | To |
|---|---|---|
| `draft` | Send to review | `review` |
| `review` | Publish / Back to draft | `published` / `draft` |
| `published` | Archive | `archived` |
| `archived` | Back to draft | `draft` |

The `default` course cannot be archived (it serves every group that has no course of its own). Archived courses are not
offered in the group **Course** select.

**Publishing is checked.** A course is published only when (1) its title exists in every language, (2) it has
at least one lesson with active words, and (3) **at least 95% of its active words have a translation in every
language (RU and UZ; a blank text is no translation, and the word's main Translation field counts as the RU text)**; otherwise a red message says what is missing (e.g. *"Translations (ru+uz) are complete
for 18 of 20 words (90.0%), required: 95.0%"*). The threshold is `CONTENT_PUBLISH_TRANSLATION_THRESHOLD`
(default `0.95`). The check applies to **new publications only**: a course that is already published is never
re-checked, and old content without an Uzbek translation is shown in Uzbek-language sessions with the Russian
text and a small note *"No translation in your language yet — Russian shown"*.

### Translations
Every word has a Russian text (the **Translation** field, also stored as the `ru` translation) and optional
translations into other interface languages. To edit them: **Content → Words** (or the **Translations** button
next to a word on the lesson screen) → open the word → **Translations** table:
- **Create** adds a language (one row per language and word); **Edit** changes the text. A changed text becomes
  a `manual` translation and loses its *Reviewed* mark; **Mark reviewed** is for the native-speaker check.
- Editing the `ru` row also updates the word's main Translation, and editing the main Translation on the
  lesson screen updates the `ru` row, so the two never differ.
- Cards and exams use the translation of the session language and fall back to Russian (see Courses above).
Words are never deleted. The **Active** flag on the word screen hides a word: an inactive word is not counted in the
course word counter or the publishing check, is not asked in exams and is not used as a wrong answer option. Switching
the flag (or adding a word on the lesson screen) refreshes the lesson cache and the course counter at once.
Exam wrong options come from the same lesson / stage first, then other stages of the same course, and only when the
course has too few words from published, non-private courses — never from draft, review, archived or private courses.

### Importing a lesson via JSON (recommended)
**Admins only.** In this phase content belongs to the platform: the **Import Lesson** page is hidden from
teachers (direct link → *403*), and the importer itself refuses any user who is not an active admin
(nothing is written). Seeders and artisan commands run without a user and are allowed only in the console.
1. Go to **Import Lesson** in the left sidebar.
2. Click **Download sample** to get the JSON template.
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
4. Upload the file and click **Import**.
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
Cards play a pre-generated mp3 when the word has one; otherwise the app falls back to the browser voice / live TTS. After importing a lesson pack or publishing a course, ask the developer/DevOps to run `php artisan audio:pregenerate <course-slug>` (it only queues the words that do not have current audio yet; `--force` redoes all). It runs in the background — voiced words appear within minutes. Edited word texts need a re-run. Inactive words are skipped. Publishing does not start it automatically.

### Manual word editing
Open any Stage → Lesson → click on a word to edit its translation, example, or transcription.

---

## Billing: subscriptions, promo codes, organizations

Navigate to **Billing → Subscriptions / Promo codes / Organizations**. These screens are for **admins only**
(teachers do not see them). Free learners get 20 new words a day; **Pro** removes the limit. Pro comes from a
subscription of the learner, or from the subscription of an **organization** whose group the learner is an active
member of (sponsored Pro). There is no online payment yet.

### Subscriptions
The table shows every subscription: subject (learner or organization), plan, status, provider (`manual` — granted
by an admin, `promo` — from a promo code), start, **Until** (empty = no end), who granted it and the reason.
Filters: status, provider, plan, subject, and *Live status* (live = trialing / active / past due).

- **Grant Pro** (button above the table): pick the subject — a *Learner* (search by name, @username or Telegram ID)
  or an *Organization* — and either switch on **No end date** or set **Pro until**. The **Reason** is required and
  is stored in the audit log (`subscription.granted`). If the subject already has a live subscription it is
  extended / replaced in place (no second subscription is created).
- **Revoke** (row button, live subscriptions only): ends the subscription now; **Reason** required, audit
  `subscription.revoked`. The learner loses Pro within seconds (the cached plan is dropped at once).
- A subscription that ran out of time is closed automatically by the hourly job `subscriptions:expire`.

### Promo codes
**Billing → Promo codes → New**. The code is suggested automatically (10 characters); you may type your own —
spaces and dashes are ignored and the code is stored in upper case (`summer-2026` → `SUMMER2026`), 6–32 Latin
letters / digits, unique. Set the **plan**, **duration (days)**, optional **max redemptions** and **valid until**,
and a note (who the code is for). *Valid until* (like *Pro until* in Grant Pro) is entered in local time
(Asia/Tashkent by default) and stored in UTC. Creation is written to the audit log (`promo.created`), every edit of
the limits / validity / *Active* / note too (`promo.updated`, with the old and new values).

- *Redeemed* is read-only: it grows only when a learner redeems the code. One learner can redeem a code once.
- To stop a code, switch **Active** off (edit). A code that was already redeemed cannot be deleted — only deactivated.
- The learner enters the code in the TWA ("Have a promo code?" on the finish screen) or sends `/promo CODE` to the
  bot in a private chat. A learner who already has Pro with an end date gets the days added to it.

### Organizations (sponsored Pro for a learning center)
1. **Billing → Organizations → New**: name, slug (Latin, cannot be changed), time zone, language.
2. Open the organization → **Groups** table → **Link group** and pick the Telegram group (only groups that belong
   to no organization are offered). **Unlink** takes it away again. Both are written to the audit log
   (`organization.linked_group` / `organization.unlinked_group`).
   Make the bot an **administrator** of the group: then Telegram reports every leave / removal; without admin rights
   only the service message "left the group" arrives, which can be missed (a member who left may keep sponsored Pro).
3. **Billing → Subscriptions → Grant Pro** with the *Organization* subject.

Every **active member of an active linked group** now has Pro (source "sponsor" in `/me`); after unlinking, leaving the
group, suspending the organization or the end of the subscription they are back on Free (their progress stays).
**Status: Suspended** switches the sponsorship off without deleting anything; every status change is written to the
audit log (`organization.status_changed`). An organization with a live subscription or linked groups cannot be
deleted (the Delete button explains why): revoke the subscription and unlink the groups, or just suspend it.
Changes apply within seconds.

---

## Dashboard Widgets

| Widget | What it shows |
|---|---|
| Total Learners | Learners visible to you (admin: all; teacher: active members of your groups) and how many were active in the last 7 days |
| Exams Last 30 Days | Number of exam sessions in the past month |
| Activity Chart | Daily active learners (line chart) |
| Top Learners | Top 10 learners by reviews in group trainings (last 30 days; a learner's personal practice is never counted for teachers) |
| Hardest Words | Top 20 words with lowest accuracy |

---

## Exam Logs

Open **Learning → Exam Logs** to see every exam session, its group, lesson, status, dates, participant count, and answer count. Open a session for two read-only drilldowns:

- **Learner results** — score, correct/answered totals, total response time, and rank.
- **Per-word answers** — learner, word, selected and correct translations, correctness, score, and server-measured response time.

Teachers see only exams from their assigned groups; administrators see all groups.

---

## Telegram Bot Configuration

### Register the webhook (once after deploy)
```bash
php artisan telegram:set-webhook
```

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
