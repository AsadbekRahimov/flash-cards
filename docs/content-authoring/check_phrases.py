#!/usr/bin/env python3
"""check_phrases.py [options] <pack dir> [<pack dir> ...] - a phrase pack (docs/35 §3.3 h, §3.4) before the import.

Checks every lesson file (mNN-lN.json) of the pack and its course.json: first what the import refuses (VocabularyImporter,
CoursePackImporter - the same rules, the same limits), then the content rules of PHRASE_BRIEF.md and the plan PHRASE_PLAN.md.
Several packs at once: a phrase may not repeat in any of them. Standard library only.

  --import-only   only the rules of the import (a file that fails one of them is not imported at all)
  --full          the whole course as well: every lesson of the plan is there, the shares of the kinds, the gap limit of the course
  --plan FILE     the plan to check against (default: PHRASE_PLAN.md next to this script); --no-plan skips the plan rules
  --check-plan    check the plan itself (numbering, titles, counts, anchors unique) and nothing else
  --summary       print a summary of the pack(s) as well: phrases, per lesson, kinds, registers, variants, levels, top gap words

Every problem is one line `ERROR <CODE> <file> #<entry> «<phrase>»: <reason>`; the last line is `N phrases checked, K errors`.
Exit code 1 when there is an error, 2 on wrong usage.
"""
import argparse, collections, glob, json, os, re, sys

HERE = os.path.dirname(os.path.abspath(__file__))
DEFAULT_PLAN = os.path.join(HERE, 'PHRASE_PLAN.md')

# --- app/Domain/Content/Support/Phrase.php, VocabularyImporter, CoursePackImporter, config/localization.php -------------------
KINDS = ['formula', 'fixed', 'idiom', 'phrasal_verb', 'proverb']
REGISTERS = ['neutral', 'informal', 'formal', 'slang', 'rude']
VARIANTS = ['both', 'uk', 'us']
CEFR = ['A1', 'A2', 'B1', 'B2', 'C1', 'C2']
FREQUENCIES = [1, 2, 3]
MAX_SYNONYMS = 10
PHRASE_FIELDS = ['kind', 'register', 'variant', 'gap', 'literal', 'cefr', 'frequency', 'synonyms']
PHRASE_TEXT_MAX = 500
LOCALES = ['ru', 'uz']
WORD_MAX = 100
MAX_EXAMPLES = 10
MAX_WORDS_IN_FILE = 500
SLUG_RE = re.compile(r'^[a-z0-9]+(?:-[a-z0-9]+)*$')

# --- the content rules (PHRASE_BRIEF.md) -------------------------------------------------------------------------------------
PHRASE_MAX = 50            # the card: up to 3 lines of the phrase, 2 lines in a choice option (docs/35 §3.3 d)
TRANSLATION_MAX = 60       # ru / uz equivalent, as on the word cards (2 lines in a choice option)
EXAMPLE_MAX_WORDS = 25
EXAMPLE_MAX_CHARS = 200
GAP_LESSON_MAX = 1         # the same key word in one lesson
GAP_COURSE_MAX = 20        # the same key word in the whole course
SLANG_SHARE_MAX = 3        # register 'slang', % of the whole course (PHRASE_PLAN.md §2: at most 30 of 1 000)
LESSON_SIZE = 10           # without a plan; with a plan - the count of the lesson there
WORD_KEYS = {'word', 'part_of_speech', 'translation', 'translations', 'example', 'example_translations', 'examples', 'hooks',
             'confusable_with', 'transcription', 'phrase'}
FILE_KEYS = {'course', 'stage', 'lesson', 'words'}
# A gap the learner could guess without knowing the phrase.
FUNCTION_WORDS = {'a', 'an', 'the', 'i', 'me', 'my', 'you', 'your', 'he', 'him', 'his', 'she', 'her', 'it', 'its', 'we', 'us',
                  'our', 'they', 'them', 'their', 'is', 'am', 'are', 'was', 'were', 'be', 'to', 'of', 'and', 'or'}
# Words a phrase, its example or a synonym never contain (PHRASE_BRIEF.md "Safety"): swearing, alcohol, drugs, gambling, sex, pork.
FORBIDDEN = {'fuck', 'fucking', 'shit', 'bitch', 'bastard', 'crap', 'piss', 'pissed', 'damn', 'hell', 'ass', 'arse', 'sex', 'sexy',
             'beer', 'wine', 'vodka', 'whisky', 'whiskey', 'booze', 'drunk', 'hangover', 'cocktail', 'alcohol', 'drug', 'drugs',
             'cocaine', 'heroin', 'marijuana', 'casino', 'gamble', 'gambling', 'pork', 'bacon'}
# The endings a forbidden word is found under (bitches, fucked, shitty, gambler, whiskies, hellish, drunken, sexual, sexually). The list keeps
# its meaning: `drink` is not in it, so neither is `drinking`.
FORBIDDEN_ENDINGS = ('ually', 'ual', 'ies', 'ing', 'ish', 'es', 'ed', 'er', 'en', 'al', 's', 'd', 'y')

APOSTROPHES = ['’', '‘', 'ʼ', '`', '´', '′']
DASHES = ['‐', '‑', '‒', '–', '—', '−']
CYRILLIC = re.compile(r'[Ѐ-ӿ]')
UZ_BAD = re.compile(r"[’ʻʼ‘`´]|[Ѐ-ӿ]")
LETTER_OR_DIGIT = r'[^\W_]'
WORD_CHAR = r"(?:[^\W_]|['’])"

ERRORS = []


def err(code, where, msg):
    ERRORS.append((code, where, msg))


