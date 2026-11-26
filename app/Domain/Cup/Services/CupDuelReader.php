<?php

declare(strict_types=1);

namespace App\Domain\Cup\Services;

use App\Domain\Cup\DTO\CupContext;
use App\Domain\Cup\DTO\CupDuelBoard;
use App\Domain\Cup\DTO\CupDuelView;
use App\Domain\Cup\Enums\CupDuelStatus;
use App\Domain\Cup\Enums\CupParticipantStatus;
use App\Domain\Cup\Enums\CupStatus;
use App\Domain\Cup\Support\CupAccess;
use App\Domain\Cup\Support\CupClock;
use App\Domain\Engagement\Enums\FriendshipStatus;
use App\Models\Cup;
use App\Models\CupDuel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The reads of the duels of a cup for the Mini App (docs/04 A.23d, docs/33 §11.9.4; D2). No rule lives here: the challenge, the
 * acceptance and the rest are {@see CupDuelService}; this reads what it wrote, by the indexes of the core (docs/33 §11.9.3).
 *
 *  - {@see board()} - `GET /cups/{id}/duels` in ONE statement: the ids of four index walks (`UNION`) - the open challenges to me
 *    (`opponent_participant_id`), the open challenges to anybody in the cup (`(cup_id, day)` of today and yesterday - a challenge lives
 *    10 minutes and may cross the midnight), my open challenge (partial unique `open_challenger_id`), my duels of today (`(cup_id, day)`)
 *    - with the names of both players (PK of `cup_participants`);
 *  - {@see find()} - one duel (`GET /cups/{id}/duels/{duel}`, the poll of the waiting screen) in ONE statement, by its PK;
 *  - the scalars a route adds to the statement of its door ({@see CupReader::locate()}): whether I may challenge today
 *    ({@see availabilityScalars()}, the table and the list - no statement of its own), the names of the players of the duel the route
 *    acts on ({@see duelScalars()}) and of the participant challenged ({@see targetScalars()}) - the answer of an action needs no read.
 * The emoji of Pro come from the cache of the table ({@see CupProEmoji::forCup()}: one statement cold, none warm).
 *
 * @phpstan-type Availability array{day: int|null, can_challenge: bool, rated_available: bool, challenges_left: int, open_duel_id: int|null}
 */
final class CupDuelReader
{
    /** The rows of one list at most: open ones ≤ one per participant, mine of a day ≤ `daily_challenges` + the accepted. */
    private const LIST_LIMIT = 200;

    /**
     * The duel, the names of its players (the PK of the duel, the PKs of the participants) and the rated slots of TODAY of the two who
     * would play it if the viewer accepted it now - the challenger and the addressee, or the viewer for an open challenge (unique
     * `(participant_id, day)`, two Index Only Scans): `rated_for_me`. Bindings: today's day, the viewer, today's day.
     */
    private const SELECT = 'SELECT d.*, c.display_name AS x_challenger_name, o.display_name AS x_opponent_name,
            (SELECT COUNT(*) FROM cup_duel_slots xs WHERE xs.participant_id = d.challenger_participant_id AND xs.day = ?) AS x_challenger_slot,
            (SELECT COUNT(*) FROM cup_duel_slots xa WHERE xa.participant_id = COALESCE(d.opponent_participant_id, ?) AND xa.day = ?) AS x_acceptor_slot
        FROM cup_duels d
        JOIN cup_participants c ON c.id = d.challenger_participant_id
        LEFT JOIN cup_participants o ON o.id = d.opponent_participant_id';

    public function __construct(private readonly CupProEmoji $emoji) {}

