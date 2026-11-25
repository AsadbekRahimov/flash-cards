#!/usr/bin/env python3
"""Self-test of check_phrases.py: python3 docs/content-authoring/test_check_phrases.py (from the root of the repository, standard library only).

The good pack (tests/fixtures/phrases/check/good/english-phrases-good) passes every rule; every file of tests/fixtures/phrases/check/bad
breaks one rule - its name is the code the checker must report: `<CODE>.json` replaces the lesson m01-l1.json of a copy of the good
pack, `<CODE>.course.json` its course.json, `<CODE>.plan.md` is a plan for --check-plan. The checker must report that code, and no
other code except the few a broken file cannot avoid (EXTRA below).
"""
import os, re, shutil, subprocess, sys, tempfile, unittest

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(os.path.dirname(HERE))
SCRIPT = os.path.join(HERE, 'check_phrases.py')
FIXTURES = os.path.join(ROOT, 'tests', 'fixtures', 'phrases')
GOOD = os.path.join(FIXTURES, 'check', 'good', 'english-phrases-good')
BAD = os.path.join(FIXTURES, 'check', 'bad')
TEST_PLAN = os.path.join(FIXTURES, 'check', 'plan-two-lessons.md')
IMPORT_FIXTURE = os.path.join(FIXTURES, 'english-phrases-test')

# Codes a bad file brings along with its own (the same fault seen by a second rule).
EXTRA = {
    'RU_REQUIRED': {'ORPHAN'},
    'TRANSLATION_MISSING': {'EXAMPLE_TRANSLATION_MISSING', 'HOOK_MISSING'},
}
# Where a bad file goes in the copy of the good pack, and the options of the run.
TARGET = {'FILE_NAME': 'm1-l1.json'}
OPTIONS = {'REGISTER_BALANCE': ['--full', '--plan', TEST_PLAN]}

LINE = re.compile(r'^ERROR (\S+) ', re.M)


def run(*args):
    p = subprocess.run([sys.executable, SCRIPT, *args], capture_output=True, text=True, cwd=ROOT)
    return p.returncode, p.stdout + p.stderr, set(LINE.findall(p.stdout))


