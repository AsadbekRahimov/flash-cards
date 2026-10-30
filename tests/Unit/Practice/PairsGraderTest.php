<?php

declare(strict_types=1);

use App\Domain\Practice\DTO\ElementState;
use App\Domain\Practice\DTO\GradeResult;
use App\Domain\Practice\DTO\PairsRound;
use App\Domain\Practice\DTO\SnapshotElement;
use App\Domain\Practice\Enums\Verdict;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Domain\Practice\Services\Grading\PairsGrader;

/*
 * docs/16 §5.5, §14.1: the grading of a round of pairs - a solo match (the first mistake of a word is written once, a match that is
 * done is not matched twice, the right answer is not given away) and the whole table of a battle (a bijection, 25 points a pair).
 */

const PG_L = ['mother' => 'lMother0001', 'father' => 'lFather0002', 'sister' => 'lSister0003', 'brother' => 'lBrother004'];
const PG_R = ['mother' => 'rMama000001', 'father' => 'rPapa000002', 'sister' => 'rSestra0003', 'brother' => 'rBrat000004'];

/** A round of four words; shown in one order, right in another. */
function graderElement(bool $battle = false): SnapshotElement
{
    $round = new PairsRound(
        1,
        3,
        [
            ['id' => PG_L['father'], 'word_id' => 12, 'text' => 'father'],
            ['id' => PG_L['mother'], 'word_id' => 11, 'text' => 'mother'],
            ['id' => PG_L['brother'], 'word_id' => 14, 'text' => 'brother'],
            ['id' => PG_L['sister'], 'word_id' => 13, 'text' => 'sister'],
        ],
        [
            ['id' => PG_R['sister'], 'text' => ['ru' => 'сестра']],
            ['id' => PG_R['mother'], 'text' => ['ru' => 'мама']],
            ['id' => PG_R['brother'], 'text' => ['ru' => 'брат']],
            ['id' => PG_R['father'], 'text' => ['ru' => 'папа']],
        ],
        array_combine(PG_L, PG_R),
        $battle,
    );

    return new SnapshotElement('eRound00001', 'pairs_round', $round->toData());
}

/** The state of a round after some play: `$matched` as words ("mother" matched), `$wrong` the words with a mistake. */
function graderState(array $matched = [], array $wrong = [], int $mismatches = 0): ElementState
{
    return new ElementState(extra: [
        'matched' => array_combine(array_map(fn (string $w): string => PG_L[$w], $matched), array_map(fn (string $w): string => PG_R[$w], $matched)),
        'mismatches' => $mismatches,
        'wrong_left' => array_map(fn (string $w): string => PG_L[$w], $wrong),
    ]);
}

/** @return array{left_id: string, right_id: string} the match of a word with the translation of a word */
function graderChoice(string $word, ?string $translationOf = null): array
{
    return ['left_id' => PG_L[$word], 'right_id' => PG_R[$translationOf ?? $word]];
}

/** @param array<string, mixed> $answer @return list<string> the error code, and `expected` / `left_id` of its details when there are */
function graderRefusal(PairsGrader $grader, SnapshotElement $element, array $answer, ?ElementState $state = null): array
{
    try {
        $grader->grade($element, $answer, $state ?? ElementState::open());
    } catch (PracticeException $e) {
        return [$e->errorCode, (string) ($e->details['expected'] ?? $e->details['left_id'] ?? '')];
    }

    return ['no refusal', ''];
}

/**
 * The right table; every pair of words in `$swap` exchanges its translations.
 *
 * @param  list<array{0: string, 1: string}>  $swap
 * @return array<string, string>
 */
function graderTable(array $swap = []): array
{
    $table = [];
    foreach (PG_L as $word => $leftId) {
        $table[$leftId] = PG_R[$word];
    }
    foreach ($swap as [$a, $b]) {
        [$table[PG_L[$a]], $table[PG_L[$b]]] = [$table[PG_L[$b]], $table[PG_L[$a]]];
    }

    return $table;
}

