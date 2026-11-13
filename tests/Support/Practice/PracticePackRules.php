<?php

declare(strict_types=1);

namespace Tests\Support\Practice;

use App\Domain\Practice\Support\EnglishLexicon;

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
}
