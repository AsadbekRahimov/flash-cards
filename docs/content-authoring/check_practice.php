<?php

declare(strict_types=1);

/**
 * Checker of the practice packs (database/data/practice/<course>-p<NN>.json) for the level courses (english-b1, english-b2).
 * A tool of the content author, not application code: it leans on `Tests\...` of autoload-dev, so it lives in docs/ (outside the
 * production image). The source of truth for the rules is tests/Feature/Content/PracticePackTest.php; when the two disagree, the test wins.
 *
 *   php docs/content-authoring/check_practice.php [--write-accepted] [--modules=1,2] database/data/practice/<file>.json [<file>.json ...]
 *
 * The pack paths are taken as given (absolute, or relative to the current directory); the repository root comes from this file's place.
 *
 * It runs the REAL rules of the application on every task: the content validator (as the import and the publication do), the graders
 * (every accepted writing / order must be graded correct), the lexicon of the dictation, and the pack rules of
 * tests/Support/Practice/PracticePackRules.php (the writings a dictation must accept, the informal address in ru / uz).
 * On top of that: the conventions of these packs (keys, three tasks of each type a lesson, target words of the lesson, level).
 *
 * --write-accepted   rewrites `accepted` of every dictation task as the rule of docs/16 §5.2 gives it (text first), then checks.
 * --modules=1,2      the modules this file must hold completely (three tasks of each type in each of their five lessons).
 *
 * Output, one line per problem, then a summary "<n> tasks checked, <e> errors, <w> warnings":
 *   ERROR  a broken rule: the pack must not be committed (the test would fail or the content is wrong).
 *   WARN   a doubt to read by eye (a rare word, a long sentence, a target word not found as a regular form): fix it or accept it knowingly.
 *   NOTE   information only (a file was rewritten by --write-accepted).
 *
 * Exit code: 0 = no errors (warnings do not count), 1 = at least one ERROR, 2 = no pack files given.
 */
use App\Domain\Practice\Enums\PracticeItemType;
use App\Domain\Practice\Enums\Verdict;
use App\Domain\Practice\Exceptions\PracticeContentException;
use App\Domain\Practice\Services\Grading\DictationGrader;
use App\Domain\Practice\Services\Grading\SentenceGrader;
use App\Domain\Practice\Services\PracticeImporter;
use App\Domain\Practice\Support\EnglishLexicon;
use App\Domain\Practice\Support\PracticeContentValidator;
use App\Domain\Practice\Support\PracticeTextNormalizer;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Practice\PracticePackRules;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/** Course slug => [level of its tasks, prefix of their keys]. A new course is a new row here and in `ppk_courses()` of PracticePackTest. */
const COURSES = [
    'english-b1' => ['B1', 'b1'],
    'english-b2' => ['B2', 'b2'],
];
const TYPE_LETTER = ['collocation' => 'c', 'sentence' => 's', 'dictation' => 'd'];
/** Compounds with a second spelling in good dictionaries: a dictation phrase with one of them accepts the other writing too. */
const OTHER_WRITINGS = [
    'rainforest' => 'rain forest', 'rainforests' => 'rain forests', 'shortlist' => 'short list', 'layoff' => 'lay-off', 'layoffs' => 'lay-offs',
    'backup' => 'back-up', 'backups' => 'back-ups', 'homemade' => 'home-made', 'coordinate' => 'co-ordinate', 'coordinates' => 'co-ordinates',
    'coordinated' => 'co-ordinated', 'counterargument' => 'counter-argument', 'username' => 'user name', 'checkup' => 'check-up',
    'email' => 'e-mail', 'emails' => 'e-mails', 'worthwhile' => 'worth while',
];
const FIELDS = [
    'collocation' => ['key', 'type', 'lesson', 'level', 'target_words', 'text', 'options', 'correct', 'translation', 'explanation'],
    'sentence' => ['key', 'type', 'lesson', 'level', 'target_words', 'prompt', 'fragments', 'accepted', 'display', 'explanation'],
    'dictation' => ['key', 'type', 'lesson', 'level', 'target_words', 'text', 'accepted', 'translation', 'explanation'],
];

