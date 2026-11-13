<?php

declare(strict_types=1);

/**
 * Candidates for resources/lexicon/en-us-gb.txt: words of the courses and tokens of the dictations that have a British spelling by a
 * rule of thumb, while SpellingVariants knows no pair for them. A tool of the content author, not application code.
 *
 *   php docs/content-authoring/spelling_pairs.php
 *
 * Reads database/data/courses/<course>/USED_WORDS.txt and the `text` of every dictation of database/data/practice/english-*.json.
 *
 * The output is a list of CANDIDATES to read by eye: the rules are rough, so some lines are wrong. The pairs are added by a person, by hand, to
 * resources/lexicon/en-us-gb.txt (a pair "british american" to a line, every form of a word on its own line); this script writes nothing.
 *
 * The second list, "ambiguous by the rule of en-us-gb.txt - not added", holds the words that are ANOTHER word in the other variety
 * (check / cheque, tire / tyre, story / storey, draft / draught, practice / practise, license / licence, curb / kerb): only the words are
 * shown, with their forms as they occur, nothing to add.
 */

use App\Domain\Practice\Support\PracticeTextNormalizer;
use App\Domain\Practice\Support\SpellingVariants;
use Illuminate\Contracts\Console\Kernel;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$data = $root.'/database/data';

/** @var array<string, array<string, true>> $tokens token => where it comes from */
$tokens = [];
foreach (glob("{$data}/courses/*/USED_WORDS.txt") ?: [] as $file) {
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $word) {
        $tokens[trim($word)]['course'] = true;
    }
}
foreach (glob("{$data}/practice/english-*.json") ?: [] as $file) {
    $pack = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
    foreach ($pack['items'] as $task) {
        if ($task['type'] !== 'dictation') {
            continue;
        }
        foreach (PracticeTextNormalizer::tokens($task['text']) as $token) {
            $tokens[$token][basename($file, '.json').' '.$task['key']] = true;
        }
    }
}

/** American stems of -or / -our; a token is the stem itself or the stem and one of the endings below, never a word that merely holds the stem. */
const OR_STEMS = [
    'color', 'favor', 'flavor', 'honor', 'humor', 'labor', 'neighbor', 'rumor', 'behavior', 'harbor', 'vapor', 'odor', 'tumor', 'savior',
    'endeavor', 'armor', 'parlor', 'rigor', 'vigor', 'splendor', 'candor', 'demeanor',
];
const OR_ENDINGS = ['', 's', 'ed', 'ing', 'ful', 'less', 'ite', 'ites', 'able', 'hood', 'ist', 'ists', 'al', 'er', 'ers'];
const RE_WORDS = [
    'center' => 'centre', 'theater' => 'theatre', 'meter' => 'metre', 'liter' => 'litre', 'fiber' => 'fibre', 'caliber' => 'calibre',
    'somber' => 'sombre', 'meager' => 'meagre', 'kilometer' => 'kilometre', 'centimeter' => 'centimetre', 'millimeter' => 'millimetre',
];
const LL_STEMS = [
    'travel', 'cancel', 'model', 'label', 'level', 'signal', 'fuel', 'quarrel', 'counsel', 'marvel', 'channel', 'dial', 'equal', 'total',
    'rival', 'tunnel', 'shovel', 'panel', 'pedal', 'jewel', 'pencil', 'control',
];
const IZE_EXCEPTIONS = ['size', 'prize', 'seize', 'capsize', 'resize', 'downsize'];
/** American => British, for the word itself and its endings s / d / ed. */
const MISC = [
    'gray' => 'grey', 'plow' => 'plough', 'mold' => 'mould', 'cozy' => 'cosy', 'pajamas' => 'pyjamas', 'skeptical' => 'sceptical',
    'skeptic' => 'sceptic', 'program' => 'programme', 'artifact' => 'artefact', 'mustache' => 'moustache', 'maneuver' => 'manoeuvre',
    'judgment' => 'judgement', 'acknowledgment' => 'acknowledgement', 'aging' => 'ageing', 'enroll' => 'enrol', 'enrollment' => 'enrolment',
    'fulfill' => 'fulfil', 'fulfillment' => 'fulfilment', 'skillful' => 'skilful', 'willful' => 'wilful', 'installment' => 'instalment',
    'distill' => 'distil', 'instill' => 'instil', 'defense' => 'defence', 'offense' => 'offence', 'pretense' => 'pretence',
    'catalog' => 'catalogue', 'dialog' => 'dialogue', 'analog' => 'analogue', 'jewelry' => 'jewellery', 'marvelous' => 'marvellous',
    'counselor' => 'counsellor', 'traveler' => 'traveller', 'archeology' => 'archaeology', 'esthetic' => 'aesthetic',
    'specialty' => 'speciality', 'airplane' => 'aeroplane', 'math' => 'maths', 'likable' => 'likeable', 'sizable' => 'sizeable',
    'pediatric' => 'paediatric', 'anesthetic' => 'anaesthetic', 'encyclopedia' => 'encyclopaedia', 'yogurt' => 'yoghurt', 'donut' => 'doughnut',
];
/** The American word is another word in British English (en-us-gb.txt keeps them out): never offered as a pair, listed apart. */
const AMBIGUOUS = [
    'tire' => 'tyre', 'license' => 'licence', 'practice' => 'practise', 'check' => 'cheque', 'curb' => 'kerb', 'story' => 'storey',
    'draft' => 'draught',
];
const FORM_ENDINGS = ['', 's', 'd', 'ed'];