    /**
     * Whether the participant may challenge today - scalars of the door's statement (refer to `cups` and `cp`): today's day of the cup
     * (unique `(cup_id, day)` of `cup_days`), my rated slot of that day (unique `(participant_id, day)`), my challenges of that day
     * (`cup_duels (cup_id, day)`), my open challenge (partial unique `open_challenger_id`).
     *
     * @return array<string, Builder>
     */
    public function availabilityScalars(CarbonImmutable $now): array
    {
        $at = $now->format('Y-m-d H:i:s');
        $day = static fn (): Builder => DB::table('cup_days as xdd')->select('xdd.day')
            ->whereColumn('xdd.cup_id', 'cups.id')->where('xdd.starts_at', '<=', $at)->where('xdd.ends_at', '>', $at)->limit(1);

        return [
            'duel_day' => $day(),
            'duel_slot' => DB::table('cup_duel_slots as xds')->selectRaw('count(*)')->whereColumn('xds.participant_id', 'cp.id')->where('xds.day', '=', $day()),
            'duel_sent' => DB::table('cup_duels as xdm')->selectRaw('count(*)')
                ->whereColumn('xdm.cup_id', 'cups.id')->where('xdm.day', '=', $day())->whereColumn('xdm.challenger_participant_id', 'cp.id'),
            'duel_open_id' => DB::table('cup_duels as xdo')->select('xdo.id')->whereColumn('xdo.open_challenger_id', 'cp.id')->limit(1),
            'duel_open_expires_at' => DB::table('cup_duels as xde')->select('xde.expires_at')->whereColumn('xde.open_challenger_id', 'cp.id')->limit(1),
        ];
    }

    /**
     * The names of the players of the duel `$duelId` IF it is a duel of this cup (`duel_challenger` NULL - no such duel here) - scalars
     * of the door's statement, by the PK of the duel and of the participants.
     *
     * @return array<string, Builder>
     */
    public function duelScalars(int $duelId): array
    {
        $name = static fn (string $column): Builder => DB::table('cup_duels as xd')
            ->join('cup_participants as xp', 'xp.id', '=', "xd.{$column}")
            ->whereColumn('xd.cup_id', 'cups.id')->where('xd.id', $duelId)->select('xp.display_name')->limit(1);

        return ['duel_challenger' => $name('challenger_participant_id'), 'duel_opponent' => $name('opponent_participant_id')];
    }

    /**
     * The name of the participant challenged (a participant of THIS cup) - a scalar of the door's statement, by the PK.
     *
     * @return array<string, Builder>
     */
    public function targetScalars(int $participantId): array
    {
        return ['duel_target' => DB::table('cup_participants as xt')->select('xt.display_name')
            ->where('xt.id', $participantId)->whereColumn('xt.cup_id', 'cups.id')->limit(1)];
    }

    /**
     * May the participant of the context challenge now (the button of the table and of the list) - from {@see availabilityScalars()},
     * no query. Null when the context was read without them or there is no participant. The core decides again at the challenge; this is
     * what the screen shows before it.
     *
     *  - `day` - today's day of a cup that runs (null otherwise);
     *  - `can_challenge` - the cup runs and has a day, is not paused, the participant is `active`, has no open challenge and has
     *    challenges left of the day;
     *  - `rated_available` - his rated duel of the day is not played yet (the slot is free): a challenge offers a rated duel unless the
     *    one challenged has played his;
     *  - `challenges_left` - of `cup.duel.daily_challenges`;
     *  - `open_duel_id` - his open challenge (its time not over), the screen shows it instead of a new one.
     *
     * @return Availability|null
     */
    public function availability(CupContext $context, CarbonImmutable $now): ?array
    {
        $me = $context->participant;
        $x = $context->extra;
        if ($me === null || ! array_key_exists('duel_day', $x)) {
            return null;
        }

        $cup = $context->cup;
        $group = $cup->telegramGroup;
        $day = $x['duel_day'] === null ? null : (int) $x['duel_day'];
        $playable = $day !== null
            && $cup->status === CupStatus::Running
            && $me->status === CupParticipantStatus::Active
            && CupAccess::allowsGroup($group)
            && CupAccess::pauseReason($group) === null;

        $openId = null;
        if ($x['duel_open_id'] !== null && $x['duel_open_expires_at'] !== null
            && CarbonImmutable::parse((string) $x['duel_open_expires_at'], 'UTC')->gt($now)) {
            $openId = (int) $x['duel_open_id'];
        }
        $daily = max(1, (int) config('cup.duel.daily_challenges', 20));
        $left = $playable ? max(0, $daily - (int) $x['duel_sent']) : 0;

        return [
            'day' => $playable ? $day : null,
            'can_challenge' => $playable && $openId === null && $left > 0,
            'rated_available' => $playable && (int) $x['duel_slot'] === 0,
            'challenges_left' => $left,
            'open_duel_id' => $openId,
        ];
    }

