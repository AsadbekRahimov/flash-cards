<?php

declare(strict_types=1);

namespace App\Domain\Game\Support;

use App\Domain\Game\DTO\RunComposition;
use App\Domain\Game\DTO\RunLimits;
use App\Domain\Game\DTO\RunPool;
use App\Domain\Game\DTO\RunPoolWord;
use App\Domain\Game\Enums\RunEvent;
use App\Domain\Game\Enums\TaskKind;
use App\Domain\Game\Services\Tasks\WordTaskHandler;
use App\Domain\Practice\DTO\MaterialWord;
use App\Domain\Practice\DTO\SnapshotElement;
use App\Domain\Practice\Services\Modes\PairsMode;
use App\Domain\Practice\Support\SeededShuffle;
use App\Domain\Practice\Support\TranslationVariants;

/**
 * The composition of a run (docs/18 §12.3.1) - a pure function of the pool, the seed and the limits:
 *
 * 1. the size: `N = clamp(words + authored, size_min, size_max)`;
 * 2. the authored tasks take their places first (no more than `authored_max`); their target words are "used";
 * 3. one round of pairs of `pairs_per_round` unused words that do not clash with one another in any locale (docs/16 §4.4) - only when
 *    the words are plentiful: the round folds four words into one element, so it is made only if the size `N` is still reachable
 *    without them (`unused - per_round + 1 + authored >= N`; a stop of 8 words plays 8 tasks of words, one of 13 plays pairs too);
 *    the words of the round are "used";
 * 4. the places left: one task per unused word, the kinds in a circle `translate_fwd -> translate_back -> listen -> spell -> gap`,
 *    skipping what the word cannot do (`listen` without a recording, `gap` without the word in its example, a task with options
 *    without three distractors that do not clash with the word) - a word with no kind at all is skipped; the circle keeps two kinds
 *    from standing side by side whenever there is a choice. The distractors come from the nearest tier first (the words of the run,
 *    then the course, then the public courses), in the order of a hash of the seed and the word;
 * 5. the order of play: a seeded shuffle; the first element is always a task made of a word (the warm-up), never an authored one;
 * 6. one event at most (`events.chance`): a sandstorm (a task to translate, on a word with a recording, becomes `listen`), an oasis or
 *    a bazaar on an element in the middle. Fixed here, in the snapshot.
 *
 * The opaque ids are random (as in the practice): what is deterministic is WHICH words, kinds, distractors and order - the
 * composition - not the strings of the ids.
 */
final class RunComposer
{
    /** The kinds made of a word, in the order of the circle. */
    private const CIRCLE = [TaskKind::TranslateFwd, TaskKind::TranslateBack, TaskKind::Listen, TaskKind::Spell, TaskKind::Gap];

