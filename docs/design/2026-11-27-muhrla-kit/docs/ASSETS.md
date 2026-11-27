# ASSETS.md — Rasmlar va ikonlar

## Maskot "Muhrcha" — `07-illustrations/mascot/`
Yog'och muhr qahramon: asal rangli dastasi — bosh, qizil-to'q sariq asosi — tana. Shaffof PNG.
| Fayl | Holat | Qayerda |
|---|---|---|
| muhrcha-hello | salom beradi | O1, O2, bo'sh holatlar |
| muhrcha-thinking | o'ylaydi | Dars savoli, O3, F5 |
| muhrcha-celebrate | sakraydi + muhr izi | Урок пройден, O8 |
| muhrcha-freeze | sharf + qor | Pro, F4 |
| muhrcha-trophy | kubok ko'taradi | Лига |
| muhrcha-reading | daftar o'qiydi | Курс, F6, bo'sh lug'at |

Ishlatish: balandlik 84–220px, nisbatni saqlang, fon qo'shmang.

## Achievement — `07-illustrations/achievements/`
`achv-<name>.png` (rangli) va `achv-<name>-locked.png` (kulrang), 512×512 doira, shaffof.
Nomlar: `first-lesson`, `streak-7`, `words-100`, `perfect`, `battle`, `league`. Ko'rsatish: 88px (grid), 112–150px (hero/story).

## Mavzu rasmlari (120) — `07-illustrations/topics/<Kurs>/<NN>.png`
- 240×240 shaffof PNG, obyekt 208px ichida markazlangan.
- `topics/index.json`: `{course, module, title, file}` — ma'lumotlar bazasidagi modul ID bilan bog'lang.
- Ko'rsatish o'lchamlari: 56 (ro'yxat), 64–76 (kataklar), 88 (karta), 104–112 maksimum.
- Ilova uchun eksport: WebP `q=85`, 2 o'lcham (120 va 240), `loading="lazy"`.
```bash
# misol: cwebp bilan
for f in topics/*/*.png; do cwebp -q 85 -resize 240 240 "$f" -o "${f%.png}.webp"; done
```
- `legacy-drinks.png`, `legacy-food.png` — Сегодня hero'dagi katta variantlar.

## Ikonlar — `08-icons/`
Lucide (ISC litsenziya), stroke 1.75, `stroke="currentColor"`. React'da: `import { Flame } from 'lucide-react'` + `strokeWidth={1.75}`.
Ishlatilganlar: house, book-open, trophy, user (tab bar); volume-2, check, x, arrow-right, chevron-left/right, lock, search, timer, snowflake, bell, globe, crown, award, users, target, rotate-ccw, puzzle va boshqalar.

## Shriftlar
- Unbounded — https://fonts.google.com/specimen/Unbounded (500, 600)
- Onest — https://fonts.google.com/specimen/Onest (400, 500, 600)
Subset: latin + cyrillic. Self-host woff2.

## Litsenziya eslatmasi
Maskot, achievement va mavzu rasmlari Figma AI bilan generatsiya qilingan va fonlari tozalangan.