describe('a match in a solo round', function (): void {
    it('is right when the translation belongs to the word, and the round goes on', function (): void {
        $result = (new PairsGrader)->grade(graderElement(), graderChoice('mother'), ElementState::open());

        expect($result)->toBeInstanceOf(GradeResult::class)
            ->and($result->verdict)->toBe(Verdict::Correct)
            ->and($result->firstAttempt)->toBeTrue()
            ->and($result->final)->toBeFalse()
            ->and($result->points)->toBe(0)
            ->and($result->reveal)->toBeNull()
            ->and($result->normalizedAnswer)->toBe(['left_id' => PG_L['mother'], 'right_id' => PG_R['mother']])
            ->and($result->extra)->toBe(['matched' => [PG_L['mother'] => PG_R['mother']], 'mismatches' => 0, 'wrong_left' => []]);
    });

    it('is wrong with another translation: a mistake is counted, the word is not matched, the right answer is not given away', function (): void {
        $result = (new PairsGrader)->grade(graderElement(), graderChoice('mother', 'father'), ElementState::open());

        expect($result->verdict)->toBe(Verdict::Wrong)
            ->and($result->firstAttempt)->toBeTrue()
            ->and($result->final)->toBeFalse()
            ->and($result->reveal)->toBeNull()
            ->and($result->normalizedAnswer)->toBe(['left_id' => PG_L['mother'], 'right_id' => PG_R['father']])
            ->and($result->extra)->toBe(['matched' => [], 'mismatches' => 1, 'wrong_left' => [PG_L['mother']]]);
        // Nothing in the result names the right translation of the word.
        expect(json_encode($result, JSON_THROW_ON_ERROR))->not->toContain(PG_R['mother']);
    });

    it('writes the FIRST mistake of a word once: a second mistake on it is counted, not written again', function (): void {
        $result = (new PairsGrader)->grade(graderElement(), graderChoice('mother', 'sister'), graderState(wrong: ['mother'], mismatches: 1));

        expect($result->verdict)->toBe(Verdict::Wrong)
            ->and($result->firstAttempt)->toBeFalse()
            ->and($result->extra)->toBe(['matched' => [], 'mismatches' => 2, 'wrong_left' => [PG_L['mother']]]);
    });

    it('keeps the mistakes of two words apart, in the order they were made', function (): void {
        $first = (new PairsGrader)->grade(graderElement(), graderChoice('sister', 'father'), graderState(wrong: ['mother'], mismatches: 1));

        expect($first->extra)->toBe(['matched' => [], 'mismatches' => 2, 'wrong_left' => [PG_L['mother'], PG_L['sister']]]);
    });

    it('matches a word after a mistake on it, but that is not a first try, and the mistake stays written', function (): void {
        $result = (new PairsGrader)->grade(graderElement(), graderChoice('mother'), graderState(wrong: ['mother'], mismatches: 1));

        expect($result->verdict)->toBe(Verdict::Correct)
            ->and($result->firstAttempt)->toBeFalse()
            ->and($result->extra)->toBe(['matched' => [PG_L['mother'] => PG_R['mother']], 'mismatches' => 1, 'wrong_left' => [PG_L['mother']]]);
    });

    it('is a first try for a word that has had no mistake, whatever happened to the others', function (): void {
        $result = (new PairsGrader)->grade(graderElement(), graderChoice('father'), graderState(matched: ['mother'], wrong: ['sister'], mismatches: 3));

        expect($result->verdict)->toBe(Verdict::Correct)
            ->and($result->firstAttempt)->toBeTrue()
            ->and($result->extra['matched'])->toBe([PG_L['mother'] => PG_R['mother'], PG_L['father'] => PG_R['father']])
            ->and($result->extra['mismatches'])->toBe(3);
    });

    it('ends the round with the last word matched, never earlier', function (): void {
        $grader = new PairsGrader;

        expect($grader->grade(graderElement(), graderChoice('brother'), graderState(matched: ['mother', 'father']))->final)->toBeFalse()
            ->and($grader->grade(graderElement(), graderChoice('brother'), graderState(matched: ['mother', 'father', 'sister']))->final)->toBeTrue();
        // A mistake on the last word does not end it.
        expect($grader->grade(graderElement(), graderChoice('brother', 'mother'), graderState(matched: ['father', 'sister']))->final)->toBeFalse();
    });

    it('refuses a word that is matched already: 409 pair_already_matched, whatever translation is tried', function (): void {
        $grader = new PairsGrader;
        $state = graderState(matched: ['mother']);

        expect(graderRefusal($grader, graderElement(), graderChoice('mother'), $state))->toBe(['pair_already_matched', PG_L['mother']])
            ->and(graderRefusal($grader, graderElement(), graderChoice('mother', 'father'), $state))->toBe(['pair_already_matched', PG_L['mother']]);
    });

    it('refuses a translation that is taken already, and names the word that has it', function (): void {
        $state = graderState(matched: ['mother']);

        expect(graderRefusal(new PairsGrader, graderElement(), graderChoice('father', 'mother'), $state))->toBe(['pair_already_matched', PG_L['mother']]);
    });

    it('refuses an id that is not in the round: 422 unknown_element_id', function (): void {
        $grader = new PairsGrader;

        expect(graderRefusal($grader, graderElement(), ['left_id' => 'lNoSuchWord', 'right_id' => PG_R['mother']]))->toBe(['unknown_element_id', ''])
            ->and(graderRefusal($grader, graderElement(), ['left_id' => PG_L['mother'], 'right_id' => 'rNoSuchCard']))->toBe(['unknown_element_id', ''])
            // The ids of the two sides are not interchangeable.
            ->and(graderRefusal($grader, graderElement(), ['left_id' => PG_R['mother'], 'right_id' => PG_L['mother']]))->toBe(['unknown_element_id', '']);
    });

    it('refuses an answer of the wrong shape: 422 invalid_answer_shape (left_right)', function (array $answer): void {
        expect(graderRefusal(new PairsGrader, graderElement(), $answer))->toBe(['invalid_answer_shape', 'left_right']);
    })->with([
        'nothing' => [[]],
        'no right id' => [['left_id' => PG_L['mother']]],
        'no left id' => [['right_id' => PG_R['mother']]],
        'empty ids' => [['left_id' => '', 'right_id' => '']],
        'an id that is not a string' => [['left_id' => 7, 'right_id' => ['x']]],
        'the table of a battle' => [['pairs' => [PG_L['mother'] => PG_R['mother']]]],
        'the skip of the other modes' => [['skip' => true]],
    ]);

    it('does not touch the state it is given', function (): void {
        $state = graderState(wrong: ['mother'], mismatches: 1);
        $before = $state->extra;

        (new PairsGrader)->grade(graderElement(), graderChoice('father', 'sister'), $state);

        expect($state->extra)->toBe($before);
    });

    it('plays a whole round to the end: the mistakes and the first tries come out as they should', function (): void {
        $grader = new PairsGrader;
        $extra = [];
        $firsts = 0;
        $finished = false;

        foreach ([['mother', 'father'], ['brother', 'mother'], ['mother', 'mother'], ['father', 'father'], ['sister', 'sister'], ['brother', 'brother']] as [$word, $translationOf]) {
            $result = $grader->grade(graderElement(), graderChoice($word, $translationOf), new ElementState(extra: $extra));
            $extra = (array) $result->extra;
            $firsts += $result->verdict === Verdict::Correct && $result->firstAttempt ? 1 : 0;
            $finished = $result->final;
        }

        expect($finished)->toBeTrue()
            ->and($firsts)->toBe(2)                                    // father and sister at the first go; mother and brother after a mistake
            ->and($extra['mismatches'])->toBe(2)
            ->and($extra['wrong_left'])->toBe([PG_L['mother'], PG_L['brother']])
            ->and($extra['matched'])->toBe(array_combine(
                [PG_L['mother'], PG_L['father'], PG_L['sister'], PG_L['brother']],
                [PG_R['mother'], PG_R['father'], PG_R['sister'], PG_R['brother']],
            ));
    });
});