def lexeme_key(word):
    """LexemeKey::of (app/Domain/Content/Support/LexemeKey.php): the spelling two phrases are the same lexeme by."""
    key = word
    for a in APOSTROPHES:
        key = key.replace(a, "'")
    for d in DASHES:
        key = key.replace(d, '-')
    key = re.sub(r'\s+', ' ', key).strip().lower()
    stripped = key.rstrip('.,!?;: ')
    if stripped:
        key = stripped
    if ' ' in key and key.split(' ', 1)[0] in ('a', 'an', 'the'):
        rest = key.split(' ', 1)[1].lstrip()
        if rest:
            key = rest
    return key[:WORD_MAX] or None


def gap_pattern(gap):
    return re.compile(r'(?<!' + WORD_CHAR + ')' + re.escape(gap) + r'(?!' + WORD_CHAR + ')', re.I)


def occurrences(phrase, gap):
    """Phrase::occurrences: the gap as a whole word, case-insensitive; an apostrophe is part of a word, a hyphen is not."""
    return len(gap_pattern(gap).findall(phrase))


def is_whole_phrase(phrase, gap):
    return re.search(LETTER_OR_DIGIT, gap_pattern(gap).sub('', phrase)) is None


def is_forbidden(token):
    """The token is a forbidden word or a form of one: one ending off (-ies gives back -y), then the word as it is, with the final
    `e` an ending took (gambled -> gamble), or without the consonant it doubled (shitty -> shit). One ending only, and a stem of at
    least two letters: class, pass, assess, glass, hello, shell, wins stay plain words."""
    if token in FORBIDDEN:
        return True
    for ending in FORBIDDEN_ENDINGS:
        stem = token[:-len(ending)]
        if not token.endswith(ending) or len(stem) < 2:
            continue
        if ending == 'ies':
            stems = {stem + 'y'}
        elif ending in ('s', 'd'):
            stems = {stem}
        else:
            stems = {stem, stem + 'e'}
            if stem[-1] == stem[-2] and stem[-1] not in 'aeiou':
                stems.add(stem[:-1])
        if stems & FORBIDDEN:
            return True
    return False


def is_str(v):
    return isinstance(v, str)


def blank(v):
    return not is_str(v) or v.strip() == ''


# --- the example contains the phrase (docs/35 §3.3 h: "the example contains the phrase; forms of the verb are allowed") -------
IRREGULAR = {
    'be': 'am is are was were been being', 'have': 'has had having', 'do': 'does did done doing', 'go': 'goes went gone going',
    'get': 'got gotten getting', 'make': 'made', 'take': 'took taken', 'give': 'gave given', 'come': 'came', 'see': 'saw seen',
    'know': 'knew known', 'break': 'broke broken', 'keep': 'kept', 'put': 'put', 'let': 'let', 'hold': 'held', 'run': 'ran',
    'fall': 'fell fallen', 'bring': 'brought', 'tell': 'told', 'say': 'said', 'feel': 'felt', 'find': 'found', 'lose': 'lost',
    'win': 'won', 'sit': 'sat', 'stand': 'stood', 'catch': 'caught', 'cut': 'cut', 'hit': 'hit', 'set': 'set', 'buy': 'bought',
    'pay': 'paid', 'sell': 'sold', 'spend': 'spent', 'leave': 'left', 'meet': 'met', 'speak': 'spoke spoken', 'think': 'thought',
    'teach': 'taught', 'throw': 'threw thrown', 'blow': 'blew blown', 'beat': 'beaten', 'bite': 'bit bitten', 'eat': 'ate eaten',
    'drink': 'drank', 'drive': 'drove driven', 'ride': 'rode ridden', 'write': 'wrote written', 'fly': 'flew flown',
    'grow': 'grew grown', 'hang': 'hung', 'hide': 'hid hidden', 'lay': 'laid', 'lie': 'lay lain lying', 'lead': 'led',
    'lend': 'lent', 'light': 'lit', 'mean': 'meant', 'read': 'read', 'ring': 'rang rung', 'rise': 'rose risen',
    'shake': 'shook shaken', 'shine': 'shone', 'shoot': 'shot', 'shut': 'shut', 'sing': 'sang sung', 'sink': 'sank sunk',
    'sleep': 'slept', 'slide': 'slid', 'spin': 'spun', 'split': 'split', 'spread': 'spread', 'steal': 'stole stolen',
    'stick': 'stuck', 'strike': 'struck', 'swear': 'swore sworn', 'sweep': 'swept', 'swim': 'swam swum', 'swing': 'swung',
    'tear': 'tore torn', 'wake': 'woke woken', 'wear': 'wore worn', 'bear': 'bore borne', 'begin': 'began begun',
    'bend': 'bent', 'bet': 'bet', 'bleed': 'bled', 'build': 'built', 'burn': 'burnt', 'burst': 'burst', 'choose': 'chose chosen',
    'deal': 'dealt', 'dig': 'dug', 'draw': 'drew drawn', 'dream': 'dreamt', 'feed': 'fed', 'fight': 'fought',
    'forget': 'forgot forgotten', 'forgive': 'forgave forgiven', 'freeze': 'froze frozen', 'hear': 'heard', 'hurt': 'hurt',
    'learn': 'learnt', 'seek': 'sought', 'send': 'sent', 'show': 'shown', 'shrink': 'shrank shrunk', 'smell': 'smelt',
    'spell': 'spelt', 'spill': 'spilt', 'spit': 'spat', 'spoil': 'spoilt', 'understand': 'understood', 'upset': 'upset',
    'cost': 'cost', 'quit': 'quit', 'can': 'could', 'will': 'would', 'shall': 'should', 'may': 'might',
}
GROUPS = [
    {'my', 'your', 'his', 'her', 'its', 'our', 'their', "one's"},
    {'me', 'you', 'him', 'her', 'it', 'us', 'them'},
    {'i', 'you', 'he', 'she', 'it', 'we', 'they'},
    {'myself', 'yourself', 'himself', 'herself', 'itself', 'ourselves', 'yourselves', 'themselves', 'oneself'},
]
PLACEHOLDERS = {'someone', 'somebody', 'something', "someone's", "somebody's", 'sb', 'sth'}
TOKEN_RE = re.compile(r"[a-z0-9]+(?:'[a-z]+)*")


