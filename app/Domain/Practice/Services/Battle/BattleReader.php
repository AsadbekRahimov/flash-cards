<?php

declare(strict_types=1);

namespace App\Domain\Practice\Services\Battle;

use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Engagement\Exceptions\FriendshipException;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Practice\DTO\BattleListView;
use App\Domain\Practice\DTO\BattleOptionsView;
use App\Domain\Practice\DTO\BattlePollView;
use App\Domain\Practice\DTO\BattleView;
use App\Domain\Practice\DTO\ElementState;
use App\Domain\Practice\DTO\LocaleSet;
use App\Domain\Practice\DTO\MaterialScope;
use App\Domain\Practice\DTO\ModeAvailability;
use App\Domain\Practice\DTO\RoundStateView;
use App\Domain\Practice\DTO\RoundView;
use App\Domain\Practice\DTO\Snapshot;
use App\Domain\Practice\DTO\SnapshotElement;
use App\Domain\Practice\Enums\BattlePhase;
use App\Domain\Practice\Enums\BattleStatus;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Enums\Verdict;
use App\Domain\Practice\Exceptions\BattleException;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Domain\Practice\Services\Modes\ModeRegistry;
use App\Domain\Practice\Services\PracticeAccess;
use App\Domain\Practice\Services\PracticeMaterialService;
use App\Domain\Practice\Support\IsoTime;
use App\Models\Battle;
use App\Models\BattleAnswer;
use App\Models\BattleParticipant;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * The read models of the battle (docs/16 §1.3, §7.3, §7.4): find / view / poll / round / list / options - the DTOs the
 * resources of the API return. A read, with two side effects a read of a match is allowed (docs/16 §8.3, §8.4):
 *
 *  - the presence of the viewer: any request of a participant to a live match is a heartbeat (`BattleClock::touch`, one
 *    conditional statement at most once per `heartbeat_write_seconds`, outside any transaction);
 *  - a transition that is due (`BattleClock::isDue`, a pure function of the row read without a lock) is made right here, under
 *    `FOR UPDATE` of the row - whoever looks first moves the match; a poll that finds nothing due takes no lock at all.
 *
 * The texts of the viewer are those of their side of the snapshot (`battle_participants.locale`); the opponent is a first
 * name. The reviews (`round`) come from the handler of the mode (`reveal`) and the stored answers - never before the round
 * is resolved.
 */
final class BattleReader
{
    public const AUDIO_ROUTE = 'twa.practice.battles.audio';

    public function __construct(
        private readonly PracticeAccess $access,
        private readonly ModeRegistry $modes,
        private readonly BattleClock $clock,
        private readonly BattleSettings $settings,
        private readonly BattleFriends $friends,
        private readonly PracticeMaterialService $material,
        private readonly CourseCatalogService $catalog,
        private readonly LocaleResolver $locales,
    ) {}

    /**
     * The battle of the viewer by id (participants loaded); somebody else's looks like a missing one.
     *
     * @throws BattleException battle_not_found
     * @throws PracticeException practice_unavailable | practice_pro_required
     */
    public function find(Learner $viewer, int $id): Battle
    {
        $this->access->assertCanPractice($viewer);

        /** @var Battle|null $battle */
        $battle = Battle::query()->with('participants')->whereKey($id)->first();
        if ($battle === null || ! $battle->involves($viewer->id)) {
            throw BattleException::notFound();
        }

        return $battle;
    }

    /** The full view of docs/16 §7.4 (`GET /practice/battles/{id}` and the answer of every action). */
    public function view(Battle $battle, Learner $viewer, CarbonImmutable $now): BattleView
    {
        $battle = $this->settle($battle, $viewer, $now);
        $me = $battle->participant($viewer->id);
        $other = $battle->participant($battle->otherId($viewer->id));
        $snapshot = Snapshot::fromArray($battle->snapshot);
        $terminal = $battle->status->isTerminal();

        return new BattleView(
            id: $battle->id,
            status: $battle->status->value,
            endReason: $battle->end_reason?->value,
            mode: $battle->mode->value,
            role: $me->role->value,
            course: ['slug' => $snapshot->courseSlug, 'title' => $snapshot->courseTitleFor($me->locale)],
            lesson: $this->lessonOf($battle, $snapshot),
            opponent: ['first_name' => $this->firstName($other->learner_id)],
            roundsTotal: $this->settings->rounds(),
            roundSeconds: $this->settings->roundSeconds($battle->mode),
            countdownSeconds: $this->settings->countdownSeconds(),
            revealSeconds: $this->settings->revealSeconds(),
            presenceGraceSeconds: $this->settings->presenceGraceSeconds(),
            deadlineGraceMs: $this->settings->deadlineGraceMs(),
            inviteExpiresAt: $battle->invite_expires_at,
            roomExpiresAt: $battle->room_expires_at,
            startsAt: $battle->starts_at,
            version: $battle->state_version,
            serverNow: $now,
            ready: ['me' => $me->ready_at !== null, 'opponent' => $other->ready_at !== null],
            opponentPresent: $this->present($battle, $other, $now),
            opponentAbsentSince: $battle->status->isLive() ? $this->clock->absentSince($battle, $other, $now) : null,
            round: $this->roundState($battle, $me, $other),
            scores: ['me' => $me->score, 'opponent' => $other->score],
            result: $terminal ? $this->result($battle, $me, $other) : null,
            rematch: $terminal ? $this->rematch($battle, $viewer) : null,
            friendshipId: $battle->friendship_id,
            audioPreload: $this->audioPreload($battle, $snapshot),
        );
    }

