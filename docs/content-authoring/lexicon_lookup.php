<?php

declare(strict_types=1);

/**
 * Does the dictation lexicon know each word (or a regular form of it), and has the word a pair in en-us-gb.txt?
 * A tool of the content author, not application code.
 *
 *   php docs/content-authoring/lexicon_lookup.php favor distant Tom
 *
 * One line per word: "known" / "UNKNOWN", and the other spelling when the word is in a pair of resources/lexicon/en-us-gb.txt.
 */

use App\Domain\Practice\Support\EnglishLexicon;
use App\Domain\Practice\Support\SpellingVariants;
use Illuminate\Contracts\Console\Kernel;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

foreach (array_slice($argv, 1) as $word) {
    $known = EnglishLexicon::isWord($word);
    $other = SpellingVariants::other(strtolower($word));
    echo str_pad($word, 20), $known ? 'known' : 'UNKNOWN', $other !== null ? "   (other spelling accepted too: {$other})" : '', "\n";
}
