<?php

declare(strict_types=1);

namespace Tests\Support\Cup;

use App\Domain\Cup\DTO\CupDuelOutcome;
use App\Domain\Cup\Services\CupDuelService;
use App\Domain\Practice\Enums\BattleStatus;
use App\Domain\Practice\Enums\PracticeMode;
use App\Models\Battle;
use App\Models\Course;
use App\Models\Cup;
use App\Models\CupDuel;
use App\Models\CupParticipant;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\Practice\BattleKit;
use Tests\Support\Practice\PracticeKit;

/**
 * The world of the duel tests (docs/33 §5.7, D1): a running cup of {@see CupKit} with the duels and the battles switched on, the
 * participants and their learners, the steps of a duel - challenge, accept - and a match played out to a given number of right answers.
 */
final class CupDuelKit
{
    /**
     * A running cup with `$players` participants (members of the chat), the duels switched on, `now` in the first minute of day 1.
     *
     * @return array{cup: Cup, learners: list<Learner>, participants: list<CupParticipant>, start: CarbonImmutable}
     */
    public static function running(int $players = 3): array
    {
        CupKit::open();
        PracticeKit::openAccess();
        config(['cup.duels' => true]);
        if (! Course::query()->where('slug', 'cup-a2')->exists()) {
            CupKit::course();
        }
        ['cup' => $cup, 'learners' => $learners, 'start' => $start] = CupKit::running($players);

        $participants = [];
        foreach ($learners as $learner) {
            $participants[] = CupParticipant::query()->where('cup_id', $cup->id)->where('learner_id', $learner->id)->firstOrFail();
        }

        return ['cup' => $cup, 'learners' => $learners, 'participants' => $participants, 'start' => $start];
    }

    public static function service(): CupDuelService
    {
        return app(CupDuelService::class);
    }

    public static function cup(Cup|int $cup): Cup
    {
        return Cup::query()->with('telegramGroup')->findOrFail($cup instanceof Cup ? $cup->id : $cup);
    }

    public static function challenge(Learner $by, Cup $cup, ?CupParticipant $target = null, bool $public = false, ?CarbonImmutable $now = null, ?string $requestId = null): CupDuelOutcome
    {
        return self::service()->challenge($by, self::cup($cup), $target?->id, $public, PracticeMode::Pairs, $requestId ?? (string) Str::uuid(), $now);
    }

    public static function accept(Learner $by, CupDuel|int $duel, bool $expectRated = true, ?CarbonImmutable $now = null): CupDuel
    {
        return self::service()->accept($by, $duel instanceof CupDuel ? $duel->id : $duel, $expectRated, $now)->duel;
    }

    public static function fresh(CupDuel|int $duel): CupDuel
    {
        return CupDuel::query()->findOrFail($duel instanceof CupDuel ? $duel->id : $duel);
    }

    public static function battleOf(CupDuel|int $duel): Battle
    {
        return BattleKit::fresh((int) self::fresh($duel)->battle_id);
    }

    /**
     * Plays the match of an accepted duel to its end: both ready, five rounds - the challenger right in the first `$challengerRight`
     * rounds, the opponent in the first `$opponentRight` -, each round answered by both and its reveal waited out. The end of the match
     * queues the scoring (sync queue in the tests: it runs at once).
     */
    public static function playOut(CupDuel $duel, Learner $challenger, Learner $opponent, int $challengerRight, int $opponentRight, ?int $stopAfterRound = null): Battle
    {
        $battle = self::battleOf($duel);
        $service = BattleKit::service();
        $service->ready($challenger, $battle->id);
        $service->ready($opponent, $battle->id);
        $battle = BattleKit::fresh($battle);
        $t = CarbonImmutable::instance($battle->starts_at ?? throw new RuntimeException('No countdown.'));
        BattleKit::bothPoll($battle, $challenger, $opponent, $t->toDateTimeString());

        $last = $stopAfterRound ?? 5;
        for ($round = 1; $round <= $last; $round++) {
            $element = BattleKit::element($battle, $round);
            BattleKit::answer($battle, $challenger, $round, $round <= $challengerRight ? BattleKit::correct($element) : BattleKit::wrong($element));
            BattleKit::answer($battle, $opponent, $round, $round <= $opponentRight ? BattleKit::correct($element) : BattleKit::wrong($element));
            if ($round < $last || $stopAfterRound === null) {
                $t = $t->addSeconds(6);
                BattleKit::bothPoll($battle, $challenger, $opponent, $t->toDateTimeString());
            }
        }

        $battle = BattleKit::fresh($battle);
        if ($stopAfterRound === null && $battle->status !== BattleStatus::Finished) {
            throw new RuntimeException("The match did not finish: {$battle->status->value}.");
        }

        return $battle;
    }
}
