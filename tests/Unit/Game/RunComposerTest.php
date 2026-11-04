<?php

declare(strict_types=1);

use App\Domain\Game\DTO\AuthoredCandidate;
use App\Domain\Game\DTO\RunComposition;
use App\Domain\Game\DTO\RunLimits;
use App\Domain\Game\DTO\RunPool;
use App\Domain\Game\DTO\RunPoolWord;
use App\Domain\Game\Enums\RunKind;
use App\Domain\Game\Support\RunComposer;
use App\Domain\Learning\Services\GapFillBuilder;
use App\Domain\Practice\DTO\LocaleSet;
use App\Domain\Practice\DTO\MaterialWord;
use App\Domain\Practice\DTO\SnapshotElement;
use App\Domain\Practice\Enums\PracticeItemType;
use App\Domain\Practice\Services\Modes\PairsMode;

/*
 * docs/18 §12.3.1: the composition of a run as a pure function of the pool, the seed and the limits - the size, the authored tasks
 * first and never at the warm-up, one round of pairs when the words are plentiful, the circle of kinds skipping what a word cannot
 * do, the distractors that do not clash, the one event.
 */

const COMPOSER_SEED = 'composer-seed-aaaaaaaaaaaaaaaaaaaaaaaa';

const WORD_KINDS = ['translate_fwd', 'translate_back', 'listen', 'spell', 'gap'];

function composerLocales(): LocaleSet
{
    return LocaleSet::of('ru');
}

/** A word of the pool: a translation of its own, an example with the word (unless `$example` is false), a voice when `$voiced`. */
function poolWord(int $id, string $word, ?string $translation = null, bool $voiced = false, bool $example = true, int $lessonId = 1, int $tier = RunPoolWord::TIER_POOL): RunPoolWord
{
    $translation ??= "перевод {$word}";
    $material = new MaterialWord($id, $lessonId, $word, $translation, $voiced ? "audio/{$word}.mp3" : null, ['ru' => $translation], $example ? "I say {$word} every day." : null);

    return new RunPoolWord($material, $voiced ? "audio/{$word}.mp3" : null, (new GapFillBuilder)->fromText($word, $material->example), PairsMode::candidate($material, composerLocales()), $tier);
}

/** @return list<RunPoolWord> */
function poolWords(int $count, bool $voiced = false, bool $example = true): array
{
    $words = [];
    for ($i = 1; $i <= $count; $i++) {
        $words[] = poolWord($i, "word{$i}", null, $voiced, $example);
    }

    return $words;
}

/** @param list<int> $targets */
function authoredCandidate(int $itemId, array $targets, PracticeItemType $type = PracticeItemType::Collocation): AuthoredCandidate
{
    return new AuthoredCandidate(new SnapshotElement("eAuthored{$itemId}", $type->value, ['item_id' => $itemId, 'version_id' => $itemId * 10, 'target_word_ids' => $targets]), $type, $itemId, $targets);
}

function stopLimits(float $eventsChance = 0.0): RunLimits
{
    return RunLimits::for(RunKind::Stop)->withEventsChance($eventsChance);
}

/** The composition as a comparable shape: kinds, word ids, the words of a round, the events. @return list<array<string, mixed>> */
function compositionShape(RunComposition $composition): array
{
    $shape = [];
    foreach ($composition->elements as $element) {
        $shape[] = [
            'kind' => $element->kind,
            'word_id' => $element->get('word_id'),
            'pairs' => $element->isPairsRound() ? array_column((array) $element->get('left'), 'word_id') : null,
            'options' => is_array($element->get('options')) ? array_map(static fn (array $o): mixed => $o['text'], $element->get('options')) : null,
            'event' => $element->get('event'),
        ];
    }

    return ['elements' => $shape, 'events' => $composition->events];
}

it('is deterministic from the seed: the same pool and seed give the same composition, another seed another order', function (): void {
    $pool = new RunPool(composerLocales(), poolWords(10, voiced: true));

    $a = RunComposer::compose($pool, COMPOSER_SEED, stopLimits(1.0));
    $b = RunComposer::compose($pool, COMPOSER_SEED, stopLimits(1.0));
    $c = RunComposer::compose($pool, 'another-seed-bbbbbbbbbbbbbbbbbbbbbbbbb', stopLimits(1.0));

    expect(compositionShape($a))->toBe(compositionShape($b))
        ->and(compositionShape($c))->not->toBe(compositionShape($a))
        // The opaque ids are random: two builds never share them.
        ->and(array_column(array_map(static fn (SnapshotElement $e): array => $e->toArray(), $a->elements), 'id'))
        ->not->toBe(array_column(array_map(static fn (SnapshotElement $e): array => $e->toArray(), $b->elements), 'id'));
});

