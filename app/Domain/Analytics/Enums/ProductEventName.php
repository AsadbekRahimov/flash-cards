<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Enums;

enum ProductEventName: string
{
    case TwaAuthenticated = 'twa.authenticated';
    case OnboardingStarted = 'onboarding.started';
    case OnboardingStepCompleted = 'onboarding.step_completed';
    case OnboardingCompleted = 'onboarding.completed';
    case CourseSelected = 'course.selected';
    case SettingsChanged = 'settings.changed';
    /** The region of the learner changed (props: from, to - a code or null; via - onboarding / settings / prompt / undo; docs/35 §2.3). */
    case RegionSet = 'region.set';
    case TrainingSessionOpened = 'training.session_opened';
    case TrainingSessionFinished = 'training.session_finished';
    case ExamStarted = 'exam.started';
    case ExamClosed = 'exam.closed';
    case ExamLeaderboardPosted = 'exam.leaderboard_posted';
    /** A learner opened a personal IELTS / SAT mock (props: exam_session_id, course_id, exam_tag, questions — docs/13 §8.4). */
    case ExamMockStarted = 'exam.mock_started';
    /** A personal mock was finalized (props: exam_session_id, course_id, percent, percentile, answered, reason — finished / expired). */
    case ExamMockFinished = 'exam.mock_finished';
    /** A personal reminder went out (props: kind, due_count, and quiz = true when the reminder is a question with four answers, docs/12 §7.5). */
    case ReminderSent = 'reminder.sent';
    case ReminderFailed = 'reminder.failed';
    /** The reminder was a question (props: kind, word_id, exercise, due_count); sent together with `reminder.sent`. */
    case ReminderQuizSent = 'reminder.quiz_sent';
    /** The reminder after a lesson went out in the bot: "fix the words in a game" (props: lesson_id, words — docs/31 §5б). */
    case LessonNudgeSent = 'lesson_nudge.sent';
    /** The learner tapped an answer, once per question (props: kind, word_id, exercise, correct, picked, latency_s, due_count). */
    case ReminderQuizAnswered = 'reminder.quiz_answered';
    /** A tap on a question that is no longer there (props: reason — missing / token_mismatch / no_message). */
    case ReminderQuizExpired = 'reminder.quiz_expired';
    /** The learner turned the personal reminders off: `reminder_time` went from a time to null (props: via — settings / onboarding). */
    case RemindersDisabled = 'reminders.disabled';
    /** The bot cannot write to the learner any more, on the transition only (props: reason - bot_blocked / not_started / user_deactivated / chat_not_found / forbidden / kicked, via - send / my_chat_member; docs/34 §4в п. 3). */
    case BotDmClosed = 'bot.dm_closed';
    /** The private chat of the learner is open again, on the transition only (props: via - dm / start / my_chat_member). */
    case BotDmReopened = 'bot.dm_reopened';
    case GroupBotAdded = 'group.bot_added';
    case GroupMemberJoined = 'group.member_joined';
    case GroupMemberLeft = 'group.member_left';
    /** Membership in an organization became active (props: via, seat_state — docs/13 §2.3). */
    case OrgMemberJoined = 'org.member_joined';
    case OrgMemberLeft = 'org.member_left';
    /** A waitlisted member got a seat (FIFO after a leave or a tariff upgrade). */
    case OrgMemberSeated = 'org.member_seated';
    /** The learner declined a membership offered by the center's staff (props: organization_id, member_id — audit 2026-11-10 M2). */
    case OrgMemberDeclined = 'org.member_declined';
    /** A center set a lesson for a group (props: assignment_id, lesson_id, organization_id — docs/13 §1.4). */
    case AssignmentCreated = 'assignment.created';
    /** A learner reached the threshold of an assignment, once per (assignment, learner) (props: assignment_id, late). */
    case AssignmentCompleted = 'assignment.completed';
    case SubscriptionGranted = 'subscription.granted';
    case SubscriptionExpired = 'subscription.expired';
    /** Auto-renewal stopped by the user; Pro stays until the period end (docs/13 §2.1). */
    case SubscriptionCanceled = 'subscription.canceled';
    case SubscriptionResumed = 'subscription.resumed';
    case CheckoutStarted = 'checkout.started';
    case PaymentSucceeded = 'payment.succeeded';
    /** A provider event that could not be applied (amount mismatch, dead order): money may need a manual refund. */
    case PaymentFailed = 'payment.failed';
    case PaymentRefunded = 'payment.refunded';
    /** A Free learner ran into the daily new-word limit: `done_reason = new_quota_exhausted` was answered (once per local day; docs/12 §5.4). */
    case PaywallShown = 'paywall.shown';
    /** A Pro offer became visible in the TWA, reported by the client, once per (learner, placement, local day) (props: placement, locale; docs/13 §5.1). */
    case BillingOfferViewed = 'billing.offer_viewed';
    /** A personal discount was issued (props: offer_id, kind, discount_pct, plan, period, organization_id — docs/13 §2.1). */
    case OfferCreated = 'offer.created';
    /** The discount was used by a paid order (props: offer_id, kind, discount_pct, payment_order_id). */
    case OfferRedeemed = 'offer.redeemed';
    case PromoRedeemed = 'promo.redeemed';
    case PromoRejected = 'promo.rejected';
    /** A new learner came through `ref_<code>` (props: referral_id, referrer_learner_id, flagged — docs/13 §7.1). */
    case ReferralLinked = 'referral.linked';
    /** The invited learner studied enough (props: referral_id, active_days). */
    case ReferralActivated = 'referral.activated';
    /** The invited learner was counted towards the inviter's milestones (props: referral_id, referrer_learner_id, counted — the friend's number; docs/13 §7.1 "2C.9"). Until 2C.9 `referral.rewarded` marked the per-friend reward of both sides. */
    case ReferralCounted = 'referral.counted';
    /** The inviter reached a milestone and its reward was settled (props: milestone, days, referral_id, subscription_id, skipped — perpetual / learner_missing / learner_deleted / learner_blocked); actor = the inviter. */
    case ReferralMilestoneReached = 'referral.milestone_reached';
    /** A referral was refused (props: reason — self / existing_learner / unknown_code / flagged; legacy referrer_capped). */
    case ReferralRejected = 'referral.rejected';
    /** The welcome trial of a new learner was granted (props: days, subscription_id, ends_at, extended — docs/13 §7.1 "2C.9"). */
    case TrialGranted = 'trial.granted';
    /** A learner eligible by the row was refused the trial (props: reason — already_used: this Telegram account had one before). */
    case TrialRefused = 'trial.refused';
    /** An offer after the welcome trial went out in the bot (props: step — 0 / 3 / 10 days after its end, subscription_id, priced — with prices or "Pro is coming"; docs/34 §2д). */
    case TrialOfferSent = 'trial_offer.sent';
    /** The learner tapped "Do not send offers" under an offer (no props; only on the change false -> true; docs/34 §2д). */
    case TrialOfferOptedOut = 'trial_offer.opted_out';
    case DailyGoalMet = 'daily_goal.met';
    /** The daily goal was lowered to the limit of the plan - Pro ended (props: from, to, via — today / settings / training / command; docs/34 §2б.2). */
    case DailyGoalLimited = 'daily_goal.limited';
    /** The goal lowered by the limit came back - Pro is back (props: from, to, via). */
    case DailyGoalRestored = 'daily_goal.restored';
    case StreakExtended = 'streak.extended';
    /** A missed local day was held by a streak freeze (props: date, freezes_left — docs/13 §7.2). */
    case StreakFrozen = 'streak.frozen';
    /** Leagues (docs/13 §7.3): a learner joined a group of the week / a week was closed (no learner). */
    case LeaguePlaced = 'league.placed';
    case LeagueWeekClosed = 'league.week_closed';
    /** Monthly rating (docs/13 §7.6): a month was closed into `pending_review` (props: month_id, starts_on, participants, candidates; no learner). */
    case LeagueMonthClosed = 'league.month_closed';
    /** An administrator confirmed the winners of a month (props: month_id, starts_on, winners; actor = the user). */
    case LeagueMonthConfirmed = 'league.month_confirmed';
    /** A candidate was disqualified / taken back by an administrator (props: month_id, candidate_id, rank; actor = the user, the learner). */
    case LeagueMonthCandidateDisqualified = 'league.month_candidate_disqualified';
    case LeagueMonthCandidateReinstated = 'league.month_candidate_reinstated';
    /** A prize of a confirmed winner was marked as delivered (props: month_id, candidate_id, place; actor = the user, the learner). */
    case LeagueMonthPrizeDelivered = 'league.month_prize_delivered';
    /**
     * Word points v2 (docs/32 S16): an answer took a count of the rating day to its cap - once per learner, rating day and count
     * (props: count — week / month, rating_day, category, cap). Recorded after the commit of the answer, only on the v2 rules.
     */
    case ScoreDayCapped = 'score.day_capped';
    /**
     * Word points v2 (docs/32 S16, the architect's decision): ONE summary of a rating day, written by the nightly
     * `leagues:report-score-day` for yesterday - no learner (props: rating_day, learners, credits, {free|pro}_learners,
     * {free|pro}_credits, {week|month}_{free|pro}_{p50|p90|capped_share}). At most one per rating day (`INSERT … WHERE NOT EXISTS`).
     */
    case ScoreCredited = 'score.credited';
    /** A friend request was created (props: friendship_id — docs/13 §7.5); actor = the requester. */
    case FriendRequested = 'friend.requested';
    /** A friend request was accepted (props: friendship_id); actor = the acceptor. */
    case FriendAccepted = 'friend.accepted';
    /** A friendship ended (props: friendship_id, how — removed / declined / canceled / unblocked / blocked); actor = who ended it. */
    case FriendRemoved = 'friend.removed';
    /** A challenge was proposed to a friend (props: challenge_id, metric, days). */
    case ChallengeProposed = 'challenge.proposed';
    /** The friend accepted the challenge (props: challenge_id, metric, starts_on). */
    case ChallengeAccepted = 'challenge.accepted';
    /** A challenge was closed by its scores (props: challenge_id, metric, result — challenger / opponent / draw); actor = the challenger. */
    case ChallengeFinished = 'challenge.finished';
    /** An achievement was unlocked, once per (learner, key) (props: key — docs/13 §7.4). */
    case AchievementUnlocked = 'achievement.unlocked';
    /** The learner shared an achievement from the TWA, once per (learner, key) (props: key). */
    case AchievementShared = 'achievement.shared';
    /** The server prepared a message to share "through the bot" and gave its id (props: kind, subject, via - mini_app, cached, photo; docs/36 §1в, H7). */
    case SharePrepared = 'share.prepared';
    /** The Mini App says the prepared message was sent (`shareMessage` → sent; props: kind; docs/36 H7). Reported by the client. */
    case ShareSent = 'share.sent';
    /** The Mini App opened `t.me/share/url` instead (props: kind, reason - old_client / error; docs/36 H7). Reported by the client. */
    case ShareFallback = 'share.fallback';
    /** A solo practice session was created (props: session_id, mode, course_id, lesson_id, source, total, retry — docs/16 §3.4). */
    case PracticeSessionStarted = 'practice.session_started';
    /** A solo session ended, once, by whoever finished it (props: session_id, mode, total, answered, correct_first, hints, mismatches, reason — completed / expired / replaced, duration_ms). */
    case PracticeSessionFinished = 'practice.session_finished';
    /** A session or a battle was refused for lack of material (props: mode, course_id, lesson_id, available, required, reason, battle). */
    case PracticeNotEnoughMaterial = 'practice.not_enough_material';
    /** A dictation could not be played and was swapped / reported (props: session_id | battle_id, source — word / phrase, reason — file_missing / client_report, verified). */
    case PracticeAudioFailed = 'practice.audio_failed';
    /** A Free learner was refused a session or a battle call by the daily limit (props: kind — sessions / battles, limit; docs/18 G1). */
    case PracticeLimitReached = 'practice.limit_reached';
    /** A battle invitation was sent (props: battle_id, mode, course_id, lesson_id, rematch); actor = the challenger. */
    case BattleInvited = 'battle.invited';
    /** The invited friend accepted (props: battle_id). */
    case BattleAccepted = 'battle.accepted';
    /** Both players are ready, the countdown began (props: battle_id, mode). */
    case BattleStarted = 'battle.started';
    /** A battle ended with a result (props: battle_id, end_reason, result — challenger / opponent / draw, rounds_played, duration_ms). */
    case BattleFinished = 'battle.finished';
    /** A battle was closed without a result (props: battle_id, end_reason). */
    case BattleClosed = 'battle.closed';
    /** A run of the game started (props: run_id, kind, course_id, stage_id, lesson_id, total, free_slot, scope_widened - docs/18 §12.8). */
    case GameRunStarted = 'game.run_started';
    /** A run ended, once, by whoever finished it (props: run_id, kind, finish_reason, total, answered, correct, typos, wrong, hints, stars, score, coins, league_points, srs_pulled, duration_ms, event). */
    case GameRunFinished = 'game.run_finished';
    /** A run was refused for lack of material (props: kind, course_id, lesson_id, available, required, reason). */
    case GameNotEnoughMaterial = 'game.not_enough_material';
    /** Coins were spent (props: amount, kind, ref_type, ref_id, balance). */
    case GameCoinsSpent = 'game.coins_spent';
    /** Coins were earned by a run (props: amount, kind, ref_type, ref_id, balance). */
    case GameCoinsEarned = 'game.coins_earned';
    /** The daily caravan was played to its end (props: run_id, due_count, correct - docs/18 §12.8). */
    case GameDailyCompleted = 'game.daily_completed';
    /** A learner beat the boss of a city (props: run_id, stage_id, accuracy - 0..100, first - the first win over this boss; docs/18 §12.8). */
    case GameBossDefeated = 'game.boss_defeated';
    /** A learner opened the share dialog of the card of a city they reached, once (props: stage_id; docs/18 §12.9). */
    case GameCityShared = 'game.city_shared';
    /** A learner bought an item of the shop (props: item_key, kind, price). */
    case GameItemBought = 'game.item_bought';
    /** A learner put a cosmetic on or took it off (props: slot, item_key - null when taken off). */
    case GameCosmeticEquipped = 'game.cosmetic_equipped';
    /** A booster was used in a run (props: run_id, effect - hint / extra_flask / second_chance). */
    case GameBoosterUsed = 'game.booster_used';
    /** A Free learner was refused by a limit of the game (props: kind - runs / boss / boosters, limit; docs/18 §12.7.4), on the courtesy check and under the lock alike. */
    case GameLimitReached = 'game.limit_reached';
    /** Tickets were granted (props: source - streak / referral / admin, delta - what was written after the cap, balance). */
    case GameTicketGranted = 'game.ticket_granted';
    /** A ticket paid for a map run beyond the free run of the day (props: run_id). */
    case GameTicketSpent = 'game.ticket_spent';
    /** The ticket of a run that ended without a single answer came back (props: run_id, reason - replaced / exited / expired; docs/18 §12.7.3). */
    case GameTicketRefunded = 'game.ticket_refunded';
    /** A grant of tickets was cut by the cap of the balance, wholly or in part (props: source, lost). */
    case GameTicketCapped = 'game.ticket_capped';
    /** An administrator changed a learner's coins (props: amount - signed, audit_log_id); actor - the user, the learner. */
    case GameCoinsAdjusted = 'game.coins_adjusted';
    /** An administrator changed a learner's tickets (props: amount, audit_log_id); actor - the user, the learner. */
    case GameTicketsAdjusted = 'game.tickets_adjusted';
    /** A teacher (or an admin) started the caravan of a class (props: class_caravan_id, group_id, members, target - docs/18 §12.11); actor - the user, the group. */
    case GameClassStarted = 'game.class_started';
    /** The week of a class caravan ended by the clock (props: class_caravan_id, group_id, stars_total, runs_total, boss_defeated); actor - the group. */
    case GameClassFinished = 'game.class_finished';
    /** The summary of a class caravan went to the group chat, once (props: class_caravan_id); actor - the group. */
    case GameClassSummaryPosted = 'game.class_summary_posted';
    /**
     * Honesty of the self-grade (docs/25 "metrics", docs/06 §12.2): a flash card graded "remember" / "easy" (quality >= 4) was
     * followed, on the same word, by a WRONG objectively graded answer (props: word_id, exercise, previous_quality, interval_before_days).
     */
    case ReviewSelfGradeMismatch = 'review.self_grade_mismatch';
    /** The learner opened the readiness screen (props: course_id, percent, learned, total, source - today / profile / course; docs/13 §8.5). */
    case ReadinessViewed = 'readiness.viewed';
    /** A quiz of the week was opened (props: exam_session_id, course_id, week_start, scored, questions, met, unmet). */
    case LevelQuizStarted = 'level_quiz.started';
    /** A quiz of the week was finalized, once (props: exam_session_id, course_id, week_start, scored, correct, total, percent, answered, reason - finished / expired). */
    case LevelQuizFinished = 'level_quiz.finished';
    /** The round of hard words was refused (props: reason - disabled / not_enough / played_today / no_objective_exercise, source; docs/06 §14). Opening is `training.session_opened` with mode = hard_review. */
    case HardRoundRefused = 'hard_round.refused';
    /**
     * Timing marks of one sampled open of the Mini App, reported by the client (docs/04 A.21; props: open_id, platform, cold, entry, locale,
     * class, and the millisecond offsets auth_ready, today_received, button_ready, first_tap, first_answer_ack, first_audio - each only when set).
     */
    case ClientTiming = 'client.timing';
    /**
     * A learner answered a poll of the bot, first time or a change (docs/35 §1.5, O7; props: poll_id, delivery_id, changed). Not the
     * administrator's test send. No actor: the tap is matched by the delivery's token and Telegram id, the learner is the delivery's.
     */
    case PollAnswered = 'poll.answered';
    /** A cup of a group was proposed as a draft (docs/33 §8; props: cup_id); actor - the group. */
    case CupProposed = 'cup.proposed';
    /** An administrator of the chat published a cup (props: cup_id, level, days); actor - the group. */
    case CupPublished = 'cup.published';
    /** A cup started at the midnight of its day 1 (props: cup_id, participants); actor - the group. */
    case CupStarted = 'cup.started';
    /**
     * A learner opened the invitation of a cup by its link and was let in - a member of the chat or a participant (docs/33 §8, K7;
     * props: cup_id, joined, status). Once per learner and cup an hour: the pilot report counts the unique learners.
     */
    case CupInviteViewed = 'cup.invite_viewed';
    /** A learner joined a cup (props: cup_id, participant_id, first_day). */
    case CupJoined = 'cup.joined';
    /** A participant started the round of a day (props: cup_id, attempt_id, day). */
    case CupRoundStarted = 'cup.round_started';
    /** The round of a day ended, once (props: cup_id, attempt_id, day, points, reason - completed / expired). */
    case CupRoundFinished = 'cup.round_finished';
    /** A cup finished (props: cup_id, participants); actor - the group. */
    case CupFinished = 'cup.finished';
    /** A cup was canceled (props: cup_id, reason - draft_expired / chat_admin / platform_admin / no_players); actor - the group. */
    case CupCanceled = 'cup.canceled';
    /** The summary of a day of a cup was posted in its chat (docs/33 §6.1, K6; props: cup_id, day); actor - the group. */
    case CupSummaryPosted = 'cup.summary_posted';
    /** A participant challenged to a duel (docs/33 §5.7, D1; props: cup_id, duel_id, public, addressed, rated_offered, mode). */
    case CupDuelChallenged = 'cup.duel_challenged';
    /** A challenge was accepted - the match is on (props: cup_id, duel_id, battle_id, rated). */
    case CupDuelAccepted = 'cup.duel_accepted';
    /** The result of a duel was taken (props: cup_id, duel_id, rated, credited, challenger_points, opponent_points). */
    case CupDuelScored = 'cup.duel_scored';
    /** An accepted duel was void - nobody answered before the match closed; the slots are free again (props: cup_id, duel_id, reason). */
    case CupDuelVoid = 'cup.duel_void';
}
