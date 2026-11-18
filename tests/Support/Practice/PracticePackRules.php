<?php

declare(strict_types=1);

namespace Tests\Support\Practice;

use App\Domain\Practice\Enums\Verdict;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Domain\Practice\Services\Grading\ErrorHuntGrader;
use App\Domain\Practice\Services\Grading\WordFormGrader;
use App\Domain\Practice\Support\EnglishLexicon;
use App\Domain\Practice\Support\ErrorHuntText;
use App\Domain\Practice\Support\PracticeTextNormalizer;

/**
 * The rules a practice pack keeps (docs/16 §4.5, §5.2), free of any test framework: the pack tests and the checking script of the
 * new packs use these very functions, so the rules have one source.
 */
final class PracticePackRules
{
    /**
     * The forms of the informal «sen» in an uz text that their shape gives away: the pronoun and the verbs of the 2nd person singular
     * (-san, -yapsan, -gansan, -ding, -sang, -gin). The English words an explanation quotes (`building`, `reading`) are not Uzbek. The
     * bare imperative (`qil`, `yubor`) and the possessive in -ng (`otang`) look like other words: the packs were read for them by hand.
     *
     * @return list<string>
     */
    public static function uzInformal(string $text): array
    {
        preg_match_all("/[a-z][a-z']*/i", $text, $matches);
        $found = [];
        foreach ($matches[0] as $word) {
            $lower = strtolower(rtrim($word, "'"));
            if (EnglishLexicon::isWord($lower)) {
                continue;
            }
            if (preg_match('/\\A(?:sen|seni|senga|senda|sendan|sening|senlar)\\z/', $lower) === 1
                || preg_match('/(?:[ay]san|yapsan|gansan|ding|sang)(?:mi|u|chi|ku)?\\z|gin\\z/', $lower) === 1) {
                $found[] = $word;
            }
        }

        return $found;
    }

    /**
     * The forms of the informal «ты» in a ru text: the pronoun with its possessive, a verb of the 2nd person singular, `давай`. A bare
     * imperative (`позвони`) looks like other words: the packs were read for it by hand.
     *
     * @return list<string>
     */
    public static function ruInformal(string $text): array
    {
        preg_match_all('/\p{L}+/u', mb_strtolower($text), $matches);

        return array_values(array_filter($matches[0], static fn (string $word): bool => preg_match('/\A(?:ты|тебя|тебе|тобой|тобою|твой|твоя|твоё|твое|твои|твоего|твоей|твоему|твоим|твоих|твоими|твою|давай)\z/u', $word) === 1
            || (preg_match('/(?:ешь|ёшь|ишь)(?:ся)?\z/u', $word) === 1 && ! in_array($word, ['лишь', 'тишь'], true))));
    }