it('sizes the run by the words: 6 words make 6 elements, 8 make 8, 20 make 10, each word once', function (int $words, int $expected, bool $pairs): void {
    $composition = RunComposer::compose(new RunPool(composerLocales(), poolWords($words)), COMPOSER_SEED, stopLimits());

    $kinds = array_column(array_map(static fn (SnapshotElement $e): array => $e->toArray(), $composition->elements), 'kind');
    $wordIds = array_filter(array_map(static fn (SnapshotElement $e): mixed => $e->get('word_id'), $composition->elements));
    expect($composition->count())->toBe($expected)
        ->and(array_unique($wordIds))->toHaveCount($expected - ($pairs ? 1 : 0))
        ->and(array_values(array_unique(array_diff($kinds, WORD_KINDS))))->toBe($pairs ? ['pairs_round'] : []);
})->with(['six' => [6, 6, false], 'eight' => [8, 8, false], 'ten' => [10, 10, false], 'twenty (plentiful: a round of pairs too)' => [20, 10, true]]);

it('walks the circle of kinds, two of a kind never side by side when there is a choice, listen only on a voice, gap only with the word in its example', function (): void {
    $kinds = static fn (RunComposition $c): array => array_column(array_map(static fn (SnapshotElement $e): array => $e->toArray(), $c->elements), 'kind');

    // Voiced words with examples: every kind of the circle appears in a run of 10.
    $full = RunComposer::compose(new RunPool(composerLocales(), poolWords(10, voiced: true)), COMPOSER_SEED, stopLimits());
    expect(array_values(array_unique($kinds($full))))->toHaveCount(5);

    // No voice: never `listen`; no example: never `gap`.
    expect($kinds(RunComposer::compose(new RunPool(composerLocales(), poolWords(10)), COMPOSER_SEED, stopLimits())))->not->toContain('listen');
    expect($kinds(RunComposer::compose(new RunPool(composerLocales(), poolWords(10, example: false)), COMPOSER_SEED, stopLimits())))->not->toContain('gap')->not->toContain('listen');

    // The circle, before the shuffle, puts the kinds in turn: in the order of the words, no kind repeats until the circle is round.
    $cycled = RunComposer::compose(new RunPool(composerLocales(), poolWords(10, voiced: true)), COMPOSER_SEED, stopLimits());
    $byWord = [];
    foreach ($cycled->elements as $element) {
        $byWord[(int) $element->get('word_id')] = $element->kind;
    }
    expect(count(array_unique(array_slice(array_values($byWord), 0, 5))))->toBeGreaterThanOrEqual(4);
});

it('skips the words that can do nothing and never gives a task with options to a word without three distractors that do not clash', function (): void {
    // Eight words that all mean the same thing: no distractor can be told from the right answer, so no options; no example, no gap;
    // spell stands - and the composition is short.
    $same = [];
    for ($i = 1; $i <= 8; $i++) {
        $same[] = poolWord($i, "same{$i}", 'одно и то же', example: false);
    }
    $composition = RunComposer::compose(new RunPool(composerLocales(), $same), COMPOSER_SEED, stopLimits());
    $kinds = array_unique(array_column(array_map(static fn (SnapshotElement $e): array => $e->toArray(), $composition->elements), 'kind'));
    expect($kinds)->toBe(['spell']);

    // A word with an empty translation can do nothing but a gap.
    $blank = [poolWord(1, 'blank', '(разг.)'), ...array_slice(poolWords(8), 1, 7)];
    $composition = RunComposer::compose(new RunPool(composerLocales(), $blank), COMPOSER_SEED, stopLimits());
    foreach ($composition->elements as $element) {
        if ((int) $element->get('word_id') === 1) {
            expect($element->kind)->toBe('gap');
        }
    }

    // A word that cannot even make a gap is skipped.
    $mute = [poolWord(1, 'mute', '(разг.)', example: false), ...array_slice(poolWords(8), 1, 7)];
    $composition = RunComposer::compose(new RunPool(composerLocales(), $mute), COMPOSER_SEED, stopLimits());
    expect($composition->count())->toBe(7)
        ->and(array_map(static fn (SnapshotElement $e): mixed => $e->get('word_id'), $composition->elements))->not->toContain(1);
});