    /**
     * `GET /cups/{id}/duels` for an active participant: ONE statement (see the class). An open challenge whose time is over (the sweeper
     * has not come yet) is not shown anywhere.
     */
    public function board(CupContext $context, CarbonImmutable $now): CupDuelBoard
    {
        $cup = $context->cup;
        $me = $context->participant->id ?? throw new \LogicException('The duels of a cup are read for a participant.');
        $at = $now->format('Y-m-d H:i:s');
        $open = CupDuelStatus::Open->value;

        $branches = [
            'SELECT id FROM cup_duels WHERE opponent_participant_id = ? AND status = ? AND expires_at > ?',
            'SELECT id FROM cup_duels WHERE open_challenger_id = ?',
        ];
        $bindings = [$me, $open, $at, $me];
        $day = $this->currentDay($cup, $now);
        if ($day !== null) {
            $days = [max(1, $day - 1), $day];
            $branches[] = 'SELECT id FROM cup_duels WHERE cup_id = ? AND day IN (?, ?) AND status = ? AND opponent_participant_id IS NULL AND expires_at > ?';
            array_push($bindings, $cup->id, ...$days);
            array_push($bindings, $open, $at);
            $branches[] = 'SELECT id FROM cup_duels WHERE cup_id = ? AND day IN (?, ?) AND (challenger_participant_id = ? OR opponent_participant_id = ?)
                AND (day = ? OR accepted_day = ?)';
            array_push($bindings, $cup->id, ...$days);
            array_push($bindings, $me, $me, $day, $day);
        }

        // An open challenge to anybody by a learner who blocked me or whom I blocked (`friendships`, either side) is not shown (docs/33
        // §11.9.2 п. 4: a block makes the duel unavailable, without a reason): the unique `(learner_id, friend_id)` of the pair, per
        // open row of another - in the same statement.
        $viewer = (int) ($context->participant->learner_id ?? 0);
        $rows = DB::select(
            self::SELECT.' WHERE d.id IN ('.implode(' UNION ', $branches).')
                AND (d.status <> ? OR d.opponent_participant_id IS NOT NULL OR d.challenger_participant_id = ? OR NOT EXISTS (
                    SELECT 1 FROM friendships f
                    WHERE f.learner_id = CASE WHEN c.learner_id < ? THEN c.learner_id ELSE ? END
                      AND f.friend_id = CASE WHEN c.learner_id < ? THEN ? ELSE c.learner_id END
                      AND f.status = ?))
                ORDER BY d.id DESC LIMIT ?',
            [
                ...self::slotBindings($day, $me), ...$bindings,
                $open, $me, $viewer, $viewer, $viewer, $viewer, FriendshipStatus::Blocked->value,
                self::LIST_LIMIT,
            ],
        );

        $incoming = $openToAll = $today = [];
        $mine = null;
        $views = $this->views($rows, $cup, $me, $day, $now);
        foreach ($views as $view) {
            $duel = $view->duel;
            if ($duel->status !== CupDuelStatus::Open) {
                if ($view->role() !== null) {
                    $today[] = $view;
                }

                continue;
            }
            if ($duel->expires_at->lte($now)) {
                continue;
            }
            match (true) {
                $duel->challenger_participant_id === $me => $mine = $view,
                $duel->opponent_participant_id === $me => $incoming[] = $view,
                $duel->opponent_participant_id === null => $openToAll[] = $view,
                default => null,
            };
        }

        return new CupDuelBoard($incoming, $openToAll, $mine, $today, $this->availability($context, $now), $now);
    }

    /**
     * One duel of the cup as the participant may see it (ONE statement, the PK of the duel): a player of it; any participant for a
     * public challenge (it is in the chat) and for an open challenge to anybody. Null otherwise - the same 404 as a missing duel.
     */
    public function find(CupContext $context, int $duelId, CarbonImmutable $now): ?CupDuelView
    {
        $me = $context->participant->id ?? throw new \LogicException('The duels of a cup are read for a participant.');
        $day = $this->currentDay($context->cup, $now);
        $rows = DB::select(self::SELECT.' WHERE d.id = ? AND d.cup_id = ?', [...self::slotBindings($day, $me), $duelId, $context->cup->id]);
        $view = $this->views($rows, $context->cup, $me, $day, $now)[0] ?? null;
        if ($view === null) {
            return null;
        }
        $duel = $view->duel;
        $visible = $view->role() !== null
            || $duel->is_public
            || ($duel->opponent_participant_id === null && $duel->status === CupDuelStatus::Open);

        return $visible ? $view : null;
    }

