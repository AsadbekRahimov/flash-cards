<?php

declare(strict_types=1);

use App\Domain\Game\DTO\RunState;
use App\Domain\Game\DTO\ScoreOutcome;
use App\Domain\Game\DTO\ScoringRules;
use App\Domain\Game\Support\RunScoring;
use App\Domain\Practice\Enums\Verdict;

/*
 * docs/18 §12.0, §12.3.2: the score, the combo, the flasks and the stars of a run as pure functions - the numbers of the config, the
 * combo from the third answer in a row, a typo, a hint, a skip, the third flask, the oasis, the second chance, the boss.
 */

function scoringRules(): ScoringRules
{
    return new ScoringRules(correct: 10, typo: 5, comboFrom: 3, comboBonus: 5, comboBonusMax: 15);
}

function scoringState(int $flasks = 3, int $combo = 0, bool $oasisUsed = false, int $secondChance = 0): RunState
{
    return new RunState(flasks: $flasks, combo: $combo, oasisUsed: $oasisUsed, secondChanceLeft: $secondChance);
}

function score(RunState $state, Verdict $verdict, bool $final = true, bool $hinted = false, bool $chargeFlask = true, bool $oasis = false, int $flasksMax = 3): ScoreOutcome
{
    return RunScoring::apply($state, $verdict, $final, $hinted, $chargeFlask, $oasis, $flasksMax, scoringRules());
}

it('pays 10 for a correct answer and the combo from the third in a row: +5, +10, then +15 at most', function (): void {
    $points = [];
    $state = scoringState();
    foreach (range(1, 6) as $n) {
        $outcome = score($state, Verdict::Correct);
        $points[] = $outcome->points;
        expect($outcome->combo)->toBe($n)->and($outcome->flaskLost)->toBeFalse()->and($outcome->flasks)->toBe(3)->and($outcome->counted)->toBe('correct')->and($outcome->failed)->toBeFalse();
        $state = scoringState(combo: $outcome->combo);
    }

    expect($points)->toBe([10, 10, 15, 20, 25, 25])
        ->and(RunScoring::comboBonus(2, scoringRules()))->toBe(0)
        ->and(RunScoring::comboBonus(3, scoringRules()))->toBe(5)
        ->and(RunScoring::comboBonus(9, scoringRules()))->toBe(15);
});

it('reads the numbers from the config', function (): void {
    config(['game.scoring' => ['correct' => 7, 'typo' => 3, 'combo_from' => 2, 'combo_bonus' => 1, 'combo_bonus_max' => 2]]);
    $rules = ScoringRules::fromConfig();

    expect([$rules->correct, $rules->typo, $rules->comboFrom, $rules->comboBonus, $rules->comboBonusMax])->toBe([7, 3, 2, 1, 2])
        ->and(RunScoring::apply(scoringState(combo: 1), Verdict::Correct, true, false, true, false, 3, $rules)->points)->toBe(8);
});

it('gives 5 for a typo, breaks the combo and keeps the flask', function (): void {
    $outcome = score(scoringState(combo: 4), Verdict::Typo);

    expect($outcome->points)->toBe(5)->and($outcome->combo)->toBe(0)->and($outcome->flaskLost)->toBeFalse()->and($outcome->flasks)->toBe(3)->and($outcome->counted)->toBe('typo');
});

it('takes a flask for a wrong or a skipped answer, breaks the combo, and fails the run on the third', function (Verdict $verdict): void {
    $first = score(scoringState(combo: 5), $verdict);
    expect($first->points)->toBe(0)->and($first->combo)->toBe(0)->and($first->flaskLost)->toBeTrue()->and($first->flasks)->toBe(2)->and($first->failed)->toBeFalse()->and($first->counted)->toBe('wrong');

    $second = score(scoringState(flasks: 2), $verdict);
    expect($second->flasks)->toBe(1)->and($second->failed)->toBeFalse();

    $third = score(scoringState(flasks: 1), $verdict);
    expect($third->flasks)->toBe(0)->and($third->flaskLost)->toBeTrue()->and($third->failed)->toBeTrue();
})->with(['wrong' => [Verdict::Wrong], 'skipped' => [Verdict::Skipped]]);

it('counts an answer after a hint in `hints` whatever the verdict: no points, no combo; a correct one keeps the flask, a wrong one loses it', function (): void {
    $correct = score(scoringState(combo: 3), Verdict::Correct, hinted: true);
    expect($correct->points)->toBe(0)->and($correct->combo)->toBe(0)->and($correct->flaskLost)->toBeFalse()->and($correct->flasks)->toBe(3)->and($correct->counted)->toBe('hint');

    $wrong = score(scoringState(), Verdict::Wrong, hinted: true);
    expect($wrong->points)->toBe(0)->and($wrong->flaskLost)->toBeTrue()->and($wrong->flasks)->toBe(2)->and($wrong->counted)->toBe('hint');
});