def tokens(text):
    t = text.lower()
    for a in APOSTROPHES:
        t = t.replace(a, "'")
    return TOKEN_RE.findall(t)


def regular_forms(b):
    if len(b) < 3:
        return {b}
    f = {b, b + 's', b + 'es', b + 'ed', b + 'd', b + 'ing'}
    if b.endswith('y'):
        f |= {b[:-1] + 'ies', b[:-1] + 'ied'}
    if b.endswith('e'):
        f |= {b[:-1] + 'ing'}
    if len(b) >= 3 and b[-1] not in 'aeiouwxy' and b[-2] in 'aeiou' and b[-3] not in 'aeiou':
        f |= {b + b[-1] + 'ed', b + b[-1] + 'ing'}
    return f


VARIANTS_OF = {}
for _lemma, _forms in IRREGULAR.items():
    _group = {_lemma, *_forms.split()} | regular_forms(_lemma)
    for _w in _group:
        VARIANTS_OF.setdefault(_w, set()).update(_group)


POSSESSIVES = GROUPS[0]
# Sayings said as they are: no word of them takes a form (Break a leg! - not "Breaks a leg!").
FROZEN_KINDS = {'formula', 'proverb'}


def token_match(p, e):
    """How the word `e` of the text stands for the word `p` of the phrase: 'same', 'form' (a form of the verb or a plural, regular or
    irregular), 'pronoun' (another pronoun of the same group, `one's` -> a possessive), or None."""
    if p == e:
        return 'same'
    if e in regular_forms(p) or e in VARIANTS_OF.get(p, ()):
        return 'form'
    if any(p in g and e in g for g in GROUPS) or (p == "one's" and e.endswith("'s")):
        return 'pronoun'
    return None


def contains_phrase(phrase, text, kind):
    """The phrase stands in the text: word by word; a possessive or an object pronoun may change (make up your mind -> made up my
    mind), `someone` / `something` stand for 1-3 words, the particle of a phrasal verb may move up to 3 words away (figure out ->
    figure this map out), the leading article may go. A form (regular, irregular) is taken by ONE word at most, and only by a word
    that changes in speech: the first word of the phrase (its verb: break the ice -> broke the ice), or the word right after a
    possessive that changed too (tighten your belt -> tighten our belts). The fixed noun of an idiom keeps its form (on the other
    hand, not "hands"); a formula or a proverb takes no form at all (Break a leg!, not "Breaks a leg!")."""
    p, e = tokens(phrase), tokens(text)
    if not p:
        return False
    starts = [p]
    if len(p) > 1 and p[0] in ('a', 'an', 'the'):
        starts.append(p[1:])

    def at(pp, i, j, moved, formed, owner_changed):
        if i == len(pp):
            return True
        if pp[i] in PLACEHOLDERS:
            return any(at(pp, i + 1, j + k, moved, formed, False) for k in (1, 2, 3) if j + k <= len(e))
        how = token_match(pp[i], e[j]) if j < len(e) else None
        if how == 'form':
            first = len(p) - len(pp) + i == 0
            if kind in FROZEN_KINDS or formed or not (first or owner_changed):
                how = None
        if how is not None and at(pp, i + 1, j + 1, moved, formed or how == 'form', how == 'pronoun' and pp[i] in POSSESSIVES):
            return True
        if kind == 'phrasal_verb' and i == 1 and not moved:
            return any(at(pp, i, j + k, True, formed, owner_changed) for k in (1, 2, 3) if j + k < len(e))
        return False

    return any(at(pp, 0, j, False, False, False) for pp in starts for j in range(len(e)))


# --- the plan (PHRASE_PLAN.md tables) ----------------------------------------------------------------------------------------
MODULE_ROW = re.compile(r'^\|\s*M(\d{2})\s*\|([^|]*)\|([^|]*)\|\s*([ABC][12])\s*[–-]\s*([ABC][12])\s*\|')
LESSON_ROW = re.compile(r'^\|\s*m(\d{2})-l(\d)\s*\|([^|]*)\|([^|]*)\|\s*(\d+)\s*\|([^|]*)\|')
SHARE_ROW = re.compile(r'^\|\s*`?(' + '|'.join(KINDS) + r')`?\s*\|[^|]*\|\s*(\d+)\s*\|\s*(\d+)\s*\|')


def read_plan(path):
    plan = {'modules': {}, 'lessons': {}, 'shares': {}, 'path': path}
    with open(path, encoding='utf-8') as fh:
        for line in fh:
            m = MODULE_ROW.match(line)
            if m:
                plan['modules'][int(m.group(1))] = {'title': m.group(2).strip(), 'title_uz': m.group(3).strip(),
                                                    'band': (m.group(4), m.group(5))}
                continue
            m = LESSON_ROW.match(line)
            if m:
                plan['lessons'][(int(m.group(1)), int(m.group(2)))] = {
                    'title': m.group(3).strip(), 'title_uz': m.group(4).strip(), 'count': int(m.group(5)),
                    'anchors': [a.strip() for a in m.group(6).split('·') if a.strip()]}
                continue
            m = SHARE_ROW.match(line)
            if m:
                plan['shares'][m.group(1)] = (int(m.group(2)), int(m.group(3)))
    return plan


