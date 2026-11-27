# ANIMATIONS.md — Motion spec

Prinsip: **bitta muallif lahza** har ekranda (odatda muhr bosilishi), qolgani tez va sezilmas. Faqat `transform` va `opacity` (60fps).

## 1. Tokenlar
| Token | Qiymat | Qayerda |
|---|---|---|
| `--dur-press` | 120ms | Tugma bosilishi |
| `--dur-state` | 200ms | Holat o'zgarishi (tanlash, toggle, tab) |
| `--dur-enter` | 260ms | Sheet, muhr, ekran elementlari |
| `--ease-out` | cubic-bezier(.2,.8,.2,1) | Default |
| `--ease-stamp` | cubic-bezier(.2,.9,.3,1.2) | Muhr (ozgina "zarb") |

## 2. Press feedback (barcha bosiladiganlar)
```css
.pressable { transition: transform var(--dur-press) var(--ease-out), background-color var(--dur-press); }
.pressable:active { transform: scale(.98); }
@media (hover: hover) and (pointer: fine) { .pressable:hover { filter: brightness(.97); } }
```
Feedback `pointerdown`'da — `click`'ni kutmang.

## 3. Stamp press (signature)
Muhr bosilishi: dars tugaganda, streak +1, achievement ochilganda, Сегодня'dagi muhr birinchi ko'rinishda.
```css
@keyframes stamp-press {
  0%   { transform: rotate(-8deg) scale(1.25); opacity: 0; }
  60%  { transform: rotate(-8deg) scale(.96);  opacity: 1; }
  100% { transform: rotate(-8deg) scale(1);    opacity: 1; }
}
.stamp--enter { animation: stamp-press var(--dur-enter) var(--ease-stamp) both; }
```
Bir vaqtda `HapticFeedback.impactOccurred('medium')`. Ekranda bittadan ortiq emas.

## 4. Darsdagi javob
- Tanlash: border/fon 200ms, `selectionChanged()`.
- "Проверить": Correct — variant fon success-soft'ga 200ms + `check` ikon scale 0.6→1; `notificationOccurred('success')`.
- Wrong — variantda 2 marta gorizontal silkinish (translateX ±4px, 240ms jami) + `notificationOccurred('error')`.
- Progress segmenti: keyingi segment muhr rangiga 200ms.

## 5. Feedback sheet / bottom sheet
```css
.sheet { transform: translateY(100%); transition: transform var(--dur-enter) var(--ease-out); }
.sheet--open { transform: translateY(0); }
```
Yopilish — teskari, 200ms. Fon dim yo'q (dars kontenti ko'rinib turadi).

## 6. Loading
- Skeleton: `bg/sunken` bloklar, opacity 0.6 ↔ 1, 1.2s ease-in-out infinite.
- Tugma loading: 3 nuqta (6px), ketma-ket opacity pulse, 900ms.

## 7. Ekranlar orasida
- Tab almashishi: kontent crossfade 160ms (siljishsiz).
- Ichki ekran (O'yinlar, Pro, Достижения): Telegram BackButton bilan; kontent 12px pastdan + opacity 0→1, 260ms.
- Onboarding qadamlar: keyingi qadam o'ngdan 24px + opacity, 260ms; progress segmenti to'ladi 200ms.

## 8. Mikro animatsiyalar
| Element | Animatsiya |
|---|---|
| Kunlik maqsad nuqtalari | Yangi so'z → nuqta muhr rangiga scale 0.6→1, 200ms, 30ms stagger |
| Achievement ochilishi | Kulrang → rangli crossfade 260ms + stamp-press |
| Liga "Siz" qatori | O'rin o'zgarsa translateY bilan 260ms |
| Seriya kalendari (F4) | Bugungi kun halqasi 1 marta pulse |
| Maskot | Faqat kirishda: 8px pastdan + opacity, 300ms. Doimiy "bounce" yo'q |
| Story karta (F3) | Muhr stamp-press, keyin karta 2° burilgan holda turadi |

## 9. Reduced motion
`prefers-reduced-motion: reduce` — barcha transform animatsiyalar opacity crossfade (120ms) bilan almashadi; silkinish va pulse o'chadi. Haptic qoladi.