    /**
     * The writings the rule of docs/16 §5.2 asks of a dictation phrase: every combination of the places of the text where a function
     * word with a verb or a negative is contracted or written out (both ways), and where a number is written in words or in digits.
     * A noun with `is` is no such place: its contraction is accepted only when the text has it.
     *
     * @return list<string> the text itself among them
     */
    public static function expectedWritings(string $text): array
    {
        $pronoun = 'he|she|it|everything|everyone|everybody|something|someone|somebody|nothing|nobody|anything|anyone';
        $clause = 'that|there|here|what|who|where|when|how|why';
        // A pronoun anywhere; a clause word only where it opens the clause (`The service here is` has no `here's`).
        $is = "((?<![a-z'])(?:{$pronoun})|(?:^|(?<=[,;:] ))(?:{$clause}))";
        $will = '\\b(I|you|he|she|it|we|they|that|there|who)';
        $three = static fn (array $m): array => ["{$m[1]} is not", "{$m[1]} isn't", "{$m[1]}'s not"];
        $rules = [
            ["/{$is} is not\\b/i", $three],
            ["/{$is}'s not\\b/i", $three],
            ["/{$is} isn't\\b/i", $three],
            ['/\\b(I) am not\\b/', static fn (): array => ['I am not', "I'm not"]],
            ["/\\b(I)'m not\\b/", static fn (): array => ['I am not', "I'm not"]],
            ['/\\b(you|we|they) are not\\b/i', static fn (array $m): array => ["{$m[1]} are not", "{$m[1]} aren't", "{$m[1]}'re not"]],
            ['/\\b(I) am\\b/', static fn (): array => ['I am', "I'm"]],
            ["/\\b(I)'m\\b/", static fn (): array => ['I am', "I'm"]],
            ['/\\b(you|we|they) are\\b/i', static fn (array $m): array => ["{$m[1]} are", "{$m[1]}'re"]],
            ["/\\b(you|we|they)'re\\b/i", static fn (array $m): array => ["{$m[1]} are", "{$m[1]}'re"]],
            ["/{$is} is\\b(?!\\s*[?.!]?$)/i", static fn (array $m): array => ["{$m[1]} is", "{$m[1]}'s"]],
            ["/{$is}'s\\b/i", static fn (array $m): array => ["{$m[1]} is", "{$m[1]}'s"]],
            ["/{$will} will\\b/i", static fn (array $m): array => ["{$m[1]} will", "{$m[1]}'ll"]],
            ["/{$will}'ll\\b/i", static fn (array $m): array => ["{$m[1]} will", "{$m[1]}'ll"]],
            ["/\\b(let)'s\\b/i", static fn (array $m): array => ["{$m[1]}'s", "{$m[1]} us"]],
            ['/\\b(let) us\\b/i', static fn (array $m): array => ["{$m[1]}'s", "{$m[1]} us"]],
        ];
        $negatives = ["don't" => 'do not', "doesn't" => 'does not', "didn't" => 'did not', "can't" => 'cannot', "won't" => 'will not',
            "isn't" => 'is not', "aren't" => 'are not', "wasn't" => 'was not', "weren't" => 'were not', "haven't" => 'have not',
            "hasn't" => 'has not', "couldn't" => 'could not', "shouldn't" => 'should not', "wouldn't" => 'would not', "mustn't" => 'must not'];
        foreach ($negatives as $short => $full) {
            $both = static fn (): array => [$short, $full];
            $rules[] = ['/\b'.preg_quote($short, '/').'\b/i', $both];
            $rules[] = ['/\b'.preg_quote($full, '/').'\b/i', $both];
        }
        $numbers = ['two thousand' => ['2000', '2,000'], 'a thousand' => ['1000', '1,000'], 'a hundred' => ['100'], 'zero' => ['0'],
            'one' => ['1'], 'two' => ['2'], 'three' => ['3'], 'four' => ['4'], 'five' => ['5'], 'six' => ['6'], 'seven' => ['7'],
            'eight' => ['8'], 'nine' => ['9'], 'ten' => ['10'], 'eleven' => ['11'], 'twelve' => ['12'], 'thirteen' => ['13'],
            'fourteen' => ['14'], 'fifteen' => ['15'], 'sixteen' => ['16'], 'seventeen' => ['17'], 'eighteen' => ['18'],
            'nineteen' => ['19'], 'twenty' => ['20'], 'thirty' => ['30'], 'forty' => ['40'], 'fifty' => ['50'], 'sixty' => ['60'],
            'seventy' => ['70'], 'eighty' => ['80'], 'ninety' => ['90'], 'hundred' => ['100'], 'thousand' => ['1000']];
        foreach ($numbers as $word => $digits) {
            $rules[] = ['/\b'.$word.'\b/i', static fn (array $m): array => [$m[0], ...$digits]];
        }

        $slots = [];
        foreach ($rules as [$pattern, $alternatives]) {
            preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($matches as $match) {
                [$whole, $start] = $match[0];
                $end = $start + strlen($whole);
                foreach ($slots as [$from, $length]) {
                    if ($start < $from + $length && $from < $end) {
                        continue 2;   // a place an earlier rule has taken (`he is not` before `he is`, `two thousand` before `two`)
                    }
                }
                $slots[] = [$start, strlen($whole), $alternatives(array_column($match, 0))];
            }
        }

        usort($slots, static fn (array $a, array $b): int => $b[0] <=> $a[0]);   // from the end: the offsets before stay right
        $writings = [$text];
        foreach ($slots as [$start, $length, $alternatives]) {
            $next = [];
            foreach ($writings as $writing) {
                foreach ($alternatives as $alternative) {
                    $next[] = substr_replace($writing, $alternative, $start, $length);
                }
            }
            $writings = $next;
        }

        return $writings;
    }