describe('the whole table of a battle round', function (): void {
    it('is correct with 100 points when every pair is right', function (): void {
        $result = (new PairsGrader)->grade(graderElement(battle: true), ['pairs' => graderTable()], ElementState::open());

        expect($result->verdict)->toBe(Verdict::Correct)
            ->and($result->points)->toBe(100)
            ->and($result->final)->toBeTrue()
            ->and($result->firstAttempt)->toBeTrue()
            ->and($result->extra)->toBeNull()
            ->and($result->reveal)->toBe(['pairs' => graderTable(), 'correct_left' => [PG_L['father'], PG_L['mother'], PG_L['brother'], PG_L['sister']]]);
    });

    it('gives 25 points for each pair that is right and is wrong unless all four are', function (): void {
        $grader = new PairsGrader;
        $element = graderElement(battle: true);

        $all = $grader->grade($element, ['pairs' => graderTable()], ElementState::open());
        $two = $grader->grade($element, ['pairs' => graderTable([['mother', 'father']])], ElementState::open());
        $none = $grader->grade($element, ['pairs' => graderTable([['mother', 'father'], ['sister', 'brother']])], ElementState::open());
        // A cycle of three: one pair stays right.
        $cycle = graderTable();
        $cycle[PG_L['mother']] = PG_R['father'];
        $cycle[PG_L['father']] = PG_R['sister'];
        $cycle[PG_L['sister']] = PG_R['mother'];
        $one = $grader->grade($element, ['pairs' => $cycle], ElementState::open());

        expect([$two->verdict, $two->points])->toBe([Verdict::Wrong, 50])
            ->and([$one->verdict, $one->points])->toBe([Verdict::Wrong, 25])
            ->and([$none->verdict, $none->points])->toBe([Verdict::Wrong, 0])
            ->and([$all->verdict, $all->points])->toBe([Verdict::Correct, 100]);
    });

    it('names in the reveal the words that were matched right', function (): void {
        $result = (new PairsGrader)->grade(graderElement(battle: true), ['pairs' => graderTable([['mother', 'father']])], ElementState::open());

        expect($result->reveal['correct_left'])->toBe([PG_L['brother'], PG_L['sister']])
            ->and($result->reveal['pairs'])->toBe(graderTable());
    });

    it('stores the answer in the order of the words on the screen, whatever order it came in', function (): void {
        $table = array_reverse(graderTable(), true);
        $result = (new PairsGrader)->grade(graderElement(battle: true), ['pairs' => $table], ElementState::open());

        expect(array_keys($result->normalizedAnswer['pairs']))->toBe([PG_L['father'], PG_L['mother'], PG_L['brother'], PG_L['sister']])
            ->and($result->points)->toBe(100);
    });

    it('takes the points from the config', function (): void {
        config(['practice.battle.points.pair' => 10]);
        $result = (new PairsGrader)->grade(graderElement(battle: true), ['pairs' => graderTable([['mother', 'father']])], ElementState::open());

        expect($result->points)->toBe(20);

        config(['practice.battle.points.pair' => 40, 'practice.battle.points.round' => 100]);
        expect((new PairsGrader)->grade(graderElement(battle: true), ['pairs' => graderTable()], ElementState::open())->points)->toBe(100); // the round never gives more
    });

    it('refuses what is not a bijection: 422 invalid_answer_shape (bijection)', function (array $answer): void {
        expect(graderRefusal(new PairsGrader, graderElement(battle: true), $answer))->toBe(['invalid_answer_shape', 'bijection']);
    })->with([
        'no table' => [[]],
        'the table is not a table' => [['pairs' => 'x']],
        'an empty table' => [['pairs' => []]],
        'three words of four' => [['pairs' => array_slice(graderTable(), 0, 3, true)]],
        'a translation twice' => [['pairs' => [PG_L['mother'] => PG_R['mother'], PG_L['father'] => PG_R['mother'], PG_L['sister'] => PG_R['sister'], PG_L['brother'] => PG_R['brother']]]],
        'a translation that is not a string' => [['pairs' => [PG_L['mother'] => ['x'], PG_L['father'] => PG_R['father'], PG_L['sister'] => PG_R['sister'], PG_L['brother'] => PG_R['brother']]]],
        'a single match, as in a solo round' => [['left_id' => PG_L['mother'], 'right_id' => PG_R['mother']]],
    ]);

    it('refuses an id that is not in the round: 422 unknown_element_id', function (): void {
        $element = graderElement(battle: true);
        $foreignWord = [...graderTable(), 'lStranger001' => PG_R['mother']];
        unset($foreignWord[PG_L['mother']]);
        $foreignCard = graderTable();
        $foreignCard[PG_L['mother']] = 'rStranger001';

        expect(graderRefusal(new PairsGrader, $element, ['pairs' => $foreignWord]))->toBe(['unknown_element_id', ''])
            ->and(graderRefusal(new PairsGrader, $element, ['pairs' => $foreignCard]))->toBe(['unknown_element_id', ''])
            ->and(graderRefusal(new PairsGrader, $element, ['pairs' => ['0' => PG_R['mother']]]))->toBe(['unknown_element_id', '']);
    });

    it('does not take a solo round for a battle one: one match at a time is refused there, a table here', function (): void {
        expect(graderRefusal(new PairsGrader, graderElement(), ['pairs' => graderTable()]))->toBe(['invalid_answer_shape', 'left_right'])
            ->and(graderRefusal(new PairsGrader, graderElement(battle: true), graderChoice('mother')))->toBe(['invalid_answer_shape', 'bijection']);
    });
});

