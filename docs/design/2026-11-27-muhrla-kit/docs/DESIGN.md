# DESIGN.md — Muhrla dizayn tizimi

## 1. G'oya
**"Daftar va muhr."** "Muhrla" — "muhr bos". Har bir o'rganilgan so'z daftarga bosilgan muhrdek qayd etiladi.
- Fon — iliq qog'oz, CTA — siyoh, aksent — bitta qizil-to'q sariq **muhr** rangi.
- Signature elementlar: **Stamp** (muhr), daftar chiziqlari + qizil hoshiya (Сегодня hero), maskot **Muhrcha** (yog'och muhr).
- O'zbek madaniy detallari: piyola va choynak (paxta naqshi), non, Rishton sopoli, do'ppi, dutor, choyxona.

## 2. Ranglar
Bitta aksent qoidasi: `accent/muhr` faqat muhr, streak, joriy progress segmenti va tab belgisi uchun. **CTA har doim `bg/inverse` (siyoh).**

| Token | Light | Dark | Ishlatilishi |
|---|---|---|---|
| bg/canvas | #F6F2EA | #121117 | Ekran foni; Telegram header/bottom bar ham shu rang |
| bg/surface | #FFFDF8 | #1C1B22 | Kartalar, ro'yxat guruhlari, tab bar |
| bg/sunken | #EEE8DC | #25232B | Ikon plitasi, progress treki, disabled |
| bg/accent-soft | #FBE3D9 | #3A221B | "Siz" qatori (liga), o'yin plitasi |
| bg/inverse | #1A1A1F | #F3EEE4 | Primary tugma, tanlangan holat |
| text/primary | #1A1A1F | #F3EEE4 | Asosiy matn |
| text/secondary | #5E5A52 | #B4AEA3 | Tavsif |
| text/tertiary | #736C60 | #8C867B | Meta, sana (kontrast ≥ 4.5:1) |
| text/on-inverse | #FFFDF8 | #1A1A1F | Siyoh fondagi matn |
| text/accent | #B53A18 | #FF8A65 | Matndagi aksent ("повторить", "зона повышения") |
| border/default | #E6DFD2 | #302E37 | Karta chegarasi, ajratgich |
| border/strong | #1A1A1F | #F3EEE4 | Tanlangan karta/variant |
| accent/muhr | #E4572E | #FF6B42 | Muhr, streak, joriy segment |
| status/success (+soft) | #2F8F5B / #E3F1E8 | #4CC38A / #173323 | To'g'ri javob, bajarilgan |
| status/error (+soft) | #B3261E / #F8E1DE | #FF7A70 / #3B1816 | Xato javob, feedback sheet |

To'g'ri/xato hech qachon faqat rang bilan ko'rsatilmaydi — har doim `check` / `x` ikon bilan.

## 3. Tipografiya
- Display: **Unbounded** 500/600 — sarlavhalar, inglizcha so'z, raqamlar.
- Matn: **Onest** 400/500/600.
- Ikkalasi latin + kirill. Self-host woff2, `font-display: swap`.

| Style | Font | Size/LH | Tracking | Qayerda |
|---|---|---|---|---|
| Display/L | Unbounded 600 | 30/36 | -2% | Ekran sarlavhasi, natija |
| Display/M | Unbounded 600 | 24/30 | -2% | Bo'lim sarlavhasi, hero |
| Title/M | Unbounded 500 | 18/24 | -1% | Blok sarlavhasi |
| Word/XL | Unbounded 600 | 44/50 | -3% | Darsdagi inglizcha so'z |
| Numeric/L | Unbounded 600 | 22/26 | -2%, tabular | Statistika, ball |
| Body/L | Onest 500 | 17/24 | 0 | Javob varianti |
| Body/M | Onest 400 | 15/22 | 0 | Asosiy matn |
| Body/M Strong | Onest 600 | 15/22 | 0 | Ro'yxat sarlavhasi |
| Button | Onest 600 | 16/20 | 0 | Tugmalar |
| Label/S | Onest 500 | 13/18 | 0 | Meta, chip |
| Caption | Onest 500 | 12/16 | +1% | Birlik, teg |

Input maydonlarida shrift kamida 16px (iOS zoom bo'lmasligi uchun).

## 4. Spacing — 4px asosli shkala
`2 · 4 · 8 · 12 · 16 · 20 · 24 · 32 · 40 · 48`
- 4 — ikon va matn minimal oralig'i
- 8 — sarlavha va meta, header ostidagi bo'shliq
- 12 — ro'yxat elementlari, tugmalar orasi
- 16 — karta ichki padding
- 20 — ekran yon chegarasi (`--safe-side`), hero/sheet padding
- 24 — asosiy bo'limlar orasi (dars ekranlarida 20, onboardingda 20)
- 32/48 — katta pauzalar, natija ekranlari

## 5. Radius
| Token | px | Qayerda |
|---|---|---|
| xs | 6 | AnswerOption kaliti, kichik chip-kvadrat |
| sm | 10 | ListRow ikon plitasi, LeagueRow, onboarding daraja belgisi |
| md | 14 | Button, AnswerOption, input, tanlov kataklari |
| lg | 20 | Ro'yxat guruhi, statistika, tariflar, modul kartalari |
| xl | 28 | Hero "daftar", bottom sheet yuqori burchaklari, story kartasi |
| full | 999 | Chip, avatar, IconButton, progress |

## 6. Soya va chegara
Soyalar ishlatilmaydi — chuqurlik 1px `border/default` va `bg/surface` ↔ `bg/canvas` farqi bilan beriladi. Tanlangan holat: 2px `border/strong`.

## 7. Layout va safe zone (390×844)
- Yuqori 103px — Telegram zonasi (47 status + 56 Telegram paneli). Kontent undan 8px pastda.
- Yon tomonlar 20px.
- Pastki 34px — home indicator. Tab bar 64 + 34 = 98px.
- Tab bar bo'lmagan ekranlarda (dars, natija, Pro, onboarding) — pastki tugma zonasi: padding 12/20/0/20 + home indicator.
- Faqat `Content` skroll bo'ladi. Balandlik: `var(--tg-viewport-stable-height)`, `100vh` emas.

## 8. Simmetriya qoidalari
- Hero bloklar markaziy o'qda: illyustratsiya → sarlavha → tavsif (Лига, Профиль, Достижения, Pro, dars so'zi, onboarding).
- Gridlar teng ustunli: achievement 3×104px `space-between`; modul kartalari 2 ustun; yosh/vaqt kataklari 3×2; maqsad kataklari 2×3; kurs tanlash 3×2.
- Statistika ustunlari `flex: 1`, orasida 1px ajratgich.
- Ro'yxatlar va jadvallar chapdan tekislanadi.

## 9. Ikonlar
Lucide, 24px grid, stroke 1.75 (faol tabda 2), rang — `currentColor`. Faqat skanerlashni yengillashtirgan joyda.

## 10. Accessibility
- Kontrast: matn ≥ 4.5:1, katta matn ≥ 3:1 (tokenlar tekshirilgan).
- Bosiladigan elementlar ≥ 44×44.
- IconButton'larda `aria-label` majburiy.
- AnswerOption: `role="radio"`, guruh `role="radiogroup"`.
- `prefers-reduced-motion` — animatsiyalar crossfade'ga almashadi.