$args = array_slice($argv, 1);
$write = false;
$modules = [];
$files = [];
foreach ($args as $arg) {
    if ($arg === '--write-accepted') {
        $write = true;
    } elseif (str_starts_with($arg, '--modules=')) {
        $modules = array_map('intval', explode(',', substr($arg, 10)));
    } else {
        $files[] = $arg;
    }
}
if ($files === []) {
    fwrite(STDERR, "usage: php docs/content-authoring/check_practice.php [--write-accepted] [--modules=1,2] <pack.json> ...\n");
    exit(2);
}

$errors = 0;
$warnings = 0;
$err = static function (string $where, string $message) use (&$errors): void {
    $errors++;
    echo "ERROR {$where}: {$message}\n";
};
$warn = static function (string $where, string $message) use (&$warnings): void {
    $warnings++;
    echo "WARN  {$where}: {$message}\n";
};

/** 2-space JSON exactly as the other packs are written. */
$encode = static function (array $data): string {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    return preg_replace_callback('/^( +)/m', static fn (array $m): string => str_repeat(' ', intdiv(strlen($m[1]), 2)), $json)."\n";
};

$validator = app(PracticeContentValidator::class);
$dictationGrader = app(DictationGrader::class);
$sentenceGrader = app(SentenceGrader::class);