def check_plan(plan):
    name = os.path.basename(plan['path'])
    mods, lessons = plan['modules'], plan['lessons']
    if not mods or not lessons:
        err('PLAN', name, 'no module or lesson rows found (| M01 | ... | and | m01-l1 | ... |)')
        return
    if sorted(mods) != list(range(1, len(mods) + 1)):
        err('PLAN', name, f'modules must be numbered 1..{len(mods)} without gaps')
    for n, m in sorted(mods.items()):
        lo, hi = m['band']
        if CEFR.index(lo) > CEFR.index(hi):
            err('PLAN', f'{name} M{n:02d}', f'level band {lo}-{hi} is reversed')
        for key in ('title', 'title_uz'):
            if not m[key]:
                err('PLAN', f'{name} M{n:02d}', f'empty {key}')
        if UZ_BAD.search(m['title_uz']):
            err('PLAN', f'{name} M{n:02d}', "title_uz must be Latin with the ASCII apostrophe (')")
        numbers = sorted(l for (mm, l) in lessons if mm == n)
        if numbers != list(range(1, len(numbers) + 1)) or not numbers:
            err('PLAN', f'{name} M{n:02d}', f'lessons must be numbered 1..N without gaps, found {numbers}')
    seen = {}
    for (mm, ll), l in sorted(lessons.items()):
        where = f'{name} m{mm:02d}-l{ll}'
        if mm not in mods:
            err('PLAN', where, f'module {mm} has no row in the module table')
        for key in ('title', 'title_uz'):
            if not l[key]:
                err('PLAN', where, f'empty {key}')
        if UZ_BAD.search(l['title_uz']):
            err('PLAN', where, "title_uz must be Latin with the ASCII apostrophe (')")
        if not 3 <= len(l['anchors']) <= 5:
            err('PLAN', where, f"{len(l['anchors'])} anchor phrases, 3-5 needed")
        for a in l['anchors']:
            k = lexeme_key(a)
            if len(a) > PHRASE_MAX:
                err('PLAN', where, f'anchor «{a}» is longer than {PHRASE_MAX} characters')
            if k in seen:
                err('PLAN', where, f'anchor «{a}» repeats «{seen[k][0]}» of {seen[k][1]}')
            seen.setdefault(k, (a, where))
    if plan['shares']:
        if sorted(plan['shares']) != sorted(KINDS):
            err('PLAN', name, 'the table of shares needs one row for every kind: ' + ', '.join(KINDS))
        if sum(lo for lo, _ in plan['shares'].values()) > 100 or sum(hi for _, hi in plan['shares'].values()) < 100:
            err('PLAN', name, 'the shares of the kinds cannot add up to 100 %')


# --- one pack ----------------------------------------------------------------------------------------------------------------
class Entry:
    def __init__(self, pack, file, n, data):
        self.pack, self.file, self.n, self.data = pack, file, n, data
        self.word = data.get('word') if is_str(data.get('word')) else '(no word)'
        self.where = f'{pack}/{file} #{n} «{self.word}»'


def refuse_constant(name):
    raise ValueError(f'{name} is not JSON (json_decode of the import refuses it)')


def load_json(path):
    """json.load without what Python takes and JSON has not: NaN, Infinity, -Infinity."""
    with open(path, encoding='utf-8') as fh:
        return json.load(fh, parse_constant=refuse_constant)


def check_manifest(d, pack, import_only):
    path = os.path.join(d, 'course.json')
    where = f'{pack}/course.json'
    if not os.path.isfile(path):
        err('MANIFEST', where, 'course.json not found')
        return None
    try:
        data = load_json(path)
    except Exception as e:
        err('MANIFEST', where, f'invalid JSON ({e})')
        return None
    if not isinstance(data, dict):
        err('MANIFEST', where, 'the root must be an object')
        return None
    slug = data.get('slug')
    if not is_str(slug) or not SLUG_RE.match(slug) or len(slug) > 64:
        err('MANIFEST', where, "'slug' must be a lowercase slug of up to 64 characters")
        slug = None
    title = data.get('title') if isinstance(data.get('title'), dict) else {}
    for loc in LOCALES:
        if blank(title.get(loc)):
            err('MANIFEST', where, f"'title.{loc}' is required")
    if data.get('exam_tag') != 'phrases':
        err('EXAM_TAG', where, "'exam_tag' must be 'phrases': the import refuses a phrase in any other course")
    if not import_only:
        problems = []
        if slug is not None and (not re.match(r'^english-phrases(-[a-z0-9]+)*$', slug) or slug != os.path.basename(d)):
            problems.append(f"slug '{slug}' must start with 'english-phrases' and equal the directory name")
        desc = data.get('description') if isinstance(data.get('description'), dict) else {}
        problems += [f"'description.{loc}' is required" for loc in LOCALES if blank(desc.get(loc))]
        if blank(data.get('level_label')):
            problems.append("'level_label' is required (the levels of the course, e.g. 'A2–C1')")
        if data.get('visibility', 'public') != 'public' or data.get('is_featured', False) is not False:
            problems.append("'visibility' must be 'public' and 'is_featured' false")
        if UZ_BAD.search(str(title.get('uz', '')) + str(desc.get('uz', ''))):
            problems.append("Uzbek must be Latin with the ASCII apostrophe (')")
        for p in problems:
            err('COURSE_META', where, p)
    return slug


