# NEW-FEATURES.md

Auditdan chiqqan yangi funksiyalar (`03-new-features/`). Hammasi mavjud komponent va tokenlarda qurilgan.

## F1 · Smart takrorlash (spaced repetition)
- Kirish: Сегодня → "Повторение", push-eslatma.
- Karta: mavzu rasmi 88, so'z (Word/XL), transkripsiya + audio, ajratgich, tarjima + misol (bosganda ochiladi).
- Pastda 2 teng tugma: Secondary "Повторить" | Primary "Знаю".
- Xotira kuchi 0–5 (5 ta muhr nuqta). Algoritm (SM-2 soddalashtirilgan): Знаю → kuch +1, interval = [1,2,4,7,14,30] kun; Повторить → kuch −1 (min 0), shu sessiyada qayta.
- Progress segmentlari: sessiyadagi kartalar soni (12 gacha).

## F2 · Мой словарь
- Kirish: Курс → "Мой словарь" (tab Курс faol).
- Qidiruv (en/ru), filtr chiplari: Все / Слабые (kuch ≤ 2) / Сильные (kuch ≥ 4).
- Qator: so'z + tarjima, 5 nuqta kuch (≤2 — `status/error`, aks holda `accent/muhr`), audio.
- Bo'sh holat: maskot `reading`.

## F3 · Stories'ga ulashish va баттлга taklif
- Achievement yoki streak ekranidan. 9:16 karta (siyoh fon, muhr 150, matn, @bot).
- "Поделиться в сторис" → `shareToStory`; "Позвать друга на баттл" → `switchInlineQuery`.
- Referal: yangi foydalanuvchi uchun +100 liga ball.

## F4 · Серия спасена
- Kun o'tkazib yuborilgan va заморозка ishlatilgandan keyingi birinchi kirishda bir marta.
- Maskot `freeze`, haftalik kalendar (done — muhr, freeze — qor, bugun — halqa, kelasi — punktir), Stamp 12 ДНЕЙ, "Осталось заморозок: N".
- Variant (zamorozka yo'q): "Серия прервалась" + Pro taklifi.

## F5 · Нет сети
- Maskot `thinking`, "Нет соединения", saqlangan progress chip, "Повторить" + "Повторять слова офлайн" (keshlangan F1).

## F6 · Язык интерфейса
- Профиль → Язык. O'zbekcha / Русский, misol bilan ("tea — choy" / "tea — чай").
- Tanlov tarjimalar va tushuntirishlarga ham ta'sir qiladi.

## Keyingi bosqich g'oyalari (chizilmagan)
- "Yozib ko'r" va "eshit va tanla" savol turlari darsga.
- Liga zinapoyasi (bronza → olmos) bottom sheet.
- Pro uchun 3 kunlik bepul sinov.
