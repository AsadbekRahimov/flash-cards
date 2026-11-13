#!/usr/bin/env python3
"""dup_translations.py <pack dir> - words of one course that share the same ru or uz translation (ambiguous in matching games)."""
import json, glob, sys, collections
by = {'ru': collections.defaultdict(list), 'uz': collections.defaultdict(list)}
for f in sorted(glob.glob(sys.argv[1].rstrip('/') + '/m*-l*.json')):
    for w in json.load(open(f, encoding='utf-8'))['words']:
        for loc in ('ru', 'uz'):
            by[loc][w['translations'][loc].strip().lower()].append(f"{w['word']} ({f.split('/')[-1][:-5]})")
n = 0
for loc in ('ru', 'uz'):
    for t, ws in sorted(by[loc].items()):
        if len(ws) > 1:
            n += 1; print(f"{loc}: '{t}' <- " + ', '.join(ws))
print(f"{n} shared translations")