describe('the round as the snapshot keeps it', function (): void {
    it('reads back what it wrote, with the marker of a battle only where it belongs', function (): void {
        $solo = graderElement();
        $battle = graderElement(battle: true);

        expect($solo->data)->not->toHaveKey('battle')
            ->and($battle->data['battle'])->toBeTrue()
            ->and(PairsRound::of($solo)->toData())->toBe($solo->data)
            ->and(PairsRound::of($battle)->toData())->toBe($battle->data)
            ->and(PairsRound::of($solo)->battle)->toBeFalse()
            ->and(PairsRound::of($battle)->battle)->toBeTrue();
    });

    it('says the card in the language of the viewer, else in the first the snapshot has', function (): void {
        $round = new PairsRound(1, 1, [['id' => 'lA', 'word_id' => 1, 'text' => 'mother']], [['id' => 'rA', 'text' => ['ru' => 'мама', 'uz' => 'ona']]], ['lA' => 'rA']);

        expect($round->rightText('rA', 'uz'))->toBe('ona')
            ->and($round->rightText('rA', 'ru'))->toBe('мама')
            ->and($round->rightText('rA', 'en'))->toBe('мама')
            ->and($round->rightText('rNo', 'ru'))->toBeNull()
            ->and($round->rightCards('uz'))->toBe([['id' => 'rA', 'text' => 'ona']])
            ->and($round->leftText('lA'))->toBe('mother')
            ->and($round->leftText('lNo'))->toBeNull();
    });

    it('survives a stored state with junk in it', function (): void {
        $state = new ElementState(extra: ['matched' => ['lA' => 'rA', 'lB' => 5], 'mismatches' => -4, 'wrong_left' => ['lA', 'lA', 7, 'lB']]);

        expect(PairsRound::matched($state))->toBe(['lA' => 'rA'])
            ->and(PairsRound::mismatches($state))->toBe(0)
            ->and(PairsRound::wrongLeft($state))->toBe(['lA', 'lB'])
            ->and(PairsRound::matched(ElementState::open()))->toBe([])
            ->and(PairsRound::wrongLeft(ElementState::open()))->toBe([]);
    });
});
