# LexiFlow Pro — Teacher Guide

This guide covers everything a teacher needs to run training sessions and exams with LexiFlow Pro.

---

## Prerequisites

1. Your Telegram account's `telegram_user_id` must be saved in the system by the administrator.
2. You must be linked to at least one **active** group in the admin panel.
3. The LexiFlow bot must be a member of that Telegram group.

> **Content is managed by platform administrators.** In this phase a teacher cannot import lessons, edit
> courses or words, or open the **Import Lesson** page (it answers *403*). If a lesson or stage you need is
> missing, ask the administrator to import it.

---

## Bot Commands Reference

All commands are typed directly into the **group chat** where the bot is active (unless noted).

| Command | Context | Description |
|---|---|---|
| `/help` | any | Shows command list |
| `/start` | private chat (DM) | Greets a teacher whose Telegram ID is linked to their profile (an unlinked account gets the learner onboarding) |
| `/start_training [stage] [lesson]` | group | Opens a flashcard training session |
| `/start_exam [stage] [lesson] [minutes]` | group | Opens a timed exam session |
| `/close_exam` | group | Closes the current exam and posts the leaderboard |
| `/stats` | private chat (DM) | Your report for the last 7 days; an account that is not linked yet is shown its Telegram ID |

---

## Step 1 — Link Your Account (once)

In **private chat** with the bot, type:
```
/start
```
The bot replies with your name if your `telegram_user_id` is linked to your teacher profile. Until it is linked the bot treats you as an ordinary learner and starts the learner onboarding (language, goal, level, ...). To find your Telegram ID, send `/stats` to the bot in the private chat: it shows the ID for an account that is not linked yet. Give this ID to the administrator, who adds it to your profile; after that `/start` greets you as a teacher.

---

## Step 2 — Start a Training Session

In the **group chat**, type:
```
/start_training
```
Defaults: Stage 1, Lesson 1.

To specify a different stage and lesson:
```
/start_training 2 3
```
(Stage 2, Lesson 3)

The bot sends a message with an **Open Training** button. Students tap it to open the flashcard interface.

### Notes
- Only one training session can be open per group at a time.
- If you run the same command again with the same stage/lesson, the existing session is reused.

---

## Step 3 — Start an Exam

```
/start_exam
```
Defaults: Stage 1, Lesson 1, 10 minutes.

To specify stage, lesson, and duration:
```
/start_exam 2 1 5
```
(Stage 2, Lesson 1, 5 minutes. Min: 1 min, max: 30 min.)

The bot posts a message with a countdown and an **Open Exam** button. Students join by tapping the button.

### What happens automatically
- Each student sees the same pool of multiple-choice questions (4 options per word).
- When the timer expires, the session closes and the bot posts the leaderboard in the group.
- Scheduler also closes sessions automatically after the time limit + a 60-second grace period.

---

## Step 4 — Close an Exam Early

```
/close_exam
```
This immediately closes the open exam, builds the final leaderboard, and posts it in the group.

---

## Error Messages and What They Mean

| Bot reply | Cause |
|---|---|
| "You are not a teacher of this group" | Your account is not linked to this group. Contact admin. |
| "Group is not active" | The group is disabled in the admin panel. Contact admin. |
| "Stage N not found" / "Lesson N not found" | That stage/lesson hasn't been imported yet. Ask the admin to import it (teachers cannot). |
| "Not enough words" | The lesson has fewer than the minimum required words. Contact admin. |
| "An exam is already open" | Another exam is running — close it first with `/close_exam`. |
| "No exam is open in this group" | You tried `/close_exam` but there is nothing to close. |
| "Duration must be between 1 and 30 minutes" | The minutes argument is out of range. |

---

## Viewing Results in the Admin Panel

1. Open your browser and go to `/admin`.
2. Log in with your teacher credentials.
3. Open **Learning → Exam Logs** to see sessions, student results, and the per-word breakdown for your assigned groups.

> Note: Teachers can only see their own groups. The admin decides which groups you are linked to.

---

## Frequently Asked Questions

