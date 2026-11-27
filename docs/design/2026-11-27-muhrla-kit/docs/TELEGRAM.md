# TELEGRAM.md — Telegram Mini App integratsiyasi

Manba: https://core.telegram.org/bots/webapps

## Ishga tushirish
```js
const tg = window.Telegram.WebApp;
tg.ready();
tg.expand();
tg.disableVerticalSwipes();           // pastga surish ilovani yopmasin (dars paytida muhim)
applyTheme(tg.colorScheme);           // 'light' | 'dark' → document.documentElement.dataset.theme
tg.onEvent('themeChanged', () => applyTheme(tg.colorScheme));

function applyTheme(scheme) {
  document.documentElement.dataset.theme = scheme;
  const bg = getComputedStyle(document.documentElement).getPropertyValue('--bg-canvas').trim();
  tg.setHeaderColor(bg);
  tg.setBackgroundColor(bg);
  tg.setBottomBarColor(bg);
}
```

## Safe area
```css
.app        { min-height: var(--tg-viewport-stable-height, 100dvh); }
.content    { padding: calc(var(--tg-content-safe-area-inset-top, 0px) + 8px) 20px 24px; }
.tabbar     { padding-bottom: max(env(safe-area-inset-bottom, 0px), var(--tg-safe-area-inset-bottom, 0px)); }
.bottom-cta { padding: 12px 20px max(env(safe-area-inset-bottom, 0px), var(--tg-safe-area-inset-bottom, 0px)); }
```
Dizayndagi 103px yuqori zona — Telegram chrome (status + panel). Uni o'zingiz chizmang.

## Navigatsiya
- Ichki ekranlar (O'yinlar, Pro, Достижения, Все курсы, Мой словарь, Поделиться, Язык, onboarding 2–8): `tg.BackButton.show()` va `onClick`.
- Kontentda o'z "Назад" tugmangizni chizmang.
- Dars davomida: `tg.enableClosingConfirmation()`, dars tugagach `disableClosingConfirmation()`.

## Haptic
| Hodisa | Chaqiruv |
|---|---|
| Variant tanlandi | `tg.HapticFeedback.selectionChanged()` |
| To'g'ri / xato | `notificationOccurred('success' \| 'error')` |
| Muhr bosildi (dars tugadi, streak, achievement) | `impactOccurred('medium')` |
| Tugma bosildi (asosiy) | `impactOccurred('light')` — ixtiyoriy |

## Onboarding uchun ma'lumot
- Ism: `tg.initDataUnsafe.user.first_name` bilan oldindan to'ldiring (O2), lekin backend'da `initData`ni tekshiring.
- Til: `user.language_code` → standart interfeys tili (uz → O'zbekcha, ru → Русский).
- Eslatmalar (O7): `tg.requestWriteAccess(granted => …)` — bot xabar yubora olishi uchun.

## To'lov (Pro)
- Telegram Stars: backend `createInvoiceLink` → frontend `tg.openInvoice(link, status => …)`.
- Narxlar backend'dan keladi (150 / 390 Stars — dizayndagi misol).

## Ulashish (F3)
- Story: `tg.shareToStory(mediaUrl, { text, widget_link: { url, name } })` — 9:16 rasm serverda generatsiya qilinadi (`03-new-features/F3-share-stories.png` dagi karta asosida).
- Do'stni баттлга: `tg.switchInlineQuery('battle', ['users'])` yoki deep link `t.me/<bot>?startapp=battle_<id>`.

## Offline (F5)
- `navigator.onLine` + `online/offline` hodisalari; dars progressini `localStorage`/IndexedDB'da saqlang.
- Tarmoq qaytganda avtomatik sinxronlash, foydalanuvchiga "Повторить" tugmasi.
