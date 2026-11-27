# SCREENS.md — Ekranlar va navigatsiya

Barcha ekranlar 390×844 (PNG'lar @2x). Telegram header: `Close` — bo'lim ekranlari, `Back` — ichki ekranlar.

## Navigatsiya xaritasi
```
Onboarding (O1 → O8, faqat birinchi kirishda)
        ↓
TabBar ─┬─ Сегодня (01) ──┬─ Урок: вопрос (04) ⇄ ошибка (05) → Урок пройден (06) → Сегодня после урока (02)
        │                 ├─ Игры со словами (07)
        │                 ├─ Повторение (F1)
        │                 └─ Серия спасена (F4) — kun o'tkazib yuborilgandan keyingi birinchi kirish
        ├─ Курс (03) ─────┬─ Все курсы (12)
        │                 └─ Мой словарь (F2)
        ├─ Лига (08)
        └─ Профиль (09) ──┬─ Достижения (11) → Поделиться в сторис (F3)
                          ├─ Подписка Pro (10)  (yoki kunlik limit tugaganda avtomatik)
                          └─ Язык интерфейса (F6)
Har qanday ekran → Нет сети (F5) — tarmoq uzilganda
```

## Ekranlar ro'yxati
| # | Fayl | Ekran | Asosiy elementlar | Holatlar |
|---|---|---|---|---|
| 01 | light/01-segodnya | Сегодня | Salom + Stamp (streak), daftar hero (mavzu rasmi, chip, Primary), Plan (3 PlanStep), kunlik maqsad 20 nuqta, tezkor havolalar | dars boshlanmagan |
| 02 | light/02-segodnya-posle-uroka | Сегодня — после урока | hero: "Тренировка", 1-qadam Done, maqsad 13/20 | dars tugagan |
| 03 | light/03-kurs | Курс | Kurs kartasi (A1–A2, progress), modullar yo'li — mavzu rasmlari + holat belgisi | done / current / locked |
| 04 | light/04-urok-vopros | Урок — вопрос | segment progress, markazda maskot + so'z + audio, 4 AnswerOption, "Проверить" | Selected |
| 05 | light/05-urok-oshibka | Урок — ошибка | Wrong + Correct, feedback sheet (misol gap, audio, "Дальше") | xato |
| 06 | light/06-urok-proyden | Урок пройден | maskot celebrate, natija statistikasi, maqsad, 2 tugma | — |
| 07 | light/07-igry | Игры со словами | tavsiya o'yin kartasi, 5 o'yin ro'yxati | — |
| 08 | light/08-liga | Лига | markazlangan hero (trophy), leaderboard + zona, siz qatori | — |
| 09 | light/09-profil | Профиль | markazlangan avatar, statistika, 3 achievement, sozlamalar | — |
| 10 | light/10-podpiska-pro | Подписка Pro | maskot freeze, afzalliklar, 2 tarif (teng), Stars | — |
| 11 | light/11-dostizheniya | Достижения | oxirgi muhr 112px, Получены 3×, Впереди 3× (progress/locked) | — |
| 12 | light/12-vse-kursy | Все курсы | kurs tanlash 3×2, modul gridi 2 ustun | — |
| D | dark/* | 01, 03, 08, 09, 11, 12 | xuddi shu, Dark tokenlar | — |

## Bo'sh / xato holatlar (kodda albatta qiling)
- Yuklanish: skeleton (`bg/sunken` bloklar).
- Xato/offline: F5 ekrani yoki inline banner.
- Bo'sh lug'at: maskot `reading` + "Пройдите первый урок — слова появятся здесь".
- Liga hali boshlanmagan: maskot `trophy` + "Лига начнётся в понедельник".