**Can students join an exam after it started?**
Yes. They can join at any time before the session closes, but they have less time to answer.

**What happens to unanswered questions?**
They count as incorrect (0 points).

**Can I have a training session and an exam open at the same time?**
Yes. Training sessions and exam sessions are independent.

**How many students can join an exam?**
There is no hard limit. All students in the active group can join.

**Do I need to close the exam manually?**
No. The scheduler closes it automatically after the time limit + 60-second grace. Use `/close_exam` only if you want to end it early.

---

## Learning Center Cabinet (`/org`)

Staff of a learning center (an **owner** or a **teacher** of the center) work in a separate cabinet at
`https://<host>/org`. It is not the admin panel: you log in with the email and password from your
invitation letter, and you only ever see your own center.

### Getting in

1. The owner invites you by email (**Teachers → Invite teacher**). You receive a letter with a
   single-use link (valid 24 hours) to set your password. If the link has expired, use **Forgot
   password?** on the login page — the account already exists.
2. Open `/org`, log in. If you belong to several centers, switch between them in the menu at the top.
3. **Owners must enable two-factor authentication** (Google Authenticator, 1Password, Authy): the cabinet
   redirects you to the setup page on the first login and asks for a code on every new session. Teachers
   may enable it in the same way; once enabled, it is required.
4. In **Profile** (top-right menu) enter **My Telegram ID** — the bot shows it in a private chat on `/stats`.
   Without it the bot's teacher commands (`/start_training`, `/start_exam`) do not recognise you, and an
   owner cannot link groups.

### Screens

| Screen | Owner | Teacher |
|---|---|---|
| **Dashboard** | seats (taken / limit, waiting list), subscription, learners and activity of the last 7 days | activity of your groups only |
| **Learners** | all members of the center with their seat state; **Add learner** by exact `@username` or Telegram ID; **Remove from center** (the seat goes to the first learner on the waiting list) | learners of your groups, read-only |
| **Groups** | **Link group** by chat id — the bot must already be in the group and *you* must be its creator or administrator (the cabinet checks this with Telegram); **Unlink** | your groups only |
| **Invitations** | create codes (label, uses, expiry), copy the `t.me/<bot>?start=org_<code>` link, deactivate | — |
| **Teachers** | invite by email, assign groups, change role, resend the invitation, remove (the last owner cannot be removed) | — |
| **Assignments** | all groups of the center: set a lesson with a deadline, see who finished, close | your groups only |
| **Reports** | activity of the whole center, per group and per learner; CSV download | the same, for your groups only |
| **Billing** | tariff and subscription, seats, payment, invoice, history (see *Payment* below) | — |
| **Center settings** | name, contact, timezone, cabinet language | — |

The cabinet speaks the language set in **Center settings** (Russian or Uzbek).

### Assignments

An assignment is *one lesson of the group's course with a deadline*.

1. **Assignments → Create assignment.** Pick the group (only groups of your center that have a course are listed), then the lesson (lessons of that group's course only), the deadline, and optionally a title (empty means the lesson title) and the completion threshold (default 100 % of the lesson's words).
2. The bot posts **one message to the group chat** with an **Open lesson** button; the learners start the lesson straight from it. They also see the assignment in the **Today** screen of the mini app, with their own progress. The bot sends **one reminder to the group** about 24 hours before the deadline (nobody gets personal messages).
3. **Progress** is the share of the lesson's active words the learner has answered correctly at least once. It is recounted every 30 minutes and whenever you open the assignment report. The list shows *N of M finished* (learners still in the group); the report shows each learner's progress and whether they finished, finished late or have not started. Only this aggregate is shown — never the learner's personal sessions or answers.
4. After the deadline an assignment stays open: late completions are still counted and marked **finished late**. **Close** ends it by hand (the final progress stays in the report; a closed assignment cannot be reopened); an assignment overdue for 14 days is closed automatically.

A teacher sees and creates assignments only for their own groups; the owner for all groups of the center.

### Reports

**Reports** shows how the learners practised over a period, as two tables: a summary **per group** and a list **per learner**.