    public static function compose(RunPool $pool, string $seed, RunLimits $limits): RunComposition
    {
        $taken = [];
        $used = [];
        $elements = [];

        // 2. the authored tasks
        $authored = $limits->withAuthored ? array_slice($pool->authored, 0, max(0, $limits->authoredMax)) : [];
        foreach ($authored as $candidate) {
            $elements[] = $candidate->element;
            $taken[] = $candidate->element->id;
            foreach ($candidate->targetWordIds as $wordId) {
                $used[$wordId] = true;
            }
        }

        // 1. the size
        $size = max($limits->minElements, min($limits->sizeMax, max($limits->sizeMin, count($pool->words) + count($authored))));
        $words = SeededShuffle::apply($pool->words, $seed, 'words:order');
        $unused = array_values(array_filter($words, static fn (RunPoolWord $w): bool => ! isset($used[$w->id()])));

        // 3. one round of pairs
        $pairsLeft = $limits->withPairs ? $limits->pairsRoundsMax : 0;
        // The round folds `pairs_per_round` words into one element: only when the size stays reachable without them.
        if ($pairsLeft > 0 && count($unused) - $limits->pairsPerRound + 1 + count($elements) >= $size) {
            $members = self::pairsMembers($unused, $limits->pairsPerRound);
            if ($members !== null && count($unused) > count($members)) {
                $candidates = [];
                foreach ($members as $member) {
                    /** @var array{word: MaterialWord, text: string, labels: array<string, string>, keys: list<string>} $pairs */
                    $pairs = $member->pairs;
                    $candidates[] = $pairs;
                    $used[$member->id()] = true;
                }
                $elements[] = PairsMode::roundElement($candidates, $seed, 1, 1, false, $taken);
                $unused = array_values(array_filter($unused, static fn (RunPoolWord $w): bool => ! isset($used[$w->id()])));
            }
        }

        // 4. the tasks made of words
        $cursor = 0;
        $wordElements = [];
        foreach ($unused as $word) {
            if (count($elements) + count($wordElements) >= $size) {
                break;
            }
            $built = self::wordElement($word, $pool, $seed, $limits, $cursor, $taken);
            if ($built !== null) {
                $wordElements[] = $built;
            }
        }

        // 5. the order of play: the warm-up first
        $shuffled = SeededShuffle::apply([...$elements, ...$wordElements], $seed, 'elements');
        $ordered = $shuffled;
        foreach ($shuffled as $index => $element) {
            if (TaskKind::tryFrom($element->kind)?->isWordTask() === true) {
                if ($index > 0) {
                    $ordered = array_values(array_replace($shuffled, [0 => $shuffled[$index], $index => $shuffled[0]]));
                }
                break;
            }
        }

        // 6. the event
        $events = [];
        if ($limits->withEvents && count($ordered) >= 3) {
            $events = self::event($ordered, $pool, $seed, $limits, $taken);
        }

        return new RunComposition($ordered, $events);
    }

    /**
     * `$count` words that do not clash with one another, in the seeded order of `$unused`; null when there are not enough.
     *
     * @param  list<RunPoolWord>  $unused
     * @return list<RunPoolWord>|null
     */
    private static function pairsMembers(array $unused, int $count): ?array
    {
        $members = [];
        $keys = [];
        foreach ($unused as $word) {
            if ($word->pairs === null) {
                continue;
            }
            if (TranslationVariants::clash($keys, $word->clashKeys())) {
                continue;
            }
            $members[] = $word;
            $keys = [...$keys, ...$word->clashKeys()];
            if (count($members) === $count) {
                return $members;
            }
        }

        return null;
    }

    /**
     * The task of one word: the first kind of the circle from `$cursor` the word can do; the cursor moves past it.
     *
     * @param  list<string>  $taken
     */
    private static function wordElement(RunPoolWord $word, RunPool $pool, string $seed, RunLimits $limits, int &$cursor, array &$taken): ?SnapshotElement
    {
        $circle = count(self::CIRCLE);
        for ($step = 0; $step < $circle; $step++) {
            $kind = self::CIRCLE[($cursor + $step) % $circle];
            $element = self::tryKind($kind, $word, $pool, $seed, $taken);
            if ($element !== null) {
                $cursor = ($cursor + $step + 1) % $circle;

                return $element;
            }
        }

        return null;
    }

    /** @param list<string> $taken */
    private static function tryKind(TaskKind $kind, RunPoolWord $word, RunPool $pool, string $seed, array &$taken): ?SnapshotElement
    {
        switch ($kind) {
            case TaskKind::Spell:
                return $word->pairs === null ? null : WordTaskHandler::buildSpell($word, $pool->locales, $taken);
            case TaskKind::Gap:
                return $word->gap === null ? null : WordTaskHandler::buildGap($word, $pool->locales, $taken);
            case TaskKind::Listen:
                if (! $word->hasAudio()) {
                    return null;
                }
                // no break
            default:
                $distractors = $word->pairs === null ? null : self::distractors($word, $pool, $seed);

                return $distractors === null ? null : WordTaskHandler::buildChoice($kind, $word, $distractors, $pool->locales, $seed, $taken);
        }
    }

