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

---

## Content Management

Navigate to **Stages → Lessons → Words**.

### Importing a lesson via JSON (recommended)
1. Go to **Import Lesson** in the left sidebar.
2. Click **Download sample** to get the JSON template.
3. Prepare your JSON file following the schema:
```json
{
  "stage": {"number": 1, "title": "Beginner", "description": "Optional"},
  "lesson": {"number": 1, "title": "Greetings"},
  "words": [
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
5. Review the report: `added / updated / skipped / errors`.

### Import rules
- Max file size: 2 MB.
- Max 500 words per file.
- Allowed `part_of_speech`: `noun`, `verb`, `adjective`, `adverb`, `pronoun`, `preposition`, `conjunction`, `interjection`.
- Duplicate words within the same lesson are rejected.
- Importing again with the same stage/lesson numbers **updates** existing words (upsert).
- If any validation error occurs, **nothing is written** (atomic transaction).

### Manual word editing
Open any Stage → Lesson → click on a word to edit its translation, example, or transcription.

---

## Dashboard Widgets

| Widget | What it shows |
|---|---|
| Total Students | Count of all enrolled students |
| Exams Last 30 Days | Number of exam sessions in the past month |
| Activity Chart | Daily active students (line chart) |
| Top Students | Top 10 students by correct answers |
| Hardest Words | Top 20 words with lowest accuracy |

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

Backup files are stored in `storage/app/private/Laravel/` (local disk) or on S3 if `BACKUP_DISKS=local,s3` is set.

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

---

## Frequently Asked Questions

**A teacher says the bot ignores their commands.**
Check: (1) group status is `active`, (2) teacher is linked to the group, (3) teacher's `telegram_user_id` is correct.

**"Stage not found" error when starting training.**
The requested stage/lesson hasn't been imported yet. Import a lesson first.

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
