#!/usr/bin/env python3
"""check_syllabus.py <syllabus.json> [forbidden.txt ...] - structure and uniqueness of a course syllabus."""
import json, sys, re
syl = json.load(open(sys.argv[1]))
forbidden = {}
for f in sys.argv[2:]:
    for line in open(f, encoding='utf-8'):
        w = line.strip()
        if w: forbidden[w] = '/'.join(f.split('/')[-2:])
errs = []; seen = {}; total = 0
mods = syl.get('modules', [])
if [m.get('number') for m in mods] != list(range(1, 21)): errs.append('modules must be numbered 1..20')
for m in mods:
    for k in ('title', 'title_uz'):
        if not str(m.get(k, '')).strip(): errs.append(f"module {m.get('number')}: empty {k}")
    if re.search(r"[’ʻʼ‘`]|[Ѐ-ӿ]", m.get('title_uz', '')): errs.append(f"module {m.get('number')}: title_uz must be Latin with ASCII apostrophe")
    lessons = m.get('lessons', [])
    if [l.get('number') for l in lessons] != [1, 2, 3, 4, 5]: errs.append(f"module {m.get('number')}: lessons must be numbered 1..5")
    for l in lessons:
        where = f"m{m.get('number'):02d}-l{l.get('number')}"
        for k in ('title', 'title_uz'):
            if not str(l.get(k, '')).strip(): errs.append(f"{where}: empty {k}")
        if re.search(r"[’ʻʼ‘`]|[Ѐ-ӿ]", l.get('title_uz', '')): errs.append(f"{where}: title_uz must be Latin with ASCII apostrophe")
        words = l.get('words', [])
        if len(words) != 10: errs.append(f"{where}: {len(words)} words, must be 10")
        for w in words:
            total += 1
            if not isinstance(w, str) or not re.fullmatch(r"[a-z]+", w): errs.append(f"{where}: '{w}' must be one lowercase word of letters a-z (no spaces, hyphens, apostrophes)"); continue
            if len(w) < 2: errs.append(f"{where}: '{w}' is too short")
            if w in seen: errs.append(f"{where}: '{w}' repeats {seen[w]}")
            seen[w] = where
            if w in forbidden: errs.append(f"{where}: '{w}' is already in {forbidden[w]}")
print(f"{total} words, {len(seen)} distinct, {len(errs)} problems")
for e in errs: print('  -', e)
sys.exit(1 if errs else 0)
