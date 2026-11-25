<?php

declare(strict_types=1);

namespace App\Domain\Cup\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Cup\DTO\CupBoard;
use App\Domain\Cup\DTO\CupContext;
use App\Domain\Cup\DTO\CupJoinOutcome;
use App\Domain\Cup\DTO\CupRoundStart;
use App\Domain\Cup\Enums\CupParticipantStatus;
use App\Domain\Cup\Enums\CupStatus;
use App\Domain\Cup\Exceptions\CupException;
use App\Domain\Cup\Support\CupAccess;
use App\Models\Cup;
use App\Models\CupParticipant;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The requests of the Mini App to a cup (docs/33 §7.2, docs/04 A.23, K7): where each one finds its cup and in which order the doors
 * are checked, so that the answer never tells a stranger more than "no". The rules themselves are the core's.
 *
 * - **By the numeric id** (`/cups/{id}…`, ids are sequential): a missing cup, a cup of a chat the learner is not in, a paused cup, a
 *   failed or busy check of Telegram - ONE answer to a stranger, 403 `cup_not_member` (`assertCanView(…, opaque: true)`); only a
 *   definite "member" of Telegram opens the cup to him. The reasons (409 / 503 / 429) are told to who the server already knows
 *   without Telegram: a participant (any status, by the database). An `active` participant passes without a call; one who left or
 *   was removed passes only a fresh check (docs/33 §11.2 п. 7). Who got through learns more: the cup is not available in this chat
 *   (pilot), its status, its pause. An action that needs a participant (the name, the round) refuses a non-participant with that
 *   same 403 before the core looks at the status of the cup.
 * - **By the code of the link** (128 random bits, seen by the whole chat): the code is a hint which cup to open, never a pass - Telegram
 *   is asked about THIS learner; a member who is not a participant sees the invitation, the others 403 (`opaque: false`: the member is
 *   told when the bot cannot check or Telegram is out, the core's reasons).
 * - **The administrator of the chat** (setup, publish, settings, cancel) is asked of Telegram by the core every time; a missing code
 *   answers what a non-administrator gets.
 *
 * Nothing of the client is a fact: the learner is the JWT's, the time is the server's, the answer of Telegram is the server's own call.
 */
final class CupMiniApp
{
    /** One `cup.invite_viewed` per learner and cup within this window: the pilot counts unique learners (docs/33 §8). */
    private const INVITE_VIEW_SECONDS = 3600;

    public function __construct(
        private readonly CupReader $reader,
        private readonly CupMembershipGate $gate,
        private readonly CupStandings $standings,
        private readonly CupProEmoji $emoji,
        private readonly CupRoundService $rounds,
        private readonly CupParticipationService $participation,
        private readonly CupLifecycleService $lifecycle,
        private readonly CupAdminCheck $admins,
        private readonly ProductEventRecorder $events,
    ) {}

    /**
     * The invitation by the link of the chat (`GET /cups/by-code/{code}`): a participant, or a member of the chat right now.
     *
     * @throws CupException cup_not_member | cup_paused | cup_unverifiable | cup_check_busy | cup_unavailable
     */
    public function invite(string $code, Learner $learner): CupContext
    {
        $context = $this->reader->locateByCode($code, $learner, withCount: true) ?? throw CupException::notMember();
        $this->gate->assertCanView($context->cup, $learner, $context->participant, opaque: false);
        $this->assertAvailable($context->cup);

        $this->recordInviteViewed($context, $learner);

        return $context;
    }

    /**
     * A cup by its id for whoever may see it (see the class): a participant at no cost, a member of the chat by a fresh check.
     *
     * @throws CupException cup_not_member | cup_unavailable
     */
    public function visible(int $cupId, Learner $learner): CupContext
    {
        return $this->door($cupId, $learner);
    }

    /**
     * The table (`GET /cups/{id}`): the standings (cached) with the emoji of Pro (cached apart), and for a participant his day and days.
     *
     * @throws CupException cup_not_member | cup_unavailable
     */
    public function board(int $cupId, Learner $learner, ?CarbonImmutable $now = null): CupBoard
    {
        $now ??= CarbonImmutable::now();
        $context = $this->visible($cupId, $learner);
        $cup = $context->cup;
        $mine = $context->participant;

        $table = $this->standings->table($cup);
        $emoji = $table === [] ? [] : $this->emoji->forCup($cup, $now);
        $standings = [];
        foreach ($table as $row) {
            $standings[] = [
                'participant_id' => $row['participant_id'],
                'rank' => $row['rank'],
                'display_name' => $row['display_name'],
                'pro_emoji' => $emoji[$row['participant_id']] ?? null,
                'total' => $row['total'],
                'round_points' => $row['round_points'],
                'days_played' => $row['days_played'],
                'left' => $row['left'],
                'me' => $mine !== null && $row['participant_id'] === $mine->id,
            ];
        }

        return new CupBoard(
            context: $context,
            standings: $standings,
            today: $mine === null ? null : $this->rounds->today($cup, $mine, $now),
            days: $mine === null || $cup->starts_at === null ? null : $this->reader->days($cup, $mine),
            canManage: $this->isOrganiser($cup, $learner),
            now: $now,
        );
    }

    /**
     * The state of today (`GET /cups/{id}/rounds/today`): `not_available` for a member who is not a participant.
     *
     * @return array{state: 'not_started'|'active'|'done'|'not_available', day: int|null, attempt_id: int|null, points: int|null, expires_at: string|null}
     *
     * @throws CupException cup_not_member | cup_unavailable
     */
    public function today(int $cupId, Learner $learner, ?CarbonImmutable $now = null): array
    {
        $context = $this->visible($cupId, $learner);

        return $this->rounds->today($context->cup, $context->participant, $now);
    }

    /**
     * Joining (`POST /cups/{id}/join`): the door of the id first - a stranger, a missing cup and a check that could not be made get
     * the same 403 and learn nothing of the cup (not even that it is over or paused); a participant (one who left comes back here) is
     * told the reasons - then the core, which asks Telegram again inside the join (the answer of the door is reused for its 10 s, the
     * core's throttle) and says WHY it could not (docs/33 §4.4: 503 / 409 `cup_unverifiable`, 409 `cup_paused`, 429 `cup_check_busy`)
     * to who the door has just found a member.
     *
     * @throws CupException cup_not_member | cup_unavailable | cup_not_open | cup_closed | cup_display_name_invalid | cup_paused
     *                      | cup_unverifiable | cup_check_busy
     */
    public function join(int $cupId, Learner $learner, ?string $displayName, ?CarbonImmutable $now = null): CupJoinOutcome
    {
        $context = $this->door($cupId, $learner);
        $outcome = $this->participation->join($context->cup, $learner, $displayName, $now);
        if ($outcome->created) {
            $this->emoji->forget($context->cup->id);
        }

        return $outcome;
    }

    /**
     * The name in the tournament (`PATCH /cups/{id}/me`): an `active` participant who is a member of the chat now (the core asks
     * Telegram); a stranger, a missing cup and a participant who left or was removed get the same 403.
     *
     * @throws CupException cup_not_member | cup_unavailable | cup_display_name_invalid | cup_rename_limit | cup_closed | cup_paused
     *                      | cup_unverifiable | cup_check_busy
     */
    public function rename(int $cupId, Learner $learner, string $displayName, ?CarbonImmutable $now = null): CupParticipant
    {
        $context = $this->reader->locate($cupId, $learner);
        $participant = $context?->participant;
        if ($context === null || $participant === null || $participant->status !== CupParticipantStatus::Active) {
            throw CupException::notMember();
        }
        $this->assertAvailable($context->cup);

        return $this->participation->rename($context->cup, $participant, $learner, $displayName, $now);
    }

    /**
     * "Play today" (`POST /cups/{id}/rounds/today`): a participant only; the core asks Telegram and decides the rest.
     *
     * @throws CupException cup_not_member | cup_unavailable | cup_not_open | cup_not_started | cup_closed | cup_round_unavailable
     *                      | cup_round_done | cup_paused | cup_unverifiable | cup_check_busy | cup_material_unavailable
     */
    public function startRound(int $cupId, Learner $learner, ?CarbonImmutable $now = null): CupRoundStart
    {
        $context = $this->reader->locate($cupId, $learner);
        if ($context === null || $context->participant === null) {
            throw CupException::notMember();
        }
        $this->assertAvailable($context->cup);

        return $this->rounds->startToday($learner, $context->cup, $now);
    }

    /**
     * "My cups" (`GET /cups`).
     *
     * @return list<CupContext>
     */
    public function mine(Learner $learner): array
    {
        return $this->reader->mine($learner);
    }

    /**
     * The setup of a draft (`GET /cups/setup/{code}`): an administrator of the chat, asked of Telegram now.
     *
     * @throws CupException cup_not_chat_admin | cup_unavailable | cup_paused | cup_unverifiable
     */
    public function setup(string $code, Learner $learner): CupContext
    {
        $context = $this->reader->locateByCode($code, $learner) ?? throw CupException::notChatAdmin();
        $this->admins->assertChatAdmin($context->cup->telegramGroup, (int) $learner->telegram_user_id);
        $this->assertAvailable($context->cup);

        return $context;
    }

    /**
     * Publishing (`POST /cups/setup/{code}/publish`): the core checks the administrator of a draft; a cup already published is shown to
     * an administrator only (the core returns it as it is).
     *
     * @throws CupException cup_not_chat_admin | cup_unavailable | cup_closed | cup_paused | cup_bot_not_admin | cup_unverifiable
     *                      | cup_level_invalid | cup_material_unavailable
     */
    public function publish(string $code, Learner $learner, ?string $level, ?string $locale, ?CarbonImmutable $now = null): Cup
    {
        $context = $this->reader->locateByCode($code, $learner) ?? throw CupException::notChatAdmin();
        $cup = $context->cup;
        if ($cup->status !== CupStatus::Draft) {
            $this->admins->assertChatAdmin($cup->telegramGroup, (int) $learner->telegram_user_id);
        }

        return $this->lifecycle->publish($cup, (int) $learner->telegram_user_id, $level, $locale, $now);
    }

    /**
     * The settings of an administrator of the chat (`PATCH /cups/{id}/settings`).
     *
     * @param  array{level?: string, locale?: string, summaries_enabled?: bool, summary_minute?: int}  $settings
     *
     * @throws CupException cup_not_member | cup_unavailable | cup_not_chat_admin | cup_closed | cup_paused | cup_unverifiable
     *                      | cup_already_published | cup_level_invalid | cup_material_unavailable
     */
    public function settings(int $cupId, Learner $learner, array $settings, ?CarbonImmutable $now = null): Cup
    {
        $context = $this->visible($cupId, $learner);

        return $this->lifecycle->updateSettings($context->cup, (int) $learner->telegram_user_id, $settings, $now);
    }

    /**
     * The cancel of an administrator of the chat (`POST /cups/{id}/cancel`): the cup after it (a closed one as it is - nothing to do).
     *
     * @throws CupException cup_not_member | cup_unavailable | cup_not_chat_admin | cup_paused | cup_unverifiable
     */
    public function cancel(int $cupId, Learner $learner, ?CarbonImmutable $now = null): Cup
    {
        $context = $this->visible($cupId, $learner);
        $cup = $context->cup;
        if ($this->lifecycle->cancelByChatAdmin($cup, (int) $learner->telegram_user_id, $now)) {
            $fresh = Cup::query()->findOrFail($cup->id);
            $fresh->setRelation('telegramGroup', $cup->telegramGroup);

            return $fresh;
        }

        return $cup;
    }

    /**
     * A cup by its id behind the door of membership: a missing cup is the refusal of a stranger, and so is every refusal of the gate
     * to a non-participant (`opaque`); a participant is told the reasons.
     *
     * @throws CupException cup_not_member | cup_unavailable | cup_paused | cup_unverifiable | cup_check_busy
     */
    private function door(int $cupId, Learner $learner): CupContext
    {
        $context = $this->reader->locate($cupId, $learner) ?? throw CupException::notMember();
        $this->gate->assertCanView($context->cup, $learner, $context->participant, opaque: true);
        $this->assertAvailable($context->cup);

        return $context;
    }

    /** The feature is open for the chat of the cup (`CUP_ACCESS`, the pilot flag of the group). Asked only of who got through the door. */
    private function assertAvailable(Cup $cup): void
    {
        if (! CupAccess::allowsGroup($cup->telegramGroup)) {
            throw CupException::unavailable();
        }
    }

    /** A hint, not a right: the learner proposed or published the cup (the settings link of the screen). */
    private function isOrganiser(Cup $cup, Learner $learner): bool
    {
        $id = (int) $learner->telegram_user_id;

        return $id > 0 && ($cup->proposed_by_telegram_user_id === $id || $cup->confirmed_by_telegram_user_id === $id);
    }

    private function recordInviteViewed(CupContext $context, Learner $learner): void
    {
        try {
            if (! Cache::add("cup:invite-viewed:v1:{$context->cup->id}:{$learner->id}", 1, self::INVITE_VIEW_SECONDS)) {
                return;
            }
        } catch (Throwable $e) {
            report($e);
        }

        $this->events->record(ProductEventName::CupInviteViewed, [
            'cup_id' => $context->cup->id,
            'joined' => $context->participant !== null,
            'status' => $context->cup->status->value,
        ], learner: $learner);
    }
}