- **Filters.** *Group* (only the groups you can see; empty means all), *Period*: the last 7 days, the last 30 days, or your own dates (from / to, both days included, at most 366 days). Days are counted in the center's time zone (**Center settings**).
- **Columns per learner.** *Reviews* and *new words* (sums over the period), *days active* (days with at least one review or new word), *days the daily goal was met*, the *current streak* (0 if the learner skipped yesterday), *assignments done / total* and *last seen*. The group table adds the number of learners and how many of them were active.
- **Assignments.** Counted are the center's assignments whose **deadline falls in the chosen period**, for learners who are still in the assignment's group; "done" means the learner reached the completion threshold (late completions count). The numbers are the last saved snapshot (refreshed every 30 minutes and when an assignment report is opened).
- **CSV.** **Download CSV (learners)** and **Download CSV (groups)** export exactly what the tables show for the current filters. The file is UTF-8 with a BOM, fields are separated by `;` (opens correctly in Excel with Russian / Uzbek regional settings), the headers are in the cabinet's language. Telegram IDs are not exported.
- **Who sees what.** The owner sees the whole center; a teacher only the learners and groups of their own groups. Learners of other centers never appear.
- **Privacy.** The report contains only daily totals and assignment results. A learner's individual sessions and answers are never shown to staff.

### Seats, in one paragraph

A learner joins the center through an invitation link, through one of the center's groups, or when the
owner adds them. While the center has an active subscription, every member takes a seat up to the tariff's
limit and gets Pro; members above the limit wait in the order they joined and get a seat as soon as one is
freed or the tariff is raised. Nobody is blocked from learning — a waiting learner simply uses the free plan.

### `/org` in the bot

Staff whose Telegram ID is linked can send `/org` to the bot in a private chat: it answers with every
center you belong to, your role, the seats (owners) and the number of learners active in the last 7 days,
plus the link to the cabinet.

### Payment (owners)

**Billing** is visible to owners only; a teacher gets "403" if they open the address by hand.

1. At the top you see the current tariff, the status, the date paid until, the seats (taken / limit) and
   how many learners wait for a seat.
2. Choose a **tariff** and a **period**. The list shows the seats and the price of every tariff
   (Tutor 30, Center S 100, Center M 300; Center L is agreed individually — the platform activates it).
   The period is **1 month** or **1 year**; when the year is cheaper than twelve months, the page shows how much
   you save. A year paid on top of a running period is added to its end.
3. Press **Pay with Payme** or **Pay with Click** (a button appears only for the methods that are switched on):
   you are taken to the provider's payment page. After the payment the subscription is extended by the
   period (30 days per month paid, 365 per year paid) and the tariff takes effect immediately — learners from the waiting
   list get seats in the order they joined. If nothing is paid within 24 hours the order closes by itself.
4. Pay by bank transfer instead: **Request an invoice** sends your choice (tariff, period, comment) to the
   platform administrator, who replies by email and activates the tariff after the payment. The same button
   is how you ask for Center L.
5. **Orders** lists every order with its status; **Invoice** opens a printable page (Ctrl+P, "Save as PDF")
   with the platform's requisites, the number, the date and the amount in sums. **Payments** lists what was
   actually charged or refunded.

**Changing the tariff.** While your subscription is active, online payment only **extends the current tariff**;
choosing another tariff and pressing Pay shows "To change the tariff, contact us". To move up or down, write to
the platform (the **Request an invoice** button with a comment works for this too): the change is agreed and
applied by hand, and from then on you renew the new tariff online. Once the paid period has ended, any tariff can
be bought online again.

Moving to a **smaller** tariff never removes anyone: learners who already hold a seat keep it, even if there
are now more of them than the limit ("Over the limit: 5 of 2 seats" on Billing and on the Dashboard); new
learners wait until seats free up or you raise the tariff again.

You get an email (and a Telegram message, if your Telegram ID is linked) 3 days and 1 day before the paid
period ends. After the end the center's learners return to the free plan; no data is deleted.