    // --- "word form" and "find the mistake" (docs/16 §5.6, §5.7; the x-files) ------------------------------------------------------

    /** The keys of the tasks of an x-file: `<prefix>-mNN-lN-(w|e)[1-3]`. */
    public const EXTRA_KEY = '/\A(a1a2|b1|b2|c1|c2)-m(0[1-9]|1\d|20)-l[1-5]-[we][1-3]\z/';

    /** The name of an x-file: its tasks are of the two newer types. */
    public const EXTRA_FILE = '/-x\d{2}\.json\z/';

    /**
     * The sentence of a word form with the canonical form in its blank.
     *
     * @param  array<string, mixed>  $content  the stored content (PracticeContentValidator::canonical)
     */
    public static function wordFormSentence(array $content): string
    {
        return str_replace('___', (string) ($content['accepted'][0] ?? ''), (string) ($content['text'] ?? ''));
    }

    /**
     * The sentence of a find-the-mistake as the learner reads it (with the mistake), and corrected by the right option.
     *
     * @param  array<string, mixed>  $content  the stored content
     * @return array{wrong: string, corrected: string}
     */
    public static function errorHuntSentences(array $content): array
    {
        $tokens = ErrorHuntText::tokens((string) ($content['text'] ?? ''));
        $right = $content['options'][$content['correct'][0] ?? -1] ?? '';

        return ['wrong' => implode(' ', $tokens), 'corrected' => ErrorHuntText::replace($tokens, (int) ($content['error_index'] ?? -1), (string) $right)];
    }

    /** The token is the word itself or a correctly spelled regular form of it (the rule of the target words of every pack). */
    public static function isFormOf(string $token, string $word): bool
    {
        return $token === $word || in_array($word, EnglishLexicon::lemmas($token), true);
    }

    /**
     * Is the target word written in the sentence: each of its words as itself or a regular form?
     */
    public static function writes(string $sentence, string $target): bool
    {
        $tokens = PracticeTextNormalizer::tokens($sentence);
        foreach (PracticeTextNormalizer::tokens($target) as $word) {
            $found = false;
            foreach ($tokens as $token) {
                if (self::isFormOf($token, $word)) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                return false;
            }
        }

        return true;
    }

    /**
     * What is wrong with a word form whatever the course (the mistakes of an author that a learner pays for): a right form the grader
     * does not take, the base word that it does not refuse with `same_as_base`, a right form that the sentence already shows, a word of a
     * right form the lexicon does not know (a typo would make a right answer of a learner a «mistake»), a target word neither written in
     * the sentence nor the base word.
     *
     * @param  array<string, mixed>  $content  the stored content
     * @param  list<string>  $targets
     * @return list<string>
     */
    public static function wordFormProblems(array $content, array $targets): array
    {
        $grader = new WordFormGrader;
        $accepted = array_values(array_filter((array) ($content['accepted'] ?? []), 'is_string'));
        $base = (string) ($content['base'] ?? '');
        $text = (string) ($content['text'] ?? '');
        $problems = [];

        foreach ($accepted as $form) {
            if ($grader->grade($accepted, $base, ['text' => $form])->verdict !== Verdict::Correct) {
                $problems[] = "the right form «{$form}» is not graded correct";
            }
            if (self::contains(PracticeTextNormalizer::tokens($text), PracticeTextNormalizer::tokens($form))) {
                $problems[] = "the right form «{$form}» is in the sentence already";
            }
            foreach (PracticeTextNormalizer::tokens($form) as $token) {
                if (! EnglishLexicon::isWord($token)) {
                    $problems[] = "«{$token}» of the right form «{$form}» is not in the lexicon (a typo? add an ordinary word to resources/lexicon/en-common.txt)";
                }
            }
        }

        $byBase = $grader->grade($accepted, $base, ['text' => $base]);
        if ($byBase->verdict !== Verdict::Wrong || ($byBase->reveal['same_as_base'] ?? false) !== true) {
            $problems[] = "the base word «{$base}» is not refused as the same word";
        }

        $sentence = self::wordFormSentence($content);
        foreach ($targets as $target) {
            if (! self::writes($sentence, $target) && PracticeTextNormalizer::normalize($target) !== PracticeTextNormalizer::normalize($base)) {
                $problems[] = "target word «{$target}» is neither in «{$sentence}» nor the base word";
            }
        }

        return $problems;
    }

