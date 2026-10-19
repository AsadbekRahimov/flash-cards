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
    case ReminderSent = 'reminder.sent';
    case ReminderFailed = 'reminder.failed';
    case GroupBotAdded = 'group.bot_added';
    case GroupMemberJoined = 'group.member_joined';
    case GroupMemberLeft = 'group.member_left';
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
    case PromoRedeemed = 'promo.redeemed';
    case PromoRejected = 'promo.rejected';
    case DailyGoalMet = 'daily_goal.met';
    case StreakExtended = 'streak.extended';
}
