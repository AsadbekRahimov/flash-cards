# Muhrla — Design Kit (Telegram Mini App)

Ingliz tili so'zlarini o'rgatadigan Telegram Mini App'ning to'liq qayta dizayni.
Yo'nalish: **"Daftar va muhr"** — o'rganilgan har bir so'z daftarga bosilgan muhrdek "muhrlanadi".

Figma manba fayl: https://www.figma.com/design/IHCjhUgwWzTEmH2RJx5kd5
(sahifalar: 📱 Screens · 🧩 Components · 📐 Guidelines · 🎨 Illustrations · 💡 Audit & New features)

## Arxiv tarkibi

| Papka / fayl | Nima bor |
|---|---|
| `01-screens/light` | 12 ta asosiy ekran, light (PNG @2x, 780×1688) |
| `01-screens/dark` | 6 ta ekranning dark versiyasi (@2x) |
| `02-onboarding` | 8 ekranli onboarding oqimi (@2x) |
| `03-new-features` | Auditdan chiqqan 6 ta yangi funksiya ekrani (@2x) |
| `04-components` | Komponentlar sahifalari, barcha holatlar bilan (@2x) |
| `05-guidelines` | Safe zone, spacing/radius, rang/tipografiya, dev notes (@1.5x) |
| `06-audit` | Audit jadvali (PNG) |
| `07-illustrations` | Maskot "Muhrcha" (6 holat), 6 ta achievement (+ locked), **120 ta mavzu rasmi** (shaffof PNG) + `topics/index.json` |
| `08-icons` | Ishlatilgan 38 ta Lucide ikon (SVG, stroke 1.75, `currentColor`) |
| `tokens/tokens.css` | CSS o'zgaruvchilar (light + dark) |
| `tokens/tokens.json` | Xuddi shu tokenlar JSON'da (Flutter/React Native uchun ham qulay) |
| `docs/DESIGN.md` | Dizayn tizimi: ranglar, tipografiya, spacing, radius, soya, grid, simmetriya qoidalari |
| `docs/COMPONENTS.md` | Har bir komponent: anatomiya, o'lcham, holatlar, props |
| `docs/ANIMATIONS.md` | Motion spec: davomiylik, easing, har bir animatsiya (CSS/JS namunalar bilan) |
| `docs/TELEGRAM.md` | Telegram WebApp API integratsiyasi, safe area, haptic, to'lov |
| `docs/SCREENS.md` | Barcha ekranlar, navigatsiya xaritasi, holatlar |
| `docs/ONBOARDING.md` | Onboarding oqimi, maydonlar, validatsiya, ma'lumot sxemasi |
| `docs/NEW-FEATURES.md` | Yangi funksiyalar: spaced repetition, lug'at, ulashish, streak, offline, til |
| `docs/AUDIT.md` | Audit topilmalari va tavsiyalar |
| `docs/ASSETS.md` | Rasmlar va ikonlarni ishlatish, eksport, nomlash |

## Tezkor start (frontend)

1. `tokens/tokens.css` ni ulang va `data-theme="dark"` ni `Telegram.WebApp.colorScheme` ga bog'lang.
2. Shriftlar: **Unbounded** (500/600) va **Onest** (400/500/600), latin + cyrillic, `font-display: swap`.
3. Ikonlar: `lucide-react` (stroke 1.75) yoki `08-icons` SVG'lari.
4. Ekran tuzilishi: Telegram zonasi (yuqori) → `Content` (skroll) → TabBar yoki pastki tugma zonasi → home indicator safe area. Batafsil: `docs/TELEGRAM.md`.
5. Har bir ekranni `01-screens`, `02-onboarding`, `03-new-features` dagi PNG bilan pixel-taqqoslab tekshiring.

## Eslatmalar
- Ekran matnlari rus tilida (ilovaning hozirgi tili). O'zbekcha interfeys — `F6` funksiyasi.
- Rasmlar AI yordamida yaratilgan (Figma AI), fonlari olib tashlangan. Mavzu rasmlari 240×240 — 112px gacha ishlatishga mo'ljallangan.
