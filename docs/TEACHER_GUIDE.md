# LexiFlow Pro — Teacher Guide

This guide covers everything a teacher needs to run training sessions and exams with LexiFlow Pro.

---

## Prerequisites

1. Your Telegram account's `telegram_user_id` must be saved in the system by the administrator.
2. You must be linked to at least one **active** group in the admin panel.
3. The LexiFlow bot must be a member of that Telegram group.

---

## Bot Commands Reference

All commands are typed directly into the **group chat** where the bot is active (unless noted).

| Command | Context | Description |
|---|---|---|
| `/help` | any | Shows command list |
| `/start` | private chat (DM) | Links your Telegram account to your teacher profile |
| `/start_training [stage] [lesson]` | group | Opens a flashcard training session |
| `/start_exam [stage] [lesson] [minutes]` | group | Opens a timed exam session |
| `/close_exam` | group | Closes the current exam and posts the leaderboard |

---

## Step 1 — Link Your Account (once)

In **private chat** with the bot, type:
```
/start
```
The bot replies with your name if your `telegram_user_id` is configured. If you get an "unknown user" message, ask the administrator to add your Telegram ID.

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
Defaults: Stage 1, Lesson 1, 2 minutes.

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
| "Stage N not found" / "Lesson N not found" | That stage/lesson hasn't been imported yet. Contact admin. |
| "Not enough words" | The lesson has fewer than the minimum required words. Contact admin. |
| "An exam is already open" | Another exam is running — close it first with `/close_exam`. |
| "No exam is open in this group" | You tried `/close_exam` but there is nothing to close. |
| "Duration must be between 1 and 30 minutes" | The minutes argument is out of range. |

---

## Viewing Results in the Admin Panel

1. Open your browser and go to `/admin`.
2. Log in with your teacher credentials.
3. Navigate to your group to see exam sessions and student progress.

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
