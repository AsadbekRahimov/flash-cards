"""Resolve git conflict hunks by keeping both sides (ours, blank line, theirs). For append-only docs."""
import sys
path = sys.argv[1]
out, state, ours, theirs = [], None, [], []
for line in open(path, encoding='utf-8').read().split('\n'):
    if line.startswith('<<<<<<< '):
        state, ours, theirs = 'ours', [], []
    elif line == '=======' and state == 'ours':
        state = 'theirs'
    elif line.startswith('>>>>>>> ') and state == 'theirs':
        while ours and ours[-1] == '':
            ours.pop()
        while theirs and theirs[0] == '':
            theirs.pop(0)
        out.extend(ours + [''] + theirs)
        state = None
    elif state == 'ours':
        ours.append(line)
    elif state == 'theirs':
        theirs.append(line)
    else:
        out.append(line)
open(path, 'w', encoding='utf-8').write('\n'.join(out))