def check_lesson_file(path, pack, slug, plan, args, entries):
    name = os.path.basename(path)
    where = f'{pack}/{name}'
    try:
        data = load_json(path)
    except Exception as e:
        err('INVALID_JSON', where, f'not valid JSON ({e})')
        return
    if not isinstance(data, dict):
        err('INVALID_JSON', where, 'the root must be an object')
        return
    if slug is not None and data.get('course') != slug:
        err('COURSE_MISMATCH', where, f"'course' must be '{slug}': the pack import skips the file")
        return
    stage, lesson, words = data.get('stage'), data.get('lesson'), data.get('words')
    bad = []
    for label, block in (('stage', stage), ('lesson', lesson)):
        if not isinstance(block, dict):
            bad.append(f"'{label}' must be an object")
            continue
        num = block.get('number')
        if not isinstance(num, int) or isinstance(num, bool) or num < 1:
            bad.append(f"'{label}.number' must be an integer from 1")
        for key in ('title', 'title_uz'):
            if key in block and not (is_str(block[key]) or (block[key] is None and not (label == 'stage' and key == 'title'))):
                bad.append(f"'{label}.{key}' must be a text")
            elif is_str(block.get(key)) and len(block[key]) > 255:
                bad.append(f"'{label}.{key}' is longer than 255 characters")
    if not isinstance(words, list) or not 1 <= len(words) <= MAX_WORDS_IN_FILE:
        bad.append(f"'words' must be a list of 1-{MAX_WORDS_IN_FILE} entries")
    elif any(not isinstance(w, dict) for w in words):
        bad.append("every entry of 'words' must be an object")
    for b in bad:
        err('STRUCTURE', where, b)
    if bad:
        return
    m_no, l_no = stage['number'], lesson['number']

    if not args.import_only:
        unknown = sorted(set(data) - FILE_KEYS)
        if unknown:
            err('UNKNOWN_KEY', where, 'unknown top-level keys: ' + ', '.join(unknown))
        if name != f'm{m_no:02d}-l{l_no}.json':
            err('FILE_NAME', where, f'the file of module {m_no}, lesson {l_no} must be named m{m_no:02d}-l{l_no}.json')
        if plan is not None:
            pm, pl = plan['modules'].get(m_no), plan['lessons'].get((m_no, l_no))
            if pm is None or pl is None:
                err('PLAN', where, f'module {m_no}, lesson {l_no} is not in the plan')
            else:
                want_s = {'number': m_no, 'title': pm['title'], 'title_uz': pm['title_uz']}
                want_l = {'number': l_no, 'title': pl['title'], 'title_uz': pl['title_uz']}
                if stage != want_s:
                    err('PLAN', where, "'stage' must be exactly " + json.dumps(want_s, ensure_ascii=False))
                if lesson != want_l:
                    err('PLAN', where, "'lesson' must be exactly " + json.dumps(want_l, ensure_ascii=False))
        want = plan['lessons'][(m_no, l_no)]['count'] if plan is not None and (m_no, l_no) in plan['lessons'] else LESSON_SIZE
        if len(words) != want:
            err('LESSON_SIZE', where, f'{len(words)} phrases, the lesson needs {want}')

    seen_exact = {}
    gaps = collections.Counter()
    for i, w in enumerate(words, 1):
        e = Entry(pack, name, i, w)
        e.module, e.lesson_no = m_no, l_no
        exact = w.get('word').strip().lower() if is_str(w.get('word')) else None
        e.import_duplicate = exact is not None and exact in seen_exact
        if e.import_duplicate:
            err('DUPLICATE_WORD', e.where, f'the same phrase as #{seen_exact[exact]} of this file: the import refuses the file')
        elif exact is not None:
            seen_exact[exact] = i
        check_entry(e, plan, args)
        entries.append(e)
        gap = (w.get('phrase') or {}).get('gap') if isinstance(w.get('phrase'), dict) else None
        if not args.import_only and is_str(gap) and gap.strip():
            gaps[gap.strip().lower()] += 1
    if not args.import_only:
        for g, c in sorted(gaps.items()):
            if c > GAP_LESSON_MAX:
                err('GAP_BALANCE', where, f"the key word '{g}' is the gap of {c} phrases of the lesson, at most {GAP_LESSON_MAX}")