class CheckPhrasesTest(unittest.TestCase):
    def test_the_good_pack_passes_every_rule(self):
        code, out, codes = run(GOOD)
        self.assertEqual((code, codes), (0, set()), out)
        self.assertIn('20 phrases checked, 0 errors', out)

    def test_the_good_pack_is_a_whole_course_of_its_plan(self):
        code, out, _ = run('--full', '--summary', '--plan', TEST_PLAN, GOOD)
        self.assertEqual(code, 0, out)
        self.assertIn('Summary: 20 phrases', out)
        self.assertIn('kind: fixed 1 (5 %), formula 19 (95 %)', out)

    def test_the_import_fixture_passes_the_import_rules_and_fails_the_content_rules(self):
        code, out, codes = run('--import-only', IMPORT_FIXTURE)
        self.assertEqual((code, codes), (0, set()), out)
        code, out, codes = run('--no-plan', IMPORT_FIXTURE)
        self.assertEqual(code, 1)
        self.assertTrue({'LESSON_SIZE', 'HOOK_MISSING', 'LITERAL_SAME'} <= codes, out)

    def test_the_real_plan_is_valid(self):
        code, out, codes = run('--check-plan')
        self.assertEqual((code, codes), (0, set()), out)
        self.assertIn('plan: 20 modules, 100 lessons, 1000 phrases, 0 errors', out)

    def test_a_part_of_the_course_is_not_the_whole_course(self):
        code, out, codes = run('--full', GOOD)
        self.assertEqual(code, 1)
        self.assertEqual(codes, {'COURSE_INCOMPLETE', 'KIND_BALANCE'}, out)

    def test_a_phrase_repeats_in_no_other_pack(self):
        with tempfile.TemporaryDirectory() as tmp:
            other = os.path.join(tmp, 'english-phrases-other')
            shutil.copytree(GOOD, other)
            for name in ('course.json', 'm01-l1.json', 'm01-l2.json'):
                path = os.path.join(other, name)
                with open(path, encoding='utf-8') as fh:
                    text = fh.read().replace('english-phrases-good', 'english-phrases-other')
                with open(path, 'w', encoding='utf-8') as fh:
                    fh.write(text)
            code, out, codes = run(GOOD, other)
        self.assertEqual((code, codes), (1, {'DUPLICATE_PHRASE'}), out)
        self.assertEqual(out.count('ERROR DUPLICATE_PHRASE'), 20, out)

    def test_every_bad_file_is_caught_by_its_own_rule(self):
        files = sorted(os.listdir(BAD))
        self.assertGreaterEqual(len(files), 50)
        for name in files:
            rule = name.split('.')[0]
            with self.subTest(rule=rule):
                path = os.path.join(BAD, name)
                if name.endswith('.plan.md'):
                    code, out, codes = run('--check-plan', '--plan', path)
                else:
                    with tempfile.TemporaryDirectory() as tmp:
                        pack = os.path.join(tmp, 'english-phrases-good')
                        shutil.copytree(GOOD, pack)
                        if name.endswith('.course.json'):
                            target = 'course.json'
                        else:
                            os.remove(os.path.join(pack, 'm01-l1.json'))
                            target = TARGET.get(rule, 'm01-l1.json')
                        shutil.copy(path, os.path.join(pack, target))
                        code, out, codes = run(*OPTIONS.get(rule, []), pack)
                self.assertEqual(code, 1, out)
                self.assertIn(rule, codes, out)
                self.assertEqual(codes - {rule} - EXTRA.get(rule, set()), set(), out)
                self.assertRegex(out, r'ERROR ' + rule + r' \S+', out)

    def test_every_rule_of_the_checker_has_a_bad_file(self):
        with open(SCRIPT, encoding='utf-8') as fh:
            source = fh.read()
        rules = set(re.findall(r"err\('([A-Z_]+)'", source))
        covered = {name.split('.')[0] for name in os.listdir(BAD)}
        covered |= {'COURSE_INCOMPLETE', 'KIND_BALANCE'}  # test_a_part_of_the_course_is_not_the_whole_course
        self.assertEqual(rules - covered, set())

    def test_the_example_may_change_the_verb_the_pronoun_and_the_place_of_the_particle(self):
        sys.path.insert(0, HERE)
        try:
            import check_phrases as c
        finally:
            sys.path.pop(0)
        self.assertTrue(c.contains_phrase('make up your mind', 'Have you made up your mind yet?', 'idiom'))
        self.assertTrue(c.contains_phrase('make up your mind', 'I made up my mind.', 'idiom'))
        self.assertTrue(c.contains_phrase('break the ice', 'He broke the ice with a joke.', 'idiom'))
        self.assertTrue(c.contains_phrase('figure out', "I can't figure this map out.", 'phrasal_verb'))
        self.assertTrue(c.contains_phrase('pull someone\'s leg', "Relax, I'm pulling your leg!", 'idiom'))
        self.assertTrue(c.contains_phrase('a piece of cake', 'The test was piece of cake.', 'idiom'))
        self.assertTrue(c.contains_phrase("How's it going?", 'Hi! How’s it going?', 'formula'))
        self.assertFalse(c.contains_phrase('figure out', "I can't figure this map out.", 'idiom'))
        self.assertFalse(c.contains_phrase('break the ice', 'He told a joke to start the talk.', 'idiom'))
        self.assertFalse(c.contains_phrase("How's it going?", 'How is it going?', 'formula'))

    def test_the_gap_and_the_key_follow_the_application(self):
        sys.path.insert(0, HERE)
        try:
            import check_phrases as c
        finally:
            sys.path.pop(0)
        # Phrase::occurrences / isWholePhrase (tests/Unit/Content/PhraseTest.php)
        self.assertEqual(c.occurrences("How's it going?", 'how'), 0)
        self.assertEqual(c.occurrences('well-known', 'known'), 1)
        self.assertEqual(c.occurrences('easy come, easy go', 'easy'), 2)
        self.assertTrue(c.is_whole_phrase('Cheers!', 'cheers'))
        self.assertFalse(c.is_whole_phrase('easy come, easy go', 'go'))
        # LexemeKey::of (tests/Unit/Content/LexemeKeyTest.php)
        self.assertEqual(c.lexeme_key('A piece of cake'), 'piece of cake')
        self.assertEqual(c.lexeme_key('How’s it going?'), "how's it going")
        self.assertEqual(c.lexeme_key('easy come, easy go!'), 'easy come, easy go')
        self.assertEqual(c.lexeme_key('to be honest'), 'to be honest')
        self.assertEqual(c.lexeme_key('The'), 'the')


if __name__ == '__main__':
    unittest.main()
