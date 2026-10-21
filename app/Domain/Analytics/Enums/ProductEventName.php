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
    case TrainingSessionOpened = 'training.session_opened';
    case TrainingSessionFinished = 'training.session_finished';
    case ExamStarted = 'exam.started';
    case ExamClosed = 'exam.closed';
    case ExamLeaderboardPosted = 'exam.leaderboard_posted';
    /** A learner opened a personal IELTS / SAT mock (props: exam_session_id, course_id, exam_tag, questions — docs/13 §8.4). */
    case ExamMockStarted = 'exam.mock_started';
    /** A personal mock was finalized (props: exam_session_id, course_id, percent, percentile, answered, reason — finished / expired). */
    case ExamMockFinished = 'exam.mock_finished';
    case ReminderSent = 'reminder.sent';
    case ReminderFailed = 'reminder.failed';
    case GroupBotAdded = 'group.bot_added';
    case GroupMemberJoined = 'group.member_joined';
    case GroupMemberLeft = 'group.member_left';
    /** Membership in an organization became active (props: via, seat_state — docs/13 §2.3). */
    case OrgMemberJoined = 'org.member_joined';
    case OrgMemberLeft = 'org.member_left';
    /** A waitlisted member got a seat (FIFO after a leave or a tariff upgrade). */
    case OrgMemberSeated = 'org.member_seated';
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
    /** Both sides were granted (or skipped: perpetual Pro) their reward (props: referral_id, days, referee_skipped, referrer_skipped). */
    case ReferralRewarded = 'referral.rewarded';
    /** A referral was refused (props: reason — self / existing_learner / unknown_code / referrer_capped / flagged). */
    case ReferralRejected = 'referral.rejected';
    case DailyGoalMet = 'daily_goal.met';
    case StreakExtended = 'streak.extended';
    /** A missed local day was held by a streak freeze (props: date, freezes_left — docs/13 §7.2). */
    case StreakFrozen = 'streak.frozen';
    /** Leagues (docs/13 §7.3): a learner joined a group of the week / a week was closed (no learner). */
    case LeaguePlaced = 'league.placed';
    case LeagueWeekClosed = 'league.week_closed';
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
}