def check_import_entry(e):
    """VocabularyImporter::rules(), normalizeWords(), normalizePhrase(), assertCourseKind() for one entry; False = stop here."""
    w, where = e.data, e.where
    word = w.get('word')
    if blank(word):
        err('STRUCTURE', where, "'word' is required")
        return False
    if len(word) > WORD_MAX:
        err('WORD_TOO_LONG', where, f'{len(word)} characters, the import takes at most {WORD_MAX}')
        return False
    field = []
    for key in ('translations', 'example_translations', 'hooks'):
        v = w.get(key)
        if v is not None and not isinstance(v, dict):
            field.append(f"'{key}' must be an object {{\"ru\": ..., \"uz\": ...}}")
        for loc, text in (v.items() if isinstance(v, dict) else []):
            limit = 2000 if key == 'example_translations' else 500
            if text is not None and (not is_str(text) or len(text) > limit):
                field.append(f"'{key}.{loc}' must be a text of up to {limit} characters")
    for key, limit in (('translation', 500), ('example', 2000), ('transcription', 100)):
        v = w.get(key)
        if v is not None and (not is_str(v) or len(v) > limit):
            field.append(f"'{key}' must be a text of up to {limit} characters")
    ex = w.get('examples')
    if ex is not None:
        if not isinstance(ex, list) or len(ex) > MAX_EXAMPLES:
            field.append(f"'examples' must be a list of up to {MAX_EXAMPLES}")
        else:
            for k, x in enumerate(ex, 1):
                if not isinstance(x, dict) or not is_str(x.get('text')) or not x['text'].strip() or len(x['text']) > 2000:
                    field.append(f"'examples' #{k} needs a 'text' of up to 2000 characters")
                elif x.get('translations') is not None and not isinstance(x['translations'], dict):
                    field.append(f"'examples' #{k}: 'translations' must be an object")
    cw = w.get('confusable_with')
    if cw is not None and (not isinstance(cw, list) or len(cw) > 10 or any(not is_str(c) or len(c) > 100 for c in cw)):
        field.append("'confusable_with' must be a list of up to 10 texts")
    if w.get('part_of_speech') is not None and not is_str(w['part_of_speech']):
        field.append("'part_of_speech' must be a text")
    if w.get('phrase') is not None and not isinstance(w['phrase'], dict):
        field.append("'phrase' must be an object")
    for f in field:
        err('FIELD', where, f)
    if field:
        return False

    maps = [('translations', w.get('translations')), ('example_translations', w.get('example_translations')),
            ('hooks', w.get('hooks'))]
    maps += [('examples', x.get('translations')) for x in (w.get('examples') or [])]
    lit = (w.get('phrase') or {}).get('literal')
    if isinstance(lit, dict):
        maps.append(('phrase.literal', lit))
    for key, m in maps:
        for loc in (m or {}):
            if loc not in LOCALES:
                err('LOCALE', where, f"unsupported locale '{loc}' in {key} (supported: {', '.join(LOCALES)})")
    tr = {k: v.strip() for k, v in (w.get('translations') or {}).items() if is_str(v) and v.strip()}
    legacy = w['translation'].strip() if is_str(w.get('translation')) else ''
    if legacy and tr.get('ru') and legacy != tr['ru']:
        err('TRANSLATION_CONFLICT', where, "'translation' and 'translations.ru' differ")
    if not legacy and not tr.get('ru'):
        err('RU_REQUIRED', where, "ru translation is required ('translations.ru')")
    for key in ('example_translations', 'hooks'):
        for loc, text in (w.get(key) or {}).items():
            if loc in LOCALES and is_str(text) and text.strip() and not tr.get(loc):
                err('ORPHAN', where, f"{key}.{loc} has no translations.{loc}")

    pos = w.get('part_of_speech')
    block = w.get('phrase')
    if pos != 'phrase':
        err('NOT_PHRASE', where, f"part_of_speech '{pos}': every entry of a phrase course must be 'phrase'"
            + (" (a 'phrase' block needs part_of_speech 'phrase')" if block is not None else ''))
        return False
    if block is None:
        err('PHRASE_MISSING', where, "part_of_speech 'phrase' needs the 'phrase' block")
        return False
    for key in block:
        if key not in PHRASE_FIELDS:
            err('PHRASE_UNKNOWN_FIELD', where, f"unknown field 'phrase.{key}' (allowed: {', '.join(PHRASE_FIELDS)})")
            return False
    for key, allowed, code in (('kind', KINDS, 'PHRASE_KIND'), ('register', REGISTERS, 'PHRASE_REGISTER'),
                               ('variant', VARIANTS, 'PHRASE_VARIANT')):
        v = block.get(key, 'both' if key == 'variant' else None)
        if v is None and key == 'variant':
            v = 'both'
        if not is_str(v) or v not in allowed:
            err(code, where, f"'phrase.{key}' {json.dumps(v, ensure_ascii=False)} must be one of: {', '.join(allowed)}")
            return False
    gap = block['gap'].strip() if is_str(block.get('gap')) else ''
    if gap == '':
        err('GAP_EMPTY', where, "'phrase.gap' is required: the key word of the gap")
        return False
    if not re.fullmatch(r'[A-Za-z]+', gap):
        err('GAP_NOT_WORD', where, f"'phrase.gap' '{gap}' must be one word of Latin letters (no apostrophe, space or hyphen)")
        return False
    n = occurrences(word, gap)
    if n == 0:
        err('GAP_MISSING', where, f"'phrase.gap' '{gap}' is not a word of the phrase")
        return False
    if n > 1:
        err('GAP_REPEATED', where, f"'phrase.gap' '{gap}' stands in the phrase {n} times: it must be once")
        return False
    if is_whole_phrase(word, gap):
        err('GAP_WHOLE', where, f"'phrase.gap' '{gap}' is the whole phrase")
        return False
    if 'literal' in block and block['literal'] is not None:
        if not isinstance(block['literal'], dict):
            err('LITERAL_INVALID', where, "'phrase.literal' must be an object {\"ru\": ..., \"uz\": ...}")
            return False
        for loc, text in block['literal'].items():
            if loc in LOCALES and text is not None and (not is_str(text) or len(text) > PHRASE_TEXT_MAX):
                err('LITERAL_INVALID', where, f"'phrase.literal.{loc}' must be a text of up to {PHRASE_TEXT_MAX} characters")
                return False
    cefr = block.get('cefr')
    if cefr is not None and cefr not in CEFR:
        err('CEFR_INVALID', where, f"'phrase.cefr' {json.dumps(cefr)} must be one of: {', '.join(CEFR)}")
        return False
    freq = block.get('frequency')
    if freq is not None and (not isinstance(freq, int) or isinstance(freq, bool) or freq not in FREQUENCIES):
        err('FREQUENCY_INVALID', where, f"'phrase.frequency' {json.dumps(freq)} must be 1, 2 or 3 (a number)")
        return False
    syn = block.get('synonyms')
    if syn is not None:
        if not isinstance(syn, list) or len(syn) > MAX_SYNONYMS or any(blank(s) or len(s) > WORD_MAX for s in syn):
            err('SYNONYMS_INVALID', where, f"'phrase.synonyms' must be a list of up to {MAX_SYNONYMS} texts of up to {WORD_MAX} characters")
            return False
    return True


def plain(text):
    """For comparing texts: lower case, ASCII apostrophe, no punctuation, single spaces."""
    t = text.lower()
    for a in APOSTROPHES:
        t = t.replace(a, "'")
    return ' '.join(re.findall(r"[^\W_]+(?:'[^\W_]+)*", t))


