# LexiFlow Pro — Student Guide

This guide explains how to use LexiFlow Pro as a student enrolled in a course via Telegram.

---

## Getting Started

You do **not** register manually. Your teacher adds the LexiFlow bot to your group chat, and you are enrolled automatically when you join.

### Prerequisites
- A Telegram account (mobile or desktop).
- You must be a member of a Telegram group where the teacher has activated LexiFlow.

---

## Training (Flashcards)

Training uses **Spaced Repetition (SM-2)**: words you find hard come back sooner; words you know well appear less often.

### How to start a training session
1. Wait for your teacher to type `/start_training` in the group.
2. A message with an **Open Training** button appears in the chat.
3. Tap the button — the Web App (TWA) opens inside Telegram.

### Inside the training screen
| Element | What it does |
|---|---|
| Progress bar (e.g. `3 / 20`) | Cards done vs. total cards in this session |
| Word + transcription | The English word and pronunciation |
| 🔊 button | Plays text-to-speech pronunciation |
| **Show translation** | Reveals the Russian translation and example |
| 😰 Hard | You didn't know it — interval resets to 1 day |
| 🙂 Good | You remembered it — normal SM-2 interval |
| 😎 Easy | You knew it effortlessly — longer interval |

### Finished screen
After the last card you see:
- How many cards you reviewed.
- When the next review is scheduled (e.g. "next review in 3 days").

---

## Exam (Multiple Choice)

Exams are competitive. All students in the group answer the same questions simultaneously and are ranked on a leaderboard.

### How an exam works
1. Teacher types `/start_exam` — a group message appears with an **Open Exam** button and a countdown timer.
2. Tap **Open Exam** to join.
3. You see one word at a time with **4 answer options**. Pick the correct translation.
4. Score depends on correctness and speed:
   - Wrong answer → 0 points.
   - Correct answer → more points for faster answers.
5. When the timer runs out (or teacher types `/close_exam`), the session closes automatically.
6. The bot posts the **leaderboard** in the group chat (🥇 🥈 🥉 medals for top 3).

### Tips
- Answer quickly — the time bonus decreases every second.
- A null answer (timer expired for a question) counts as wrong.
- Duplicate submits for the same question are rejected.

---

## Frequently Asked Questions

**Can I open the training without the teacher's command?**
No. The teacher must start each session. The Web App URL is unique per session.

**The audio doesn't play — what do I do?**
The app uses your browser's text-to-speech. On some older Android Telegram versions, TTS may fall back to a server-side voice. If you hear nothing, try on Desktop Telegram.

**I accidentally closed the app mid-training. Can I continue?**
Ask your teacher to restart the session. Your progress up to the last reviewed card is saved.

**I see "Training has ended" — what happened?**
The teacher closed the session. Your reviews up to that point are already saved.

**My answers are not registering — "Too many requests" error.**
The API enforces a rate limit (30 answers/minute). Wait a few seconds and try again.
