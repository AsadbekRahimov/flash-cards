<?php

declare(strict_types=1);

namespace App\Domain\Cup\Services;

use App\Domain\Cup\DTO\CupContext;
use App\Domain\Cup\DTO\CupDuelBoard;
use App\Domain\Cup\DTO\CupDuelView;
use App\Domain\Cup\Enums\CupDuelDmKind;
use App\Domain\Cup\Enums\CupParticipantStatus;
use App\Domain\Cup\Exceptions\CupException;
use App\Domain\Cup\Support\CupAccess;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Exceptions\BattleException;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Jobs\SendCupDuelDmJob;
use App\Models\CupDuel;
use App\Models\CupParticipant;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

/**
 * The requests of the Mini App to the duels of a cup (docs/04 A.23d, docs/33 §11.9.4; D2): the door of the cup first, then the core
 * ({@see CupDuelService}) or the reads ({@see CupDuelReader}), then the DMs of the bot after the commit.
 *
 * The door (`/cups/{id}/duels…`): ONE statement - the cup by its id, its group and my participation ({@see CupReader::locate()}) plus
 * what the route needs (the names of the players, whether I may challenge) - and NO call to Telegram. A missing cup, a cup of a chat I
 * am not in, a participant who left or was removed: one 403 `cup_not_member`, the same body - a duel is a thing between `active`
 * participants, and a stranger learns nothing of the cup or the duel. Then the switch of the chat (pilot: 403 `cup_unavailable`). A
 * duel the participant may not see - missing, of another cup, addressed to somebody else - is one 404 `cup_duel_not_found`. The core
 * asks Telegram about the one who acts (challenge, accept) and decides the rest; its reasons are told to the participant.
 * The switch of the duels (`CUP_DUELS`, the battles, the practice) is answered before the door: `EnsureCupDuels`.
 */
final class CupDuelMiniApp
{
    public function __construct(
        private readonly CupReader $reader,
        private readonly CupDuelReader $duels,
        private readonly CupDuelService $core,
    ) {}

    /**
     * `GET /cups/{id}/duels`: the door with the scalars of the availability, then ONE statement of the list.
     *
     * @throws CupException cup_not_member | cup_unavailable
     */
    public function board(int $cupId, Learner $learner, ?CarbonImmutable $now = null): CupDuelBoard
    {
        $now ??= CarbonImmutable::now();
        $context = $this->door($cupId, $learner, $this->duels->availabilityScalars($now));

        return $this->duels->board($context, $now);
    }

    /**
     * `GET /cups/{id}/duels/{duel}` (the screen of a challenge, the poll of the waiting screen): ONE statement after the door.
     *
     * @throws CupException cup_not_member | cup_unavailable | cup_duel_not_found
     */
    public function show(int $cupId, int $duelId, Learner $learner, ?CarbonImmutable $now = null): CupDuelView
    {
        $now ??= CarbonImmutable::now();
        $context = $this->door($cupId, $learner);

        return $this->duels->find($context, $duelId, $now) ?? throw CupException::duelNotFound();
    }

    /**
     * `POST /cups/{id}/duels`: the core decides; a private challenge to somebody is told to him in a DM.
     *
     * @return array{0: CupDuelView, 1: bool} the duel and whether this call made it
     *
     * @throws CupException see {@see CupDuelService::challenge()}
     * @throws PracticeException practice_mode_disabled | practice_limit_reached
     */
    public function challenge(int $cupId, Learner $learner, ?int $opponentParticipantId, bool $public, PracticeMode $mode, string $requestId, ?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->startOfSecond();
        $context = $this->door($cupId, $learner, $opponentParticipantId === null ? [] : $this->duels->targetScalars($opponentParticipantId));
        $me = $this->participant($context);

        $outcome = $this->core->challenge($learner, $context->cup, $opponentParticipantId, $public, $mode, $requestId, $now);
        $duel = $outcome->duel;
        if ($outcome->created && ! $duel->is_public && $duel->opponent_participant_id !== null) {
            SendCupDuelDmJob::send(CupDuelDmKind::Challenged, $duel->id);
        }

        $names = [$me->id => $me->display_name];
        if ($opponentParticipantId !== null) {
            $names[$opponentParticipantId] = self::string($context->extra['duel_target'] ?? null);
        }

        return [$this->duels->view($duel, $context->cup, $me->id, $names, $now), $outcome->created];
    }