    /** The poll of docs/16 §7.4: the heartbeat, the transition that is due, a small answer. */
    public function poll(Battle $battle, Learner $viewer, CarbonImmutable $now): BattlePollView
    {
        return $this->pollView($this->settle($battle, $viewer, $now), $viewer, $now);
    }

    /** The poll of a battle as it is in memory (no query for a match that is on; the rematch of one that is over). */
    public function pollView(Battle $battle, Learner $viewer, CarbonImmutable $now): BattlePollView
    {
        $me = $battle->participant($viewer->id);
        $other = $battle->participant($battle->otherId($viewer->id));

        return new BattlePollView(
            id: $battle->id,
            status: $battle->status->value,
            endReason: $battle->end_reason?->value,
            version: $battle->state_version,
            serverNow: $now,
            ready: ['me' => $me->ready_at !== null, 'opponent' => $other->ready_at !== null],
            opponentPresent: $this->present($battle, $other, $now),
            opponentAbsentSince: $battle->status->isLive() ? $this->clock->absentSince($battle, $other, $now) : null,
            round: $this->roundState($battle, $me, $other),
            scores: ['me' => $me->score, 'opponent' => $other->score],
            rematch: $battle->status->isTerminal() ? $this->rematch($battle, $viewer) : null,
        );
    }

    /**
     * A round (docs/16 §7.4): the element in the viewer's language; the review once the round is resolved. Rounds that have
     * not started are 409 `round_not_open` (the countdown shows round 1 without a phase, so a client can draw the board early).
     *
     * @throws BattleException round_not_open
     */
    public function round(Battle $battle, Learner $viewer, int $no, CarbonImmutable $now): RoundView
    {
        $battle = $this->settle($battle, $viewer, $now);
        if ($no < 1 || $no > $battle->current_round) {
            throw BattleException::roundNotOpen($no, $battle->phase);
        }

        $me = $battle->participant($viewer->id);
        $other = $battle->participant($battle->otherId($viewer->id));
        $snapshot = Snapshot::fromArray($battle->snapshot);
        $element = $snapshot->at($no - 1) ?? throw BattleException::roundNotOpen($no, $battle->phase);
        $handler = $this->modes->for($battle->mode);

        $current = $no === $battle->current_round;
        $resolved = $no <= $this->clock->roundsPlayed($battle);
        $reveal = null;
        if ($resolved) {
            $review = $handler->reveal($element, $me->locale, ElementState::open());
            $translation = $review['translation'] ?? null;
            $explanation = $review['explanation'] ?? null;
            unset($review['translation'], $review['explanation']);

            /** @var Collection<int, BattleAnswer> $answers */
            $answers = BattleAnswer::query()->where('battle_id', $battle->id)->where('round', $no)->get();
            $reveal = [
                'correct' => $review,
                'me' => $this->side($answers, $me->learner_id),
                'opponent' => $this->side($answers, $other->learner_id),
                'explanation' => is_string($explanation) ? $explanation : null,
                'translation' => is_string($translation) ? $translation : null,
            ];
        }

        return new RoundView(
            no: $no,
            phase: $resolved ? BattlePhase::Reveal->value : ($current ? $battle->phase?->value : null),
            deadlineAt: $current ? $battle->round_deadline_at : null,
            phaseEndsAt: $current ? $battle->phase_ends_at : null,
            serverNow: $now,
            element: $handler->project($element, $me->locale, ElementState::open()->withContext($this->audioUrl($battle, $element), 0)),
            reveal: $reveal,
        );
    }

