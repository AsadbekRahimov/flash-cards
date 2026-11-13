#!/usr/bin/env python3
"""check_pack.py <pack dir> <syllabus.json> [module numbers...] - lesson files against the syllabus and the pack rules."""
import json, sys, re, os, glob
d, syl = sys.argv[1].rstrip('/'), json.load(open(sys.argv[2]))
only = {int(x) for x in sys.argv[3:]}
POS = {'noun', 'verb', 'adjective', 'adverb', 'pronoun', 'preposition', 'conjunction', 'interjection'}
slug = syl['course']; errs = []; n = 0
def forms(b):
    f = {b, b+'s', b+'es', b+'ed', b+'d', b+'ing'}
    if b.endswith('y'): f |= {b[:-1]+'ies', b[:-1]+'ied'}
    if b.endswith('e'): f |= {b[:-1]+'ing'}
    return f
for m in syl['modules']:
    if only and m['number'] not in only: continue
    for l in m['lessons']:
        name = f"m{m['number']:02d}-l{l['number']}.json"; path = f"{d}/{name}"
        if not os.path.exists(path): errs.append(f"{name}: file is missing"); continue
        try: data = json.load(open(path, encoding='utf-8'))
        except Exception as e: errs.append(f"{name}: not valid JSON ({e})"); continue
        if data.get('course') != slug: errs.append(f"{name}: course must be '{slug}'")
        st, le = data.get('stage', {}), data.get('lesson', {})
        if st != {'number': m['number'], 'title': m['title'], 'title_uz': m['title_uz']}: errs.append(f"{name}: 'stage' must be exactly {json.dumps({'number': m['number'], 'title': m['title'], 'title_uz': m['title_uz']}, ensure_ascii=False)}")
        if le != {'number': l['number'], 'title': l['title'], 'title_uz': l['title_uz']}: errs.append(f"{name}: 'lesson' must be exactly {json.dumps({'number': l['number'], 'title': l['title'], 'title_uz': l['title_uz']}, ensure_ascii=False)}")
        words = data.get('words', [])
        if [w.get('word') for w in words] != l['words']: errs.append(f"{name}: words must be exactly, in this order: {l['words']}")
        for w in words:
            n += 1; where = f"{name} / {w.get('word')}"
            if set(w.keys()) != {'word', 'part_of_speech', 'translation', 'translations', 'example', 'example_translations'}: errs.append(f"{where}: keys must be word, part_of_speech, translation, translations, example, example_translations"); continue
            tr, ex, et = w['translations'], w['example'], w['example_translations']
            if w['part_of_speech'] not in POS: errs.append(f"{where}: part_of_speech '{w['part_of_speech']}' is not allowed")
            if set(tr.keys()) != {'ru', 'uz'} or set(et.keys()) != {'ru', 'uz'}: errs.append(f"{where}: translations and example_translations need exactly ru and uz"); continue
            if w['translation'] != tr['ru']: errs.append(f"{where}: 'translation' must equal translations.ru")
            for k, v in (('translations.ru', tr['ru']), ('translations.uz', tr['uz']), ('example', ex), ('example_translations.ru', et['ru']), ('example_translations.uz', et['uz'])):
                if not isinstance(v, str) or not v.strip() or v != v.strip(): errs.append(f"{where}: {k} is empty or has outer spaces")
            if re.search(r"[’ʻʼ‘`]|[Ѐ-ӿ]", tr['uz'] + et['uz']): errs.append(f"{where}: Uzbek must be Latin with the ASCII apostrophe (')")
            if not re.search(r"[Ѐ-ӿ]", tr['ru']) or not re.search(r"[Ѐ-ӿ]", et['ru']): errs.append(f"{where}: Russian text must be Cyrillic")
            if re.search(r"[Ѐ-ӿ]", ex): errs.append(f"{where}: the example must be English")
            wc = len(re.findall(r"[A-Za-z'-]+", ex))
            if not 5 <= wc <= 10: errs.append(f"{where}: the example has {wc} words, must be 5-10")
            if not re.search(r"(?<![A-Za-z0-9])(?:" + "|".join(sorted(map(re.escape, forms(w['word'])), key=len, reverse=True)) + r")(?![A-Za-z0-9])", ex, re.I): errs.append(f"{where}: the example must contain the word itself or a regular form (+s, +es, +ed, +d, +ing, y->ies/ied, e->ing)")
            if len(tr['ru']) > 60 or len(tr['uz']) > 60: errs.append(f"{where}: a translation is longer than 60 characters - give the main meaning, not a definition")
            if ex[0] != ex[0].upper() or ex[-1] not in '.!?': errs.append(f"{where}: the example must be a sentence (capital letter, final punctuation)")
print(f"{n} words checked, {len(errs)} problems")
for e in errs: print('  -', e)
sys.exit(1 if errs else 0)