$where = static fn (array $from): string => implode(', ', array_slice(array_keys($from), 0, 3));

$out = [];
$ambiguous = [];
foreach ($tokens as $token => $from) {
    $candidates = [];

    // -ize / -yze (-ise / -yse); `size`, `prize` and the like are not of it
    if (preg_match('/^(.*)(iz|yz)(e|es|ed|ing|er|ers|ation|ations)$/', $token, $m) === 1
        && ! in_array($m[1].$m[2].'e', IZE_EXCEPTIONS, true) && strlen($m[1]) > 2) {
        $candidates[] = $m[1].($m[2] === 'iz' ? 'is' : 'ys').$m[3];
    }

    // -or => -our: the stem alone, or the stem and a regular ending
    foreach (OR_STEMS as $stem) {
        if (! str_starts_with($token, $stem)) {
            continue;
        }
        if (in_array(substr($token, strlen($stem)), OR_ENDINGS, true)) {
            $candidates[] = substr($stem, 0, -2).'our'.substr($token, strlen($stem));
        }
    }

    // -er => -re: the word itself or its plural
    foreach (RE_WORDS as $us => $gb) {
        if ($token === $us) {
            $candidates[] = $gb;
        } elseif ($token === $us.'s') {
            $candidates[] = $gb.'s';
        }
    }

    // a doubled l before an ending
    foreach (LL_STEMS as $stem) {
        if (preg_match('/^'.$stem.'(ed|ing|er|ers)$/', $token, $m) === 1) {
            $candidates[] = $stem.'l'.$m[1];
        }
    }

    // single words and their forms; a British word that ends in e takes the ending without a second e
    foreach (MISC as $us => $gb) {
        foreach (FORM_ENDINGS as $ending) {
            if ($token === $us.$ending) {
                $candidates[] = ($ending !== '' && str_ends_with($gb, 'e') && str_starts_with($ending, 'e') ? $gb.substr($ending, 1) : $gb.$ending);
            }
        }
    }

    foreach (array_unique($candidates) as $british) {
        if ($british !== $token && ! SpellingVariants::same($token, $british)) {
            $out[] = sprintf('%-18s %-18s %s', $token, $british, $where($from));
        }
    }

    // the words of the other kind: only the word itself, not a made-up form of the other word
    foreach (AMBIGUOUS as $us => $gb) {
        foreach (FORM_ENDINGS as $ending) {
            if ($token === $us.$ending) {
                $ambiguous[] = sprintf('%-18s (%s / %s)   %s', $token, $us, $gb, $where($from));
            }
        }
    }
}
sort($out);
sort($ambiguous);

echo implode("\n", $out), "\n", count($out), " candidates\n";
echo "\nambiguous by the rule of en-us-gb.txt - not added:\n", implode("\n", $ambiguous), "\n", count($ambiguous), " words\n";