def check_quality_entry(e, plan):
    """The content rules of PHRASE_BRIEF.md (docs/35 §3.3 h) for one entry that passed the import rules."""
    w, where, word = e.data, e.where, e.data['word']
    block = w['phrase']
    unknown = sorted(set(w) - WORD_KEYS)
    if unknown:
        err('UNKNOWN_KEY', where, 'unknown keys: ' + ', '.join(unknown) + ' (the import ignores them silently)')
    if len(word) > PHRASE_MAX:
        err('PHRASE_LENGTH', where, f'{len(word)} characters: a phrase of the card is at most {PHRASE_MAX}')

    texts = [('word', word)]
    tr = w.get('translations') or {}
    for loc in LOCALES:
        if blank(tr.get(loc)):
            err('TRANSLATION_MISSING', where, f'translations.{loc} is required')
    for loc in LOCALES:
        t = tr.get(loc)
        if blank(t):
            continue
        texts.append((f'translations.{loc}', t))
        if plain(t) == plain(word):
            err('TRANSLATION_SAME', where, f'translations.{loc} repeats the English phrase: give the {loc} equivalent')
        if len(t) > TRANSLATION_MAX:
            err('TRANSLATION_LENGTH', where, f'translations.{loc} has {len(t)} characters, at most {TRANSLATION_MAX}: the equivalent, not an explanation')
        if t.rstrip().endswith('.'):
            err('TEXT_FORMAT', where, f'translations.{loc} ends with a period')

    ex = w.get('example')
    examples = [x['text'] for x in (w.get('examples') or [])]
    if blank(ex):
        err('EXAMPLE_MISSING', where, "'example' is required: a sentence or a two-line dialogue with the phrase")
    else:
        texts.append(('example', ex))
        et = w.get('example_translations') or {}
        for loc in LOCALES:
            if blank(et.get(loc)):
                err('EXAMPLE_TRANSLATION_MISSING', where, f'example_translations.{loc} is required')
            else:
                texts.append((f'example_translations.{loc}', et[loc]))
    for x in w.get('examples') or []:
        for loc in LOCALES:
            if blank((x.get('translations') or {}).get(loc)):
                err('EXAMPLE_TRANSLATION_MISSING', where, f"examples «{x['text'][:30]}…»: translations.{loc} is required")
            else:
                texts.append((f'examples.translations.{loc}', x['translations'][loc]))
    for text in ([ex] if not blank(ex) else []) + examples:
        if not contains_phrase(word, text, block['kind']):
            err('EXAMPLE_NO_PHRASE', where, f'the example «{text}» does not contain the phrase (or a form of its verb)')
        fmt = []
        if re.search(r'(^|[\s—–-])[A-Z]\s?:', text):
            fmt.append('speaker labels like "A:" (the voice reads them out): write two lines with " — " between them')
        if re.search(r'\s-{1,2}\s|\s–\s', text):
            fmt.append('a hyphen or an en dash between the lines: use the em dash " — "')
        if CYRILLIC.search(text):
            fmt.append('Cyrillic letters in the English example')
        if not re.match(r'^["“]?[A-Z0-9]', text) or not re.search(r'[.!?…]["”]?$', text):
            fmt.append('it must start with a capital letter and end with . ! ? or …')
        if len(tokens(text)) > EXAMPLE_MAX_WORDS or len(text) > EXAMPLE_MAX_CHARS:
            fmt.append(f'longer than {EXAMPLE_MAX_WORDS} words or {EXAMPLE_MAX_CHARS} characters')
        for f in fmt:
            err('EXAMPLE_FORMAT', where, f'example «{text}»: {f}')

    hooks = w.get('hooks') or {}
    for loc in LOCALES:
        if blank(hooks.get(loc)):
            err('HOOK_MISSING', where, f'hooks.{loc} is required: when and to whom the phrase is said')
        else:
            texts.append((f'hooks.{loc}', hooks[loc]))

    literal = block.get('literal') or {}
    for loc in LOCALES:
        lt = literal.get(loc)
        if blank(lt):
            continue
        texts.append((f'phrase.literal.{loc}', lt))
        if not blank(tr.get(loc)) and plain(lt) == plain(tr[loc]):
            err('LITERAL_SAME', where, f'phrase.literal.{loc} repeats translations.{loc}: leave literal out when the word-for-word meaning is the equivalent')
        if plain(lt) == plain(word):
            err('LITERAL_SAME', where, f'phrase.literal.{loc} repeats the English phrase')
    if blank(literal.get('ru')) != blank(literal.get('uz')):
        err('LITERAL_INCOMPLETE', where, 'phrase.literal needs both ru and uz, or neither')

    for key, t in texts:
        if t != t.strip() or '  ' in t:
            err('TEXT_FORMAT', where, f'{key} has outer or double spaces')
        if key.endswith('.uz') and UZ_BAD.search(t):
            err('UZ_SCRIPT', where, f"{key}: Uzbek must be Latin with the ASCII apostrophe (o', g', ma'no), no Cyrillic, no ’ ʻ ‘ `")
        if key.endswith('.ru') and not CYRILLIC.search(t):
            err('RU_SCRIPT', where, f'{key}: Russian text must be Cyrillic')

    gap = block['gap'].strip().lower()
    if gap in FUNCTION_WORDS:
        err('GAP_FUNCTION_WORD', where, f"the gap '{gap}' is a function word anyone can guess: take the key word of the phrase")
    if block.get('register') == 'rude':
        err('REGISTER_RUDE', where, "register 'rude' is not taken into the course")
    english = ' '.join([word, ex if is_str(ex) else ''] + examples + list(block.get('synonyms') or []))
    found = sorted({t for t in tokens(english) if is_forbidden(t)})
    if found:
        err('FORBIDDEN_WORD', where, 'words the course never uses: ' + ', '.join(found))

    cefr = block.get('cefr')
    if cefr is None:
        err('LEVEL', where, "'phrase.cefr' is required in the course")
    elif plan is not None and e.module in plan['modules']:
        lo, hi = plan['modules'][e.module]['band']
        if not CEFR.index(lo) - 1 <= CEFR.index(cefr) <= CEFR.index(hi):
            err('LEVEL', where, f'cefr {cefr} does not fit module {e.module} ({lo}–{hi}; one level below is allowed)')
    if block.get('frequency') is None:
        err('FREQUENCY_MISSING', where, "'phrase.frequency' is required in the course (1, 2 or 3)")