    /**
     * The answer of an action (challenge, accept, decline, cancel) on the duel the core gave back: the names come from the door's
     * statement (`$names`, participant id => name); a name it could not know (a repeated `request_id` with another body) costs one read.
     *
     * @param  array<int, string|null>  $names
     */
    public function view(CupDuel $duel, Cup $cup, int $viewerParticipantId, array $names, CarbonImmutable $now): CupDuelView
    {
        $ids = array_values(array_filter([$duel->challenger_participant_id, $duel->opponent_participant_id]));
        $missing = array_values(array_filter($ids, static fn (int $id): bool => ($names[$id] ?? null) === null));
        if ($missing !== []) {
            foreach (DB::table('cup_participants')->whereIn('id', $missing)->get(['id', 'display_name']) as $row) {
                $names[(int) $row->id] = (string) $row->display_name;
            }
        }
        $emoji = $this->emoji->forCup($cup, $now);
        $opponent = $duel->opponent_participant_id;

        return new CupDuelView(
            duel: $duel,
            challengerName: (string) ($names[$duel->challenger_participant_id] ?? ''),
            opponentName: $opponent === null ? null : (string) ($names[$opponent] ?? ''),
            challengerEmoji: $emoji[$duel->challenger_participant_id] ?? null,
            opponentEmoji: $opponent === null ? null : ($emoji[$opponent] ?? null),
            viewerParticipantId: $viewerParticipantId,
            now: $now,
        );
    }

    /** @return list<int> the bindings of the slots of {@see SELECT}: today's day (0 - none: no slot matches), the viewer, the day */
    private static function slotBindings(?int $day, int $viewer): array
    {
        return [$day ?? 0, $viewer, $day ?? 0];
    }

    /**
     * The kind of the duel for the viewer (docs/04 A.23d `rated_for_me`): an open challenge is rated if neither of the two who would
     * play it on his acceptance has played the rated duel of today - what the acceptance decides again (`expect_rated`); without a day
     * of the cup (not running) - what the challenge offered. Null for a duel that is not open: the resource shows `rated` /
     * `rated_offered`.
     *
     * @param  array<string, mixed>  $values
     */
    private static function ratedForMe(CupDuel $duel, array $values, ?int $day): ?bool
    {
        if ($duel->status !== CupDuelStatus::Open) {
            return null;
        }
        if ($day === null) {
            return $duel->rated_offered;
        }

        return (int) ($values['x_challenger_slot'] ?? 0) === 0 && (int) ($values['x_acceptor_slot'] ?? 0) === 0;
    }

    /**
     * @param  array<int, \stdClass>  $rows
     * @return list<CupDuelView>
     */
    private function views(array $rows, Cup $cup, int $viewer, ?int $day, CarbonImmutable $now): array
    {
        if ($rows === []) {
            return [];
        }
        $emoji = $this->emoji->forCup($cup, $now);

        $views = [];
        foreach ($rows as $row) {
            $values = (array) $row;
            $challengerName = (string) $values['x_challenger_name'];
            $opponentName = $values['x_opponent_name'] === null ? null : (string) $values['x_opponent_name'];
            $slots = ['x_challenger_slot' => $values['x_challenger_slot'] ?? 0, 'x_acceptor_slot' => $values['x_acceptor_slot'] ?? 0];
            unset($values['x_challenger_name'], $values['x_opponent_name'], $values['x_challenger_slot'], $values['x_acceptor_slot']);
            /** @var CupDuel $duel */
            $duel = (new CupDuel)->newFromBuilder($values);
            $opponent = $duel->opponent_participant_id;
            $views[] = new CupDuelView(
                duel: $duel,
                challengerName: $challengerName,
                opponentName: $opponentName,
                challengerEmoji: $emoji[$duel->challenger_participant_id] ?? null,
                opponentEmoji: $opponent === null ? null : ($emoji[$opponent] ?? null),
                viewerParticipantId: $viewer,
                now: $now,
                ratedForMe: self::ratedForMe($duel, $slots, $day),
            );
        }

        return $views;
    }

    /** Today's day of a running cup (1…days), or null. No query: the clock of the cup ({@see CupClock}), as the core decides it. */
    private function currentDay(Cup $cup, CarbonImmutable $now): ?int
    {
        if ($cup->status !== CupStatus::Running || $cup->starts_at === null) {
            return null;
        }
        $day = CupClock::dayNumber($cup->starts_at, $cup->timezone, $now);

        return $day >= 1 && $day <= $cup->days ? $day : null;
    }
}