    /**
     * The battles of the viewer (docs/16 §7.3): the match they are busy with (an invitation of their own that has not
     * expired, a room, a countdown, a game), the invitations to them, the last matches that are over.
     *
     * @throws PracticeException practice_unavailable | practice_pro_required
     */
    public function list(Learner $viewer, CarbonImmutable $now): BattleListView
    {
        $this->access->assertCanPractice($viewer);

        $active = null;
        $activeId = BattleParticipant::query()->where('active_learner_id', $viewer->id)->value('battle_id');
        if ($activeId !== null) {
            /** @var Battle|null $mine */
            $mine = Battle::query()->with('participants')->whereKey((int) $activeId)->first();
            if ($mine !== null) {
                $mine = $this->settle($mine, $viewer, $now, touch: false);
                $active = $mine->status->isTerminal() ? null : $this->compact($mine, $viewer);
            }
        }

        /** @var Collection<int, Battle> $incoming */
        $incoming = Battle::query()->with('participants')
            ->where('opponent_id', $viewer->id)
            ->where('status', BattleStatus::Invited->value)
            ->where('invite_expires_at', '>', $now)
            ->orderByDesc('id')
            ->get();

        /** @var Collection<int, Battle> $finished */
        $finished = Battle::query()->with('participants')
            ->where(fn (Builder $mine) => $mine->where('challenger_id', $viewer->id)->orWhere('opponent_id', $viewer->id))
            ->whereIn('status', [BattleStatus::Finished->value, BattleStatus::Closed->value])
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->limit($this->settings->finishedList())
            ->get();

        $names = $this->firstNames([...$incoming->all(), ...$finished->all()], $viewer);

        $incomingRows = [];
        foreach ($incoming as $battle) {
            $snapshot = Snapshot::fromArray($battle->snapshot);
            $incomingRows[] = [
                'id' => $battle->id,
                'mode' => $battle->mode->value,
                'from' => ['first_name' => $names[$battle->challenger_id] ?? null],
                'course' => ['slug' => $snapshot->courseSlug, 'title' => $snapshot->courseTitleFor($battle->participant($viewer->id)->locale)],
                'lesson' => $this->lessonOf($battle, $snapshot),
                'invite_expires_at' => IsoTime::format($battle->invite_expires_at),
                'rematch' => $battle->rematch_of_id !== null,
            ];
        }

        $finishedRows = [];
        foreach ($finished as $battle) {
            $me = $battle->participant($viewer->id);
            $other = $battle->participant($battle->otherId($viewer->id));
            $finishedRows[] = [
                'id' => $battle->id,
                'mode' => $battle->mode->value,
                'opponent' => ['first_name' => $names[$other->learner_id] ?? null],
                'outcome' => $this->outcome($battle, $viewer),
                'scores' => ['me' => $me->score, 'opponent' => $other->score],
                'finished_at' => IsoTime::format($battle->finished_at ?? $battle->updated_at ?? $now),
            ];
        }

        return new BattleListView($active, $incomingRows, $finishedRows);
    }

    /**
     * What the viewer may invite a friend to (docs/16 §7.3): each format with its state for the material of both players' languages.
     *
     * @throws BattleException battle_disabled | not_friends
     * @throws FriendshipException friendship_not_found
     * @throws PracticeException practice_unavailable | practice_pro_required | course_not_found | lesson_not_found | lesson_not_in_course
     */
    public function options(Learner $viewer, int $friendshipId, string $courseSlug, ?int $lessonId): BattleOptionsView
    {
        $this->access->assertCanPractice($viewer);
        $this->access->assertBattleEnabled();

        [$friend] = $this->friends->byFriendship($viewer, $friendshipId);
        $course = $this->material->courseForBattle($courseSlug);
        $lesson = $lessonId === null ? null : $this->material->lesson($course, $lessonId);

        $viewerLocale = $this->locales->forLearner($viewer);
        $locales = new LocaleSet([$viewerLocale, $this->locales->forLearner($friend)]);
        $availability = $this->material->availability(new MaterialScope($course, $lesson), null, $locales);

        $modes = [];
        foreach (PracticeMode::cases() as $mode) {
            $report = $availability[$mode->value] ?? ModeAvailability::unavailable($this->settings->rounds());
            $modes[] = [
                'mode' => $mode->value,
                'state' => match (true) {
                    ! $mode->isEnabled() => 'disabled',
                    $report->isAvailable => 'available',
                    default => 'not_enough',
                },
                'available' => $report->available,
                'required' => $report->required,
                'reason' => $report->reason,
                'round_seconds' => $this->settings->roundSeconds($mode),
            ];
        }

        return new BattleOptionsView(
            friend: ['first_name' => $friend->first_name],
            course: ['slug' => $course->slug, 'title' => $this->catalog->title($course, $viewerLocale)],
            lesson: $lesson === null ? null : ['id' => $lesson->id, 'stage' => $lesson->stage?->number, 'number' => $lesson->number, 'title' => $lesson->title],
            modes: $modes,
            friendAccess: $this->access->state($friend)->isAvailable() ? 'available' : 'no_access',
        );
    }