/** @var array<string, array{words: array<string, string>, examples: array<string, string>}> $courses */
$courses = [];
$course = static function (string $slug) use (&$courses, $root): ?array {
    if (! isset($courses[$slug])) {
        $dir = $root."/database/data/courses/{$slug}";
        if (! is_dir($dir)) {
            return null;
        }
        $words = [];
        $examples = [];
        foreach (glob($dir.'/m*-l*.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
            $lesson = $data['stage']['number'].'.'.$data['lesson']['number'];
            foreach ($data['words'] as $word) {
                $words[$word['word']] = $lesson;
                $examples[PracticeTextNormalizer::normalize($word['example'])] = $word['word'];
            }
        }
        $courses[$slug] = ['words' => $words, 'examples' => $examples];
    }

    return $courses[$slug];
};

/** Is the token the word itself or a correctly spelled regular form of it? */
$isFormOf = static function (string $token, string $word): bool {
    return $token === $word || in_array($word, EnglishLexicon::lemmas($token), true);
};

$total = 0;
$keys = [];
$english = [];      // normalized English sentence => key
$perLesson = [];    // "course module.lesson" => ['collocation' => n, ..., 'targets' => set]

foreach ($files as $file) {
    $name = basename($file);
    $raw = @file_get_contents($file);
    if ($raw === false) {
        $err($name, 'cannot read the file');

        continue;
    }
    try {
        $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        $err($name, 'not valid JSON: '.$e->getMessage());

        continue;
    }
    if (! is_array($data) || array_keys($data) !== ['format', 'course', 'items']) {
        $err($name, 'top-level keys must be exactly format, course, items (in this order)');

        continue;
    }
    if ($data['format'] !== PracticeImporter::FORMAT) {
        $err($name, 'format must be '.PracticeImporter::FORMAT);
    }
    $slug = (string) $data['course'];
    $pack = $course($slug);
    if ($pack === null || ! isset(COURSES[$slug])) {
        $err($name, "unknown course «{$slug}»");

        continue;
    }
    if (! is_array($data['items']) || ! array_is_list($data['items'])) {
        $err($name, 'items must be a list');

        continue;
    }
    if (count($data['items']) > 300) {
        $err($name, 'more than 300 tasks in one file');
    }

    $changed = false;
    foreach ($data['items'] as $index => &$task) {
        $total++;
        $key = is_array($task) && is_string($task['key'] ?? null) ? $task['key'] : "#{$index}";
        $where = "{$name} {$key}";
        if (! is_array($task)) {
            $err($where, 'a task must be an object');

            continue;
        }
        $type = $task['type'] ?? null;
        if (! is_string($type) || ! isset(FIELDS[$type])) {
            $err($where, 'type must be collocation, sentence or dictation');

            continue;
        }

        // ---- the fields
        $allowed = FIELDS[$type];
        $have = array_keys($task);
        foreach (array_diff($have, $allowed) as $extra) {
            $err($where, "unknown field «{$extra}»");
        }
        foreach ($allowed as $field) {
            if (! array_key_exists($field, $task) && ! ($type === 'dictation' && $field === 'explanation')) {
                $err($where, "field «{$field}» is missing");
            }
        }
        if (array_values(array_intersect($allowed, $have)) !== $have && array_diff($have, $allowed) === []) {
            $err($where, 'fields are not in the order: '.implode(', ', $allowed));
        }

        // ---- the key, the lesson, the level
        $stage = $task['lesson']['stage'] ?? null;
        $number = $task['lesson']['number'] ?? null;
        if (! is_int($stage) || ! is_int($number) || $stage < 1 || $stage > 20 || $number < 1 || $number > 5) {
            $err($where, 'lesson must be {"stage": 1..20, "number": 1..5}');

            continue;
        }
        $lesson = "{$stage}.{$number}";
        $pattern = sprintf('/\A%s-m%02d-l%d-%s[1-3]\z/', COURSES[$slug][1], $stage, $number, TYPE_LETTER[$type]);
        if (preg_match($pattern, $key) !== 1) {
            $err($where, sprintf('key must be %s-m%02d-l%d-%s1..3 for this lesson and type', COURSES[$slug][1], $stage, $number, TYPE_LETTER[$type]));
        }
        if (isset($keys["{$slug} {$key}"])) {
            $err($where, 'the key is used twice (also in '.$keys["{$slug} {$key}"].')');
        }
        $keys["{$slug} {$key}"] = $name;
        if (($task['level'] ?? null) !== COURSES[$slug][0]) {
            $err($where, 'level must be '.COURSES[$slug][0]);
        }

        // ---- the target words
        $targets = $task['target_words'] ?? null;
        if (! is_array($targets) || $targets === [] || count($targets) > 2) {
            $err($where, 'target_words must hold one word of the lesson (two at most)');
            $targets = [];
        }
        foreach ($targets as $target) {
            $at = $pack['words'][$target] ?? null;
            if ($at === null) {
                $err($where, "target word «{$target}» is not a word of the course (lower case, exactly as in the lesson file)");
            } elseif ($at !== $lesson) {
                $err($where, "target word «{$target}» belongs to lesson {$at}, the task is in {$lesson}");
            }
        }

        // ---- the dictation writings by the rule (before the validator sees them)
        if ($type === 'dictation' && is_string($task['text'] ?? null)) {
            $expected = [];
            foreach ([$task['text'], ...PracticePackRules::expectedWritings($task['text'])] as $writing) {
                // The rule writes a contraction in lower case even where it opens the phrase: a writing starts as the text does.
                $expected[PracticeTextNormalizer::normalize($writing)] ??= ucfirst($writing);
            }
            // Another accepted way to write a compound (docs/16 §5.2: "другое написание слова - на усмотрение автора"): one
            // extra writing per phrase, with every such word of the text respelled.
            foreach (array_values($expected) as $writing) {
                $other = preg_replace_callback('/\b('.implode('|', array_map('preg_quote', array_keys(OTHER_WRITINGS))).')\b/i', static function (array $m): string {
                    $to = OTHER_WRITINGS[strtolower($m[1])];

                    return ctype_upper($m[1][0]) ? ucfirst($to) : $to;
                }, $writing);
                if (is_string($other) && $other !== $writing) {
                    $expected[PracticeTextNormalizer::normalize($other)] ??= $other;
                }
            }
            $expected = array_values($expected);
            if ($write) {
                if (($task['accepted'] ?? null) !== $expected) {
                    $task['accepted'] = $expected;
                    $changed = true;
                }
            }
            $accepted = is_array($task['accepted'] ?? null) ? array_values(array_filter($task['accepted'], 'is_string')) : [];
            if (($accepted[0] ?? null) !== $task['text']) {
                $err($where, 'accepted must start with the text itself');
            }
            $normalizedAccepted = array_map(PracticeTextNormalizer::normalize(...), $accepted);
            foreach ($expected as $writing) {
                if (! in_array(PracticeTextNormalizer::normalize($writing), $normalizedAccepted, true)) {
                    $err($where, "accepted lacks the writing «{$writing}» (run with --write-accepted)");
                }
            }
            foreach ($accepted as $writing) {
                if (! in_array($writing, $expected, true)) {
                    $err($where, "accepted has a writing the rule does not give: «{$writing}»");
                }
            }
            if (count($expected) > 6) {
                $err($where, 'the text gives more than 5 extra writings: rewrite it with fewer contractible places');
            }
        }

        // ---- the validator of the application (what the import and the publication check)
        $itemType = PracticeItemType::from($type);
        $content = null;
        try {
            $content = $validator->canonical($itemType, $task);
            $validator->assertPublishable($itemType, $content);
        } catch (PracticeContentException $e) {
            $err($where, 'validator: '.$e->getMessage());
        } catch (Throwable $e) {
            $err($where, 'validator crashed: '.$e->getMessage());
        }

        // ---- the texts in a language
        foreach (['prompt', 'translation', 'explanation'] as $field) {
            if (! array_key_exists($field, $task)) {
                continue;
            }
            $texts = $task[$field];
            if (! is_array($texts) || array_keys($texts) !== ['ru', 'uz']) {
                $err($where, "{$field} must be {\"ru\": ..., \"uz\": ...}");

                continue;
            }
            foreach ($texts as $locale => $text) {
                if (! is_string($text) || trim($text) === '' || $text !== trim($text)) {
                    $err($where, "{$field}.{$locale} is empty or has spaces round it");

                    continue;
                }
                if (preg_match('/[<>]/', $text) === 1) {
                    $err($where, "{$field}.{$locale} has < or >");
                }
                if (preg_match('/  |\t|\n|\x{00A0}|\x{200B}/u', $text) === 1) {
                    $err($where, "{$field}.{$locale} has a double space, a tab, a line break or an invisible character");
                }
                if ($locale === 'uz') {
                    if (preg_match('/[’ʻʼ‘`]|\p{Cyrillic}/u', $text) === 1) {
                        $err($where, "{$field}.uz has Cyrillic or a typographic apostrophe");
                    }
                    foreach (PracticePackRules::uzInformal($text) as $word) {
                        $err($where, "{$field}.uz addresses the learner as «sen» (or looks so): «{$word}»");
                    }
                } else {
                    if (preg_match('/\p{Cyrillic}/u', $text) !== 1) {
                        $err($where, "{$field}.ru has no Cyrillic");
                    }
                    foreach (PracticePackRules::ruInformal($text) as $word) {
                        $err($where, "{$field}.ru addresses the learner as «ты» (or looks so): «{$word}»");
                    }
                }
                if ($field !== 'explanation' && preg_match('/[.!?…]\z/u', $text) !== 1 && $type !== 'dictation') {
                    $err($where, "{$field}.{$locale} must end with . ! or ?");
                }
            }
        }

        // ---- the English of the task
        $sentence = null;
        if ($type === 'collocation') {
            $text = $task['text'] ?? '';
            $options = $task['options'] ?? [];
            $correct = $task['correct'] ?? [];
            if (is_string($text) && is_array($options) && is_array($correct)) {
                if (substr_count($text, '___') !== 1 || str_contains($text, '____')) {
                    $err($where, 'text must have exactly one ___ (three underscores)');
                }
                if (str_starts_with(ltrim($text), '___')) {
                    $err($where, 'the gap must not open the sentence');
                }
                if (preg_match('/\A\p{Lu}/u', $text) !== 1 || preg_match('/[.!?]\z/', $text) !== 1) {
                    $err($where, 'text must start with a capital letter and end with . ! or ?');
                }
                if (count($correct) !== 1) {
                    $err($where, 'correct must hold exactly one option');
                }
                if (($options[0] ?? null) !== ($correct[0] ?? null)) {
                    $err($where, 'the correct option comes first in options');
                }
                $length = count(PracticeTextNormalizer::tokens(str_replace('___', 'x', $text)));
                if ($length < 5 || $length > 13) {
                    $warn($where, "the sentence has {$length} words: 6 to 12 is the norm");
                }
                foreach ($options as $option) {
                    if (! is_string($option) || preg_match("/\\A[a-z][a-z'-]*\\z/", $option) !== 1) {
                        $err($where, 'an option must be one lower-case word: «'.(is_string($option) ? $option : '?').'»');
                    } elseif (! EnglishLexicon::isWord($option) && ! EnglishLexicon::isWord(str_replace('-', '', $option))) {
                        $warn($where, "option «{$option}» is not in the lexicon (a rare word, or a typo?)");
                    }
                }
                if (isset($correct[0]) && is_string($correct[0])) {
                    $sentence = str_replace('___', $correct[0], $text);
                }
                if (preg_match('/\b(a|an) ___/i', $text, $m) === 1) {
                    $warn($where, "the article «{$m[1]}» stands right before the gap: it must fit all three options and give nothing away");
                }
            }
        } elseif ($type === 'sentence') {
            $fragments = $task['fragments'] ?? [];
            $display = $task['display'] ?? '';
            if (is_array($fragments) && is_string($display)) {
                if (count($fragments) < 4 || count($fragments) > 8) {
                    $err($where, 'a sentence has 4 to 8 fragments');
                }
                foreach ($fragments as $fragment) {
                    if (! is_string($fragment) || preg_match("/\\A[A-Za-z][A-Za-z' -]*\\z/", $fragment) !== 1) {
                        $err($where, 'a fragment is letters only (apostrophe, hyphen, a space inside a chunk): «'.(is_string($fragment) ? $fragment : '?').'»');
                    } elseif (preg_match('/\A[A-Z]/', $fragment) === 1 && preg_match("/\\AI(?:'[a-z]+)?\\z/", $fragment) !== 1) {
                        $warn($where, "fragment «{$fragment}» starts with a capital letter (only I and proper names may)");
                    }
                    if (is_string($fragment) && substr_count($fragment, ' ') > 2) {
                        $err($where, "fragment «{$fragment}» is longer than three words");
                    }
                }
                $loose = array_map('strtolower', array_filter($fragments, 'is_string'));
                if (in_array('the', $loose, true) && (in_array('a', $loose, true) || in_array('an', $loose, true))) {
                    $warn($where, 'a / an and the are separate fragments: if the two nouns can exchange them, glue each article to its noun in one fragment or rephrase');
                }
                if (($task['accepted'][0] ?? null) !== $fragments) {
                    $err($where, 'the first accepted order must be the fragments themselves');
                }
                if (preg_match('/\A\p{Lu}/u', $display) !== 1 || preg_match('/[.!?]\z/', $display) !== 1) {
                    $err($where, 'display must start with a capital letter and end with . ! or ?');
                }
                $sentence = $display;
            }
            if ($content !== null) {
                $ids = [];
                foreach ($content['fragments'] as $i => $text) {
                    $ids["f{$i}"] = $text;
                }
                $forms = $sentenceGrader->forms($content['fragments'], $content['accepted'], $content['display'] ?? null)['accepted'];
                foreach ($content['accepted'] as $sequence) {
                    $order = array_map(static fn (int $i): string => "f{$i}", $sequence);
                    if ($sentenceGrader->grade($ids, $forms, ['order' => $order])->verdict !== Verdict::Correct) {
                        $err($where, 'an accepted order is not graded correct: «'.$sentenceGrader->join($ids, $order).'»');
                    }
                }
            }
        } else {
            $text = $task['text'] ?? '';
            if (is_string($text)) {
                $tokens = PracticeTextNormalizer::tokens($text);
                if (count($tokens) < 3 || count($tokens) > 9) {
                    $err($where, 'a dictation phrase has 3 to 9 words');
                }
                if (mb_strlen($text) > 60) {
                    $err($where, 'a dictation phrase is at most 60 characters long');
                }
                if (preg_match('/\A\p{Lu}/u', $text) !== 1) {
                    $err($where, 'text must start with a capital letter');
                }
                if (preg_match('/[.!,;:]\z/', $text) === 1) {
                    $err($where, 'text has no final mark, except ? of a question');
                }
                if (preg_match('/\d/', $text) === 1) {
                    $err($where, 'no digits in a dictation text');
                }
                if (preg_match('/[,;:()"«»“”-]/u', $text) === 1) {
                    $err($where, 'no comma, hyphen, bracket or quote in a dictation text');
                }
                foreach ([$text, ...(is_array($task['accepted'] ?? null) ? $task['accepted'] : [])] as $writing) {
                    if (! is_string($writing)) {
                        continue;
                    }
                    preg_match_all("/[a-z][a-z'-]*/i", $writing, $matches);
                    foreach ($matches[0] as $word) {
                        // A hyphenated word is known as one word (`e-mail`) or by its parts, as PracticePackTest reads it.
                        $parts = explode('-', $word);
                        if (! EnglishLexicon::isWord($word) && ! EnglishLexicon::isWord(str_replace('-', '', $word))
                            && array_filter($parts, static fn (string $part): bool => ! EnglishLexicon::isWord($part)) !== []) {
                            if (preg_match('/\A[A-Z]/', $word) === 1 && $word !== ucfirst(strtolower(explode(' ', $text)[0]))) {
                                $err($where, "«{$word}»: no names or capitalised words inside a dictation text");
                            } else {
                                $warn($where, 'LEXICON «'.strtolower($word).'» is not in the dictation lexicon: keep it only if it is an ordinary, correctly spelled English word, and list it in your report');
                            }
                        }
                    }
                    if ($content !== null && $dictationGrader->grade($content['accepted'], $writing)->verdict !== Verdict::Correct) {
                        $err($where, "the writing «{$writing}» is not graded correct");
                    }
                }
                $sentence = $text;
            }
        }

        if ($sentence !== null) {
            $normalized = PracticeTextNormalizer::normalize($sentence);
            $tokens = explode(' ', $normalized);
            foreach ($targets as $target) {
                if (! is_string($target)) {
                    continue;
                }
                $found = false;
                foreach ($tokens as $token) {
                    if ($isFormOf($token, $target)) {
                        $found = true;
                        break;
                    }
                }
                if (! $found) {
                    $warn($where, "target word «{$target}» is not found in the English sentence as itself or a regular form: «{$sentence}»");
                }
            }
            if ($type !== 'dictation') {
                foreach ($tokens as $token) {
                    if (preg_match('/\d/', $token) === 1) {
                        continue;
                    }
                    if (! EnglishLexicon::isWord($token)) {
                        $warn($where, "«{$token}» is not in the lexicon (a name, a rare word, or a typo?)");
                    }
                }
            }
            $dupKey = "{$slug} {$normalized}";
            if (isset($english[$dupKey])) {
                $err($where, 'the same English sentence as in '.$english[$dupKey]);
            }
            $english[$dupKey] = $key;
            if (isset($pack['examples'][$normalized])) {
                $err($where, 'the English sentence repeats the example of the card «'.$pack['examples'][$normalized].'»: write a new one');
            }
        }

        $slot = "{$slug} {$lesson}";
        $perLesson[$slot][$type] = ($perLesson[$slot][$type] ?? 0) + 1;
        foreach ($targets as $target) {
            if (is_string($target)) {
                $perLesson[$slot]['targets'][$target] = true;
            }
        }
        $perLesson[$slot]['file'] = $name;
        $perLesson[$slot]['stage'] = $stage;
    }
    unset($task);

    if ($write && $changed) {
        file_put_contents($file, $encode($data));
        echo "NOTE  {$name}: accepted of the dictation tasks rewritten by the rule\n";
    } elseif ($raw !== $encode($data)) {
        $err($name, 'the file is not in the canonical layout (2-space JSON, unescaped unicode, one trailing newline); run with --write-accepted or re-dump it');
    }

    // ---- the lessons this file must hold completely
    if ($modules !== []) {
        foreach ($modules as $module) {
            foreach (range(1, 5) as $n) {
                $slot = "{$slug} {$module}.{$n}";
                foreach (array_keys(TYPE_LETTER) as $type) {
                    if (($perLesson[$slot][$type] ?? 0) !== 3) {
                        $err($name, "lesson {$module}.{$n} must have exactly 3 {$type} tasks, has ".($perLesson[$slot][$type] ?? 0));
                    }
                }
            }
        }
        foreach ($perLesson as $slot => $info) {
            if (($info['file'] ?? null) === $name && ! in_array($info['stage'], $modules, true)) {
                $err($name, "tasks of module {$info['stage']} do not belong to this file");
            }
        }
    }
}

foreach ($perLesson as $slot => $info) {
    $distinct = count($info['targets'] ?? []);
    if ($distinct < 9) {
        $err($slot, "the nine tasks of a lesson must target at least 9 different words of it, they target {$distinct}");
    }
    foreach (array_keys(TYPE_LETTER) as $type) {
        if (($info[$type] ?? 0) > 3) {
            $err($slot, "more than 3 {$type} tasks");
        }
    }
}

echo "{$total} tasks checked, {$errors} errors, {$warnings} warnings\n";
exit($errors === 0 ? 0 : 1);
