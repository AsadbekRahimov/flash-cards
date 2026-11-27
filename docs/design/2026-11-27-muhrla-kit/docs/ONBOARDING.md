# ONBOARDING.md

Birinchi kirishda 8 ekran (`02-onboarding/`). Progress: 7 segment (O2–O8), "n / 7". Har qadamda Telegram BackButton.

| # | Ekran | Savol | Kiritish turi | Majburiy | Default |
|---|---|---|---|---|---|
| O1 | Добро пожаловать | — | "Начать" | — | — |
| O2 | Имя | "Как вас зовут?" | TextField (1–32 belgi) | ha | `initDataUnsafe.user.first_name` |
| O3 | Возраст | "Сколько вам лет?" | 6 katak, bitta tanlov | ha | — |
| O4 | Цель | "Зачем вам английский?" | 6 katak, ko'p tanlov | kamida 1 | — |
| O5 | Уровень | "С какого уровня начнём?" | 6 qator, bitta tanlov / test | ha | A1–A2 |
| O6 | Цель на день | "Сколько слов в день?" | 4 qator | ha | 20 (рекомендуем) |
| O7 | Напоминание | "Когда напомнить?" | 6 vaqt + switch | yo'q | 20:00, switch On |
| O8 | План готов | Xulosa | "Начать первый урок" | — | — |

## Ma'lumot sxemasi
```ts
type OnboardingProfile = {
  name: string;                       // O2
  ageRange: 'lt14' | '14-17' | '18-24' | '25-34' | '35-44' | '45+'; // O3
  goals: Array<'career' | 'study' | 'travel' | 'relocation' | 'communication' | 'media'>; // O4
  course: 'A1-A2' | 'B1' | 'B2' | 'C1' | 'C2' | 'Phrases';   // O5
  dailyWords: 5 | 10 | 20 | 30;       // O6
  reminder: { enabled: boolean; time: string /* 'HH:mm' */ }; // O7
  uiLanguage: 'uz' | 'ru';            // language_code'dan
};
```

## Qoidalar
- "Продолжить" tanlov qilinmaguncha Disabled holatda.
- O4: tugmada tanlanganlar soni ("Продолжить · выбрано 2").
- O5: "Пройти тест за 2 минуты" — 10 savollik placement test (A1→C1 so'zlari), natija kursni avtomatik tanlaydi.
- O7: switch On bo'lsa `requestWriteAccess()`; rad etilsa switch Off ga qaytadi va izoh chiqadi.
- Yosh faqat mavzu/misollarni moslash uchun; boshqa foydalanuvchilarga ko'rinmaydi. 18 yoshdan kichiklar uchun баттл/liga chatlari cheklangan bo'lishi kerak (backend siyosati).
- Har qadam serverga saqlanadi — yarmida yopilsa, o'sha joydan davom etadi.

## Illyustratsiyalar
O1 — maskot `hello` + atrofida 6 ta mavzu rasmi (simmetrik), O2 — `hello`, O3 — `thinking`, O4 — mavzu rasmlari kataklarda, O6 — soat/kalendar (A1-04), O7 — telefon (A1-12), O8 — `celebrate`.