    // ------------------------------------------------------------------ the pieces

    /**
     * The heartbeat of the viewer (a live match) and the transition that is due, if any - made under the lock, from a fresh
     * read. Returns the battle as it is after that.
     */
    private function settle(Battle $battle, Learner $viewer, CarbonImmutable $now, bool $touch = true): Battle
    {
        if ($touch && $battle->status->isLive()) {
            $this->clock->touch($battle, $battle->participant($viewer->id), $now);
        }
        if (! $this->clock->isDue($battle, $now)) {
            return $battle;
        }

        return DB::transaction(function () use ($battle, $now): Battle {
            /** @var Battle|null $locked */
            $locked = Battle::query()->whereKey($battle->id)->lockForUpdate()->first();
            if ($locked === null) {
                return $battle;
            }
            $locked->load('participants');
            $this->clock->advance($locked, $now);

            return $locked;
        });
    }

    private function present(Battle $battle, BattleParticipant $other, CarbonImmutable $now): bool
    {
        return ! $battle->status->isLive() || ! $this->clock->isAbsent($battle, $other, $now);
    }

    /** The round the match is in: a countdown (no phase yet) or a game; null otherwise. */
    private function roundState(Battle $battle, BattleParticipant $me, BattleParticipant $other): ?RoundStateView
    {
        if (($battle->status !== BattleStatus::Countdown && $battle->status !== BattleStatus::Playing) || $battle->current_round < 1) {
            return null;
        }

        $answered = $battle->meta[BattleClock::META_ANSWERED] ?? [];

        return new RoundStateView(
            no: $battle->current_round,
            phase: $battle->phase?->value,
            deadlineAt: $battle->round_deadline_at,
            phaseEndsAt: $battle->phase_ends_at,
            myAnswered: is_array($answered) && (int) ($answered[$me->role->value] ?? 0) >= $battle->current_round,
            opponentAnswered: is_array($answered) && (int) ($answered[$other->role->value] ?? 0) >= $battle->current_round,
        );
    }

    /**
     * The result of a match that is over: the outcome for the viewer, the reason, the rounds that were resolved.
     *
     * @return array{outcome: string, end_reason: string|null, rounds: list<array{no: int, me: array{verdict: string, points: int}, opponent: array{verdict: string, points: int}}>}
     */
    private function result(Battle $battle, BattleParticipant $me, BattleParticipant $other): array
    {
        $played = $this->clock->roundsPlayed($battle);
        $rounds = [];
        if ($played > 0) {
            /** @var Collection<int, BattleAnswer> $answers */
            $answers = BattleAnswer::query()->where('battle_id', $battle->id)->where('round', '<=', $played)->orderBy('round')->get();
            for ($no = 1; $no <= $played; $no++) {
                $ofRound = $answers->filter(fn (BattleAnswer $answer): bool => $answer->round === $no);
                $mine = $this->side($ofRound, $me->learner_id);
                $theirs = $this->side($ofRound, $other->learner_id);
                $rounds[] = [
                    'no' => $no,
                    'me' => ['verdict' => $mine['verdict'], 'points' => $mine['points']],
                    'opponent' => ['verdict' => $theirs['verdict'], 'points' => $theirs['points']],
                ];
            }
        }

        return ['outcome' => $this->outcome($battle, $me->learner_id), 'end_reason' => $battle->end_reason?->value, 'rounds' => $rounds];
    }

    /** won | lost | draw for a finished match, none for a closed one. */
    private function outcome(Battle $battle, Learner|int $viewer): string
    {
        $viewerId = $viewer instanceof Learner ? $viewer->id : $viewer;

        return match (true) {
            $battle->status !== BattleStatus::Finished => 'none',
            $battle->winner_learner_id === null => 'draw',
            $battle->winner_learner_id === $viewerId => 'won',
            default => 'lost',
        };
    }