    /**
     * Three words that do not clash with the word in any locale: the nearest tier first, within a tier by a hash of the seed and the
     * candidate (docs/18 §12.3.1). Null when there are not three.
     *
     * @return list<RunPoolWord>|null
     */
    public static function distractors(RunPoolWord $word, RunPool $pool, string $seed): ?array
    {
        $own = [];
        foreach ($pool->words as $candidate) {
            if ($candidate->id() !== $word->id()) {
                $own[] = new RunPoolWord($candidate->word, $candidate->audioPath, $candidate->gap, $candidate->pairs, $candidate->word->lessonId === $word->word->lessonId ? RunPoolWord::TIER_POOL : RunPoolWord::TIER_COURSE);
            }
        }
        $candidates = [...$own, ...$pool->distractors];

        $rank = [];
        foreach ($candidates as $index => $candidate) {
            $rank[$index] = [$candidate->tier, hash_hmac('sha256', "distractor|{$word->id()}|{$candidate->id()}", $seed)];
        }
        uksort($candidates, static fn (int $a, int $b): int => $rank[$a] <=> $rank[$b]);

        $chosen = [];
        $keys = $word->clashKeys();
        $seen = [$word->id() => true];
        foreach ($candidates as $candidate) {
            if ($candidate->pairs === null || isset($seen[$candidate->id()]) || TranslationVariants::clash($keys, $candidate->clashKeys())) {
                continue;
            }
            $seen[$candidate->id()] = true;
            $chosen[] = $candidate;
            $keys = [...$keys, ...$candidate->clashKeys()];
            if (count($chosen) === WordTaskHandler::DISTRACTORS) {
                return $chosen;
            }
        }

        return null;
    }

    /**
     * The one event of the run, by the seed: whether there is one (`events.chance`), and which. A sandstorm needs a task to translate on
     * a word with a recording (the element is rebuilt as `listen` with `event: sandstorm`); an oasis or a bazaar sits on the element in
     * the middle. When the seed asks for an event the run cannot have, the next one in the row is taken.
     *
     * @param  list<SnapshotElement>  $ordered  changed in place for a sandstorm
     * @param  list<string>  $taken
     * @return list<array{at: int, type: string}>
     */
    private static function event(array &$ordered, RunPool $pool, string $seed, RunLimits $limits, array &$taken): array
    {
        $roll = hexdec(substr(hash_hmac('sha256', 'event|roll', $seed), 0, 8)) / 0x100000000;
        if ($roll >= $limits->eventsChance) {
            return [];
        }

        $types = [RunEvent::Sandstorm, RunEvent::Oasis, RunEvent::Bazaar];
        $first = hexdec(substr(hash_hmac('sha256', 'event|type', $seed), 0, 8)) % count($types);
        $middle = intdiv(count($ordered), 2);

        for ($step = 0; $step < count($types); $step++) {
            $type = $types[($first + $step) % count($types)];
            if ($type === RunEvent::Sandstorm) {
                $at = self::sandstormAt($ordered, $pool, $seed, $taken);
                if ($at !== null) {
                    return [['at' => $at, 'type' => $type->value]];
                }

                continue;
            }
            if ($type === RunEvent::Oasis && $limits->flasks <= 0) {
                continue;
            }

            return [['at' => $middle, 'type' => $type->value]];
        }

        return [];
    }

    /**
     * Turns one translation task on a voiced word into `listen` (the first such element after the warm-up); null when there is none.
     *
     * @param  list<SnapshotElement>  $ordered
     * @param  list<string>  $taken
     */
    private static function sandstormAt(array &$ordered, RunPool $pool, string $seed, array &$taken): ?int
    {
        $byId = [];
        foreach ($pool->words as $word) {
            $byId[$word->id()] = $word;
        }

        foreach ($ordered as $index => $element) {
            if ($index === 0 || ($element->kind !== TaskKind::TranslateFwd->value && $element->kind !== TaskKind::TranslateBack->value)) {
                continue;
            }
            $word = $byId[(int) $element->get('word_id')] ?? null;
            if ($word === null || ! $word->hasAudio()) {
                continue;
            }
            $distractors = self::distractors($word, $pool, $seed);
            if ($distractors === null) {
                continue;
            }
            $ordered[$index] = WordTaskHandler::buildChoice(TaskKind::Listen, $word, $distractors, $pool->locales, $seed, $taken, RunEvent::Sandstorm);

            return $index;
        }

        return null;
    }
}
