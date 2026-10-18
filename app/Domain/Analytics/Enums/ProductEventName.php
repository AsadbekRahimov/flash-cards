<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Enums;

enum ProductEventName: string
{
    case TwaAuthenticated = 'twa.authenticated';
    case TrainingSessionOpened = 'training.session_opened';
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
    case PromoRedeemed = 'promo.redeemed';
}