it('builds the options of a choice from the nearest tier first and never lets a clashing word in', function (): void {
    // Three pool words only; the distractors come from the course (tier 1) and the public courses (tier 2).
    $words = [poolWord(1, 'cat', 'кот'), poolWord(2, 'dog', 'собака'), poolWord(3, 'fish', 'рыба', example: false)];
    $clash = poolWord(50, 'kitty', 'кот', tier: RunPoolWord::TIER_COURSE); // the same translation as "cat": never beside it
    $course = [poolWord(10, 'bird', 'птица', tier: RunPoolWord::TIER_COURSE), poolWord(11, 'cow', 'корова', tier: RunPoolWord::TIER_COURSE), $clash];
    $public = [poolWord(20, 'horse', 'лошадь', tier: RunPoolWord::TIER_PUBLIC), poolWord(21, 'goat', 'коза', tier: RunPoolWord::TIER_PUBLIC)];
    $pool = new RunPool(composerLocales(), $words, [], [...$course, ...$public]);

    $distractors = RunComposer::distractors($words[0], $pool, COMPOSER_SEED);
    expect($distractors)->not->toBeNull()->toHaveCount(3);
    $ids = array_map(static fn (RunPoolWord $w): int => $w->id(), $distractors ?? []);
    // The two other words of the pool (tier 0) come first; the third is from the course, never the clashing "kitty".
    expect(array_slice($ids, 0, 2))->toEqualCanonicalizing([2, 3])
        ->and(in_array($ids[2], [10, 11], true))->toBeTrue()
        ->and($ids)->not->toContain(50);

    // With only the clashing course word and nothing else, "cat" has two distractors: no task with options for it.
    $short = new RunPool(composerLocales(), $words, [], [$clash]);
    expect(RunComposer::distractors($words[0], $short, COMPOSER_SEED))->toBeNull();
});

it('puts the authored tasks first, no more than three, never at the warm-up, and does not ask their target words again', function (): void {
    $words = poolWords(12);
    $authored = [authoredCandidate(1, [1, 2]), authoredCandidate(2, [3], PracticeItemType::Sentence), authoredCandidate(3, [4], PracticeItemType::Dictation), authoredCandidate(4, [5])];
    $composition = RunComposer::compose(new RunPool(composerLocales(), $words, $authored), COMPOSER_SEED, stopLimits());

    $kinds = array_column(array_map(static fn (SnapshotElement $e): array => $e->toArray(), $composition->elements), 'kind');
    $authoredKinds = array_values(array_intersect($kinds, ['collocation', 'sentence', 'dictation']));
    expect($composition->count())->toBe(10)
        ->and($authoredKinds)->toHaveCount(3)
        ->and(in_array($kinds[0], WORD_KINDS, true))->toBeTrue()
        ->and($composition->elements[0]->get('target_word_ids'))->toBeNull();

    // The three chosen are the first three of the pool; the words they target are not asked again.
    $itemIds = array_values(array_filter(array_map(static fn (SnapshotElement $e): mixed => $e->get('item_id'), $composition->elements)));
    expect($itemIds)->toEqualCanonicalizing([1, 2, 3]);
    $wordIds = array_values(array_filter(array_map(static fn (SnapshotElement $e): mixed => $e->get('word_id'), $composition->elements)));
    expect(array_intersect($wordIds, [1, 2, 3, 4]))->toBe([])
        ->and(array_unique($wordIds))->toHaveCount(7);
});

it('makes one round of four pairs only when the words are plentiful: 12 words play without it, 13 with it', function (): void {
    $twelve = RunComposer::compose(new RunPool(composerLocales(), poolWords(12)), COMPOSER_SEED, stopLimits());
    expect(array_column(array_map(static fn (SnapshotElement $e): array => $e->toArray(), $twelve->elements), 'kind'))->not->toContain('pairs_round')
        ->and($twelve->count())->toBe(10);

    $thirteen = RunComposer::compose(new RunPool(composerLocales(), poolWords(13)), COMPOSER_SEED, stopLimits());
    $rounds = array_values(array_filter($thirteen->elements, static fn (SnapshotElement $e): bool => $e->isPairsRound()));
    expect($rounds)->toHaveCount(1)
        ->and($thirteen->count())->toBe(10)
        ->and($thirteen->elements[0]->isPairsRound())->toBeFalse();

    $round = $rounds[0];
    $members = array_column((array) $round->get('left'), 'word_id');
    expect($members)->toHaveCount(4)
        ->and(count((array) $round->get('right')))->toBe(4)
        ->and(count((array) $round->get('pairs')))->toBe(4);
    // The words of the round are not asked again.
    $wordIds = array_values(array_filter(array_map(static fn (SnapshotElement $e): mixed => $e->get('word_id'), $thirteen->elements)));
    expect(array_intersect($wordIds, $members))->toBe([]);

    // An authored task counts as an element: with one that targets no word of the pool the round comes with 12 words (12 - 4 + 1 + 1 = 10).
    $withAuthored = RunComposer::compose(new RunPool(composerLocales(), poolWords(12), [authoredCandidate(1, [99])]), COMPOSER_SEED, stopLimits());
    expect(array_column(array_map(static fn (SnapshotElement $e): array => $e->toArray(), $withAuthored->elements), 'kind'))->toContain('pairs_round');

    // Words that clash with one another never share the round.
    $clashing = [...poolWords(13), poolWord(14, 'kitty', 'перевод word1')];
    $composition = RunComposer::compose(new RunPool(composerLocales(), $clashing), COMPOSER_SEED, stopLimits());
    foreach ($composition->elements as $element) {
        if ($element->isPairsRound()) {
            $ids = array_column((array) $element->get('left'), 'word_id');
            expect(in_array(1, $ids, true) && in_array(14, $ids, true))->toBeFalse();
        }
    }
});

