# COMPONENTS.md

Barcha komponentlar Figma'da `🧩 Components` sahifasida, tavsifi (description) bilan. PNG: `04-components/`.
Har bir rang/radius Figma variable'ga bog'langan — kodda faqat tokenlardan foydalaning.

---

## Button
`04-components/button.png`
- Variantlar: `Type = Primary | Secondary | Ghost` × `State = Default | Pressed | Disabled`
- Props: `label: string`, `showIcon: boolean` (o'ngda `arrow-right` 20px, stroke 2)
- O'lcham: balandlik 52, padding 0/20, gap 8, radius `md` (14), kenglik — konteynerni to'ldiradi.
- Primary: bg `bg/inverse`, matn `text/on-inverse`. Ekranda faqat bitta Primary.
- Secondary: bg `bg/surface`, border 1.5 `border/default`.
- Ghost: fon yo'q — "Не сейчас", "Пропустить".
- Pressed: `scale(.98)` + fon (Primary 82% opacity, boshqalar `bg/sunken`), 120ms.
- Disabled: bg `bg/sunken`, matn `text/tertiary`, `aria-disabled`.
- Loading: label o'rnida 3 nuqta pulse (Motion: ANIMATIONS.md §6).

```tsx
<Button type="primary" icon>Начать урок</Button>
```

## IconButton
- 44×44, radius full, bg `bg/sunken`; Pressed: `border/default`.
- Ikon 20px. `aria-label` majburiy ("Прослушать слово").

## Chip
- `Tone = Neutral | Accent | Success | Ink`, balandlik 26, padding 0/10, radius full, `Label/S`.
- Faqat meta-ma'lumot uchun (daraja, holat). Bosiladigan filtr sifatida ishlatilganda balandlikni 32 qiling.

## Stamp (signature)
`04-components/stamp.png`
- 92×92, tashqi halqa 2.5px `accent/muhr`, ichki halqa 1px punktir (2/3), −8° burilgan.
- Props: `value` ("12"), `unit` ("ДНЕЙ").
- Ishlatilishi: streak (Сегодня), seriya saqlandi, natija.
- Animatsiya: "stamp press" (ANIMATIONS.md §3).

## TG/Header (Telegram chrome — chizilmaydi!)
- `Nav = Close | Back`. 103px: 47 status + 56 Telegram paneli.
- Bu Telegram'ga tegishli; dizaynda kontekst uchun ko'rsatilgan. "Назад" — `Telegram.WebApp.BackButton`.

## TabBar
- `Active = Сегодня | Курс | Лига | Профиль`. 64 + 34 safe = 98px, bg `bg/surface`, yuqori border 1px.
- Element: ikon 24 + `Label/S`; faol: matn SemiBold `text/primary`, ikon stroke 2 + ostida 16×3 `accent/muhr` belgi.
- Faqat bo'limlar. Dars, natija, Pro, onboardingda yashiriladi.

## AnswerOption
`04-components/answer-option.png`
- `State = Default | Selected | Correct | Wrong`. 60h, radius `md`, padding 0/16/0/12, gap 12.
- Chapda kalit 28×28 radius `xs` (1–4 — desktop Telegram'da klaviatura).
- Default: border 1.5 `border/default`; Selected: border 2 `border/strong`, kalit siyoh;
  Correct: `status/success-soft` + border 2 success + `check`; Wrong: `status/error-soft` + border 2 error + `x`.
- `role="radio"`, tanlanganda `HapticFeedback.selectionChanged()`.

## ListRow
- Min 60h, padding 10/12, chapda 40×40 plita radius `sm` (`bg/sunken`) + ikon 20.
- Props: `title`, `subtitle?`, `value?`, chevron. Guruh ichida separator 1px, chapdan 64px inset.

## PlanStep
- `State = Done | Current | Upcoming`. Doira 28: Done — success + check; Current — muhr + raqam; Upcoming — outline + raqam.
- Props: `title`, `meta`.

## LeagueRow
- `State = Default | You`. 52h, radius `sm`. Rank 20px, avatar 32 (bosh harf), ism, ball (tabular-nums).
- You: `bg/accent-soft`, avatar muhr rangida; ro'yxat skroll bo'lganda sticky.
- "Зона повышения" ajratgichi: `Caption` `text/accent` + 1px chiziqlar.

## AchievementBadge
`04-components/achievement-badge.png`
- `State = Unlocked | Progress | Locked`, kenglik 104, markazlangan ustun.
- Art 88×88 (instance swap: `achv/<name>` yoki `achv/<name>/locked`), sarlavha 13/600, meta 12.
- Progress: 64×4 bar (`bg/sunken` + `accent/muhr`).
- Grid: 3 ustun `justify-content: space-between`.

## Ekran darajasidagi naqshlar (komponent emas, lekin qayta ishlatiladi)
- **Daftar hero** (Сегодня): surface radius xl, gorizontal chiziqlar har 28px (`border/default` 80%), chapda 30px'da 1.5px qizil hoshiya (`accent/muhr` 55%), padding 20/20/20/44.
- **Progress segmentlari** (dars/onboarding): 4–6px balandlik, radius 3, o'tilgan — siyoh, joriy — muhr, qolgan — sunken.
- **Feedback sheet**: yuqori burchaklar 28, `status/error-soft`, padding 20, ichida misol gap + audio + "Дальше".
- **Selectable tile** (onboarding): surface radius lg, border 1.5 → tanlangan 2px strong + chap-yuqorida 22px siyoh check.
- **TextField**: 56h, radius md, Focused — border 2 strong + muhr rangli kursor; label `Label/S` tepada, hint `Caption` pastda.
- **Switch**: 48×28, On — `status/success`, knob 22 oq.