def check_entry(e, plan, args):
    before = len(ERRORS)
    e.ok = check_import_entry(e) and len(ERRORS) == before  # the content rules only for an entry the import takes
    if e.ok and not args.import_only:
        check_quality_entry(e, plan)


def check_across(entries, plan, args, files_seen):
    """Rules over the whole set: a phrase repeats nowhere (LexemeKey), the gap limit of the course, --full."""
    if args.import_only:
        return
    by_key = {}
    for e in entries:
        k = lexeme_key(e.word) if e.word != '(no word)' else None
        if k is None:
            continue
        if k in by_key:
            first = by_key[k]
            if not (e.import_duplicate and first.pack == e.pack and first.file == e.file):
                err('DUPLICATE_PHRASE', e.where, f'the same phrase as {first.where} (one lexeme: case, final punctuation, '
                    'apostrophes and a leading article do not count)')
        else:
            by_key[k] = e
    gaps = collections.Counter(e.data['phrase']['gap'].strip().lower() for e in entries if e.ok)
    for g, c in sorted(gaps.items()):
        if c > GAP_COURSE_MAX:
            err('GAP_BALANCE', 'course', f"the key word '{g}' is the gap of {c} phrases, at most {GAP_COURSE_MAX} in the course")
    if not args.full:
        return
    if plan is not None:
        missing = [f'm{m:02d}-l{l}' for (m, l) in sorted(plan['lessons']) if (m, l) not in files_seen]
        if missing:
            err('COURSE_INCOMPLETE', 'course', f'{len(missing)} lessons of the plan have no file: ' + ', '.join(missing[:10])
                + (' …' if len(missing) > 10 else ''))
        total = sum(l['count'] for l in plan['lessons'].values())
        if len(entries) != total:
            err('COURSE_INCOMPLETE', 'course', f'{len(entries)} phrases, the plan has {total}')
        ok = [e for e in entries if e.ok]
        slang = sum(1 for e in ok if e.data['phrase']['register'] == 'slang')
        if ok and 100 * slang / len(ok) > SLANG_SHARE_MAX:
            err('REGISTER_BALANCE', 'course', f"{slang} phrases of register 'slang' ({100 * slang / len(ok):.0f} %), at most {SLANG_SHARE_MAX} % of the course")
        kinds = collections.Counter(e.data['phrase']['kind'] for e in ok)
        for kind, (lo, hi) in sorted(plan['shares'].items()):
            share = 100 * kinds[kind] / len(ok) if ok else 0
            if not lo <= share <= hi:
                err('KIND_BALANCE', 'course', f'{kind}: {share:.0f} % of the phrases, the plan wants {lo}-{hi} %')


def summary(entries):
    ok = [e for e in entries if e.ok]
    print(f'Summary: {len(entries)} phrases, {len(ok)} with a valid phrase block')
    per = collections.Counter(f'{e.pack}/{e.file}' for e in entries)
    print('  per lesson: ' + ', '.join(f'{f.split("/")[-1][:-5]} {c}' for f, c in sorted(per.items())))
    for field in ('kind', 'register', 'variant', 'cefr', 'frequency'):
        c = collections.Counter(str(e.data['phrase'].get(field, 'both' if field == 'variant' else '-')) for e in ok)
        print(f'  {field}: ' + ', '.join(f'{k} {v} ({100 * v / max(len(ok), 1):.0f} %)' for k, v in sorted(c.items())))
    gaps = collections.Counter(e.data['phrase']['gap'].strip().lower() for e in ok)
    print('  top gap words: ' + ', '.join(f'{g} {c}' for g, c in gaps.most_common(15)))


def main(argv):
    ap = argparse.ArgumentParser(description=__doc__.split('\n')[0])
    ap.add_argument('packs', nargs='*')
    ap.add_argument('--import-only', action='store_true')
    ap.add_argument('--full', action='store_true')
    ap.add_argument('--plan', default=DEFAULT_PLAN)
    ap.add_argument('--no-plan', action='store_true')
    ap.add_argument('--check-plan', action='store_true')
    ap.add_argument('--summary', action='store_true')
    args = ap.parse_args(argv)
    plan = None if args.no_plan or args.import_only else read_plan(args.plan)
    if args.check_plan:
        plan = read_plan(args.plan)
        check_plan(plan)
        for code, where, msg in ERRORS:
            print(f'ERROR {code} {where}: {msg}')
        total = sum(l['count'] for l in plan['lessons'].values())
        print(f"plan: {len(plan['modules'])} modules, {len(plan['lessons'])} lessons, {total} phrases, {len(ERRORS)} errors")
        return 1 if ERRORS else 0
    if not args.packs:
        ap.print_usage(sys.stderr)
        return 2

    entries, files_seen = [], set()
    for d in args.packs:
        d = d.rstrip('/')
        if not os.path.isdir(d):
            print(f'not a directory: {d}', file=sys.stderr)
            return 2
        pack = os.path.basename(d)
        slug = check_manifest(d, pack, args.import_only)
        files = sorted(glob.glob(os.path.join(d, 'm*-l*.json')), key=lambda p: [int(x) if x.isdigit() else x for x in re.split(r'(\d+)', p)])
        if not files:
            err('STRUCTURE', pack, 'no lesson files (m*-l*.json)')
        for f in files:
            m = re.fullmatch(r'm(\d+)-l(\d+)\.json', os.path.basename(f))
            if m:
                files_seen.add((int(m.group(1)), int(m.group(2))))
            check_lesson_file(f, pack, slug, plan, args, entries)
    check_across(entries, plan, args, files_seen)

    if args.summary:
        summary(entries)
    for code, where, msg in ERRORS:
        print(f'ERROR {code} {where}: {msg}')
    print(f'{len(entries)} phrases checked, {len(ERRORS)} errors')
    return 1 if ERRORS else 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