it('fixes at most one event by the seed: none at chance 0, one at chance 1; a sandstorm only on a voiced word, oasis and bazaar in the middle', function (): void {
    $voiced = new RunPool(composerLocales(), poolWords(10, voiced: true));
    expect(RunComposer::compose($voiced, COMPOSER_SEED, stopLimits(0.0))->events)->toBe([]);

    $seen = [];
    for ($i = 0; $i < 40; $i++) {
        $composition = RunComposer::compose($voiced, "event-seed-{$i}", stopLimits(1.0));
        expect($composition->events)->toHaveCount(1);
        [$event] = $composition->events;
        $seen[$event['type']] = true;
        if ($event['type'] === 'sandstorm') {
            $element = $composition->elements[$event['at']];
            expect($event['at'])->toBeGreaterThan(0)
                ->and($element->kind)->toBe('listen')
                ->and($element->get('event'))->toBe('sandstorm')
                ->and($element->get('audio_path'))->not->toBeNull();
            // Every other element is free of the event; the sandstorm is the only listen with a mark.
            foreach ($composition->elements as $index => $other) {
                if ($index !== $event['at']) {
                    expect($other->get('event'))->toBeNull();
                }
            }
        } else {
            expect($event['at'])->toBe(intdiv($composition->count(), 2));
        }
    }
    expect($seen)->toHaveKeys(['sandstorm', 'oasis', 'bazaar']);

    // No voice anywhere: no sandstorm, ever - the seed that asked for it gets the next event.
    $silent = new RunPool(composerLocales(), poolWords(10));
    for ($i = 0; $i < 40; $i++) {
        $events = RunComposer::compose($silent, "event-seed-{$i}", stopLimits(1.0))->events;
        expect($events)->toHaveCount(1)->and($events[0]['type'])->not->toBe('sandstorm');
    }
});

it('puts a bazaar only on an element that can give a hint: a round of pairs, a sentence or a dictation in the middle gets the next event or none', function (): void {
    $authored = [authoredCandidate(1, [901], PracticeItemType::Sentence), authoredCandidate(2, [902], PracticeItemType::Dictation), authoredCandidate(3, [903])];
    $pool = new RunPool(composerLocales(), poolWords(13), $authored);

    $bazaars = 0;
    $hintlessMiddle = 0;
    for ($i = 0; $i < 120; $i++) {
        $composition = RunComposer::compose($pool, "bazaar-seed-{$i}", stopLimits(1.0));
        $middle = $composition->elements[intdiv($composition->count(), 2)];
        $hintless = $middle->isPairsRound() || in_array($middle->kind, ['sentence', 'dictation'], true);
        $hintlessMiddle += $hintless ? 1 : 0;
        foreach ($composition->events as $event) {
            if ($event['type'] === 'bazaar') {
                $bazaars++;
                expect($hintless)->toBeFalse("seed {$i}: the bazaar sits on a {$middle->kind}");
            }
        }
    }

    expect($bazaars)->toBeGreaterThan(0)->and($hintlessMiddle)->toBeGreaterThan(0);
});

it('composes a boss without pairs, authored tasks, events or flasks: twelve tasks of words', function (): void {
    $limits = RunLimits::for(RunKind::Boss);
    expect($limits->flasks)->toBe(0)->and($limits->withPairs)->toBeFalse()->and($limits->withAuthored)->toBeFalse()->and($limits->withEvents)->toBeFalse()->and($limits->sizeMax)->toBe(12);

    $composition = RunComposer::compose(new RunPool(composerLocales(), poolWords(12, voiced: true), [authoredCandidate(1, [1])]), COMPOSER_SEED, $limits->withEventsChance(1.0));
    $kinds = array_column(array_map(static fn (SnapshotElement $e): array => $e->toArray(), $composition->elements), 'kind');

    expect($composition->count())->toBe(12)
        ->and(array_diff($kinds, WORD_KINDS))->toBe([])
        ->and($composition->events)->toBe([]);

    $daily = RunLimits::for(RunKind::Daily);
    expect($daily->withPairs)->toBeFalse()->and($daily->withAuthored)->toBeFalse()->and($daily->withEvents)->toBeTrue()->and($daily->flasks)->toBe(3);
});
