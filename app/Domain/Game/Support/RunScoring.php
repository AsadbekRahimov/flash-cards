<?php

declare(strict_types=1);

namespace App\Domain\Game\Support;

use App\Domain\Game\DTO\RunState;
use App\Domain\Game\DTO\ScoreOutcome;
use App\Domain\Game\DTO\ScoringRules;
use App\Domain\Practice\Enums\Verdict;

/**
 * The score, the combo, the flasks and the stars of a run (docs/18 §12.0, §12.3.2) - pure functions of the state and the verdict,
 * nothing read, nothing written:
 *
 * - a correct answer (no hint): `correct` points, the combo grows; from the `comboFrom`-th correct in a row a bonus of `comboBonus`
 *   per further answer, up to `comboBonusMax` (3rd: +5, 4th: +10, 5th and on: +15);
 * - `typo`: `typo` points, the combo breaks (it is not a clean recall), no flask;
 * - `wrong` / `skipped`: 0 points, the combo breaks, a flask is lost - unless the "second chance" booster is still unused (it spares
 *   the FIRST loss of the run, the verdict stays `wrong` for the counters and the SRS);
 * - an answer after a hint: 0 points, the combo breaks, counted in `hints` whatever the verdict; a correct one costs no flask, a wrong
 *   one does;
 * - a match of pairs before the round ends: nothing (0 points, the combo waits); a MISMATCH costs the round its one flask at the first
 *   one (`$chargeFlask`), the later ones of the round cost nothing more;
 * - the oasis (`$oasis`, the event of this element) gives a flask back AFTER the loss of this answer, if the flasks are below the
 *   maximum; it cannot save a run that has just lost its last flask - `failed` is decided before it;
 * - `failed`: the flasks are out (only a run that has flasks: a boss has `flasksMax = 0` and never fails this way).
 */
final class RunScoring
{
    /**
     * @param  Verdict  $verdict  of this answer (a finished round of pairs: `correct` without mismatches, else `wrong`)
     * @param  bool  $final  the element is done with this answer (false: a match of pairs in the middle of a round)
     * @param  bool  $hinted  the element had a hint before this answer
     * @param  bool  $chargeFlask  a wrong answer of this element costs a flask (false: the round of pairs lost it earlier)
     * @param  bool  $oasis  this element carries the oasis
     */
    public static function apply(RunState $state, Verdict $verdict, bool $final, bool $hinted, bool $chargeFlask, bool $oasis, int $flasksMax, ScoringRules $rules): ScoreOutcome
    {
        $mistake = $verdict === Verdict::Wrong || $verdict === Verdict::Skipped || $verdict === Verdict::Timeout;
        $flasks = $state->flasks;
        $flaskLost = false;
        $secondChance = false;

        if ($mistake && $chargeFlask && $flasksMax > 0) {
            if ($state->secondChanceLeft > 0) {
                $secondChance = true;
            } else {
                $flaskLost = true;
                $flasks = max(0, $flasks - 1);
            }
        }

        if (! $final) {
            // A match in the middle of a round: no points, no combo, no counter; only the flask above (and the run may end on it).
            return new ScoreOutcome(0, $state->combo, $flaskLost, $secondChance, $flasks, false, $flasksMax > 0 && $flasks === 0, ScoreOutcome::COUNTED_NONE);
        }

        if ($hinted) {
            $points = 0;
            $combo = 0;
            $counted = ScoreOutcome::COUNTED_HINT;
        } elseif ($verdict === Verdict::Correct) {
            $combo = $state->combo + 1;
            $points = $rules->correct + self::comboBonus($combo, $rules);
            $counted = ScoreOutcome::COUNTED_CORRECT;
        } elseif ($verdict === Verdict::Typo) {
            $points = $rules->typo;
            $combo = 0;
            $counted = ScoreOutcome::COUNTED_TYPO;
        } else {
            $points = 0;
            $combo = 0;
            $counted = ScoreOutcome::COUNTED_WRONG;
        }

        $failed = $flasksMax > 0 && $flasks === 0;
        $oasisApplied = false;
        if ($oasis && ! $failed && ! $state->oasisUsed && $flasks < $flasksMax) {
            $flasks++;
            $oasisApplied = true;
        }

        return new ScoreOutcome($points, $combo, $flaskLost, $secondChance, $flasks, $oasisApplied, $failed, $counted);
    }

    /** The bonus of the `$combo`-th correct answer in a row: nothing before `comboFrom`, then `comboBonus` more per answer up to the cap. */
    public static function comboBonus(int $combo, ScoringRules $rules): int
    {
        if ($combo < $rules->comboFrom) {
            return 0;
        }

        return min($rules->comboBonusMax, $rules->comboBonus * ($combo - $rules->comboFrom + 1));
    }

    /** The stars of a finished run (docs/18 §12.0): 3 minus the flasks lost (never below 0); a failed run has none. */
    public static function stars(int $flaskLosses, bool $failed): int
    {
        return $failed ? 0 : max(0, 3 - $flaskLosses);
    }

    /** A boss is beaten at `required` of the answers correct WITHOUT a hint (`typo` does not count, docs/18 §12.0). */
    public static function bossWon(int $correct, int $total, float $required): bool
    {
        return $total > 0 && $correct / $total >= $required;
    }

    /** The stars of a boss fight: 3 for a victory, none for a defeat. */
    public static function bossStars(bool $won): int
    {
        return $won ? 3 : 0;
    }
}