it('spares the first loss with the second chance: the flask stays, the verdict and the counter do not change', function (): void {
    $spared = score(scoringState(secondChance: 1), Verdict::Wrong);
    expect($spared->flaskLost)->toBeFalse()->and($spared->secondChanceUsed)->toBeTrue()->and($spared->flasks)->toBe(3)->and($spared->counted)->toBe('wrong')->and($spared->points)->toBe(0);

    // The service decrements the chance; the next loss costs.
    $next = score(scoringState(secondChance: 0), Verdict::Wrong);
    expect($next->flaskLost)->toBeTrue()->and($next->secondChanceUsed)->toBeFalse();
});

it('gives the oasis flask back after the element, once, never above the maximum and never to a run that just failed', function (): void {
    $after = score(scoringState(flasks: 2), Verdict::Correct, oasis: true);
    expect($after->oasisApplied)->toBeTrue()->and($after->flasks)->toBe(3);

    $afterLoss = score(scoringState(flasks: 2), Verdict::Wrong, oasis: true);
    expect($afterLoss->flaskLost)->toBeTrue()->and($afterLoss->oasisApplied)->toBeTrue()->and($afterLoss->flasks)->toBe(2)->and($afterLoss->failed)->toBeFalse();

    expect(score(scoringState(flasks: 3), Verdict::Correct, oasis: true)->oasisApplied)->toBeFalse()
        ->and(score(scoringState(flasks: 2, oasisUsed: true), Verdict::Correct, oasis: true)->oasisApplied)->toBeFalse()
        ->and(score(scoringState(flasks: 2), Verdict::Correct, oasis: false)->oasisApplied)->toBeFalse();

    $lastFlask = score(scoringState(flasks: 1), Verdict::Wrong, oasis: true);
    expect($lastFlask->failed)->toBeTrue()->and($lastFlask->oasisApplied)->toBeFalse()->and($lastFlask->flasks)->toBe(0);
});

it('never takes a flask from a boss (no flasks) and never fails it that way', function (): void {
    $outcome = score(scoringState(flasks: 0), Verdict::Wrong, flasksMax: 0);

    expect($outcome->flaskLost)->toBeFalse()->and($outcome->flasks)->toBe(0)->and($outcome->failed)->toBeFalse()->and($outcome->counted)->toBe('wrong');
});

it('treats a round of pairs: a mismatch costs the one flask of the round and nothing else, the finished round is one answer', function (): void {
    $mismatch = score(scoringState(combo: 2), Verdict::Wrong, final: false);
    expect($mismatch->points)->toBe(0)->and($mismatch->combo)->toBe(2)->and($mismatch->flaskLost)->toBeTrue()->and($mismatch->flasks)->toBe(2)->and($mismatch->counted)->toBe('none');

    // The second mismatch of the same round: the flask went already.
    $again = score(scoringState(flasks: 2, combo: 2), Verdict::Wrong, final: false, chargeFlask: false);
    expect($again->flaskLost)->toBeFalse()->and($again->flasks)->toBe(2)->and($again->counted)->toBe('none');

    $match = score(scoringState(flasks: 2, combo: 2), Verdict::Correct, final: false);
    expect($match->points)->toBe(0)->and($match->combo)->toBe(2)->and($match->counted)->toBe('none');

    $roundWithMistakes = score(scoringState(flasks: 2, combo: 2), Verdict::Wrong, final: true, chargeFlask: false);
    expect($roundWithMistakes->points)->toBe(0)->and($roundWithMistakes->combo)->toBe(0)->and($roundWithMistakes->flaskLost)->toBeFalse()->and($roundWithMistakes->counted)->toBe('wrong');

    $cleanRound = score(scoringState(combo: 2), Verdict::Correct, final: true, chargeFlask: false);
    expect($cleanRound->points)->toBe(15)->and($cleanRound->combo)->toBe(3)->and($cleanRound->counted)->toBe('correct');

    // The third flask can go on a mismatch: the run ends in the middle of the round.
    expect(score(scoringState(flasks: 1), Verdict::Wrong, final: false)->failed)->toBeTrue();
});

it('counts the stars from the flasks lost and gives none to a failed run', function (): void {
    expect(RunScoring::stars(0, false))->toBe(3)
        ->and(RunScoring::stars(1, false))->toBe(2)
        ->and(RunScoring::stars(2, false))->toBe(1)
        ->and(RunScoring::stars(3, false))->toBe(0)
        ->and(RunScoring::stars(4, false))->toBe(0)
        ->and(RunScoring::stars(0, true))->toBe(0);
});

it('beats a boss at 80 % correct without a hint and gives three stars for it', function (): void {
    expect(RunScoring::bossWon(10, 12, 0.8))->toBeTrue()
        ->and(RunScoring::bossWon(9, 12, 0.8))->toBeFalse()
        ->and(RunScoring::bossWon(0, 0, 0.8))->toBeFalse()
        ->and(RunScoring::bossStars(true))->toBe(3)
        ->and(RunScoring::bossStars(false))->toBe(0);
});