    /**
     * `POST /cups/{id}/duels/{duel}/accept {expect_rated}`: the core opens the match; the challenger is told in a DM - the room waits
     * for him a few seconds only.
     *
     * @return array{0: CupDuelView, 1: bool}
     *
     * @throws CupException see {@see CupDuelService::accept()}
     * @throws BattleException battle_busy | battle_disabled | battle_material_unavailable
     * @throws PracticeException practice_mode_disabled
     */
    public function accept(int $cupId, int $duelId, Learner $learner, bool $expectRated, ?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->startOfSecond();
        [$context, $names] = $this->duelDoor($cupId, $duelId, $learner);

        $outcome = $this->core->accept($learner, $duelId, $expectRated, $now);
        if ($outcome->created) {
            SendCupDuelDmJob::send(CupDuelDmKind::Accepted, $outcome->duel->id);
        }

        return [$this->viewOf($outcome->duel, $context, $names, $now), $outcome->created];
    }

    /**
     * `POST /cups/{id}/duels/{duel}/decline` (the one challenged).
     *
     * @throws CupException cup_not_member | cup_unavailable | cup_duel_not_found | cup_duel_not_recipient | cup_duel_closed
     */
    public function decline(int $cupId, int $duelId, Learner $learner, ?CarbonImmutable $now = null): CupDuelView
    {
        $now = ($now ?? CarbonImmutable::now())->startOfSecond();
        [$context, $names] = $this->duelDoor($cupId, $duelId, $learner);

        return $this->viewOf($this->core->decline($learner, $duelId, $now), $context, $names, $now);
    }

    /**
     * `POST /cups/{id}/duels/{duel}/cancel` (the challenger withdraws).
     *
     * @throws CupException cup_not_member | cup_unavailable | cup_duel_not_found | cup_duel_closed
     */
    public function cancel(int $cupId, int $duelId, Learner $learner, ?CarbonImmutable $now = null): CupDuelView
    {
        $now = ($now ?? CarbonImmutable::now())->startOfSecond();
        [$context, $names] = $this->duelDoor($cupId, $duelId, $learner);

        return $this->viewOf($this->core->cancel($learner, $duelId, $now), $context, $names, $now);
    }

    /**
     * The door of an action on a duel: the cup, my row and the names of the players of the duel IF it is a duel of this cup - one
     * statement. A duel of another cup or none: 404, before the core.
     *
     * @return array{0: CupContext, 1: array{challenger: string, opponent: string|null}}
     *
     * @throws CupException cup_not_member | cup_unavailable | cup_duel_not_found
     */
    private function duelDoor(int $cupId, int $duelId, Learner $learner): array
    {
        $context = $this->door($cupId, $learner, $this->duels->duelScalars($duelId));
        $challenger = $context->extra['duel_challenger'] ?? null;
        if ($challenger === null) {
            throw CupException::duelNotFound();
        }
        $opponent = $context->extra['duel_opponent'] ?? null;

        return [$context, ['challenger' => (string) $challenger, 'opponent' => $opponent === null ? null : (string) $opponent]];
    }

    /** @param array{challenger: string, opponent: string|null} $names */
    private function viewOf(CupDuel $duel, CupContext $context, array $names, CarbonImmutable $now): CupDuelView
    {
        $me = $this->participant($context);
        $byId = [$duel->challenger_participant_id => $names['challenger'], $me->id => $me->display_name];
        if ($duel->opponent_participant_id !== null && $duel->opponent_participant_id !== $me->id) {
            $byId[$duel->opponent_participant_id] = $names['opponent'];
        }

        return $this->duels->view($duel, $context->cup, $me->id, $byId, $now);
    }

    /**
     * The door of the duels (see the class): an `active` participant, without Telegram; the switch of the chat after.
     *
     * @param  array<string, Builder>  $extra
     *
     * @throws CupException cup_not_member | cup_unavailable
     */
    private function door(int $cupId, Learner $learner, array $extra = []): CupContext
    {
        $context = $this->reader->locate($cupId, $learner, $extra);
        if ($context === null || $context->participant === null || $context->participant->status !== CupParticipantStatus::Active) {
            throw CupException::notMember();
        }
        if (! CupAccess::allowsGroup($context->cup->telegramGroup)) {
            throw CupException::unavailable();
        }

        return $context;
    }

    private function participant(CupContext $context): CupParticipant
    {
        return $context->participant ?? throw CupException::notMember();
    }

    private static function string(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