    /**
     * One player's side of a resolved round: the payload as accepted (null for a time-out or a row that is not there), the
     * verdict, the points.
     *
     * @param  Collection<int, BattleAnswer>  $answers
     * @return array{answer: array<string, mixed>|null, verdict: string, points: int}
     */
    private function side(Collection $answers, int $learnerId): array
    {
        $row = $answers->first(fn (BattleAnswer $answer): bool => $answer->learner_id === $learnerId);
        if ($row === null) {
            return ['answer' => null, 'verdict' => Verdict::Timeout->value, 'points' => 0];
        }

        return [
            'answer' => $row->verdict === Verdict::Timeout ? null : $row->payload,
            'verdict' => $row->verdict->value,
            'points' => $row->points,
        ];
    }

    /**
     * The nearest battle offered as a rematch of this one: who offered it, where it stands.
     *
     * @return array{battle_id: int, status: string, by_me: bool}|null
     */
    private function rematch(Battle $battle, Learner $viewer): ?array
    {
        /** @var Battle|null $rematch */
        $rematch = Battle::query()->select(['id', 'status', 'challenger_id'])->where('rematch_of_id', $battle->id)->orderByDesc('id')->first();

        return $rematch === null ? null : ['battle_id' => $rematch->id, 'status' => $rematch->status->value, 'by_me' => $rematch->challenger_id === $viewer->id];
    }

    /** @return array{id: int, stage: int|null, number: int|null, title: string|null}|null */
    private function lessonOf(Battle $battle, Snapshot $snapshot): ?array
    {
        if ($battle->lesson_id === null) {
            return null;
        }

        return ['id' => $battle->lesson_id, 'stage' => $snapshot->lessonStage, 'number' => $snapshot->lessonNumber, 'title' => $snapshot->lessonTitle];
    }

    /**
     * The clips of the five rounds for the room / countdown of a dictation (docs/16 §10.3); null for any other case.
     *
     * @return list<string>|null
     */
    private function audioPreload(Battle $battle, Snapshot $snapshot): ?array
    {
        if ($battle->mode !== PracticeMode::Dictation || ($battle->status !== BattleStatus::Room && $battle->status !== BattleStatus::Countdown)) {
            return null;
        }

        $urls = [];
        foreach ($snapshot->elements as $element) {
            $url = $this->audioUrl($battle, $element);
            if ($url !== null) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /** The URL of the audio endpoint of an element with a clip (the route of 18.6b when it is registered, else its documented path). */
    private function audioUrl(Battle $battle, SnapshotElement $element): ?string
    {
        $path = $element->get('audio_path');
        if (! is_string($path) || $path === '') {
            return null;
        }

        return Route::has(self::AUDIO_ROUTE)
            ? route(self::AUDIO_ROUTE, ['battle' => $battle->id, 'element' => $element->id], false)
            : "/api/twa/practice/battles/{$battle->id}/audio/{$element->id}";
    }

    /** @return array{id: int, status: string, mode: string, role: string, course: array{slug: string, title: string}, lesson: array{id: int, stage: int|null, number: int|null, title: string|null}|null, opponent: array{first_name: string|null}} */
    private function compact(Battle $battle, Learner $viewer): array
    {
        $me = $battle->participant($viewer->id);
        $snapshot = Snapshot::fromArray($battle->snapshot);

        return [
            'id' => $battle->id,
            'status' => $battle->status->value,
            'mode' => $battle->mode->value,
            'role' => $me->role->value,
            'course' => ['slug' => $snapshot->courseSlug, 'title' => $snapshot->courseTitleFor($me->locale)],
            'lesson' => $this->lessonOf($battle, $snapshot),
            'opponent' => ['first_name' => $this->firstName($battle->otherId($viewer->id))],
        ];
    }

    private function firstName(int $learnerId): ?string
    {
        $name = Learner::query()->whereKey($learnerId)->value('first_name');

        return is_string($name) ? $name : null;
    }

    /**
     * The first names of the other players of the battles, in one query.
     *
     * @param  list<Battle>  $battles
     * @return array<int, string|null>
     */
    private function firstNames(array $battles, Learner $viewer): array
    {
        $ids = [];
        foreach ($battles as $battle) {
            $ids[$battle->otherId($viewer->id)] = true;
        }
        if ($ids === []) {
            return [];
        }

        $names = [];
        foreach (Learner::query()->whereIn('id', array_keys($ids))->get(['id', 'first_name']) as $learner) {
            $names[$learner->id] = $learner->first_name;
        }

        return $names;
    }
}