    /**
     * What is wrong with a find-the-mistake whatever the course: the grader on the task (words `t<i>`, options `o<i>`) does not find the
     * mistake at its place, does not take the right correction or takes a wrong one; the case of the first letters of the corrections gives
     * the answer away (ErrorHuntText::optionsCaseFault: all capital when the mistake is the first word, one case otherwise -
     * the pronoun `I` and its contractions aside); a word of the corrected sentence the lexicon does not
     * know (a number is no word; `$names` are the lower-case names a course allows); a target word not written in the corrected sentence.
     *
     * @param  array<string, mixed>  $content  the stored content
     * @param  list<string>  $targets
     * @param  list<string>  $names
     * @return list<string>
     */
    public static function errorHuntProblems(array $content, array $targets, array $names = []): array
    {
        $grader = new ErrorHuntGrader;
        $tokens = ErrorHuntText::tokens((string) ($content['text'] ?? ''));
        $index = (int) ($content['error_index'] ?? -1);
        $tokenIds = array_map(static fn (int $i): string => "t{$i}", array_keys($tokens));
        $options = array_values((array) ($content['options'] ?? []));
        $optionIds = array_map(static fn (int $i): string => "o{$i}", array_keys($options));
        $correctIds = array_map(static fn (mixed $i): string => 'o'.(int) $i, (array) ($content['correct'] ?? []));
        $problems = [];

        try {
            if (! $grader->locate($tokenIds, "t{$index}", ['token_id' => "t{$index}"])) {
                $problems[] = 'the mistake is not found at its place';
            }
            foreach ($optionIds as $i => $id) {
                $right = $grader->fix($optionIds, $correctIds, ['option_id' => $id]);
                if ($right !== in_array($id, $correctIds, true)) {
                    $problems[] = 'the correction «'.$options[$i].'» is graded '.($right ? 'right' : 'wrong').' against the key';
                }
            }
            if (count($correctIds) !== 1) {
                $problems[] = 'not exactly one right correction';
            }
        } catch (PracticeException $e) {
            $problems[] = 'the grader refuses the task: '.$e->errorCode;
        }

        $caseFault = ErrorHuntText::optionsCaseFault(array_map(static fn (mixed $option): string => is_string($option) ? $option : '', $options), $index);
        if ($caseFault === 'first_word') {
            $problems[] = 'the mistake is the first word, but a correction starts with a small letter: every correction must start with a capital (one capital gives the answer away)';
        } elseif ($caseFault === 'mixed') {
            $problems[] = 'the corrections start with letters of different case (`I` and its contractions aside): the odd one gives the answer away';
        }

        $corrected = self::errorHuntSentences($content)['corrected'];
        foreach (PracticeTextNormalizer::tokens($corrected) as $token) {
            if (preg_match('/\d/', $token) !== 1 && ! EnglishLexicon::isWord($token) && ! in_array($token, $names, true)) {
                $problems[] = "«{$token}» of the corrected sentence is not in the lexicon";
            }
        }
        foreach ($targets as $target) {
            if (! self::writes($corrected, $target)) {
                $problems[] = "target word «{$target}» is not in the corrected sentence «{$corrected}»";
            }
        }

        return $problems;
    }

    /**
     * @param  list<string>  $haystack
     * @param  list<string>  $needle
     */
    private static function contains(array $haystack, array $needle): bool
    {
        $length = count($needle);
        if ($length === 0) {
            return false;
        }
        for ($i = 0; $i + $length <= count($haystack); $i++) {
            if (array_slice($haystack, $i, $length) === $needle) {
                return true;
            }
        }

        return false;
    }
}
